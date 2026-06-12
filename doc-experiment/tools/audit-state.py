#!/usr/bin/env python3
"""Audit the HTML API documentation experiment state.

This is a read-only start-of-run check. It compares the active corpus and
worktree against the latest completed result summary, reports comparability
hazards, and prints the next protocol-safe action.
"""

import argparse
import json
import re
import subprocess
import sys
from pathlib import Path


EXPERIMENT_ROOT = Path(__file__).resolve().parent.parent
REPO_ROOT = EXPERIMENT_ROOT.parent

CURRENT_SUBJECT = {
    "model": "gpt-5.4",
    "reasoning_effort": "medium",
    "service_tier": "priority",
}

CURRENT_JUDGE = {
    "model": "gpt-5.5",
    "reasoning_effort": "xhigh",
    "service_tier": "priority",
}


def run_text(command: list[str]) -> str:
    proc = subprocess.run(
        command,
        cwd=REPO_ROOT,
        text=True,
        capture_output=True,
        check=False,
    )
    if proc.returncode != 0:
        raise RuntimeError(
            f"{' '.join(command)} failed with {proc.returncode}\n{proc.stderr}"
        )
    return proc.stdout.strip()


def active_tasks() -> dict[str, dict]:
    tasks = {}
    for tests_file in sorted((EXPERIMENT_ROOT / "corpus").glob("*/tests.json")):
        task_dir = tests_file.parent
        meta = json.loads(tests_file.read_text())
        task_id = meta.get("id") or task_dir.name
        tasks[task_id] = {
            "id": task_id,
            "split": meta.get("split"),
            "role": meta.get("role"),
            "commonness": meta.get("commonness"),
            "concept": meta.get("concept"),
            "processor": meta.get("processor"),
            "has_task_prompt": (task_dir / "task.md").exists(),
            "has_reference": (task_dir / "reference.php").exists(),
        }
    return tasks


def round_number(path: Path) -> int:
    match = re.fullmatch(r"round-(\d+)", path.name)
    if not match:
        return -1
    return int(match.group(1))


def completed_rounds() -> list[dict]:
    rounds = []
    for round_dir in sorted((EXPERIMENT_ROOT / "results").glob("round-*")):
        summary_file = round_dir / "round-summary.json"
        if not summary_file.exists():
            continue
        summary = json.loads(summary_file.read_text())
        metadata_file = round_dir / "round-metadata.json"
        metadata = json.loads(metadata_file.read_text()) if metadata_file.exists() else None
        rounds.append(
            {
                "round": round_dir.name,
                "number": round_number(round_dir),
                "summary_file": summary_file,
                "score": summary.get("round_score"),
                "by_split": summary.get("by_split", {}),
                "task_ids": sorted(summary.get("tasks", {}).keys()),
                "metadata": metadata,
            }
        )
    return sorted(rounds, key=lambda item: item["number"])


def validate_round(round_name: str) -> tuple[dict | None, list[str]]:
    proc = subprocess.run(
        [
            "python3",
            str(EXPERIMENT_ROOT / "tools" / "validate-round.py"),
            round_name,
            "--json",
        ],
        cwd=REPO_ROOT,
        text=True,
        capture_output=True,
        check=False,
    )
    if proc.returncode != 0:
        message = (proc.stderr or proc.stdout).strip()
        return None, [message or f"validate-round.py failed for {round_name}"]
    return json.loads(proc.stdout), []


def prepared_current_rounds(train_ids: list[str]) -> list[dict]:
    train_set = set(train_ids)
    prepared = []
    for round_dir in sorted((EXPERIMENT_ROOT / "results").glob("round-*")):
        metadata_file = round_dir / "round-metadata.json"
        summary_file = round_dir / "round-summary.json"
        if not metadata_file.exists() or summary_file.exists():
            continue

        metadata = json.loads(metadata_file.read_text())
        if metadata.get("mode") != "weak-tier-calibration":
            continue
        if metadata.get("subject") != CURRENT_SUBJECT:
            continue
        if metadata.get("judge") != CURRENT_JUDGE:
            continue
        if set(metadata.get("task_ids", [])) != train_set:
            continue

        report, errors = validate_round(round_dir.name)
        prepared.append(
            {
                "round": round_dir.name,
                "number": round_number(round_dir),
                "mode": metadata.get("mode"),
                "task_count": len(metadata.get("task_ids", [])),
                "trials_per_task": metadata.get("trials_per_task"),
                "scratch": metadata.get("scratch"),
                "lifecycle": report.get("lifecycle") if report else "invalid",
                "complete_trials": report.get("complete_trials") if report else 0,
                "expected_trials": report.get("expected_trials") if report else 0,
                "tasks_with_judges": report.get("tasks_with_judges") if report else 0,
                "errors": (report.get("errors", []) if report else []) + errors,
                "warnings": report.get("warnings", []) if report else [],
            }
        )
    return sorted(prepared, key=lambda item: item["number"])


