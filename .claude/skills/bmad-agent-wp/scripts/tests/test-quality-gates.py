#!/usr/bin/env python3
# /// script
# requires-python = ">=3.10"
# ///
"""Unit tests for quality-gates.py

Run: python3 scripts/tests/test-quality-gates.py
"""

import importlib.util
import json
import subprocess
import sys
import time
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

class TestVerdict(unittest.TestCase):
    """"Nothing ran" must never be reported as "everything passed"."""

    def run_gates(self, *extra: str):
        return subprocess.run(
            [sys.executable, str(SCRIPTS_DIR / "quality-gates.py"), *extra],
            capture_output=True, text=True,
        )

    def test_no_gate_actually_ran_is_unknown_not_green(self):
        with tempfile.TemporaryDirectory() as tmp:
            result = self.run_gates(tmp, "--gate", "ghost=/definitely/not/here")
            self.assertEqual(result.returncode, 2, "an unknown must not exit 0")
            report = json.loads(result.stdout)
            self.assertFalse(report["all_green"])
            self.assertIn("unknown", report["verdict"])
            self.assertEqual(report["unavailable"], 1)

    def test_a_gate_that_runs_and_passes_is_green(self):
        with tempfile.TemporaryDirectory() as tmp:
            result = self.run_gates(tmp, "--gate", f"noop={sys.executable} -c pass")
            self.assertEqual(result.returncode, 0, result.stderr)
            report = json.loads(result.stdout)
            self.assertTrue(report["all_green"])
            self.assertEqual(report["verdict"], "green")

    def test_gates_run_in_parallel_by_default(self):
        """Three sleeping gates should finish in about one sleep, not three."""
        with tempfile.TemporaryDirectory() as tmp:
            script = Path(tmp) / "sleeper.py"
            script.write_text("import time\ntime.sleep(0.6)\n")
            sleeper = f"{sys.executable} {script}"
            start = time.monotonic()
            result = self.run_gates(tmp, "--gate", f"a={sleeper}",
                                    "--gate", f"b={sleeper}", "--gate", f"c={sleeper}")
            elapsed = time.monotonic() - start
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertLess(elapsed, 1.5, f"gates appear to have run serially ({elapsed:.2f}s)")


if __name__ == "__main__":
    unittest.main()
