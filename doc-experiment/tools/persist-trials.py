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


def main() -> int:
    if len(sys.argv) != 2:
        print("Usage: persist-trials.py <results-dir> < trials.json", file=sys.stderr)
        return 2

    results_dir = Path(sys.argv[1])
    trials = json.load(sys.stdin)

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
