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

SUBJECT_LADDER = [
    {
        "model": "gpt-5.4",
        "reasoning_effort": "medium",
        "service_tier": "priority",
    },
    {
        "model": "gpt-5.4",
        "reasoning_effort": "low",
        "service_tier": "priority",
    },
    {
        "model": "gpt-5.4-mini",
        "reasoning_effort": "high",
        "service_tier": "priority",
    },
    {
        "model": "gpt-5.4-mini",
        "reasoning_effort": "low",
        "service_tier": "priority",
    },
]

SATURATED_SCORE = 97.0
DIAGNOSTIC_MODES = {"discoverability-probe", "shadow-doc-a/b"}
PREPARABLE_MODES = {
    "checkpoint",
    "discoverability-probe",
    "scored-train",
    "shadow-doc-a/b",
    "weak-tier-calibration",
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
                "mode": metadata.get("mode") if metadata else None,
                "metadata": metadata,
            }
        )
    return sorted(rounds, key=lambda item: item["number"])


def latest_log_next_action() -> str | None:
    log_file = EXPERIMENT_ROOT / "LOG.md"
    if not log_file.exists():
        return None

    text = log_file.read_text()
    first_heading = text.find("\n## ")
    if first_heading == -1:
        return None
    next_heading = text.find("\n## ", first_heading + 1)
    first_entry = text[first_heading: next_heading if next_heading != -1 else len(text)]
    match = re.search(
        r"Next action:\s*(.+?)(?:\n\n|$)",
        first_entry,
        flags=re.DOTALL,
    )
    if not match:
        return None
    return " ".join(match.group(1).split())


def mode_from_text(text: str | None) -> str | None:
    if not text:
        return None
    normalized = text.lower()
    for mode in (
        "checkpoint",
        "weak-tier-calibration",
        "scored-train",
        "discoverability-probe",
        "shadow-doc-a/b",
    ):
        if mode in normalized:
            return mode
    if "regression sentinel" in normalized:
        return "checkpoint"
    return None


def format_policy(policy: dict | None) -> str:
    if not policy:
        return "unknown"
    return (
        f"{policy.get('model')}/"
        f"{policy.get('reasoning_effort')}/"
        f"{policy.get('service_tier')}"
    )


def policy_index(policy: dict | None, ladder: list[dict]) -> int | None:
    if not policy:
        return None
    for index, item in enumerate(ladder):
        if item == policy:
            return index
    return None


def next_subject_tier(policy: dict | None) -> dict | None:
    index = policy_index(policy, SUBJECT_LADDER)
    if index is None or index + 1 >= len(SUBJECT_LADDER):
        return None
    return SUBJECT_LADDER[index + 1]


def subject_from_text(text: str | None) -> dict | None:
    if not text:
        return None
    normalized = text.replace("`", "").lower()
    for policy in SUBJECT_LADDER:
        needle = (
            f"{policy['model']} / {policy['reasoning_effort']} / "
            f"{policy['service_tier']}"
        ).lower()
        if needle in normalized:
            return policy
    return None


def selected_subject(latest: dict | None, latest_log_action: str | None) -> tuple[dict, str]:
    log_subject = subject_from_text(latest_log_action)
    if log_subject:
        return log_subject, "latest LOG.md next action"

    if latest and latest.get("mode") == "weak-tier-calibration":
        latest_subject = latest.get("metadata", {}).get("subject")
        latest_score = latest.get("score")
        if (
            latest_subject
            and latest_score is not None
            and latest_score >= SATURATED_SCORE
        ):
            next_subject = next_subject_tier(latest_subject)
            if next_subject:
                return next_subject, f"saturated {latest['round']} weak-tier calibration"
        if latest_subject:
            return latest_subject, f"latest {latest['round']} weak-tier calibration"

    return CURRENT_SUBJECT, "default current subject tier"


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
    report = None
    errors = []
    if proc.stdout.strip():
        try:
            report = json.loads(proc.stdout)
        except json.JSONDecodeError:
            report = None
    if report:
        errors.extend(report.get("errors", []))
    if proc.returncode != 0 and not errors:
        message = (proc.stderr or proc.stdout).strip()
        errors.append(message or f"validate-round.py failed for {round_name}")
    return report, errors


def expected_task_ids_for_mode(
    mode: str | None,
    train_ids: list[str],
    holdout_ids: list[str],
) -> list[str] | None:
    if mode == "checkpoint":
        return sorted([*train_ids, *holdout_ids])
    if mode in DIAGNOSTIC_MODES:
        return None
    return train_ids


