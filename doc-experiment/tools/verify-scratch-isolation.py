#!/usr/bin/env python3
"""Verify that a staged scratch directory exposes only docs and task prompts."""

import argparse
import hashlib
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


def file_hashes(root: Path, relpaths: set[str]) -> dict[str, str]:
    hashes = {}
    for relpath in sorted(relpaths):
        path = root / relpath
        if path.exists() and path.is_file():
            hashes[relpath] = hashlib.sha256(path.read_bytes()).hexdigest()
    return hashes


def metadata_task_ids(metadata_file: Path) -> tuple[Path | None, list[str], dict[str, str]]:
    metadata = json.loads(metadata_file.read_text())
    staged = metadata.get("staged_task_files")
    if staged:
        task_ids = [Path(path).stem for path in staged]
    else:
        task_ids = metadata.get("task_ids", [])
    scratch = Path(metadata["scratch"]) if metadata.get("scratch") else None
    return scratch, task_ids, metadata.get("scratch_file_sha256", {})


def verify_scratch(
    root: Path,
    task_ids: list[str],
    expected_hashes: dict[str, str] | None = None,
) -> tuple[list[str], dict[str, str]]:
    errors = []
    if not root.exists():
        return [f"scratch directory does not exist: {root}"], {}
    if not root.is_dir():
        return [f"scratch path is not a directory: {root}"], {}

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

    hashes = file_hashes(root, expected)
    if expected_hashes:
        for relpath, expected_hash in sorted(expected_hashes.items()):
            actual_hash = hashes.get(relpath)
            if actual_hash != expected_hash:
                errors.append(
                    f"hash mismatch for {relpath}: expected {expected_hash}, got {actual_hash}"
                )

    return errors, hashes


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("scratch", nargs="?", help="Scratch directory to inspect")
    parser.add_argument("--task-id", action="append", default=[])
    parser.add_argument(
        "--metadata",
        type=Path,
        help="round-metadata.json to read scratch path and task ids from",
    )
    parser.add_argument(
        "--json",
        action="store_true",
        help="Print JSON with validation status and SHA-256 hashes",
    )
    args = parser.parse_args()

    scratch = Path(args.scratch) if args.scratch else None
    task_ids = list(args.task_id)
    expected_hashes = {}
    if args.metadata:
        metadata_scratch, metadata_tasks, metadata_hashes = metadata_task_ids(args.metadata)
        scratch = scratch or metadata_scratch
        if not task_ids:
            task_ids = metadata_tasks
        expected_hashes = metadata_hashes

    if scratch is None:
        print("verify-scratch-isolation.py: scratch path required", file=sys.stderr)
        return 2

    errors, hashes = verify_scratch(scratch, task_ids, expected_hashes)
    if args.json:
        print(
            json.dumps(
                {
                    "ok": not errors,
                    "scratch": str(scratch),
                    "task_count": len(task_ids),
                    "errors": errors,
                    "scratch_file_sha256": hashes,
                },
                indent=2,
            )
        )
        return 0 if not errors else 1

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
