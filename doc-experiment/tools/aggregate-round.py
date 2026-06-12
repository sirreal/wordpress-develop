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


def main() -> int:
    if len(sys.argv) != 2:
        print("Usage: aggregate-round.py <results-dir>", file=sys.stderr)
        return 2

    results_dir = Path(sys.argv[1])
    task_scores = {}

    for task_dir in sorted(p for p in results_dir.iterdir() if p.is_dir()):
        judge_file = task_dir / "judge.json"
        adherence_by_trial = {}
        if judge_file.exists():
            judge = json.loads(judge_file.read_text())
            for trial in judge.get("trials", []):
                adherence_by_trial[trial["trial_id"]] = trial["adherence"]

        trial_scores = []
        trial_details = []
        for trial_dir in sorted(p for p in task_dir.iterdir() if p.is_dir()):
            execution_file = trial_dir / "execution.json"
            if not execution_file.exists():
                continue
            execution = json.loads(execution_file.read_text())
            total = execution["total"]
            passed = execution["passed"] or 0
            pass_fraction = passed / total if total else 0.0
            adherence = adherence_by_trial.get(trial_dir.name, 0)
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

    if not task_scores:
        print("No results found.", file=sys.stderr)
        return 1

    metadata = None
    metadata_file = results_dir / "round-metadata.json"
    if metadata_file.exists():
        metadata = json.loads(metadata_file.read_text())

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

    print(json.dumps(summary, indent=2))
    return 0


if __name__ == "__main__":
    sys.exit(main())
