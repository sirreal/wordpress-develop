#!/usr/bin/env python3
"""Validate active corpus task fixtures and reference implementations."""

import argparse
import json
import subprocess
import sys
from pathlib import Path


EXPERIMENT_ROOT = Path(__file__).resolve().parent.parent


def load_task(task_dir: Path) -> dict:
    tests_file = task_dir / "tests.json"
    meta = json.loads(tests_file.read_text())
    task_id = meta.get("id") or task_dir.name
    return {
        "id": task_id,
        "dir_name": task_dir.name,
        "dir": task_dir,
        "tests": tests_file,
        "task_md": task_dir / "task.md",
        "reference": task_dir / "reference.php",
        "split": meta.get("split") or "unknown",
        "concept": meta.get("concept") or "unknown",
        "case_count": len(meta.get("cases", [])),
    }


def active_tasks() -> dict[str, dict]:
    tasks = {}
    for tests_file in sorted((EXPERIMENT_ROOT / "corpus").glob("*/tests.json")):
        task = load_task(tests_file.parent)
        tasks[task["id"]] = task
    return tasks


def run_reference(task: dict) -> dict:
    proc = subprocess.run(
        [
            "php",
            str(EXPERIMENT_ROOT / "harness" / "run-tests.php"),
            str(task["reference"]),
            str(task["tests"]),
        ],
        capture_output=True,
        text=True,
        check=False,
    )

    try:
        execution = json.loads(proc.stdout)
    except json.JSONDecodeError:
        execution = {
            "passed": 0,
            "total": task["case_count"],
            "cases": [],
            "error": "harness produced invalid JSON",
        }

    doing_it_wrong = []
    trigger_error = []
    for case in execution.get("cases", []):
        doing_it_wrong.extend(case.get("doing_it_wrong") or [])
        trigger_error.extend(case.get("trigger_error") or [])

    return {
        "id": task["id"],
        "dir_name": task["dir_name"],
        "split": task["split"],
        "concept": task["concept"],
        "passed": execution.get("passed"),
        "total": execution.get("total"),
        "returncode": proc.returncode,
        "missing_files": [
            name
            for name, path in {
                "task.md": task["task_md"],
                "reference.php": task["reference"],
                "tests.json": task["tests"],
            }.items()
            if not path.exists()
        ],
        "doing_it_wrong_count": len(doing_it_wrong),
        "trigger_error_count": len(trigger_error),
        "stderr": proc.stderr.strip(),
        "error": execution.get("error"),
    }


def select_tasks(tasks: dict[str, dict], split: str, explicit: list[str]) -> list[dict]:
    if explicit:
        missing = sorted(set(explicit) - set(tasks))
        if missing:
            raise RuntimeError("unknown task ids: " + ", ".join(missing))
        selected = [tasks[task_id] for task_id in explicit]
    else:
        selected = list(tasks.values())

    if split != "all":
        selected = [task for task in selected if task["split"] == split]
    return selected


def validate(results: list[dict], strict_signals: bool = False) -> tuple[list[str], list[str]]:
    errors = []
    warnings = []
    for result in results:
        task_id = result["id"]
        if result["id"] != result["dir_name"]:
            errors.append(f"{task_id}: tests.json id does not match directory name")
        if result["missing_files"]:
            errors.append(f"{task_id}: missing " + ", ".join(result["missing_files"]))
        if result["returncode"] != 0:
            errors.append(f"{task_id}: reference failed hidden tests")
        if result["passed"] != result["total"]:
            errors.append(f"{task_id}: passed {result['passed']}/{result['total']}")
        if result["doing_it_wrong_count"]:
            message = f"{task_id}: reference triggered _doing_it_wrong"
            (errors if strict_signals else warnings).append(message)
        if result["trigger_error_count"]:
            message = f"{task_id}: reference triggered PHP errors"
            (errors if strict_signals else warnings).append(message)
    return errors, warnings


def print_text(results: list[dict], errors: list[str], warnings: list[str]) -> None:
    total_cases = sum(result.get("total") or 0 for result in results)
    print(f"Validated {len(results)} active task reference(s), {total_cases} case(s).")
    for result in results:
        print(
            f"- {result['id']}: {result['passed']}/{result['total']} "
            f"{result['split']} {result['concept']}"
        )
    for warning in warnings:
        print(f"WARNING: {warning}", file=sys.stderr)
    for error in errors:
        print(f"ERROR: {error}", file=sys.stderr)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--split", choices=["all", "train", "holdout"], default="all")
    parser.add_argument("--task", dest="tasks", action="append", default=[])
    parser.add_argument("--json", action="store_true")
    parser.add_argument(
        "--strict-signals",
        action="store_true",
        help="Fail if reference executions record _doing_it_wrong or wp_trigger_error events",
    )
    args = parser.parse_args()

    selected = select_tasks(active_tasks(), args.split, args.tasks)
    results = [run_reference(task) for task in selected]
    errors, warnings = validate(results, args.strict_signals)

    report = {
        "ok": not errors,
        "task_count": len(results),
        "case_count": sum(result.get("total") or 0 for result in results),
        "results": results,
        "warnings": warnings,
        "errors": errors,
    }

    if args.json:
        print(json.dumps(report, indent=2))
    else:
        print_text(results, errors, warnings)

    return 0 if not errors else 1


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as exc:
        print(f"validate-corpus.py: {exc}", file=sys.stderr)
        sys.exit(1)
