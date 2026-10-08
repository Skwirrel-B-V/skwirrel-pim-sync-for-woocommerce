#!/usr/bin/env python3
# /// script
# requires-python = ">=3.10"
# ///
"""Unit tests for init-sanctum.py

Run: python3 scripts/tests/test-init-sanctum.py
"""

import importlib.util
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

SCRIPTS_DIR = Path(__file__).resolve().parent.parent
SKILL_DIR = SCRIPTS_DIR.parent


def load(module_file: str):
    """Load a hyphenated script file as a module."""
    path = SCRIPTS_DIR / module_file
    spec = importlib.util.spec_from_file_location(path.stem.replace("-", "_"), path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


init = load("init-sanctum.py")


class TestParsers(unittest.TestCase):
    def test_parse_frontmatter(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "cap.md"
            path.write_text("---\nname: audit\ndescription: Find defects\ncode: AU\n---\n\n# Audit\n")
            meta = init.parse_frontmatter(path)
            self.assertEqual(meta["name"], "audit")
            self.assertEqual(meta["code"], "AU")

    def test_substitute_vars(self):
        result = init.substitute_vars("Hello {user_name}", {"user_name": "Jos"})
        self.assertEqual(result, "Hello Jos")


class TestAgentMetadata(unittest.TestCase):
    def test_agent_metadata_comes_from_customize_toml(self):
        meta = init.load_agent_metadata(SKILL_DIR)
        self.assertEqual(meta["name"], "Henk")
        self.assertEqual(meta["title"], "WordPress Plugin Engineer")
        self.assertEqual(meta["agent_type"], "autonomous")


class TestCapabilityDiscovery(unittest.TestCase):
    def test_discovers_capabilities_from_real_references(self):
        caps = init.discover_capabilities(SKILL_DIR / "references", "references")
        codes = {cap["code"] for cap in caps}
        self.assertEqual(codes, {"AU", "BD", "DG", "AP", "SR", "UW"})

    def test_skips_first_breath_and_files_without_a_code(self):
        caps = init.discover_capabilities(SKILL_DIR / "references", "references")
        names = {cap["name"] for cap in caps}
        self.assertNotIn("first-breath", names)
        self.assertNotIn("memory-guidance", names)
        self.assertNotIn("capability-authoring", names)

    def test_generated_capabilities_md_lists_learned_section_when_evolvable(self):
        caps = [{"code": "AU", "name": "audit", "description": "d", "source": "references/audit.md"}]
        self.assertIn("## Learned", init.generate_capabilities_md(caps, evolvable=True))
        self.assertNotIn("## Learned", init.generate_capabilities_md(caps, evolvable=False))


class TestScaffolding(unittest.TestCase):
    def run_init(self, project_root: Path, *extra: str):
        return subprocess.run(
            [sys.executable, str(SCRIPTS_DIR / "init-sanctum.py"),
             str(project_root), str(SKILL_DIR), *extra],
            capture_output=True, text=True,
        )

    def test_json_mode_emits_a_structured_summary(self):
        with tempfile.TemporaryDirectory() as tmp:
            project_root = Path(tmp)

            first = self.run_init(project_root, "--json")
            self.assertEqual(first.returncode, 0, first.stderr)
            report = json.loads(first.stdout)
            self.assertTrue(report["created"])
            self.assertCountEqual(report["capabilities"], ["AU", "BD", "DG", "AP", "SR", "UW"])
            self.assertIn("quality-gates.py", report["scripts_copied"])

            second = json.loads(self.run_init(project_root, "--json").stdout)
            self.assertFalse(second["created"])

    def test_creates_a_complete_sanctum(self):
        with tempfile.TemporaryDirectory() as tmp:
            project_root = Path(tmp)
            result = self.run_init(project_root, "--user-name", "Jos")
            self.assertEqual(result.returncode, 0, result.stderr)

            sanctum = project_root / "docs" / "henk"
            for name in ("INDEX.md", "PERSONA.md", "CREED.md", "BOND.md",
                         "MEMORY.md", "PULSE.md", "CAPABILITIES.md"):
                self.assertTrue((sanctum / name).exists(), f"missing {name}")
            self.assertTrue((sanctum / "sessions").is_dir())
            self.assertTrue((sanctum / "capabilities").is_dir())
            self.assertTrue((sanctum / "pulse").is_dir())
            self.assertTrue((sanctum / "reports").is_dir())

            # Config values are substituted, not left as placeholders
            bond = (sanctum / "BOND.md").read_text()
            self.assertIn("Jos", bond)
            self.assertNotIn("{user_name}", bond)

            # Identity triplet is projected from customize.toml, not hardcoded
            persona = (sanctum / "PERSONA.md").read_text()
            self.assertIn("**Name:** Henk", persona)
            self.assertIn("WordPress Plugin Engineer", persona)
            self.assertNotIn("{agent_name}", persona)

            # First Breath stays in the skill bundle
            self.assertFalse((sanctum / "references" / "first-breath.md").exists())
            self.assertTrue((sanctum / "references" / "audit.md").exists())

            # Working scripts travel with the sanctum; the init script does not
            self.assertTrue((sanctum / "scripts" / "quality-gates.py").exists())
            self.assertFalse((sanctum / "scripts" / "init-sanctum.py").exists())

            # Capabilities are generated from frontmatter
            capabilities = (sanctum / "CAPABILITIES.md").read_text()
            self.assertIn("[UW]", capabilities)
            self.assertIn("## Learned", capabilities)
            self.assertIn("Project Skills I Defer To", capabilities)

    def test_is_idempotent_and_refuses_to_overwrite_a_sanctum(self):
        with tempfile.TemporaryDirectory() as tmp:
            project_root = Path(tmp)
            self.assertEqual(self.run_init(project_root).returncode, 0)

            sanctum = project_root / "docs" / "henk"
            (sanctum / "MEMORY.md").write_text("hard-won knowledge")

            second = self.run_init(project_root)
            self.assertEqual(second.returncode, 0)
            self.assertIn("already exists", second.stdout)
            self.assertEqual((sanctum / "MEMORY.md").read_text(), "hard-won knowledge")


if __name__ == "__main__":
    unittest.main()