def paths_changed_since(commit: str) -> list[str]:
    if not commit:
        return []
    output = run_text(["git", "diff", "--name-only", f"{commit}..HEAD"])
    return [line for line in output.splitlines() if line]


def last_commit_for(path: Path) -> str | None:
    output = run_text(["git", "log", "-1", "--format=%H", "--", str(path)])
    return output or None


def classify_paths(paths: list[str]) -> dict[str, list[str]]:
    groups = {
        "source_docs": [],
        "corpus": [],
        "results": [],
        "experiment_docs": [],
        "tooling": [],
        "other": [],
    }
    source_doc_paths = {
        "src/wp-includes/html-api/class-wp-html-tag-processor.php",
        "src/wp-includes/html-api/class-wp-html-processor.php",
    }
    for path in paths:
        if path in source_doc_paths:
            groups["source_docs"].append(path)
        elif path.startswith("doc-experiment/corpus/"):
            groups["corpus"].append(path)
        elif path.startswith("doc-experiment/results/"):
            groups["results"].append(path)
        elif path in {
            "doc-experiment/PLAN.md",
            "doc-experiment/PROTOCOL.md",
            "doc-experiment/NEXT-HYPOTHESES.md",
            "doc-experiment/LOG.md",
            "doc-experiment/README.md",
        }:
            groups["experiment_docs"].append(path)
        elif path.startswith("doc-experiment/tools/") or path == "doc-experiment/render-docs-markdown.py":
            groups["tooling"].append(path)
        else:
            groups["other"].append(path)
    return groups


def has_current_no_edit_baseline(rounds: list[dict], train_ids: list[str]) -> bool:
    train_set = set(train_ids)
    for round_info in rounds:
        metadata = round_info["metadata"]
        if not metadata:
            continue
        if metadata.get("mode") not in {"weak-tier-calibration", "scored-train"}:
            continue
        if metadata.get("subject") != CURRENT_SUBJECT:
            continue
        if set(metadata.get("task_ids", [])) != train_set:
            continue
        if set(round_info["task_ids"]) == train_set:
            return True
    return False


