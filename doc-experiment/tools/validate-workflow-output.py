#!/usr/bin/env python3
"""Validate workflow output JSON before ingesting it into round results.

Trial workflow output must include a subject_isolation attestation alongside
its result array so scored artifacts record the enforced tool boundary.
"""

import argparse
import json
import sys
from pathlib import Path


EXPERIMENT_ROOT = Path(__file__).resolve().parent.parent


def metadata(round_name: str) -> dict:
    metadata_file = EXPERIMENT_ROOT / "results" / round_name / "round-metadata.json"
    if not metadata_file.exists():
        raise FileNotFoundError(f"missing round metadata: {metadata_file}")
    return json.loads(metadata_file.read_text())


def load_result(output_file: Path) -> list[dict]:
    payload = load_payload(output_file)
    return result_from_payload(payload)


def load_payload(output_file: Path) -> dict:
    payload = json.loads(output_file.read_text())
    if not isinstance(payload, dict):
        raise ValueError("workflow output must be an object with a result array")
    return payload


def result_from_payload(payload: dict) -> list[dict]:
    result = payload.get("result")
    if not isinstance(result, list):
        raise ValueError("workflow output must contain a result array")
    return result


def trial_payload_from_output(payload: dict) -> dict:
    result = payload.get("result")
    if isinstance(result, dict) and "subject_isolation" in result and "result" in result:
        return result
    return payload


def validate_isolated_workdir_attestation(attestation: dict) -> list[str]:
    errors = []

    expected_values = {
        "agent_type": "codex-cli-isolated-workdir",
        "runner": "codex exec",
        "input_delivery": "prompt-embedded-docs",
        "sandbox_mode": "read-only",
        "approval_policy": "never",
        "project_rules_loaded": False,
        "user_config_loaded": False,
        "repo_available_to_subject": False,
    }
    for key, expected in expected_values.items():
        if attestation.get(key) != expected:
            errors.append(f"subject_isolation.{key} must be {expected!r}")

    input_files = attestation.get("input_files")
    if not isinstance(input_files, list):
        errors.append("subject_isolation.input_files must list isolated input files")
    elif sorted(input_files) != ["html-processor.md", "html-tag-processor.md", "task.md"]:
        errors.append(
            "subject_isolation.input_files must be exactly html-processor.md, "
            "html-tag-processor.md, and task.md"
        )

    work_root = attestation.get("work_root")
    if not isinstance(work_root, str) or not work_root.strip():
        errors.append("subject_isolation.work_root must be a non-empty string")

    notes = attestation.get("equivalent_boundary_notes")
    if not isinstance(notes, str) or not notes.strip():
        errors.append(
            "subject_isolation.equivalent_boundary_notes must explain isolated-workdir mode"
        )

    return errors


def validate_subject_isolation(payload: dict) -> list[str]:
    attestation = payload.get("subject_isolation")
    if not isinstance(attestation, dict):
        return ["subject_isolation must be an object"]

    errors = []
    if attestation.get("enforced") is not True:
        errors.append("subject_isolation.enforced must be true")

    isolation_mode = attestation.get("isolation_mode", "read-grep-tool-boundary")
    if isolation_mode == "isolated-workdir":
        errors.extend(validate_isolated_workdir_attestation(attestation))
    elif isolation_mode == "read-grep-tool-boundary":
        agent_type = attestation.get("agent_type")
        if not isinstance(agent_type, str) or not agent_type.strip():
            errors.append("subject_isolation.agent_type must be a non-empty string")

        allowed_tools = attestation.get("allowed_tools")
        if not isinstance(allowed_tools, list):
            errors.append("subject_isolation.allowed_tools must be exactly Read and Grep")
        elif any(not isinstance(tool, str) for tool in allowed_tools):
            errors.append("subject_isolation.allowed_tools entries must be strings")
        elif sorted(allowed_tools) != ["Grep", "Read"]:
            errors.append("subject_isolation.allowed_tools must be exactly Read and Grep")

        if isinstance(agent_type, str) and agent_type.strip() != "docs-test-subject":
            notes = attestation.get("equivalent_boundary_notes")
            if not isinstance(notes, str) or not notes.strip():
                errors.append(
                    "subject_isolation.equivalent_boundary_notes must explain "
                    "non-standard agent type"
                )
    else:
        errors.append(
            "subject_isolation.isolation_mode must be read-grep-tool-boundary "
            "or isolated-workdir"
        )

    notes = attestation.get("notes")
    if notes is not None and (not isinstance(notes, str) or not notes.strip()):
        errors.append("subject_isolation.notes must be a non-empty string when present")

    return errors


