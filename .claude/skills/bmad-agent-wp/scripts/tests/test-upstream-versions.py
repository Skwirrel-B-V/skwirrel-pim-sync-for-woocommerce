#!/usr/bin/env python3
# /// script
# requires-python = ">=3.10"
# ///
"""Unit tests for upstream-versions.py

Run: python3 scripts/tests/test-upstream-versions.py
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


upstream = load("upstream-versions.py")


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


class TestUpstreamVersions(unittest.TestCase):
    def test_version_tuple_stops_at_non_numeric(self):
        self.assertEqual(upstream.version_tuple("6.9.1"), (6, 9, 1))
        self.assertEqual(upstream.version_tuple("10.6-beta.1"), (10, 6))
        self.assertEqual(upstream.version_tuple(None), ())

    def test_is_behind(self):
        self.assertTrue(upstream.is_behind("6.5", "6.9"))
        self.assertFalse(upstream.is_behind("6.9", "6.9"))
        self.assertFalse(upstream.is_behind("7.0", "6.9"))

    def test_is_behind_is_false_when_either_side_unknown(self):
        self.assertFalse(upstream.is_behind(None, "6.9"))
        self.assertFalse(upstream.is_behind("6.5", None))

    def test_gap_severity(self):
        self.assertEqual(upstream.gap_severity("6.9", "7.0"), "major")
        self.assertEqual(upstream.gap_severity("10.6", "10.9"), "minor")
        self.assertEqual(upstream.gap_severity("7.0", "7.0.4"), "patch")
        self.assertEqual(upstream.gap_severity("7.1", "7.0"), "none")

    def test_collect_declared_prefers_bootstrap_then_readme(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "example.php").write_text(BOOTSTRAP)
            (root / "readme.txt").write_text(README)
            declared = upstream.collect_declared(root)
            self.assertEqual(declared["tested_up_to"], "6.9")
            self.assertEqual(declared["wc_tested_up_to"], "10.6")
            self.assertEqual(declared["source"], "example.php")

if __name__ == "__main__":
    unittest.main()
