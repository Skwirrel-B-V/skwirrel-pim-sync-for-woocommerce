#!/usr/bin/env python3
# /// script
# requires-python = ">=3.10"
# ///
"""Unit tests for release-consistency.py

Run: python3 scripts/tests/test-release-consistency.py
"""

import importlib.util
import json
import tempfile
import unittest
from pathlib import Path

SCRIPTS_DIR = Path(__file__).resolve().parent.parent


def load(module_file: str):
    """Load a hyphenated script file as a module."""
    path = SCRIPTS_DIR / module_file
    spec = importlib.util.spec_from_file_location(path.stem.replace("-", "_"), path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


release = load("release-consistency.py")


BOOTSTRAP = """<?php
/**
 * Plugin Name: Example Plugin
 * Version: 3.12.2
 * Requires at least: 6.5
 * Requires PHP: 8.3
 * WC requires at least: 8.0
 * WC tested up to: 10.6
 * Tested up to: 6.9
 */
define( 'EXAMPLE_PLUGIN_VERSION', '3.12.2' );
"""

README = """=== Example Plugin ===
Requires at least: 6.5
Tested up to: 6.9
Stable tag: 3.12.2

== Changelog ==

= 3.12.2 =
* Fixed a thing.

= 3.12.1 =
* Fixed another thing.
"""


class TestReleaseConsistency(unittest.TestCase):
    def test_detects_bootstrap_by_plugin_name_header(self):
        self.assertTrue(release.is_plugin_bootstrap(BOOTSTRAP))
        self.assertFalse(release.is_plugin_bootstrap("<?php // just a class"))

    def test_parses_header_fields(self):
        header = release.parse_header_fields(BOOTSTRAP)
        self.assertEqual(header["version"], "3.12.2")
        self.assertEqual(header["requires_php"], "8.3")
        self.assertEqual(header["wc_tested_up_to"], "10.6")

    def test_parses_version_constants(self):
        constants = release.parse_version_constants(BOOTSTRAP)
        self.assertEqual(constants["EXAMPLE_PLUGIN_VERSION"], "3.12.2")

    def test_parses_const_style_declaration(self):
        constants = release.parse_version_constants("const PLUGIN_VERSION = '1.2.3';")
        self.assertEqual(constants["PLUGIN_VERSION"], "1.2.3")

    def test_parses_readme(self):
        readme = release.parse_readme(README)
        self.assertEqual(readme["stable_tag"], "3.12.2")
        self.assertIn("3.12.1", readme["changelog_versions"])

    def test_parses_changelog_md(self):
        versions = release.parse_changelog_md("## [1.2.0] - 2026-01-01\n\n### 1.1.0\n")
        self.assertEqual(versions, ["1.2.0", "1.1.0"])

    def test_parses_package_json(self):
        self.assertEqual(release.parse_package_json('{"version": "3.12.2"}'), "3.12.2")
        self.assertIsNone(release.parse_package_json("not json"))

    def test_consistent_plugin_reports_no_problems(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "example.php").write_text(BOOTSTRAP)
            (root / "readme.txt").write_text(README)
            (root / "package.json").write_text(json.dumps({"version": "3.12.2"}))
            report = release.build_report(root, root, None)
            self.assertTrue(report["consistent"], report["problems"])
            self.assertEqual(report["target_version"], "3.12.2")

    def test_mismatched_stable_tag_is_reported(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "example.php").write_text(BOOTSTRAP)
            (root / "readme.txt").write_text(README.replace("Stable tag: 3.12.2",
                                                            "Stable tag: 3.12.1"))
            report = release.build_report(root, root, None)
            self.assertFalse(report["consistent"])
            self.assertTrue(any("readme_stable_tag" in p for p in report["problems"]))

    def test_missing_changelog_entry_is_reported(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "example.php").write_text(BOOTSTRAP)
            (root / "readme.txt").write_text(README)
            report = release.build_report(root, root, "3.13.0")
            self.assertFalse(report["consistent"])
            self.assertTrue(any("3.13.0" in p for p in report["problems"]))

    def test_missing_bootstrap_is_an_error(self):
        with tempfile.TemporaryDirectory() as tmp:
            report = release.build_report(Path(tmp), Path(tmp), None)
            self.assertIn("error", report)

if __name__ == "__main__":
    unittest.main()
