#!/usr/bin/env python3
"""Aggregates a round's results into task and round scores.

Usage: python3 aggregate-round.py <results-dir>

Expects <results-dir>/<task-id>/trial-<n>/ containing:
  - execution.json   (run-tests.php output for the trial's candidate)
  - judge.json       (judge verdict; needs trials[].adherence keyed by trial)
Layout details are flexible: this reads every execution.json under each
task directory and pairs it with adherence scores from the task-level
judge.json (trial key = trial directory name).

Score formula (per PLAN.md): trial = 0.7 * pass_fraction * 100
+ 0.3 * adherence; task = mean(trials); round = mean(tasks).
"""

import json
import sys
from pathlib import Path


def load_metadata(results_dir: Path) -> dict | None:
    metadata_file = results_dir / "round-metadata.json"
    if not metadata_file.exists():
        return None
    return json.loads(metadata_file.read_text())


def load_subject_isolation(results_dir: Path) -> dict | None:
    attestation_file = results_dir / "subject-isolation.json"
    if not attestation_file.exists():
        return None
    return json.loads(attestation_file.read_text())


def main() -> int:
    if len(sys.argv) != 2:
        print("Usage: aggregate-round.py <results-dir>", file=sys.stderr)
        return 2

    results_dir = Path(sys.argv[1])
    metadata = load_metadata(results_dir)
    expected_task_ids = metadata.get("task_ids", []) if metadata else None
    expected_trials = metadata.get("trials_per_task") if metadata else None
    task_scores = {}
    errors = []

    if expected_task_ids is not None:
        task_dirs = [results_dir / task_id for task_id in expected_task_ids]
        unexpected_dirs = sorted(
            p.name
            for p in results_dir.iterdir()
            if p.is_dir() and p.name not in set(expected_task_ids)
        )
        if unexpected_dirs:
            errors.append("unexpected task result directories: " + ", ".join(unexpected_dirs))
    else:
        task_dirs = sorted(p for p in results_dir.iterdir() if p.is_dir())

    for task_dir in task_dirs:
        if not task_dir.exists():
            errors.append(f"missing task result directory: {task_dir.name}")
            continue
        judge_file = task_dir / "judge.json"
        adherence_by_trial = {}
        if judge_file.exists():
            judge = json.loads(judge_file.read_text())
            for trial in judge.get("trials", []):
                adherence_by_trial[trial["trial_id"]] = trial["adherence"]
        elif metadata is not None:
            errors.append(f"{task_dir.name}: missing judge.json")

        trial_scores = []
        trial_details = []
        trial_dirs = sorted(p for p in task_dir.iterdir() if p.is_dir())
        if expected_trials is not None:
            expected_trial_names = {f"trial-{i}" for i in range(1, expected_trials + 1)}
            actual_trial_names = {p.name for p in trial_dirs}
            missing_trials = sorted(expected_trial_names - actual_trial_names)
            unexpected_trials = sorted(actual_trial_names - expected_trial_names)
            if missing_trials:
                errors.append(f"{task_dir.name}: missing trials: {', '.join(missing_trials)}")
            if unexpected_trials:
                errors.append(f"{task_dir.name}: unexpected trials: {', '.join(unexpected_trials)}")
            trial_dirs = [task_dir / name for name in sorted(expected_trial_names)]

        for trial_dir in trial_dirs:
            execution_file = trial_dir / "execution.json"
            if not execution_file.exists():
                if metadata is not None:
                    errors.append(f"{task_dir.name}/{trial_dir.name}: missing execution.json")
                continue
            execution = json.loads(execution_file.read_text())
            total = execution["total"]
            passed = execution["passed"] or 0
            pass_fraction = passed / total if total else 0.0
            if trial_dir.name not in adherence_by_trial:
                if metadata is not None:
                    errors.append(f"{task_dir.name}/{trial_dir.name}: missing judge adherence")
                    continue
                adherence = 0
            else:
                adherence = adherence_by_trial[trial_dir.name]
            score = 0.7 * pass_fraction * 100 + 0.3 * adherence
            trial_scores.append(score)
            trial_details.append(
                {
                    "trial": trial_dir.name,
                    "passed": passed,
                    "total": total,
                    "adherence": adherence,
                    "score": round(score, 2),
                }
            )

        if trial_scores:
            task_scores[task_dir.name] = {
                "score": round(sum(trial_scores) / len(trial_scores), 2),
                "trials": trial_details,
            }

    if errors:
        for error in errors:
            print(f"aggregate-round.py: {error}", file=sys.stderr)
        return 1

    if not task_scores:
        print("No results found.", file=sys.stderr)
        return 1

    # Per-category breakdowns from corpus labels (concept, role, split).
    corpus_dir = Path(__file__).resolve().parent.parent / "corpus"
    by_concept = {}
    by_split = {}
    core_scores = []
    for task_id, data in task_scores.items():
        meta_file = corpus_dir / task_id / "tests.json"
        if not meta_file.exists():
            continue
        meta = json.loads(meta_file.read_text())
        data["labels"] = {
            "role": meta.get("role"),
            "commonness": meta.get("commonness"),
            "concept": meta.get("concept"),
            "processor": meta.get("processor"),
            "split": meta.get("split"),
        }
        by_concept.setdefault(meta.get("concept"), []).append(data["score"])
        by_split.setdefault(meta.get("split"), []).append(data["score"])
        if meta.get("role") == "core":
            core_scores.append(data["score"])

    round_score = sum(t["score"] for t in task_scores.values()) / len(task_scores)
    summary = {
        "round_score": round(round_score, 2),
        "core_score": round(sum(core_scores) / len(core_scores), 2)
        if core_scores
        else None,
        "by_split": {
            k: round(sum(v) / len(v), 2) for k, v in sorted(by_split.items())
        },
        "by_concept": {
            k: round(sum(v) / len(v), 2) for k, v in sorted(by_concept.items())
        },
        "tasks": task_scores,
    }
    if metadata is not None:
        summary["round_metadata"] = {
            key: metadata.get(key)
            for key in (
                "round",
                "mode",
                "task_ids",
                "task_count",
                "trials_per_task",
                "subject",
                "judge",
                "git_head",
                "git_status_short",
            )
        }
    subject_isolation = load_subject_isolation(results_dir)
    if subject_isolation is not None:
        summary["subject_isolation"] = subject_isolation

    print(json.dumps(summary, indent=2))
    return 0


if __name__ == "__main__":
    sys.exit(main())
