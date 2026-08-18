#!/usr/bin/env python3
# /// script
# requires-python = ">=3.10"
# ///
"""
Run every test file in this directory.

The test files are named after the hyphenated scripts they cover, which makes
them invalid module names for `unittest discover`. This runner loads each one
explicitly instead.

Usage:
    uv run ./scripts/tests/run-tests.py
"""

import argparse
import importlib.util
import sys
import unittest
from pathlib import Path

TESTS_DIR = Path(__file__).resolve().parent


def load_test_module(path: Path):
    spec = importlib.util.spec_from_file_location(path.stem.replace("-", "_"), path)
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


def main() -> int:
    parser = argparse.ArgumentParser(description="Run all unit tests for this skill's scripts.")
    parser.add_argument("--verbose", action="store_true", help="Verbose test output")
    args = parser.parse_args()

    suite = unittest.TestSuite()
    loader = unittest.TestLoader()
    for path in sorted(TESTS_DIR.glob("test-*.py")):
        suite.addTests(loader.loadTestsFromModule(load_test_module(path)))

    result = unittest.TextTestRunner(verbosity=2 if args.verbose else 1).run(suite)
    return 0 if result.wasSuccessful() else 1


if __name__ == "__main__":
    sys.exit(main())