def validate_coverage(
    entries: list[dict],
    expected_ids: set[str],
    label: str,
) -> list[str]:
    errors = []
    ids = []
    for index, entry in enumerate(entries):
        if not isinstance(entry, dict):
            errors.append(f"entry {index}: {label} entry must be an object")
            continue
        ids.append(entry.get("id"))
    duplicates = sorted({entry_id for entry_id in ids if ids.count(entry_id) > 1})
    if duplicates:
        errors.append(f"duplicate {label}: " + ", ".join(str(item) for item in duplicates))
    missing = sorted(expected_ids - set(ids))
    unexpected = sorted(set(ids) - expected_ids)
    if missing:
        errors.append(f"missing {label}: " + ", ".join(missing))
    if unexpected:
        errors.append(f"unexpected {label}: " + ", ".join(str(item) for item in unexpected))
    return errors


def validate_trials(entries: list[dict], meta: dict) -> list[str]:
    expected_tasks = set(meta.get("task_ids", []))
    expected_trials = int(meta.get("trials_per_task", 0))
    expected_pairs = {
        (task_id, trial)
        for task_id in expected_tasks
        for trial in range(1, expected_trials + 1)
    }
    errors = []
    seen_pairs = []

    for index, entry in enumerate(entries):
        if not isinstance(entry, dict):
            errors.append(f"entry {index}: trial result must be an object")
            continue
        task_id = entry.get("id")
        trial = entry.get("trial")
        if task_id not in expected_tasks:
            errors.append(f"entry {index}: unexpected task id {task_id!r}")
        if not isinstance(trial, int):
            errors.append(f"entry {index}: trial must be an integer")
            continue
        if trial < 1 or trial > expected_trials:
            errors.append(f"entry {index}: unexpected trial number {trial}")
        seen_pairs.append((task_id, trial))

        ok = entry.get("ok")
        if ok is not None and not isinstance(ok, bool):
            errors.append(f"{task_id}/trial-{trial}: ok must be boolean when present")
        code = entry.get("code")
        if not isinstance(code, str) or not code.strip():
            errors.append(f"{task_id}/trial-{trial}: code must be a non-empty string")
        elif not code.lstrip().startswith("<?php"):
            errors.append(f"{task_id}/trial-{trial}: code must start with <?php")
        explanation = entry.get("explanation")
        if not isinstance(explanation, str) or not explanation.strip():
            errors.append(
                f"{task_id}/trial-{trial}: explanation must be a non-empty string"
            )
        confidence = entry.get("confidence")
        if not isinstance(confidence, int) or confidence < 0 or confidence > 100:
            errors.append(f"{task_id}/trial-{trial}: confidence must be integer 0-100")

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


