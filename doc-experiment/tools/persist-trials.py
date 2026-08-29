#!/usr/bin/env python3
"""Persists trial results from the trials workflow and executes each
candidate against its task's hidden tests.

Usage: python3 persist-trials.py <results-dir> < trials.json

stdin: JSON array of {id, trial, ok, code, explanation, confidence}.
Writes per trial: candidate.php, response.json, execution.json.
Prints a per-task pass summary.
"""

import json
import shutil
import subprocess
import sys
from pathlib import Path

EXPERIMENT_ROOT = Path(__file__).resolve().parent.parent


def validate_trial_payloads(trials: list[dict]) -> list[str]:
    errors = []
    for entry in trials:
        task_id = entry.get("id")
        trial = entry.get("trial")
        label = f"{task_id}/trial-{trial}"

        code = entry.get("code")
        if not isinstance(code, str) or not code.strip():
            errors.append(f"{label}: code must be a non-empty string")
        elif not code.lstrip().startswith("<?php"):
            errors.append(f"{label}: code must start with <?php")

        explanation = entry.get("explanation")
        if not isinstance(explanation, str) or not explanation.strip():
            errors.append(f"{label}: explanation must be a non-empty string")

        confidence = entry.get("confidence")
        if not isinstance(confidence, int) or confidence < 0 or confidence > 100:
            errors.append(f"{label}: confidence must be integer 0-100")

    return errors


def validate_against_metadata(results_dir: Path, trials: list[dict]) -> list[str]:
    metadata_file = results_dir / "round-metadata.json"
    if not metadata_file.exists():
        return []

    metadata = json.loads(metadata_file.read_text())
    expected_tasks = set(metadata.get("task_ids", []))
    expected_trials = int(metadata.get("trials_per_task", 0))
    expected_pairs = {
        (task_id, trial)
        for task_id in expected_tasks
        for trial in range(1, expected_trials + 1)
    }

    seen_pairs = []
    errors = []
    for entry in trials:
        task_id = entry.get("id")
        trial = entry.get("trial")
        if task_id not in expected_tasks:
            errors.append(f"unexpected trial task id: {task_id}")
        if not isinstance(trial, int):
            errors.append(f"{task_id}: trial id is not an integer: {trial!r}")
            continue
        if trial < 1 or trial > expected_trials:
            errors.append(f"{task_id}: unexpected trial number: {trial}")
        seen_pairs.append((task_id, trial))

    duplicates = sorted({pair for pair in seen_pairs if seen_pairs.count(pair) > 1})
    if duplicates:
        errors.append(
            "duplicate trials: "
            + ", ".join(f"{task}/trial-{trial}" for task, trial in duplicates)
        )

    actual_pairs = set(seen_pairs)
    missing = sorted(expected_pairs - actual_pairs)
    unexpected = sorted(actual_pairs - expected_pairs)
    if missing:
        errors.append(
            "missing trials: "
            + ", ".join(f"{task}/trial-{trial}" for task, trial in missing[:12])
            + (f", ... and {len(missing) - 12} more" if len(missing) > 12 else "")
        )
    if unexpected:
        errors.append(
            "unexpected trials: "
            + ", ".join(f"{task}/trial-{trial}" for task, trial in unexpected)
        )

    return errors


def validate_no_existing_artifacts(results_dir: Path, trials: list[dict]) -> list[str]:
    errors = []
    for entry in trials:
        task_id = entry.get("id")
        trial = entry.get("trial")
        trial_dir = results_dir / str(task_id) / f"trial-{trial}"
        if not trial_dir.exists():
            continue
        existing = [path.name for path in trial_dir.iterdir()]
        if existing:
            errors.append(
                f"{task_id}/trial-{trial}: refusing to overwrite existing trial artifacts "
                f"in {trial_dir}"
            )
    return errors


def validate_execution_payload(execution: object, label: str) -> list[str]:
    if not isinstance(execution, dict):
        return [f"{label}: harness output must be an object"]

    errors = []
    passed = execution.get("passed")
    total = execution.get("total")
    if not isinstance(passed, int) or passed < 0:
        errors.append(f"{label}: harness passed must be a non-negative integer")
    if not isinstance(total, int) or total < 1:
        errors.append(f"{label}: harness total must be a positive integer")
    if isinstance(passed, int) and isinstance(total, int) and passed > total:
        errors.append(f"{label}: harness passed exceeds total")
    if not isinstance(execution.get("cases"), list):
        errors.append(f"{label}: harness cases must be an array")
    return errors


def execute_candidate(task_id: str, trial: int, candidate_file: Path) -> dict:
    label = f"{task_id}/trial-{trial}"
    tests = EXPERIMENT_ROOT / "corpus" / task_id / "tests.json"
    proc = subprocess.run(
        [
            "php",
            str(EXPERIMENT_ROOT / "harness" / "run-tests.php"),
            str(candidate_file),
            str(tests),
        ],
        capture_output=True,
        text=True,
    )

    try:
        execution = json.loads(proc.stdout)
    except json.JSONDecodeError as exc:
        message = proc.stderr.strip() or proc.stdout.strip()
        raise RuntimeError(f"{label}: harness produced invalid JSON: {exc}; {message}")

    errors = validate_execution_payload(execution, label)
    if errors:
        raise RuntimeError("; ".join(errors))

    return execution


def cleanup_created_trial_dirs(trial_dirs: list[Path]) -> None:
    for trial_dir in reversed(trial_dirs):
        if trial_dir.exists():
            shutil.rmtree(trial_dir)


def main() -> int:
    if len(sys.argv) != 2:
        print("Usage: persist-trials.py <results-dir> < trials.json", file=sys.stderr)
        return 2

    results_dir = Path(sys.argv[1])
    trials = json.load(sys.stdin)
    errors = [
        *validate_trial_payloads(trials),
        *validate_against_metadata(results_dir, trials),
        *validate_no_existing_artifacts(results_dir, trials),
    ]
    if errors:
        for error in errors:
            print(f"persist-trials.py: {error}", file=sys.stderr)
        return 1

    summary = {}
    created_trial_dirs = []
    for trial in trials:
        task_id = trial["id"]
        trial_number = trial["trial"]
        trial_dir = results_dir / task_id / f"trial-{trial_number}"
        candidate_file = trial_dir / "candidate.php"

        try:
            trial_dir.mkdir(parents=True, exist_ok=True)
            created_trial_dirs.append(trial_dir)
            candidate_file.write_text(trial["code"])
            execution = execute_candidate(task_id, trial_number, candidate_file)
        except (OSError, RuntimeError) as exc:
            cleanup_created_trial_dirs(created_trial_dirs)
            print(f"persist-trials.py: {exc}", file=sys.stderr)
            return 1

        try:
            (trial_dir / "response.json").write_text(
                json.dumps(
                    {
                        "ok": trial.get("ok", False),
                        "explanation": trial.get("explanation"),
                        "confidence": trial.get("confidence"),
                    },
                    indent=2,
                )
                + "\n"
            )

            (trial_dir / "execution.json").write_text(
                json.dumps(
                    execution,
                    indent=2,
                    ensure_ascii=False,
                )
                + "\n"
            )
        except OSError as exc:
            cleanup_created_trial_dirs(created_trial_dirs)
            print(f"persist-trials.py: {exc}", file=sys.stderr)
            return 1

        summary.setdefault(task_id, []).append(
            f"{execution['passed']}/{execution['total']}"
        )

    for task_id in sorted(summary):
        print(f"{task_id}: {' '.join(summary[task_id])}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
