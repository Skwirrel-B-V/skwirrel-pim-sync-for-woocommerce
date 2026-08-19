#!/usr/bin/env python3
# /// script
# requires-python = ">=3.10"
# ///
"""
Check that a WordPress plugin's version is consistent everywhere it appears.

Compares the plugin header `Version:`, the PHP version constant, readme.txt
`Stable tag:`, the readme.txt changelog entry, CHANGELOG.md, and package.json.
A mismatch in any of these is the classic cause of a deploy workflow rejecting
a tag after it has already been pushed.

Also reports the declared support floors (Requires at least, Requires PHP,
WC requires at least, Tested up to) so they can be sanity-checked.

Exit codes: 0 = consistent, 1 = inconsistency found, 2 = error.

Usage:
    uv run ./scripts/release-consistency.py <plugin-dir>
    uv run ./scripts/release-consistency.py plugin/my-plugin --repo-root . --expect 3.13.0
"""

import argparse
import json
import re
import sys
from pathlib import Path

VERSION_RE = r"\d+\.\d+(?:\.\d+)*(?:-[0-9A-Za-z.-]+)?"

HEADER_FIELDS = {
    "version": "Version",
    "requires_at_least": "Requires at least",
    "requires_php": "Requires PHP",
    "tested_up_to": "Tested up to",
    "wc_requires_at_least": "WC requires at least",
    "wc_tested_up_to": "WC tested up to",
}


def parse_header_fields(text: str) -> dict:
    """Pull WordPress-style `Field: value` header lines out of a file's top block."""
    found = {}
    for key, label in HEADER_FIELDS.items():
        match = re.search(rf"^\s*(?:\*\s*)?{re.escape(label)}:\s*(.+?)\s*$",
                          text, re.MULTILINE | re.IGNORECASE)
        if match:
            found[key] = match.group(1).strip()
    return found


def is_plugin_bootstrap(text: str) -> bool:
    """A plugin's main file is the one carrying the `Plugin Name:` header."""
    return re.search(r"^\s*(?:\*\s*)?Plugin Name:\s*\S", text, re.MULTILINE | re.IGNORECASE) is not None


def parse_version_constants(text: str) -> dict:
    """Find version constants defined via define() or const."""
    constants = {}
    for match in re.finditer(
        rf"define\(\s*['\"]([A-Z0-9_]*VERSION)['\"]\s*,\s*['\"]({VERSION_RE})['\"]", text
    ):
        constants[match.group(1)] = match.group(2)
    for match in re.finditer(
        rf"\bconst\s+([A-Z0-9_]*VERSION)\s*=\s*['\"]({VERSION_RE})['\"]", text
    ):
        constants[match.group(1)] = match.group(2)
    return constants


def parse_readme(text: str) -> dict:
    """Extract Stable tag, floors, and changelog versions from a readme.txt."""
    result = parse_header_fields(text)
    stable = re.search(rf"^\s*Stable tag:\s*({VERSION_RE})\s*$", text,
                       re.MULTILINE | re.IGNORECASE)
    result["stable_tag"] = stable.group(1) if stable else None
    result["changelog_versions"] = re.findall(rf"^\s*=\s*({VERSION_RE})\s*=\s*$",
                                              text, re.MULTILINE)
    return result


def parse_changelog_md(text: str) -> list[str]:
    """Extract released versions from a Keep-a-Changelog style CHANGELOG.md."""
    return re.findall(rf"^#{{1,4}}\s*\[?({VERSION_RE})\]?", text, re.MULTILINE)


def parse_package_json(text: str) -> str | None:
    """Read the version field out of a package.json."""
    try:
        data = json.loads(text)
    except json.JSONDecodeError:
        return None
    version = data.get("version")
    return version if isinstance(version, str) else None


def find_bootstrap(plugin_dir: Path) -> Path | None:
    """Locate the plugin's main PHP file."""
    for php_file in sorted(plugin_dir.glob("*.php")):
        try:
            if is_plugin_bootstrap(php_file.read_text(errors="replace")):
                return php_file
        except OSError:
            continue
    return None


