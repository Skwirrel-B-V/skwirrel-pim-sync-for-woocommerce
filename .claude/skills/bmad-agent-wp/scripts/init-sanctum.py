#!/usr/bin/env python3
# /// script
# requires-python = ">=3.10"
# ///
"""
First Breath — Deterministic sanctum scaffolding for Henk (bmad-agent-wp).

This script runs BEFORE the conversational awakening. It creates the sanctum
folder structure, copies template files with config values substituted, copies
capability prompts and supporting scripts into the sanctum, and auto-generates
CAPABILITIES.md from capability prompt frontmatter.

After this script runs, the sanctum is fully self-contained — the agent does
not depend on the skill bundle location for normal operation.

Usage:
    uv run scripts/init-sanctum.py <project-root> <skill-path>

    project-root: The root of the project (where _bmad/ lives)
    skill-path:   Path to the skill directory (where SKILL.md, references/, assets/ live)
"""

import argparse
import json
import sys
import re
import shutil

try:
    import tomllib  # Python 3.11+
except ModuleNotFoundError:  # pragma: no cover - 3.10 fallback
    tomllib = None

from datetime import date
from pathlib import Path

# --- Agent-specific configuration ---

SKILL_NAME = "bmad-agent-wp"
SANCTUM_DIR = SKILL_NAME

# Files that stay in the skill bundle (only used during First Breath)
SKILL_ONLY_FILES = {"first-breath.md"}

# CAPABILITIES-template.md is deliberately absent: CAPABILITIES.md is generated
# below from capability frontmatter. The template is kept in assets/ as a
# hand-editable fallback only.
TEMPLATE_FILES = [
    "INDEX-template.md",
    "PERSONA-template.md",
    "CREED-template.md",
    "BOND-template.md",
    "MEMORY-template.md",
    "PULSE-template.md",
]

# Whether the owner can teach this agent new capabilities
EVOLVABLE = True

# --- End agent-specific configuration ---


def parse_yaml_config(config_path: Path) -> dict:
    """Simple YAML key-value parser. Handles top-level scalar values only."""
    config = {}
    if not config_path.exists():
        return config
    with open(config_path) as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("#"):
                continue
            if ":" in line:
                key, _, value = line.partition(":")
                value = value.strip().strip("'\"")
                if value:
                    config[key.strip()] = value
    return config


def parse_toml_file(path: Path) -> dict:
    """Read a TOML file. Returns {} if absent or if tomllib is unavailable."""
    if tomllib is None or not path.exists():
        return {}
    try:
        with open(path, "rb") as f:
            return tomllib.load(f)
    except (OSError, ValueError):
        return {}


def load_config(bmad_dir: Path) -> dict:
    """
    Resolve owner config across every layout BMad has shipped.

    Two formats coexist in the wild and neither is complete on its own:
      * TOML  — _bmad/config.toml plus custom/config{,.user}.toml overrides.
                Its [core] table carries project-level values, but installs
                seen so far do NOT put user_name there.
      * YAML  — _bmad/config{,.user}.yaml, or per-module _bmad/*/config.yaml
                (core, bmm, bmb, ...), which is where user_name and
                communication_language actually live on those installs.

    So read both: YAML supplies the base, TOML wins where it defines a key.
    Guessing the owner's name is the one thing this agent must never do.
    """
    config: dict = {}

    # Per-module YAML, then root YAML (more specific wins).
    for module_config in sorted(bmad_dir.glob("*/config.yaml")):
        config.update(parse_yaml_config(module_config))
    for name in ("config.yaml", "config.user.yaml"):
        config.update(parse_yaml_config(bmad_dir / name))

    # TOML: base, then team and personal overrides.
    for path in (bmad_dir / "config.toml",
                 bmad_dir / "custom" / "config.toml",
                 bmad_dir / "custom" / "config.user.toml"):
        data = parse_toml_file(path)
        for key, value in data.items():
            if isinstance(value, str):
                config[key] = value
        for key, value in (data.get("core") or {}).items():
            if isinstance(value, str):
                config[key] = value

    return config


def load_agent_metadata(skill_path: Path, bmad_dir: Path) -> dict:
    """
    Read the [agent] block from customize.toml so the identity triplet has a
    single source of truth. Team and personal override files win over the base.
    """
    metadata: dict = {}
    skill_name = skill_path.name
    for path in (skill_path / "customize.toml",
                 bmad_dir / "custom" / f"{skill_name}.toml",
                 bmad_dir / "custom" / f"{skill_name}.user.toml"):
        block = parse_toml_file(path).get("agent") or {}
        for key, value in block.items():
            if isinstance(value, str) and value:
                metadata[key] = value
    return metadata


