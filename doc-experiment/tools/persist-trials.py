#!/usr/bin/env python3
"""Persists trial results from the trials workflow and executes each
candidate against its task's hidden tests.

Usage: python3 persist-trials.py <results-dir> < trials.json

stdin: JSON array of {id, trial, ok, code, explanation, confidence}.
Writes per trial: candidate.php, response.json, execution.json.
Prints a per-task pass summary.
"""

import json
import subprocess
import sys
from pathlib import Path

EXPERIMENT_ROOT = Path(__file__).resolve().parent.parent


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


def main() -> int:
    if len(sys.argv) != 2:
        print("Usage: persist-trials.py <results-dir> < trials.json", file=sys.stderr)
        return 2

    results_dir = Path(sys.argv[1])
    trials = json.load(sys.stdin)
    errors = validate_against_metadata(results_dir, trials)
    if errors:
        for error in errors:
            print(f"persist-trials.py: {error}", file=sys.stderr)
        return 1

    summary = {}
    for trial in trials:
        task_id = trial["id"]
        trial_dir = results_dir / task_id / f"trial-{trial['trial']}"
        trial_dir.mkdir(parents=True, exist_ok=True)

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

        code = trial.get("code")
        if not code:
            (trial_dir / "execution.json").write_text(
                json.dumps({"passed": 0, "total": 0, "error": "no code returned"}) + "\n"
            )
            summary.setdefault(task_id, []).append("no-code")
            continue

        if not code.lstrip().startswith("<?php"):
            code = "<?php\n" + code
        (trial_dir / "candidate.php").write_text(code)

        tests = EXPERIMENT_ROOT / "corpus" / task_id / "tests.json"
        proc = subprocess.run(
            [
                "php",
                str(EXPERIMENT_ROOT / "harness" / "run-tests.php"),
                str(trial_dir / "candidate.php"),
                str(tests),
            ],
            capture_output=True,
            text=True,
        )
        (trial_dir / "execution.json").write_text(proc.stdout or "{}")
        try:
            execution = json.loads(proc.stdout)
            summary.setdefault(task_id, []).append(
                f"{execution['passed']}/{execution['total']}"
            )
        except (json.JSONDecodeError, KeyError):
            summary.setdefault(task_id, []).append("harness-error")

    for task_id in sorted(summary):
        print(f"{task_id}: {' '.join(summary[task_id])}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
