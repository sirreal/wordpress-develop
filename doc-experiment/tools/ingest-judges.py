#!/usr/bin/env python3
"""Ingests a judge-workflow output file: writes per-task judge.json,
aggregates the round, and prints a compact comparison digest.

Usage: python3 ingest-judges.py <workflow-output-file> <round-NN> [<baseline-round-NN>]

Digest: round/core/split/concept scores, per-task deltas vs baseline,
and doc-gap one-liners for tasks scoring below 97 (train only —
held-out gaps are listed separately, marked DO-NOT-ACT.)
"""

import json
import shutil
import subprocess
import sys
from pathlib import Path

EXPERIMENT_ROOT = Path(__file__).resolve().parent.parent


def validate_verdicts(results_dir: Path, verdicts: list[dict]) -> list[str]:
    metadata_file = results_dir / "round-metadata.json"
    if not metadata_file.exists():
        return []

    metadata = json.loads(metadata_file.read_text())
    expected = set(metadata.get("task_ids", []))
    actual_list = [entry.get("id") for entry in verdicts]
    actual = set(actual_list)
    errors = []

    duplicates = sorted({task_id for task_id in actual_list if actual_list.count(task_id) > 1})
    if duplicates:
        errors.append("duplicate judge verdicts: " + ", ".join(duplicates))

    missing = sorted(expected - actual)
    unexpected = sorted(actual - expected)
    if missing:
        errors.append("missing judge verdicts: " + ", ".join(missing))
    if unexpected:
        errors.append("unexpected judge verdicts: " + ", ".join(unexpected))

    return errors


def validate_no_existing_artifacts(results_dir: Path, verdicts: list[dict]) -> list[str]:
    existing = []
    summary_file = results_dir / "round-summary.json"
    if summary_file.exists():
        existing.append(str(summary_file))

    for entry in verdicts:
        task_id = entry.get("id")
        judge_file = results_dir / str(task_id) / "judge.json"
        if judge_file.exists():
            existing.append(str(judge_file))

    if not existing:
        return []
    return [
        "refusing to overwrite existing judge artifacts: "
        + ", ".join(existing[:12])
        + (f", ... and {len(existing) - 12} more" if len(existing) > 12 else "")
    ]


def cleanup_created_judge_artifacts(paths: list[Path]) -> None:
    for path in reversed(paths):
        if path.exists():
            if path.is_dir():
                shutil.rmtree(path)
            else:
                path.unlink()


def main() -> int:
    output_file, round_name = sys.argv[1], sys.argv[2]
    baseline = sys.argv[3] if len(sys.argv) > 3 else None
    results_dir = EXPERIMENT_ROOT / "results" / round_name

    validate_output = subprocess.run(
        [
            "python3",
            str(EXPERIMENT_ROOT / "tools" / "validate-workflow-output.py"),
            "judges",
            output_file,
            round_name,
        ],
        capture_output=True,
        text=True,
    )
    if validate_output.returncode != 0:
        print(validate_output.stdout, end="")
        print(validate_output.stderr, file=sys.stderr)
        return validate_output.returncode

    verdicts = json.load(open(output_file))["result"]
    errors = [
        *validate_verdicts(results_dir, verdicts),
        *validate_no_existing_artifacts(results_dir, verdicts),
    ]
    if errors:
        for error in errors:
            print(f"ingest-judges.py: {error}", file=sys.stderr)
        return 1

    validate_trials = subprocess.run(
        [
            "python3",
            str(EXPERIMENT_ROOT / "tools" / "validate-round.py"),
            round_name,
            "--require-trials-complete",
        ],
        capture_output=True,
        text=True,
    )
    if validate_trials.returncode != 0:
        print(validate_trials.stdout, end="")
        print(validate_trials.stderr, file=sys.stderr)
        return validate_trials.returncode

    created_artifacts = []
    for entry in verdicts:
        tid, v = entry["id"], entry["verdict"]
        judge_file = results_dir / tid / "judge.json"
        created_artifacts.append(judge_file)
        try:
            judge_file.write_text(json.dumps(v, indent=2, ensure_ascii=False) + "\n")
        except OSError as exc:
            cleanup_created_judge_artifacts(created_artifacts)
            print(f"ingest-judges.py: {exc}", file=sys.stderr)
            return 1
    print(f"{len(verdicts)} verdicts persisted")

    validate = subprocess.run(
        [
            "python3",
            str(EXPERIMENT_ROOT / "tools" / "validate-round.py"),
            round_name,
            "--require-judged",
        ],
        capture_output=True,
        text=True,
    )
    if validate.returncode != 0:
        cleanup_created_judge_artifacts(created_artifacts)
        print(validate.stdout, end="")
        print(validate.stderr, file=sys.stderr)
        return validate.returncode

    proc = subprocess.run(
        ["python3", str(EXPERIMENT_ROOT / "tools" / "aggregate-round.py"), str(results_dir)],
        capture_output=True,
        text=True,
    )
    if proc.returncode != 0:
        cleanup_created_judge_artifacts(created_artifacts)
        print(proc.stderr, file=sys.stderr)
        return proc.returncode
    try:
        summary = json.loads(proc.stdout)
    except json.JSONDecodeError as exc:
        cleanup_created_judge_artifacts(created_artifacts)
        print(f"ingest-judges.py: aggregate output is invalid JSON: {exc}", file=sys.stderr)
        return 1
    summary_file = results_dir / "round-summary.json"
    created_artifacts.append(summary_file)
    try:
        summary_file.write_text(proc.stdout)
    except OSError as exc:
        cleanup_created_judge_artifacts(created_artifacts)
        print(f"ingest-judges.py: {exc}", file=sys.stderr)
        return 1

    base_tasks = {}
    if baseline:
        base_file = EXPERIMENT_ROOT / "results" / baseline / "round-summary.json"
        if base_file.exists():
            base_tasks = {
                k: v["score"] for k, v in json.loads(base_file.read_text())["tasks"].items()
            }

    print(f"ROUND {summary['round_score']}  core {summary['core_score']}")
    print("split:  ", summary["by_split"])
    print("concept:", summary["by_concept"])
    for k, v in sorted(summary["tasks"].items(), key=lambda kv: kv[1]["score"]):
        delta = f" ({v['score'] - base_tasks[k]:+.1f})" if k in base_tasks else ""
        if v["score"] < 100 or (k in base_tasks and abs(v["score"] - base_tasks[k]) > 0.5):
            trials = "  ".join(
                f"{t['passed']}/{t['total']}a{t['adherence']}" for t in v["trials"]
            )
            print(f"  {k}: {v['score']:.2f}{delta}  {trials}")

    # Doc gaps for weak tasks, train/holdout separated.
    for entry in verdicts:
        tid, v = entry["id"], entry["verdict"]
        score = summary["tasks"].get(tid, {}).get("score", 100)
        if score >= 97:
            continue
        split = summary["tasks"][tid].get("labels", {}).get("split", "?")
        tag = "DO-NOT-ACT(holdout)" if split == "holdout" else "train"
        for g in v.get("doc_gaps", []):
            print(f"  GAP[{tag}] {tid}: {g['location'][:70]} :: {g['problem'][:130]}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
