#!/usr/bin/env python3
# /// script
# requires-python = ">=3.10"
# ///
"""
Run a PHP project's quality gates and return a parsed pass/fail summary.

Detects the usual WordPress-plugin toolchain (Pest/PHPUnit, PHPStan, PHP_CodeSniffer)
in `vendor/bin/`, runs each gate, and reports a compact JSON summary instead of
dumping thousands of lines of raw tool output into a conversation.

Exit codes: 0 = all gates passed, 1 = at least one gate failed, 2 = error.

Usage:
    uv run ./scripts/quality-gates.py <project-root>
    uv run ./scripts/quality-gates.py . --only tests,static
    uv run ./scripts/quality-gates.py . --gate "tests=composer test" -o gates.json
"""

import argparse
import json
import re
import shutil
import subprocess
import sys
from pathlib import Path

# Gate name -> (relative binary path, arguments)
DEFAULT_GATES = {
    "tests": [("vendor/bin/pest", []), ("vendor/bin/phpunit", [])],
    "static": [("vendor/bin/phpstan", ["analyse", "--no-progress"])],
    "style": [("vendor/bin/phpcs", ["--report=summary"])],
}

# Patterns that reveal a count without needing the whole output
COUNT_PATTERNS = [
    re.compile(r"\b(?:Tests|Assertions):\s*(\d+)", re.IGNORECASE),
    re.compile(r"\b(\d+)\s+errors?\b", re.IGNORECASE),
    re.compile(r"\b(\d+)\s+failures?\b", re.IGNORECASE),
    re.compile(r"\bFAILED\b"),
]

MAX_TAIL_LINES = 40


def detect_gates(project_root: Path, only: set[str] | None) -> dict[str, list[str]]:
    """Resolve which gates can actually run in this project."""
    resolved = {}
    for name, candidates in DEFAULT_GATES.items():
        if only and name not in only:
            continue
        for rel_path, args in candidates:
            binary = project_root / rel_path
            if binary.exists():
                resolved[name] = [str(binary), *args]
                break
    return resolved


def parse_overrides(overrides: list[str]) -> dict[str, list[str]]:
    """Parse --gate NAME=COMMAND into a command list."""
    parsed = {}
    for item in overrides:
        if "=" not in item:
            raise ValueError(f"--gate expects NAME=COMMAND, got: {item}")
        name, _, command = item.partition("=")
        parsed[name.strip()] = command.strip().split()
    return parsed


def summarize(output: str) -> dict:
    """Extract the useful signal from a tool's raw output."""
    lines = [line.rstrip() for line in output.splitlines() if line.strip()]
    signals = []
    for pattern in COUNT_PATTERNS:
        for match in pattern.finditer(output):
            signals.append(match.group(0).strip())
    return {
        "line_count": len(lines),
        "signals": sorted(set(signals))[:10],
        "tail": lines[-MAX_TAIL_LINES:],
    }


def run_gate(name: str, command: list[str], cwd: Path, timeout: int) -> dict:
    """Run one gate and reduce its output to a summary."""
    if not shutil.which(command[0]) and not Path(command[0]).exists():
        return {"gate": name, "status": "unavailable", "command": " ".join(command),
                "reason": f"not found: {command[0]}"}
    try:
        completed = subprocess.run(
            command, cwd=cwd, capture_output=True, text=True, timeout=timeout
        )
    except subprocess.TimeoutExpired:
        return {"gate": name, "status": "error", "command": " ".join(command),
                "reason": f"timed out after {timeout}s"}
    except OSError as exc:
        return {"gate": name, "status": "error", "command": " ".join(command),
                "reason": str(exc)}

    output = (completed.stdout or "") + (completed.stderr or "")
    return {
        "gate": name,
        "status": "pass" if completed.returncode == 0 else "fail",
        "command": " ".join(command),
        "exit_code": completed.returncode,
        **summarize(output),
    }


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Run PHP quality gates (tests, static analysis, code style) "
                    "and report a parsed summary as JSON."
    )
    parser.add_argument("project_root", nargs="?", default=".",
                        help="Project root containing vendor/bin (default: current directory)")
    parser.add_argument("-o", "--output", help="Write JSON here instead of stdout")
    parser.add_argument("--only", help="Comma-separated gates to run (tests,static,style)")
    parser.add_argument("--gate", action="append", default=[], metavar="NAME=COMMAND",
                        help="Override or add a gate, e.g. --gate 'tests=composer test'")
    parser.add_argument("--timeout", type=int, default=900,
                        help="Per-gate timeout in seconds (default: 900)")
    parser.add_argument("--verbose", action="store_true", help="Progress to stderr")
    args = parser.parse_args()

    project_root = Path(args.project_root).resolve()
    if not project_root.is_dir():
        print(f"error: not a directory: {project_root}", file=sys.stderr)
        return 2

    only = {part.strip() for part in args.only.split(",")} if args.only else None

    try:
        overrides = parse_overrides(args.gate)
    except ValueError as exc:
        print(f"error: {exc}", file=sys.stderr)
        return 2

    gates = detect_gates(project_root, only)
    gates.update(overrides)

    if not gates:
        print("error: no gates detected or specified; use --gate NAME=COMMAND",
              file=sys.stderr)
        return 2

    results = []
    for name, command in gates.items():
        if args.verbose:
            print(f"running {name}: {' '.join(command)}", file=sys.stderr)
        results.append(run_gate(name, command, project_root, args.timeout))

    failed = [r for r in results if r["status"] == "fail"]
    errored = [r for r in results if r["status"] == "error"]

    report = {
        "project_root": str(project_root),
        "gates_run": len(results),
        "passed": len([r for r in results if r["status"] == "pass"]),
        "failed": len(failed),
        "errored": len(errored),
        "all_green": not failed and not errored,
        "results": results,
    }

    payload = json.dumps(report, indent=2)
    if args.output:
        Path(args.output).write_text(payload)
        if args.verbose:
            print(f"wrote {args.output}", file=sys.stderr)
    else:
        print(payload)

    if errored:
        return 2
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