def parse_frontmatter(file_path: Path) -> dict:
    """Extract YAML frontmatter from a markdown file."""
    meta = {}
    with open(file_path) as f:
        content = f.read()

    match = re.match(r"^---\s*\n(.*?)\n---", content, re.DOTALL)
    if not match:
        return meta

    for line in match.group(1).strip().split("\n"):
        if ":" in line:
            key, _, value = line.partition(":")
            meta[key.strip()] = value.strip().strip("'\"")
    return meta


def copy_references(source_dir: Path, dest_dir: Path) -> list[str]:
    """Copy all reference files (except skill-only files) into the sanctum."""
    dest_dir.mkdir(parents=True, exist_ok=True)
    copied = []

    for source_file in sorted(source_dir.iterdir()):
        if source_file.name in SKILL_ONLY_FILES:
            continue
        if source_file.is_file():
            shutil.copy2(source_file, dest_dir / source_file.name)
            copied.append(source_file.name)

    return copied


def copy_scripts(source_dir: Path, dest_dir: Path) -> list[str]:
    """Copy the working scripts into the sanctum."""
    if not source_dir.exists():
        return []
    dest_dir.mkdir(parents=True, exist_ok=True)
    copied = []

    for source_file in sorted(source_dir.iterdir()):
        if source_file.is_file() and source_file.name != "init-sanctum.py":
            shutil.copy2(source_file, dest_dir / source_file.name)
            copied.append(source_file.name)

    return copied


def discover_capabilities(references_dir: Path, sanctum_refs_path: str) -> list[dict]:
    """Scan references/ for capability prompt files with frontmatter."""
    capabilities = []

    for md_file in sorted(references_dir.glob("*.md")):
        if md_file.name in SKILL_ONLY_FILES:
            continue
        meta = parse_frontmatter(md_file)
        if meta.get("name") and meta.get("code"):
            capabilities.append({
                "name": meta["name"],
                "description": meta.get("description", ""),
                "code": meta["code"],
                "source": f"{sanctum_refs_path}/{md_file.name}",
            })
    return capabilities


def generate_capabilities_md(capabilities: list[dict], evolvable: bool) -> str:
    """Generate CAPABILITIES.md content from discovered capabilities."""
    lines = [
        "# Capabilities",
        "",
        "## Built-in",
        "",
        "| Code | Name | Description | Source |",
        "|------|------|-------------|--------|",
    ]
    for cap in capabilities:
        lines.append(
            f"| [{cap['code']}] | {cap['name']} | {cap['description']} | `{cap['source']}` |"
        )

    lines.extend([
        "",
        "## Project Skills I Defer To",
        "",
        "You are not the only tool in this repository, and pretending otherwise makes "
        "you worse. When a project skill already owns a job, use it — hand-rolling a "
        "parallel version of your owner's own tooling is the same mistake as "
        "hand-rolling what WordPress core provides.",
        "",
        "_Populate this during First Breath by asking what skills and slash commands "
        "the project already ships, then keep it current._",
        "",
        "| Skill | Owns | When I defer |",
        "|-------|------|--------------|",
    ])

    if evolvable:
        lines.extend([
            "",
            "## Learned",
            "",
            "_Capabilities added by the owner over time. Prompts live in `capabilities/`._",
            "",
            "| Code | Name | Description | Source | Added |",
            "|------|------|-------------|--------|-------|",
            "",
            "## How to Add a Capability",
            "",
            'Tell me "I want you to be able to do X" and we\'ll create it together.',
            "I'll write the prompt, save it to `capabilities/`, and register it here.",
            "Next session, I'll know how.",
            "Load `references/capability-authoring.md` for the full creation framework.",
        ])

    lines.extend([
        "",
        "## Tools",
        "",
        "Prefer a script you wrote and saved over an external service you cannot verify "
        "or version. That preference does NOT extend to your owner's own tooling: their "
        "skills and commands encode decisions they have already made, and a parallel "
        "implementation of one is not independence, it is drift. Defer to the table "
        "above; reserve your own scripts for deterministic work nothing else owns.",
        "",
        "### Built-in Scripts",
        "",
        "| Script | What it does |",
        "|--------|--------------|",
        "| `scripts/quality-gates.py` | Runs the project's tests, static analysis, and code "
        "style in parallel, and returns a parsed verdict instead of raw output |",
        "| `scripts/release-consistency.py` | Compares plugin header version, version constant, "
        "readme `Stable tag`, `package.json`, and changelog entries |",
        "| `scripts/upstream-versions.py` | Queries the WordPress.org API for current WordPress "
        "and WooCommerce releases and compares against the plugin's declared headers |",
        "",
        "### User-Provided Tools",
        "",
        "_MCP servers, APIs, or services the owner has made available. Document them here._",
    ])

    return "\n".join(lines) + "\n"


