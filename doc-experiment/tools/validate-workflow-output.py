#!/usr/bin/env python3
"""Validate workflow output JSON before ingesting it into round results."""

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
    payload = json.loads(output_file.read_text())
    if not isinstance(payload, dict):
        raise ValueError("workflow output must be an object with a result array")
    result = payload.get("result")
    if not isinstance(result, list):
        raise ValueError("workflow output must contain a result array")
    return result


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
    entries = load_result(args.output_file)
    errors = validate_trials(entries, meta) if args.phase == "trials" else validate_judges(entries, meta)

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
