#!/usr/bin/env python3
# /// script
# requires-python = ">=3.10"
# ///
"""
Compare current WordPress and WooCommerce releases against what a plugin declares.

Queries the public WordPress.org APIs for the latest WordPress core release and
the latest WooCommerce release, reads the plugin's own headers (Tested up to,
Requires at least, WC tested up to, WC requires at least), and reports the gap.

The gap is the input to an impact review — it says which release notes are worth
reading, not what breaks. Finding what breaks means reading those notes and
searching the code.

This script makes network requests, which is a deliberate exception to the
usual no-network rule: the current release of WordPress cannot be determined
offline. With --offline (or when the network is unreachable) it reports the
declared headers alone and says the upstream side is unknown.

Exit codes: 0 = up to date (or offline report), 1 = plugin is behind, 2 = error.

Usage:
    uv run ./scripts/upstream-versions.py <plugin-dir> [<plugin-dir> ...]
    uv run ./scripts/upstream-versions.py plugin/my-plugin --offline
"""

import argparse
import json
import re
import sys
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

CORE_API = "https://api.wordpress.org/core/version-check/1.7/"
PLUGIN_API = ("https://api.wordpress.org/plugins/info/1.2/"
              "?action=plugin_information&request[slug]={slug}")

HEADER_LABELS = {
    "requires_at_least": "Requires at least",
    "tested_up_to": "Tested up to",
    "requires_php": "Requires PHP",
    "wc_requires_at_least": "WC requires at least",
    "wc_tested_up_to": "WC tested up to",
}


def parse_headers(text: str) -> dict:
    """Pull WordPress-style `Field: value` header lines out of a file."""
    found = {}
    for key, label in HEADER_LABELS.items():
        match = re.search(rf"^\s*(?:\*\s*)?{re.escape(label)}:\s*(.+?)\s*$",
                          text, re.MULTILINE | re.IGNORECASE)
        if match:
            found[key] = match.group(1).strip()
    return found


def version_tuple(value: str | None) -> tuple[int, ...]:
    """Turn '8.2.1' into (8, 2, 1) for comparison. Unparseable parts are dropped."""
    if not value:
        return ()
    parts = []
    for chunk in re.split(r"[.\-+]", value.strip()):
        if chunk.isdigit():
            parts.append(int(chunk))
        else:
            break
    return tuple(parts)


def is_behind(declared: str | None, current: str | None) -> bool:
    """True when the declared version is older than the current release."""
    left, right = version_tuple(declared), version_tuple(current)
    if not left or not right:
        return False
    return left < right


def gap_severity(declared: str | None, current: str | None) -> str:
    """Classify a gap by the first version component that differs."""
    left, right = version_tuple(declared), version_tuple(current)
    if not left or not right or left >= right:
        return "none"
    for index, label in enumerate(("major", "minor", "patch")):
        if left[index:index + 1] != right[index:index + 1]:
            return label
    return "patch"


def fetch_json(url: str, timeout: int) -> dict:
    request = urllib.request.Request(url, headers={"User-Agent": "henk-upstream-watch"})
    with urllib.request.urlopen(request, timeout=timeout) as response:
        return json.loads(response.read().decode("utf-8"))


def latest_wordpress(timeout: int) -> str | None:
    data = fetch_json(CORE_API, timeout)
    offers = data.get("offers") or []
    for offer in offers:
        if offer.get("response") in ("upgrade", "latest") and offer.get("current"):
            return offer["current"]
    return offers[0].get("current") if offers else None


def latest_plugin(slug: str, timeout: int) -> str | None:
    data = fetch_json(PLUGIN_API.format(slug=slug), timeout)
    version = data.get("version")
    return version if isinstance(version, str) else None