def build_audit() -> dict:
    tasks = active_tasks()
    train_ids = sorted(task_id for task_id, task in tasks.items() if task["split"] == "train")
    holdout_ids = sorted(task_id for task_id, task in tasks.items() if task["split"] == "holdout")
    rounds = completed_rounds()
    latest = rounds[-1] if rounds else None

    latest_commit = last_commit_for(latest["summary_file"]) if latest else None
    changed_since_latest = paths_changed_since(latest_commit) if latest_commit else []
    changed_groups = classify_paths(changed_since_latest)
    status_short = run_text(["git", "status", "--short"])

    latest_task_set = set(latest["task_ids"]) if latest else set()
    current_train_set = set(train_ids)
    current_all_set = set(tasks.keys())

    corpus_matches_latest_train = latest_task_set == current_train_set
    corpus_matches_latest_active = latest_task_set == current_all_set
    current_baseline_exists = has_current_no_edit_baseline(rounds, train_ids)
    prepared_rounds = prepared_current_rounds(train_ids)
    latest_prepared = prepared_rounds[-1] if prepared_rounds else None

    mismatches = []
    if status_short:
        mismatches.append("worktree has local drift")
    if latest and not corpus_matches_latest_train:
        mismatches.append("latest completed round task set differs from current train set")
    if changed_groups["source_docs"]:
        mismatches.append("source doc files changed since latest completed score")
    if changed_groups["tooling"]:
        mismatches.append("tooling changed since latest completed score")
    if changed_groups["corpus"]:
        mismatches.append("corpus changed since latest completed score")
    if not current_baseline_exists:
        mismatches.append("no current-corpus no-edit baseline for current subject tier")

    if status_short:
        next_action = "reconcile local worktree drift before scoring"
    elif latest_prepared and latest_prepared["errors"]:
        next_action = f"repair or restage {latest_prepared['round']} before launching agents"
    elif latest_prepared and latest_prepared["lifecycle"] == "prepared":
        next_action = (
            f"launch trials for prepared current-corpus baseline {latest_prepared['round']} "
            "with gpt-5.4/medium/priority"
        )
    elif latest_prepared and latest_prepared["lifecycle"] == "trials-partial":
        next_action = f"complete missing trial artifacts for {latest_prepared['round']}"
    elif latest_prepared and latest_prepared["lifecycle"] == "trials-complete":
        next_action = f"run judges for {latest_prepared['round']} with gpt-5.5/xhigh/priority"
    elif latest_prepared and latest_prepared["lifecycle"] == "judged":
        next_action = f"aggregate {latest_prepared['round']} and record the current-corpus baseline"
    elif not current_baseline_exists:
        next_action = (
            "prepare and run weak-tier-calibration no-edit baseline on current train corpus "
            "with gpt-5.4/medium/priority"
        )
    else:
        next_action = "run citation-only discoverability probes or shadow-doc A/B diagnostics"

    return {
        "git": {
            "head": run_text(["git", "rev-parse", "HEAD"]),
            "status_short": status_short,
        },
        "active_corpus": {
            "task_count": len(tasks),
            "train_count": len(train_ids),
            "holdout_count": len(holdout_ids),
            "train_task_ids": train_ids,
            "holdout_task_ids": holdout_ids,
            "concepts": sorted({task.get("concept") for task in tasks.values()}),
        },
        "latest_completed_round": {
            "round": latest["round"] if latest else None,
            "score": latest["score"] if latest else None,
            "by_split": latest["by_split"] if latest else {},
            "task_count": len(latest["task_ids"]) if latest else 0,
            "task_ids": latest["task_ids"] if latest else [],
            "summary_commit": latest_commit,
        },
        "current_policy": {
            "subject": CURRENT_SUBJECT,
            "judge": CURRENT_JUDGE,
        },
        "comparability": {
            "latest_tasks_match_current_train": corpus_matches_latest_train,
            "latest_tasks_match_current_active": corpus_matches_latest_active,
            "tasks_added_vs_latest": sorted(current_train_set - latest_task_set),
            "tasks_removed_vs_latest": sorted(latest_task_set - current_train_set),
            "current_no_edit_baseline_exists": current_baseline_exists,
            "prepared_current_round": latest_prepared,
            "changed_since_latest_summary_commit": changed_groups,
        },
        "mismatches": mismatches,
        "next_action": next_action,
    }


def print_text(audit: dict) -> None:
    latest = audit["latest_completed_round"]
    print("HTML API docs experiment state")
    print(f"- git head: {audit['git']['head']}")
    print(f"- worktree: {'dirty' if audit['git']['status_short'] else 'clean'}")
    print(
        f"- active corpus: {audit['active_corpus']['train_count']} train, "
        f"{audit['active_corpus']['holdout_count']} holdout"
    )
    print(
        f"- latest completed round: {latest['round']} score {latest['score']} "
        f"split {latest['by_split']}"
    )
    print(
        "- latest round matches current train: "
        f"{audit['comparability']['latest_tasks_match_current_train']}"
    )
    print(
        "- current no-edit baseline exists: "
        f"{audit['comparability']['current_no_edit_baseline_exists']}"
    )
    prepared = audit["comparability"]["prepared_current_round"]
    if prepared:
        print(
            f"- prepared current round: {prepared['round']} {prepared['lifecycle']} "
            f"trials {prepared['complete_trials']}/{prepared['expected_trials']} "
            f"judges {prepared['tasks_with_judges']}/{prepared['task_count']}"
        )
        if prepared["errors"]:
            print("- prepared round errors:")
            for error in prepared["errors"]:
                print(f"  - {error}")
    if audit["mismatches"]:
        print("- mismatches:")
        for mismatch in audit["mismatches"]:
            print(f"  - {mismatch}")
    print(f"- next action: {audit['next_action']}")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--json", action="store_true", help="Print machine-readable JSON")
    args = parser.parse_args()

    audit = build_audit()
    if args.json:
        print(json.dumps(audit, indent=2))
    else:
        print_text(audit)
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as exc:
        print(f"audit-state.py: {exc}", file=sys.stderr)
        sys.exit(1)
