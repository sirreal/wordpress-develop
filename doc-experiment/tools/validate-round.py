#!/usr/bin/env python3
"""Validate a prepared or scored experiment round.

The validator distinguishes round lifecycle states:

- prepared: metadata exists, scratch isolation passes, no trials yet.
- trials-complete: every expected candidate/response/execution exists.
- judged: trials are complete and every expected judge.json exists.
- scored: judged plus round-summary.json exists and matches expected tasks.

It is read-only and does not execute candidates or aggregate scores.
"""

import argparse
import json
import sys
from pathlib import Path


EXPERIMENT_ROOT = Path(__file__).resolve().parent.parent


def round_dir(name: str) -> Path:
    return EXPERIMENT_ROOT / "results" / name.removeprefix("doc-experiment/results/")


def load_json(path: Path) -> dict:
    return json.loads(path.read_text())


def expected_from_metadata(results_dir: Path) -> tuple[dict | None, list[str], int | None]:
    metadata_file = results_dir / "round-metadata.json"
    if not metadata_file.exists():
        return None, [], None
    metadata = load_json(metadata_file)
    return metadata, list(metadata.get("task_ids", [])), metadata.get("trials_per_task")


def expected_from_summary(results_dir: Path) -> tuple[dict | None, list[str], int | None]:
    summary_file = results_dir / "round-summary.json"
    if not summary_file.exists():
        return None, [], None
    summary = load_json(summary_file)
    task_ids = sorted(summary.get("tasks", {}).keys())
    trial_count = None
    if task_ids:
        counts = {
            len(task.get("trials", []))
            for task in summary.get("tasks", {}).values()
        }
        if len(counts) == 1:
            trial_count = counts.pop()
    return summary, task_ids, trial_count


def validate_scratch(metadata: dict | None) -> list[str]:
    if not metadata:
        return []
    scratch = metadata.get("scratch")
    if not scratch:
        return ["metadata has no scratch path"]
    scratch_dir = Path(scratch)
    expected_files = {
        "html-tag-processor.md",
        "html-processor.md",
        *metadata.get("staged_task_files", []),
    }
    actual_files = {
        path.relative_to(scratch_dir).as_posix()
        for path in scratch_dir.rglob("*")
        if path.is_file()
    } if scratch_dir.exists() else set()
    errors = []
    if not scratch_dir.exists():
        errors.append(f"scratch directory missing: {scratch_dir}")
    missing = sorted(expected_files - actual_files)
    unexpected = sorted(actual_files - expected_files)
    if missing:
        errors.append("scratch missing expected files: " + ", ".join(missing))
    if unexpected:
        errors.append("scratch has unexpected files: " + ", ".join(unexpected))
    return errors