def prepared_current_rounds(
    expected_task_ids: list[str] | None,
    allowed_task_ids: list[str],
    subject_policy: dict,
    mode: str,
) -> list[dict]:
    expected_task_set = set(expected_task_ids) if expected_task_ids is not None else None
    allowed_task_set = set(allowed_task_ids)
    prepared = []
    for round_dir in sorted((EXPERIMENT_ROOT / "results").glob("round-*")):
        metadata_file = round_dir / "round-metadata.json"
        summary_file = round_dir / "round-summary.json"
        if not metadata_file.exists() or summary_file.exists():
            continue

        metadata = json.loads(metadata_file.read_text())
        if metadata.get("mode") != mode:
            continue
        if metadata.get("subject") != subject_policy:
            continue
        if metadata.get("judge") != CURRENT_JUDGE:
            continue
        metadata_task_set = set(metadata.get("task_ids", []))
        if expected_task_set is not None and metadata_task_set != expected_task_set:
            continue
        if expected_task_set is None and not metadata_task_set.issubset(allowed_task_set):
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
                "errors": errors,
                "warnings": report.get("warnings", []) if report else [],
            }
        )
    return sorted(prepared, key=lambda item: item["number"])


def status_paths(status_short: str) -> list[str]:
    paths = []
    for line in status_short.splitlines():
        if not line:
            continue
        paths.append(line[3:].strip())
    return paths


def status_only_expected_round_artifacts(
    status_short: str,
    prepared_round: dict | None,
) -> bool:
    if not status_short or not prepared_round:
        return False

    round_prefix = f"doc-experiment/results/{prepared_round['round']}/"
    for path in status_paths(status_short):
        if path == round_prefix.rstrip("/") or path.startswith(round_prefix):
            continue
        return False
    return True


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


def current_no_edit_baselines(
    rounds: list[dict],
    train_ids: list[str],
    subject_policy: dict,
) -> list[dict]:
    train_set = set(train_ids)
    baselines = []
    for round_info in rounds:
        metadata = round_info["metadata"]
        if not metadata:
            continue
        if metadata.get("mode") not in {"weak-tier-calibration", "scored-train"}:
            continue
        if metadata.get("subject") != subject_policy:
            continue
        if metadata.get("judge") != CURRENT_JUDGE:
            continue
        if set(metadata.get("task_ids", [])) != train_set:
            continue
        if set(round_info["task_ids"]) != train_set:
            continue

        report, errors = validate_round(round_info["round"])
        lifecycle = report.get("lifecycle") if report else "invalid"
        valid = lifecycle == "scored" and not errors
        baselines.append(
            {
                "round": round_info["round"],
                "number": round_info["number"],
                "score": round_info["score"],
                "by_split": round_info["by_split"],
                "lifecycle": lifecycle,
                "valid": valid,
                "errors": errors,
                "warnings": report.get("warnings", []) if report else [],
            }
        )
    return sorted(baselines, key=lambda item: item["number"])