def collect_declared(plugin_dir: Path) -> dict:
    """Read declared headers from the plugin bootstrap, falling back to readme.txt."""
    declared: dict = {}
    for php_file in sorted(plugin_dir.glob("*.php")):
        text = php_file.read_text(errors="replace")
        if re.search(r"^\s*(?:\*\s*)?Plugin Name:\s*\S", text, re.MULTILINE | re.IGNORECASE):
            declared.update(parse_headers(text))
            declared["source"] = php_file.name
            break
    readme = plugin_dir / "readme.txt"
    if readme.exists():
        for key, value in parse_headers(readme.read_text(errors="replace")).items():
            declared.setdefault(key, value)
    return declared


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Compare current WordPress and WooCommerce releases against a "
                    "plugin's declared support headers."
    )
    parser.add_argument("plugin_dir", nargs="*", default=["."],
                        help="One or more directories containing plugin bootstraps "
                             "(default: current directory). Pass several to check them "
                             "all against a single pair of upstream lookups.")
    parser.add_argument("--offline", action="store_true",
                        help="Skip network calls and report declared headers only")
    parser.add_argument("--timeout", type=int, default=15,
                        help="Network timeout in seconds (default: 15)")
    parser.add_argument("-o", "--output", help="Write JSON here instead of stdout")
    parser.add_argument("--verbose", action="store_true", help="Progress to stderr")
    args = parser.parse_args()

    plugin_dirs = [Path(p).resolve() for p in args.plugin_dir]
    for path in plugin_dirs:
        if not path.is_dir():
            print(f"error: not a directory: {path}", file=sys.stderr)
            return 2

    plugins = []
    for path in plugin_dirs:
        declared = collect_declared(path)
        if not declared:
            print(f"error: no plugin headers found in {path}", file=sys.stderr)
            return 2
        plugins.append((path, declared))

    upstream: dict = {"wordpress": None, "woocommerce": None}
    notes: list[str] = []

    if args.offline:
        notes.append("offline mode — upstream versions not checked")
    else:
        # Two unrelated hosts with independent timeouts: fetch them at the same
        # time so the wall clock is one timeout, not two. Every plugin passed in
        # is measured against this single pair of lookups.
        sources = {
            "wordpress": lambda: latest_wordpress(args.timeout),
            "woocommerce": lambda: latest_plugin("woocommerce", args.timeout),
        }

        def fetch_one(item):
            name, fetch = item
            if args.verbose:
                print(f"fetching latest {name}", file=sys.stderr)
            try:
                return name, fetch(), None
            except (urllib.error.URLError, TimeoutError,
                    json.JSONDecodeError, OSError) as exc:
                return name, None, f"could not reach the {name} API: {exc}"

        with ThreadPoolExecutor(max_workers=len(sources)) as pool:
            for name, value, note in pool.map(fetch_one, sources.items()):
                upstream[name] = value
                if note:
                    notes.append(note)

    checked = []
    total_gaps = 0
    for path, declared in plugins:
        gaps = []
        for label, declared_key, upstream_key in (
            ("WordPress", "tested_up_to", "wordpress"),
            ("WooCommerce", "wc_tested_up_to", "woocommerce"),
        ):
            declared_value = declared.get(declared_key)
            current = upstream.get(upstream_key)
            if is_behind(declared_value, current):
                severity = gap_severity(declared_value, current)
                gaps.append({
                    "platform": label,
                    "tested_up_to": declared_value,
                    "current_release": current,
                    "severity": severity,
                    "action": f"read the {label} release notes between {declared_value} and "
                              f"{current}, then search the plugin for what changed"
                              if severity in ("major", "minor")
                              else f"patch-level only — bump 'Tested up to' to {current} unless "
                                   f"the release notes say otherwise",
                })
        total_gaps += len(gaps)
        checked.append({
            "plugin_dir": str(path),
            "declared": declared,
            "gaps": gaps,
            "up_to_date": not gaps,
        })

    first = checked[0]
    report = {
        # Single-plugin keys kept at the top level for callers that expect them.
        "plugin_dir": first["plugin_dir"],
        "declared": first["declared"],
        "gaps": first["gaps"],
        "upstream": upstream,
        "plugins": checked,
        "up_to_date": total_gaps == 0,
        "notes": notes,
    }

    payload = json.dumps(report, indent=2)
    if args.output:
        Path(args.output).write_text(payload)
        if args.verbose:
            print(f"wrote {args.output}", file=sys.stderr)
    else:
        print(payload)

    return 1 if total_gaps else 0


if __name__ == "__main__":
    sys.exit(main())