def validate_round(results_dir: Path) -> dict:
    metadata, metadata_tasks, metadata_trials = expected_from_metadata(results_dir)
    summary, summary_tasks, summary_trials = expected_from_summary(results_dir)

    expected_tasks = metadata_tasks or summary_tasks
    expected_trials = metadata_trials or summary_trials or 3
    errors = []
    warnings = []

    if not results_dir.exists():
        errors.append(f"results directory missing: {results_dir}")
    if not metadata and not summary:
        errors.append("missing both round-metadata.json and round-summary.json")

    if metadata and metadata.get("task_count") != len(metadata_tasks):
        errors.append("metadata task_count does not match task_ids length")
    if summary and metadata_tasks and set(summary_tasks) != set(metadata_tasks):
        errors.append("round-summary task set does not match metadata task_ids")

    errors.extend(validate_scratch(metadata))

    task_status = {}
    total_trials = 0
    complete_trials = 0
    tasks_with_all_trials = 0
    tasks_with_judges = 0

    for task_id in expected_tasks:
        task_dir = results_dir / task_id
        expected_trial_names = [f"trial-{i}" for i in range(1, expected_trials + 1)]
        missing_trials = []
        incomplete_trials = []
        present_trials = []

        for trial_name in expected_trial_names:
            total_trials += 1
            trial_dir = task_dir / trial_name
            files = {
                "candidate.php": trial_dir / "candidate.php",
                "response.json": trial_dir / "response.json",
                "execution.json": trial_dir / "execution.json",
            }
            if not trial_dir.exists():
                missing_trials.append(trial_name)
                continue
            present_trials.append(trial_name)
            missing_files = [name for name, path in files.items() if not path.exists()]
            if missing_files:
                incomplete_trials.append(
                    {"trial": trial_name, "missing_files": missing_files}
                )
            else:
                complete_trials += 1

        judge_file = task_dir / "judge.json"
        has_judge = judge_file.exists()
        if has_judge:
            tasks_with_judges += 1

        if not missing_trials and not incomplete_trials:
            tasks_with_all_trials += 1

        task_status[task_id] = {
            "present_trials": present_trials,
            "missing_trials": missing_trials,
            "incomplete_trials": incomplete_trials,
            "has_judge": has_judge,
        }

    has_trials = complete_trials > 0
    trials_complete = bool(expected_tasks) and complete_trials == total_trials
    judged = trials_complete and tasks_with_judges == len(expected_tasks)
    scored = judged and (results_dir / "round-summary.json").exists()

    if summary and not judged:
        errors.append("round-summary.json exists before all trials are judged")
    if has_trials and not trials_complete:
        warnings.append("some trial files are missing or incomplete")
    if trials_complete and not judged:
        warnings.append("trials are complete but one or more judge.json files are missing")
    if judged and not scored:
        warnings.append("judges are complete but round-summary.json is missing")

    if scored:
        lifecycle = "scored"
    elif judged:
        lifecycle = "judged"
    elif trials_complete:
        lifecycle = "trials-complete"
    elif has_trials:
        lifecycle = "trials-partial"
    elif metadata:
        lifecycle = "prepared"
    else:
        lifecycle = "unknown"

    return {
        "round": results_dir.name,
        "lifecycle": lifecycle,
        "expected_task_count": len(expected_tasks),
        "expected_trials_per_task": expected_trials,
        "complete_trials": complete_trials,
        "expected_trials": total_trials,
        "tasks_with_all_trials": tasks_with_all_trials,
        "tasks_with_judges": tasks_with_judges,
        "has_summary": (results_dir / "round-summary.json").exists(),
        "metadata": {
            "mode": metadata.get("mode") if metadata else None,
            "subject": metadata.get("subject") if metadata else None,
            "judge": metadata.get("judge") if metadata else None,
            "scratch": metadata.get("scratch") if metadata else None,
        },
        "task_status": task_status,
        "warnings": warnings,
        "errors": errors,
    }


def print_text(report: dict) -> None:
    print(f"{report['round']}: {report['lifecycle']}")
    print(
        f"- tasks: {report['expected_task_count']}, "
        f"trials: {report['complete_trials']}/{report['expected_trials']}, "
        f"judges: {report['tasks_with_judges']}/{report['expected_task_count']}, "
        f"summary: {report['has_summary']}"
    )
    if report["metadata"]["mode"]:
        print(f"- mode: {report['metadata']['mode']}")
        print(f"- subject: {report['metadata']['subject']}")
        print(f"- judge: {report['metadata']['judge']}")
    for warning in report["warnings"]:
        print(f"WARNING: {warning}")
    for error in report["errors"]:
        print(f"ERROR: {error}")
    if report["lifecycle"] != "scored":
        missing = [
            task_id
            for task_id, status in report["task_status"].items()
            if status["missing_trials"] or status["incomplete_trials"] or not status["has_judge"]
        ]
        if missing:
            print("- incomplete tasks: " + ", ".join(missing[:12]))
            if len(missing) > 12:
                print(f"  ... and {len(missing) - 12} more")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("round", help="Round name, e.g. round-18")
    parser.add_argument("--json", action="store_true")
    parser.add_argument(
        "--require-scored",
        action="store_true",
        help="Exit non-zero unless the round is fully scored",
    )
    parser.add_argument(
        "--require-judged",
        action="store_true",
        help="Exit non-zero unless the round has complete trials and judges",
    )
    parser.add_argument(
        "--require-trials-complete",
        action="store_true",
        help="Exit non-zero unless every expected trial has execution results",
    )
    args = parser.parse_args()

    report = validate_round(round_dir(args.round))
    if args.json:
        print(json.dumps(report, indent=2))
    else:
        print_text(report)

    if report["errors"]:
        return 1
    if args.require_trials_complete and report["lifecycle"] not in {
        "trials-complete",
        "judged",
        "scored",
    }:
        return 1
    if args.require_judged and report["lifecycle"] not in {"judged", "scored"}:
        return 1
    if args.require_scored and report["lifecycle"] != "scored":
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