def build_audit() -> dict:
    tasks = active_tasks()
    train_ids = sorted(task_id for task_id, task in tasks.items() if task["split"] == "train")
    holdout_ids = sorted(task_id for task_id, task in tasks.items() if task["split"] == "holdout")
    rounds = completed_rounds()
    latest = rounds[-1] if rounds else None
    latest_log_action = latest_log_next_action()
    latest_log_mode = mode_from_text(latest_log_action)
    active_subject, active_subject_reason = selected_subject(latest, latest_log_action)

    latest_commit = last_commit_for(latest["summary_file"]) if latest else None
    changed_since_latest = paths_changed_since(latest_commit) if latest_commit else []
    changed_groups = classify_paths(changed_since_latest)
    status_short = run_text(["git", "status", "--short"])

    latest_task_set = set(latest["task_ids"]) if latest else set()
    current_train_set = set(train_ids)
    current_all_set = set(tasks.keys())
    current_train_rounds = [
        item for item in rounds
        if set(item["task_ids"]) == current_train_set
    ]
    latest_current_train = current_train_rounds[-1] if current_train_rounds else None

    corpus_matches_latest_train = latest_task_set == current_train_set
    corpus_matches_latest_active = latest_task_set == current_all_set
    latest_is_diagnostic_subset = (
        latest is not None
        and latest.get("mode") in DIAGNOSTIC_MODES
        and latest_task_set.issubset(current_train_set)
    )
    latest_is_current_active_checkpoint = (
        latest is not None
        and latest.get("mode") == "checkpoint"
        and corpus_matches_latest_active
    )
    current_baselines = current_no_edit_baselines(rounds, train_ids, active_subject)
    current_baseline_exists = any(baseline["valid"] for baseline in current_baselines)
    prepared_mode = (
        latest_log_mode
        if latest_log_mode in PREPARABLE_MODES
        else "weak-tier-calibration"
    )
    expected_prepared_task_ids = expected_task_ids_for_mode(
        prepared_mode,
        train_ids,
        holdout_ids,
    )
    prepared_rounds = prepared_current_rounds(
        expected_prepared_task_ids,
        train_ids,
        active_subject,
        prepared_mode,
    )
    latest_prepared = prepared_rounds[-1] if prepared_rounds else None
    status_is_expected_round_artifacts = status_only_expected_round_artifacts(
        status_short,
        latest_prepared,
    )
    next_round_name = f"round-{(latest['number'] + 1) if latest else 1}"

    mismatches = []
    if status_short and not status_is_expected_round_artifacts:
        mismatches.append("worktree has local drift")
    if (
        latest
        and not corpus_matches_latest_train
        and not latest_is_diagnostic_subset
        and not latest_is_current_active_checkpoint
    ):
        mismatches.append("latest completed round task set differs from current train set")
    if changed_groups["source_docs"]:
        mismatches.append("source doc files changed since latest completed score")
    if changed_groups["tooling"]:
        mismatches.append("tooling changed since latest completed score")
    if changed_groups["corpus"]:
        mismatches.append("corpus changed since latest completed score")
    if not current_baseline_exists and not changed_groups["source_docs"]:
        mismatches.append("no current-corpus no-edit baseline for current subject/judge policy")

    next_action_commands = []
    if status_short and not status_is_expected_round_artifacts:
        next_action = "reconcile local worktree drift before scoring"
    elif latest_prepared and latest_prepared["errors"]:
        next_action = f"repair or restage {latest_prepared['round']} before launching agents"
    elif latest_prepared and latest_prepared["lifecycle"] == "prepared":
        next_action = (
            f"launch trials for prepared {latest_prepared['mode']} {latest_prepared['round']} "
            f"with {format_policy(active_subject)}; use the local Codex CLI runner when the "
            "Workflow UI runner is unavailable"
        )
        next_action_commands = [
            f"python3 doc-experiment/tools/run-codex-trials.py {latest_prepared['round']} "
            f"--output doc-experiment/results/{latest_prepared['round']}/codex-trials-output.json",
            f"python3 doc-experiment/tools/validate-workflow-output.py trials "
            f"doc-experiment/results/{latest_prepared['round']}/codex-trials-output.json "
            f"{latest_prepared['round']}",
            f"python3 doc-experiment/tools/ingest-trials.py "
            f"doc-experiment/results/{latest_prepared['round']}/codex-trials-output.json "
            f"{latest_prepared['round']}",
            f"python3 doc-experiment/tools/validate-round.py {latest_prepared['round']} "
            "--require-trials-complete",
        ]
    elif latest_prepared and latest_prepared["lifecycle"] == "trials-partial":
        next_action = f"complete missing trial artifacts for {latest_prepared['round']}"
    elif latest_prepared and latest_prepared["lifecycle"] == "trials-complete":
        next_action = f"run judges for {latest_prepared['round']} with gpt-5.5/xhigh/priority"
        next_action_commands = [
            f"python3 doc-experiment/tools/run-codex-judges.py {latest_prepared['round']} "
            f"--output doc-experiment/results/{latest_prepared['round']}/codex-judges-output.json",
            f"python3 doc-experiment/tools/validate-workflow-output.py judges "
            f"doc-experiment/results/{latest_prepared['round']}/codex-judges-output.json "
            f"{latest_prepared['round']}",
            f"python3 doc-experiment/tools/ingest-judges.py "
            f"doc-experiment/results/{latest_prepared['round']}/codex-judges-output.json "
            f"{latest_prepared['round']}",
            f"python3 doc-experiment/tools/validate-round.py {latest_prepared['round']} "
            "--require-scored",
        ]
    elif latest_prepared and latest_prepared["lifecycle"] == "judged":
        next_action = f"aggregate {latest_prepared['round']} and record the current-corpus baseline"
    elif changed_groups["source_docs"]:
        next_action = "prepare and run scored-train for the current source documentation hypothesis"
    elif not current_baseline_exists:
        next_action = (
            "prepare and run weak-tier-calibration no-edit baseline on current train corpus "
            f"with {format_policy(active_subject)}"
        )
        next_action_commands = [
            f"python3 doc-experiment/tools/prepare-round.py {next_round_name} "
            f"--mode weak-tier-calibration "
            f"--subject-model {active_subject['model']} "
            f"--subject-reasoning-effort {active_subject['reasoning_effort']} "
            f"--subject-service-tier {active_subject['service_tier']}",
        ]
    elif latest_log_mode == "checkpoint":
        next_action = latest_log_action
        next_action_commands = [
            f"python3 doc-experiment/tools/prepare-round.py {next_round_name} "
            f"--mode checkpoint "
            f"--subject-model {active_subject['model']} "
            f"--subject-reasoning-effort {active_subject['reasoning_effort']} "
            f"--subject-service-tier {active_subject['service_tier']}",
        ]
    elif (latest_is_diagnostic_subset or latest_is_current_active_checkpoint) and latest_log_action:
        next_action = latest_log_action
    elif latest_is_diagnostic_subset:
        next_action = (
            "analyze latest diagnostic subset result and update LOG/NEXT before "
            "source promotion or the next measurement"
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
            "mode": latest["mode"] if latest else None,
            "score": latest["score"] if latest else None,
            "by_split": latest["by_split"] if latest else {},
            "task_count": len(latest["task_ids"]) if latest else 0,
            "task_ids": latest["task_ids"] if latest else [],
            "summary_commit": latest_commit,
        },
        "latest_current_train_round": {
            "round": latest_current_train["round"] if latest_current_train else None,
            "mode": latest_current_train["mode"] if latest_current_train else None,
            "score": latest_current_train["score"] if latest_current_train else None,
            "by_split": latest_current_train["by_split"] if latest_current_train else {},
            "task_count": (
                len(latest_current_train["task_ids"]) if latest_current_train else 0
            ),
            "task_ids": latest_current_train["task_ids"] if latest_current_train else [],
        },
        "current_policy": {
            "subject": active_subject,
            "subject_reason": active_subject_reason,
            "judge": CURRENT_JUDGE,
        },
        "comparability": {
            "latest_tasks_match_current_train": corpus_matches_latest_train,
            "latest_tasks_match_current_active": corpus_matches_latest_active,
            "latest_is_diagnostic_subset": latest_is_diagnostic_subset,
            "latest_is_current_active_checkpoint": latest_is_current_active_checkpoint,
            "tasks_added_vs_latest": sorted(current_train_set - latest_task_set),
            "tasks_removed_vs_latest": sorted(latest_task_set - current_train_set),
            "current_no_edit_baseline_exists": current_baseline_exists,
            "current_no_edit_baselines": current_baselines,
            "prepared_current_round": latest_prepared,
            "prepared_mode": prepared_mode,
            "prepared_task_count": (
                len(expected_prepared_task_ids)
                if expected_prepared_task_ids is not None
                else None
            ),
            "status_is_expected_round_artifacts": status_is_expected_round_artifacts,
            "changed_since_latest_summary_commit": changed_groups,
        },
        "mismatches": mismatches,
        "next_action": next_action,
        "next_action_commands": next_action_commands,
    }