def validate_judge_verdict(entry: dict, expected_trials: int) -> list[str]:
    task_id = entry.get("id")
    verdict = entry.get("verdict")
    if not isinstance(verdict, dict):
        return [f"{task_id}: verdict must be an object"]

    errors = []
    trials = verdict.get("trials")
    if not isinstance(trials, list):
        errors.append(f"{task_id}: verdict.trials must be an array")
        trials = []

    expected_trial_ids = {f"trial-{i}" for i in range(1, expected_trials + 1)}
    actual_trial_ids = []
    for trial in trials:
        trial_id = trial.get("trial_id") if isinstance(trial, dict) else None
        actual_trial_ids.append(trial_id)
        if not isinstance(trial, dict):
            errors.append(f"{task_id}: trial verdict must be an object")
            continue
        adherence = trial.get("adherence")
        if trial_id not in expected_trial_ids:
            errors.append(f"{task_id}: unexpected trial_id {trial_id!r}")
        if not isinstance(adherence, int) or adherence < 0 or adherence > 100:
            errors.append(f"{task_id}/{trial_id}: adherence must be integer 0-100")
        if not isinstance(trial.get("hallucinated_methods"), list):
            errors.append(f"{task_id}/{trial_id}: hallucinated_methods must be an array")
        else:
            for index, method in enumerate(trial.get("hallucinated_methods")):
                if not isinstance(method, str):
                    errors.append(
                        f"{task_id}/{trial_id}: hallucinated_methods[{index}] "
                        "must be a string"
                    )
        notes = trial.get("notes")
        if not isinstance(notes, str) or not notes.strip():
            errors.append(f"{task_id}/{trial_id}: notes must be a non-empty string")

    missing_trials = sorted(expected_trial_ids - set(actual_trial_ids))
    duplicate_trials = sorted({
        trial_id for trial_id in actual_trial_ids if actual_trial_ids.count(trial_id) > 1
    })
    if missing_trials:
        errors.append(f"{task_id}: missing trial verdicts: " + ", ".join(missing_trials))
    if duplicate_trials:
        errors.append(f"{task_id}: duplicate trial verdicts: " + ", ".join(duplicate_trials))

    failure_analysis = verdict.get("failure_analysis")
    if not isinstance(failure_analysis, str) or not failure_analysis.strip():
        errors.append(f"{task_id}: failure_analysis must be a non-empty string")
    doc_gaps = verdict.get("doc_gaps")
    if not isinstance(doc_gaps, list):
        errors.append(f"{task_id}: doc_gaps must be an array")
    else:
        for index, gap in enumerate(doc_gaps):
            if not isinstance(gap, dict):
                errors.append(f"{task_id}: doc_gaps[{index}] must be an object")
                continue
            for key in ("location", "problem", "suggestion"):
                if not isinstance(gap.get(key), str) or not gap.get(key).strip():
                    errors.append(
                        f"{task_id}: doc_gaps[{index}].{key} must be a non-empty string"
                    )

    return errors


def validate_judges(entries: list[dict], meta: dict) -> list[str]:
    expected_tasks = set(meta.get("task_ids", []))
    expected_trials = int(meta.get("trials_per_task", 0))
    errors = validate_coverage(entries, expected_tasks, "judge verdicts")
    for entry in entries:
        if isinstance(entry, dict) and entry.get("id") in expected_tasks:
            errors.extend(validate_judge_verdict(entry, expected_trials))
    return errors


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("phase", choices=["trials", "judges"])
    parser.add_argument("output_file", type=Path)
    parser.add_argument("round", help="Round name, e.g. round-18")
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args()

    meta = metadata(args.round)
    payload = load_payload(args.output_file)
    if args.phase == "trials":
        payload = trial_payload_from_output(payload)
    entries = result_from_payload(payload)
    errors = (
        [*validate_subject_isolation(payload), *validate_trials(entries, meta)]
        if args.phase == "trials"
        else validate_judges(entries, meta)
    )

    report = {
        "ok": not errors,
        "phase": args.phase,
        "round": args.round,
        "entries": len(entries),
        "errors": errors,
    }
    if args.json:
        print(json.dumps(report, indent=2))
    elif errors:
        for error in errors:
            print(f"ERROR: {error}", file=sys.stderr)
    else:
        print(f"OK: {args.phase} workflow output has {len(entries)} expected entries.")

    return 0 if not errors else 1


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as exc:
        print(f"validate-workflow-output.py: {exc}", file=sys.stderr)
        sys.exit(1)