def build_report(plugin_dir: Path, repo_root: Path, expected: str | None) -> dict:
    sources: dict[str, dict] = {}
    problems: list[str] = []
    floors: dict = {}

    bootstrap = find_bootstrap(plugin_dir)
    if bootstrap is None:
        return {"error": f"no plugin bootstrap (file with a 'Plugin Name:' header) in {plugin_dir}"}

    bootstrap_text = bootstrap.read_text(errors="replace")
    header = parse_header_fields(bootstrap_text)
    floors = {k: v for k, v in header.items() if k != "version"}

    header_version = header.get("version")
    sources["plugin_header"] = {"file": str(bootstrap.relative_to(repo_root)
                                            if repo_root in bootstrap.parents else bootstrap),
                                "version": header_version}
    if not header_version:
        problems.append("plugin header has no Version: field")

    constants = parse_version_constants(bootstrap_text)
    for name, value in constants.items():
        sources[f"constant:{name}"] = {"file": sources["plugin_header"]["file"], "version": value}
    if not constants:
        problems.append("no version constant found in the plugin bootstrap")

    readme_path = plugin_dir / "readme.txt"
    changelog_versions: list[str] = []
    if readme_path.exists():
        readme = parse_readme(readme_path.read_text(errors="replace"))
        sources["readme_stable_tag"] = {"file": "readme.txt", "version": readme.get("stable_tag")}
        changelog_versions = readme.get("changelog_versions", [])
        for key in ("requires_at_least", "requires_php", "tested_up_to"):
            if readme.get(key):
                floors.setdefault(f"readme_{key}", readme[key])
        if not readme.get("stable_tag"):
            problems.append("readme.txt has no Stable tag")
    else:
        problems.append("readme.txt not found — required for WordPress.org releases")

    package_path = repo_root / "package.json"
    if package_path.exists():
        package_version = parse_package_json(package_path.read_text(errors="replace"))
        if package_version:
            sources["package_json"] = {"file": "package.json", "version": package_version}

    target = expected or header_version
    mismatches = [
        {"source": name, "file": data["file"], "version": data["version"]}
        for name, data in sources.items()
        if data["version"] != target
    ]
    for item in mismatches:
        problems.append(
            f"{item['source']} is {item['version'] or 'missing'}, expected {target}"
        )

    if target and changelog_versions and target not in changelog_versions:
        problems.append(f"readme.txt changelog has no '= {target} =' entry")

    md_path = repo_root / "CHANGELOG.md"
    if md_path.exists():
        md_versions = parse_changelog_md(md_path.read_text(errors="replace"))
        if target and md_versions and target not in md_versions:
            problems.append(f"CHANGELOG.md has no entry for {target}")

    return {
        "plugin_dir": str(plugin_dir),
        "repo_root": str(repo_root),
        "target_version": target,
        "sources": sources,
        "declared_floors": floors,
        "consistent": not problems,
        "problems": problems,
    }


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Check a WordPress plugin's version consistency across every "
                    "file that declares it, and report the declared support floors."
    )
    parser.add_argument("plugin_dir", nargs="?", default=".",
                        help="Directory containing the plugin bootstrap (default: current directory)")
    parser.add_argument("--repo-root", default=None,
                        help="Repository root holding CHANGELOG.md / package.json "
                             "(default: the plugin directory)")
    parser.add_argument("--expect", default=None,
                        help="Version everything must match (default: the plugin header version)")
    parser.add_argument("-o", "--output", help="Write JSON here instead of stdout")
    parser.add_argument("--verbose", action="store_true", help="Progress to stderr")
    args = parser.parse_args()

    plugin_dir = Path(args.plugin_dir).resolve()
    if not plugin_dir.is_dir():
        print(f"error: not a directory: {plugin_dir}", file=sys.stderr)
        return 2
    repo_root = Path(args.repo_root).resolve() if args.repo_root else plugin_dir

    if args.verbose:
        print(f"checking {plugin_dir} against repo root {repo_root}", file=sys.stderr)

    report = build_report(plugin_dir, repo_root, args.expect)
    payload = json.dumps(report, indent=2)

    if args.output:
        Path(args.output).write_text(payload)
        if args.verbose:
            print(f"wrote {args.output}", file=sys.stderr)
    else:
        print(payload)

    if "error" in report:
        print(f"error: {report['error']}", file=sys.stderr)
        return 2
    return 0 if report["consistent"] else 1


if __name__ == "__main__":
    sys.exit(main())