def print_text(audit: dict) -> None:
    latest = audit["latest_completed_round"]
    latest_train = audit["latest_current_train_round"]
    print("HTML API docs experiment state")
    print(f"- git head: {audit['git']['head']}")
    print(f"- worktree: {'dirty' if audit['git']['status_short'] else 'clean'}")
    print(
        f"- active corpus: {audit['active_corpus']['train_count']} train, "
        f"{audit['active_corpus']['holdout_count']} holdout"
    )
    print(
        "- selected subject policy: "
        f"{format_policy(audit['current_policy']['subject'])} "
        f"({audit['current_policy']['subject_reason']})"
    )
    print(
        f"- latest completed round: {latest['round']} mode {latest['mode']} "
        f"score {latest['score']} "
        f"split {latest['by_split']}"
    )
    if latest_train["round"] and latest_train["round"] != latest["round"]:
        print(
            f"- latest current-train round: {latest_train['round']} "
            f"mode {latest_train['mode']} score {latest_train['score']} "
            f"split {latest_train['by_split']}"
        )
    print(
        "- latest round matches current train: "
        f"{audit['comparability']['latest_tasks_match_current_train']}"
    )
    print(
        "- latest round matches current active corpus: "
        f"{audit['comparability']['latest_tasks_match_current_active']}"
    )
    if audit["comparability"].get("latest_is_diagnostic_subset"):
        print("- latest round is a diagnostic subset; not treated as corpus drift")
    if audit["comparability"].get("latest_is_current_active_checkpoint"):
        print("- latest round is a checkpoint; held-out tasks are expected")
    print(
        "- current no-edit baseline exists for current subject/judge policy: "
        f"{audit['comparability']['current_no_edit_baseline_exists']}"
    )
    current_baselines = audit["comparability"].get("current_no_edit_baselines", [])
    for baseline in current_baselines:
        status = "valid" if baseline["valid"] else "invalid"
        print(
            f"- current no-edit baseline candidate: {baseline['round']} {status} "
            f"score {baseline['score']}"
        )
        if baseline["errors"]:
            print("  errors:")
            for error in baseline["errors"]:
                print(f"  - {error}")
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
    if audit["next_action_commands"]:
        print("- next action commands:")
        for command in audit["next_action_commands"]:
            print(f"  - {command}")


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
