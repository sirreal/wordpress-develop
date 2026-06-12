#!/usr/bin/env python3
"""Verify that a staged scratch directory exposes only docs and task prompts."""

import argparse
import json
import sys
from pathlib import Path


DOC_FILES = {"html-tag-processor.md", "html-processor.md"}
FORBIDDEN_NAMES = {
    "reference.php",
    "tests.json",
    "PLAN.md",
    "PROTOCOL.md",
    "NEXT-HYPOTHESES.md",
    "LOG.md",
    "GOAL.md",
}
FORBIDDEN_SUBSTRINGS = {
    "class-wp-html-tag-processor.php",
    "class-wp-html-processor.php",
}


def files_under(root: Path) -> set[str]:
    return {
        path.relative_to(root).as_posix()
        for path in root.rglob("*")
        if path.is_file()
    }


def expected_files(task_ids: list[str]) -> set[str]:
    return DOC_FILES | {f"tasks/{task_id}.md" for task_id in task_ids}


def metadata_task_ids(metadata_file: Path) -> tuple[Path | None, list[str]]:
    metadata = json.loads(metadata_file.read_text())
    staged = metadata.get("staged_task_files")
    if staged:
        task_ids = [Path(path).stem for path in staged]
    else:
        task_ids = metadata.get("task_ids", [])
    scratch = Path(metadata["scratch"]) if metadata.get("scratch") else None
    return scratch, task_ids


def verify_scratch(root: Path, task_ids: list[str]) -> list[str]:
    errors = []
    if not root.exists():
        return [f"scratch directory does not exist: {root}"]
    if not root.is_dir():
        return [f"scratch path is not a directory: {root}"]

    actual = files_under(root)
    expected = expected_files(task_ids)
    missing = sorted(expected - actual)
    unexpected = sorted(actual - expected)

    if missing:
        errors.append("missing expected files: " + ", ".join(missing))
    if unexpected:
        errors.append("unexpected files: " + ", ".join(unexpected))

    for relpath in sorted(actual):
        name = Path(relpath).name
        if name in FORBIDDEN_NAMES:
            errors.append(f"forbidden file exposed: {relpath}")
        for forbidden in FORBIDDEN_SUBSTRINGS:
            if forbidden in relpath:
                errors.append(f"forbidden source path exposed: {relpath}")

    return errors


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("scratch", nargs="?", help="Scratch directory to inspect")
    parser.add_argument("--task-id", action="append", default=[])
    parser.add_argument(
        "--metadata",
        type=Path,
        help="round-metadata.json to read scratch path and task ids from",
    )
    args = parser.parse_args()

    scratch = Path(args.scratch) if args.scratch else None
    task_ids = list(args.task_id)
    if args.metadata:
        metadata_scratch, metadata_tasks = metadata_task_ids(args.metadata)
        scratch = scratch or metadata_scratch
        if not task_ids:
            task_ids = metadata_tasks

    if scratch is None:
        print("verify-scratch-isolation.py: scratch path required", file=sys.stderr)
        return 2

    errors = verify_scratch(scratch, task_ids)
    if errors:
        for error in errors:
            print(f"ERROR: {error}", file=sys.stderr)
        return 1

    print(
        f"OK: {scratch} exposes 2 docs and {len(task_ids)} task prompt(s), "
        "with no forbidden files."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