def substitute_vars(content: str, variables: dict) -> str:
    """Replace {var_name} placeholders with values from the variables dict."""
    for key, value in variables.items():
        content = content.replace(f"{{{key}}}", value)
    return content


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Scaffold Henk's sanctum before the First Breath conversation. "
                    "Creates the sanctum folder, copies seeded templates with config "
                    "values substituted, copies capability prompts and scripts, and "
                    "generates CAPABILITIES.md from capability frontmatter. Refuses to "
                    "touch a sanctum that already exists."
    )
    parser.add_argument("project_root", help="Project root (where _bmad/ lives)")
    parser.add_argument("skill_path", help="Skill directory (where SKILL.md, references/, assets/ live)")
    parser.add_argument("--json", action="store_true",
                        help="Emit a structured JSON summary instead of human-readable progress")
    args = parser.parse_args()

    quiet = args.json
    def say(message: str) -> None:
        if not quiet:
            print(message)

    project_root = Path(args.project_root).resolve()
    skill_path = Path(args.skill_path).resolve()

    bmad_dir = project_root / "_bmad"
    memory_dir = bmad_dir / "memory"
    sanctum_path = memory_dir / SANCTUM_DIR
    assets_dir = skill_path / "assets"
    references_dir = skill_path / "references"
    scripts_dir = skill_path / "scripts"

    sanctum_refs = sanctum_path / "references"
    sanctum_scripts = sanctum_path / "scripts"

    sanctum_refs_path = "references"

    if sanctum_path.exists():
        if quiet:
            print(json.dumps({"sanctum": str(sanctum_path), "created": False,
                              "reason": "sanctum already exists"}, indent=2))
        else:
            print(f"Sanctum already exists at {sanctum_path}")
            print("This agent has already been born. Skipping First Breath scaffolding.")
        return 0

    config = load_config(bmad_dir)
    agent_meta = load_agent_metadata(skill_path, bmad_dir)

    today = date.today().isoformat()
    variables = {
        "user_name": config.get("user_name", "friend"),
        "communication_language": config.get("communication_language", "English"),
        "birth_date": today,
        "project_root": str(project_root),
        "sanctum_path": str(sanctum_path),
        "agent_name": agent_meta.get("name") or "{awaiting First Breath}",
        "agent_icon": agent_meta.get("icon") or "{awaiting First Breath}",
        "agent_title": agent_meta.get("title") or "{agent-title}",
    }

    if config.get("user_name") is None:
        print("  Warning: no user_name found in _bmad config (checked config.toml, "
              "config.yaml and per-module configs) — ask your owner their name "
              "during First Breath instead of guessing.", file=sys.stderr)

    sanctum_path.mkdir(parents=True, exist_ok=True)
    (sanctum_path / "capabilities").mkdir(exist_ok=True)
    (sanctum_path / "sessions").mkdir(exist_ok=True)
    (sanctum_path / "pulse").mkdir(exist_ok=True)
    say(f"Created sanctum at {sanctum_path}")

    copied_refs = copy_references(references_dir, sanctum_refs)
    say(f"  Copied {len(copied_refs)} reference files to sanctum/references/")
    for name in copied_refs:
        say(f"    - {name}")

    copied_scripts = copy_scripts(scripts_dir, sanctum_scripts)
    if copied_scripts:
        say(f"  Copied {len(copied_scripts)} scripts to sanctum/scripts/")
        for name in copied_scripts:
            say(f"    - {name}")

    for template_name in TEMPLATE_FILES:
        template_path = assets_dir / template_name
        if not template_path.exists():
            say(f"  Warning: template {template_name} not found, skipping")
            continue

        output_name = template_name.replace("-template", "").upper()
        output_name = output_name[:-3] + ".md"

        content = template_path.read_text()
        content = substitute_vars(content, variables)

        output_path = sanctum_path / output_name
        output_path.write_text(content)
        say(f"  Created {output_name}")

    capabilities = discover_capabilities(references_dir, sanctum_refs_path)
    capabilities_content = generate_capabilities_md(capabilities, evolvable=EVOLVABLE)
    (sanctum_path / "CAPABILITIES.md").write_text(capabilities_content)
    say(f"  Created CAPABILITIES.md ({len(capabilities)} built-in capabilities discovered)")

    if quiet:
        print(json.dumps({
            "sanctum": str(sanctum_path),
            "created": True,
            "references_copied": copied_refs,
            "scripts_copied": copied_scripts,
            "capabilities": [cap["code"] for cap in capabilities],
            "user_name": variables["user_name"],
            "agent_name": variables["agent_name"],
        }, indent=2))
    else:
        print()
        print("First Breath scaffolding complete.")
        print("The conversational awakening can now begin.")
        print(f"Sanctum: {sanctum_path}")

    return 0


if __name__ == "__main__":
    sys.exit(main())
