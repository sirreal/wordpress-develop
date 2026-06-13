#!/usr/bin/env python3
"""Ingests a trials-workflow output file: persists isolation evidence,
candidates, hidden-test executions, and a compact pass summary.

Usage: python3 ingest-trials.py <workflow-output-file> <round-NN>
"""

import json
import subprocess
import sys
from pathlib import Path

EXPERIMENT_ROOT = Path(__file__).resolve().parent.parent


def main() -> int:
    output_file, round_name = sys.argv[1], sys.argv[2]
    results_dir = EXPERIMENT_ROOT / "results" / round_name

    validate = subprocess.run(
        [
            "python3",
            str(EXPERIMENT_ROOT / "tools" / "validate-workflow-output.py"),
            "trials",
            output_file,
            round_name,
        ],
        capture_output=True,
        text=True,
    )
    if validate.returncode != 0:
        print(validate.stdout, end="")
        print(validate.stderr, file=sys.stderr)
        return validate.returncode

    payload = json.load(open(output_file))
    trials = payload["result"]
    subject_isolation = payload["subject_isolation"]
    results_dir.mkdir(parents=True, exist_ok=True)

    proc = subprocess.run(
        ["python3", str(EXPERIMENT_ROOT / "tools" / "persist-trials.py"), str(results_dir)],
        input=json.dumps(trials),
        capture_output=True,
        text=True,
    )
    print(proc.stdout, end="")
    if proc.returncode != 0:
        print(proc.stderr, file=sys.stderr)
        return proc.returncode

    (results_dir / "subject-isolation.json").write_text(
        json.dumps(subject_isolation, indent=2, ensure_ascii=False) + "\n"
    )

    # Compact failure summary: only imperfect trials.
    failures = []
    for line in proc.stdout.splitlines():
        task, _, scores = line.partition(":")
        marks = scores.split()
        if any("/" in m and len(set(m.split("/"))) > 1 for m in marks):
            failures.append(line)
    print("--- imperfect:", len(failures), "tasks" if failures else "(all clean)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
