#!/usr/bin/env python3
# /// script
# requires-python = ">=3.10"
# ///
"""Unit tests for quality-gates.py

Run: python3 scripts/tests/test-quality-gates.py
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


gates = load("quality-gates.py")


class TestQualityGates(unittest.TestCase):
    def test_summarize_keeps_tail_and_signals(self):
        output = "\n".join(f"line {i}" for i in range(100)) + "\nTests: 42\n3 errors\n"
        summary = gates.summarize(output)
        self.assertEqual(len(summary["tail"]), gates.MAX_TAIL_LINES)
        self.assertTrue(any("Tests: 42" in s for s in summary["signals"]))

    def test_parse_overrides(self):
        parsed = gates.parse_overrides(["tests=composer test"])
        self.assertEqual(parsed["tests"], ["composer", "test"])

    def test_parse_overrides_rejects_bad_input(self):
        with self.assertRaises(ValueError):
            gates.parse_overrides(["nonsense"])

    def test_detect_gates_finds_existing_binaries(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "vendor" / "bin").mkdir(parents=True)
            (root / "vendor" / "bin" / "pest").write_text("#!/bin/sh\n")
            detected = gates.detect_gates(root, None)
            self.assertIn("tests", detected)
            self.assertNotIn("static", detected)

if __name__ == "__main__":
    unittest.main()
