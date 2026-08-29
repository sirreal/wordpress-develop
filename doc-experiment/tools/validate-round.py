#!/usr/bin/env python3
"""Validate a prepared or scored experiment round.

The validator distinguishes round lifecycle states:

- prepared: metadata exists, scratch isolation passes, no trials yet.
- trials-complete: every expected candidate/response/execution exists.
- judged: trials are complete and every expected judge.json exists.
- scored: judged plus round-summary.json exists and matches expected tasks.

It is read-only and does not execute candidates. For metadata-backed scored
rounds, it recomputes aggregate scores to verify the persisted summary.
"""

import argparse
import hashlib
import json
import subprocess
import sys
from pathlib import Path


EXPERIMENT_ROOT = Path(__file__).resolve().parent.parent
REPO_ROOT = EXPERIMENT_ROOT.parent


def round_dir(name: str) -> Path:
    return EXPERIMENT_ROOT / "results" / name.removeprefix("doc-experiment/results/")


def load_json(path: Path) -> dict:
    return json.loads(path.read_text())


def expected_from_metadata(results_dir: Path) -> tuple[dict | None, list[str], int | None]:
    metadata_file = results_dir / "round-metadata.json"
    if not metadata_file.exists():
        return None, [], None
    metadata = load_json(metadata_file)
    return metadata, list(metadata.get("task_ids", [])), metadata.get("trials_per_task")


def expected_from_summary(results_dir: Path) -> tuple[dict | None, list[str], int | None]:
    summary_file = results_dir / "round-summary.json"
    if not summary_file.exists():
        return None, [], None
    summary = load_json(summary_file)
    task_ids = sorted(summary.get("tasks", {}).keys())
    trial_count = None
    if task_ids:
        counts = {
            len(task.get("trials", []))
            for task in summary.get("tasks", {}).values()
        }
        if len(counts) == 1:
            trial_count = counts.pop()
    return summary, task_ids, trial_count


def validate_scratch(metadata: dict | None) -> list[str]:
    if not metadata:
        return []
    scratch = metadata.get("scratch")
    if not scratch:
        return ["metadata has no scratch path"]
    scratch_dir = Path(scratch)
    expected_files = {
        "html-tag-processor.md",
        "html-processor.md",
        *metadata.get("staged_task_files", []),
    }
    actual_files = {
        path.relative_to(scratch_dir).as_posix()
        for path in scratch_dir.rglob("*")
        if path.is_file()
    } if scratch_dir.exists() else set()
    errors = []
    if not scratch_dir.exists():
        errors.append(f"scratch directory missing: {scratch_dir}")
    missing = sorted(expected_files - actual_files)
    unexpected = sorted(actual_files - expected_files)
    if missing:
        errors.append("scratch missing expected files: " + ", ".join(missing))
    if unexpected:
        errors.append("scratch has unexpected files: " + ", ".join(unexpected))

    expected_hashes = metadata.get("scratch_file_sha256", {})
    if expected_hashes:
        for relpath, expected_hash in sorted(expected_hashes.items()):
            path = scratch_dir / relpath
            if not path.exists() or not path.is_file():
                continue
            actual_hash = hashlib.sha256(path.read_bytes()).hexdigest()
            if actual_hash != expected_hash:
                errors.append(
                    f"scratch hash mismatch for {relpath}: "
                    f"expected {expected_hash}, got {actual_hash}"
                )
    return errors


def validate_source_digests(metadata: dict | None) -> list[str]:
    if not metadata or not metadata.get("source_file_digests"):
        return []

    recorded = metadata["source_file_digests"]
    errors = []

    def read_source_digests(ref: str | None = None) -> tuple[dict | None, list[str]]:
        command = [
            "php",
            str(EXPERIMENT_ROOT / "tools" / "source-digests.php"),
            "--json",
        ]
        if ref and ref != "working-tree":
            command.extend(["--ref", ref])

        proc = subprocess.run(
            command,
            cwd=REPO_ROOT,
            text=True,
            capture_output=True,
            check=False,
        )
        if proc.returncode != 0:
            message = (proc.stderr or proc.stdout).strip()
            return None, [f"source digest verification failed: {message}"]
        return json.loads(proc.stdout), []

    def compare_source_digests(actual: dict, context: str) -> list[str]:
        comparison_errors = []
        if recorded.get("algorithm") != actual.get("algorithm"):
            comparison_errors.append(
                f"{context} source digest algorithm mismatch: "
                f"expected {recorded.get('algorithm')}, got {actual.get('algorithm')}"
            )

        recorded_files = recorded.get("files", {})
        actual_files = actual.get("files", {})
        missing = sorted(set(recorded_files) - set(actual_files))
        unexpected = sorted(set(actual_files) - set(recorded_files))
        if missing:
            comparison_errors.append(
                f"{context} source digest missing files: " + ", ".join(missing)
            )
        if unexpected:
            comparison_errors.append(
                f"{context} source digest unexpected files: " + ", ".join(unexpected)
            )

        for file, recorded_digests in sorted(recorded_files.items()):
            actual_digests = actual_files.get(file)
            if not actual_digests:
                continue
            for key in (
                "source_sha256",
                "php_without_comments_sha256",
                "php_without_comments_token_count",
            ):
                if recorded_digests.get(key) != actual_digests.get(key):
                    comparison_errors.append(
                        f"{context} source digest mismatch for {file} {key}: "
                        f"expected {recorded_digests.get(key)}, got {actual_digests.get(key)}"
                    )
        return comparison_errors

    ref = recorded.get("ref")
    if ref and ref != "working-tree":
        ref_digests, ref_errors = read_source_digests(ref)
        errors.extend(ref_errors)
        if ref_digests:
            errors.extend(compare_source_digests(ref_digests, f"recorded ref {ref}"))

    current_digests, current_errors = read_source_digests()
    errors.extend(current_errors)
    if current_digests:
        errors.extend(compare_source_digests(current_digests, "current worktree"))
    return errors


def validate_corpus_digests(metadata: dict | None) -> list[str]:
    if not metadata or not metadata.get("corpus_file_digests"):
        return []

    recorded = metadata["corpus_file_digests"]
    errors = []
    if recorded.get("algorithm") != "sha256":
        errors.append(
            "corpus digest algorithm mismatch: "
            f"expected sha256, got {recorded.get('algorithm')}"
        )

    recorded_tasks = recorded.get("tasks")
    if not isinstance(recorded_tasks, dict):
        return [*errors, "corpus digests tasks must be an object"]

    expected_tasks = set(metadata.get("task_ids", []))
    missing_tasks = sorted(expected_tasks - set(recorded_tasks))
    unexpected_tasks = sorted(set(recorded_tasks) - expected_tasks)
    if missing_tasks:
        errors.append("corpus digests missing tasks: " + ", ".join(missing_tasks))
    if unexpected_tasks:
        errors.append("corpus digests unexpected tasks: " + ", ".join(unexpected_tasks))

    for task_id, task_record in sorted(recorded_tasks.items()):
        if not isinstance(task_record, dict):
            errors.append(f"{task_id}: corpus digest record must be an object")
            continue
        files = task_record.get("files")
        if not isinstance(files, dict):
            errors.append(f"{task_id}: corpus digest files must be an object")
            continue
        expected_files = {
            f"doc-experiment/corpus/{task_id}/task.md",
            f"doc-experiment/corpus/{task_id}/reference.php",
            f"doc-experiment/corpus/{task_id}/tests.json",
        }
        missing_files = sorted(expected_files - set(files))
        unexpected_files = sorted(set(files) - expected_files)
        if missing_files:
            errors.append(f"{task_id}: corpus digest missing files: " + ", ".join(missing_files))
        if unexpected_files:
            errors.append(f"{task_id}: corpus digest unexpected files: " + ", ".join(unexpected_files))
        for relpath, expected_hash in sorted(files.items()):
            path = REPO_ROOT / relpath
            if not path.exists() or not path.is_file():
                errors.append(f"{task_id}: corpus file missing: {relpath}")
                continue
            actual_hash = hashlib.sha256(path.read_bytes()).hexdigest()
            if actual_hash != expected_hash:
                errors.append(
                    f"{task_id}: corpus hash mismatch for {relpath}: "
                    f"expected {expected_hash}, got {actual_hash}"
                )

    return errors


def validate_isolated_workdir_attestation(attestation: dict, context: str) -> list[str]:
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
            errors.append(f"{context}: subject isolation {key} must be {expected!r}")

    input_files = attestation.get("input_files")
    if not isinstance(input_files, list):
        errors.append(f"{context}: subject isolation input_files must be a list")
    elif sorted(input_files) != ["html-processor.md", "html-tag-processor.md", "task.md"]:
        errors.append(
            f"{context}: subject isolation input_files must be exactly "
            "html-processor.md, html-tag-processor.md, and task.md"
        )

    work_root = attestation.get("work_root")
    if not isinstance(work_root, str) or not work_root.strip():
        errors.append(f"{context}: subject isolation work_root must be a non-empty string")

    notes = attestation.get("equivalent_boundary_notes")
    if not isinstance(notes, str) or not notes.strip():
        errors.append(
            f"{context}: subject isolation equivalent_boundary_notes must explain "
            "isolated-workdir mode"
        )

    return errors


def validate_subject_isolation_attestation(attestation: dict, context: str) -> list[str]:
    if not isinstance(attestation, dict):
        return [f"{context}: subject isolation attestation must be an object"]

    errors = []
    if attestation.get("enforced") is not True:
        errors.append(f"{context}: subject isolation enforced must be true")

    isolation_mode = attestation.get("isolation_mode", "read-grep-tool-boundary")
    if isolation_mode == "isolated-workdir":
        errors.extend(validate_isolated_workdir_attestation(attestation, context))
    elif isolation_mode == "read-grep-tool-boundary":
        agent_type = attestation.get("agent_type")
        if not isinstance(agent_type, str) or not agent_type.strip():
            errors.append(f"{context}: subject isolation agent_type must be a non-empty string")

        allowed_tools = attestation.get("allowed_tools")
        if not isinstance(allowed_tools, list):
            errors.append(
                f"{context}: subject isolation allowed_tools must be exactly Read and Grep"
            )
        elif any(not isinstance(tool, str) for tool in allowed_tools):
            errors.append(f"{context}: subject isolation allowed_tools entries must be strings")
        elif sorted(allowed_tools) != ["Grep", "Read"]:
            errors.append(
                f"{context}: subject isolation allowed_tools must be exactly Read and Grep"
            )

        if isinstance(agent_type, str) and agent_type.strip() != "docs-test-subject":
            notes = attestation.get("equivalent_boundary_notes")
            if not isinstance(notes, str) or not notes.strip():
                errors.append(
                    f"{context}: subject isolation equivalent_boundary_notes must explain "
                    "non-standard agent type"
                )
    else:
        errors.append(
            f"{context}: subject isolation isolation_mode must be read-grep-tool-boundary "
            "or isolated-workdir"
        )

    notes = attestation.get("notes")
    if notes is not None and (not isinstance(notes, str) or not notes.strip()):
        errors.append(f"{context}: subject isolation notes must be a non-empty string when present")

    return errors


def validate_subject_isolation_artifact(results_dir: Path) -> list[str]:
    attestation_file = results_dir / "subject-isolation.json"
    if not attestation_file.exists():
        return ["subject-isolation.json is missing for present trial artifacts"]

    try:
        attestation = json.loads(attestation_file.read_text())
    except json.JSONDecodeError as exc:
        return [f"subject-isolation.json is invalid JSON: {exc}"]

    return validate_subject_isolation_attestation(attestation, "subject-isolation.json")


def validate_trial_artifacts(trial_dir: Path) -> list[str]:
    errors = []
    candidate_file = trial_dir / "candidate.php"
    response_file = trial_dir / "response.json"
    execution_file = trial_dir / "execution.json"

    candidate = candidate_file.read_text()
    if not candidate.strip():
        errors.append(f"{trial_dir.parent.name}/{trial_dir.name}: candidate.php is empty")
    if not candidate.lstrip().startswith("<?php"):
        errors.append(
            f"{trial_dir.parent.name}/{trial_dir.name}: candidate.php must start with <?php"
        )

    try:
        response = json.loads(response_file.read_text())
    except json.JSONDecodeError as exc:
        errors.append(
            f"{trial_dir.parent.name}/{trial_dir.name}: response.json is invalid JSON: {exc}"
        )
        response = None
    if isinstance(response, dict):
        if response.get("ok") is not None and not isinstance(response.get("ok"), bool):
            errors.append(f"{trial_dir.parent.name}/{trial_dir.name}: response ok must be boolean")
        explanation = response.get("explanation")
        if not isinstance(explanation, str) or not explanation.strip():
            errors.append(
                f"{trial_dir.parent.name}/{trial_dir.name}: response explanation must be non-empty"
            )
        confidence = response.get("confidence")
        if not isinstance(confidence, int) or confidence < 0 or confidence > 100:
            errors.append(
                f"{trial_dir.parent.name}/{trial_dir.name}: response confidence must be integer 0-100"
            )
    elif response is not None:
        errors.append(f"{trial_dir.parent.name}/{trial_dir.name}: response.json must be an object")

    try:
        execution = json.loads(execution_file.read_text())
    except json.JSONDecodeError as exc:
        errors.append(
            f"{trial_dir.parent.name}/{trial_dir.name}: execution.json is invalid JSON: {exc}"
        )
        execution = None
    if isinstance(execution, dict):
        passed = execution.get("passed")
        total = execution.get("total")
        if not isinstance(passed, int) or passed < 0:
            errors.append(
                f"{trial_dir.parent.name}/{trial_dir.name}: execution passed must be a non-negative integer"
            )
        if not isinstance(total, int) or total < 1:
            errors.append(
                f"{trial_dir.parent.name}/{trial_dir.name}: execution total must be a positive integer"
            )
        if isinstance(passed, int) and isinstance(total, int) and passed > total:
            errors.append(
                f"{trial_dir.parent.name}/{trial_dir.name}: execution passed exceeds total"
            )
        if not isinstance(execution.get("cases"), list):
            errors.append(
                f"{trial_dir.parent.name}/{trial_dir.name}: execution cases must be an array"
            )
    elif execution is not None:
        errors.append(f"{trial_dir.parent.name}/{trial_dir.name}: execution.json must be an object")

    return errors


def validate_judge_artifact(judge_file: Path, expected_trials: int) -> list[str]:
    task_id = judge_file.parent.name
    try:
        verdict = json.loads(judge_file.read_text())
    except json.JSONDecodeError as exc:
        return [f"{task_id}: judge.json is invalid JSON: {exc}"]

    if not isinstance(verdict, dict):
        return [f"{task_id}: judge.json must be an object"]

    errors = []
    trials = verdict.get("trials")
    if not isinstance(trials, list):
        errors.append(f"{task_id}: judge.json trials must be an array")
        trials = []

    expected_trial_ids = {f"trial-{i}" for i in range(1, expected_trials + 1)}
    actual_trial_ids = []
    for trial in trials:
        trial_id = trial.get("trial_id") if isinstance(trial, dict) else None
        actual_trial_ids.append(trial_id)
        if not isinstance(trial, dict):
            errors.append(f"{task_id}: judge trial verdict must be an object")
            continue
        if trial_id not in expected_trial_ids:
            errors.append(f"{task_id}: unexpected judge trial_id {trial_id!r}")
        adherence = trial.get("adherence")
        if not isinstance(adherence, int) or adherence < 0 or adherence > 100:
            errors.append(f"{task_id}/{trial_id}: judge adherence must be integer 0-100")
        hallucinated_methods = trial.get("hallucinated_methods")
        if not isinstance(hallucinated_methods, list):
            errors.append(f"{task_id}/{trial_id}: judge hallucinated_methods must be an array")
        else:
            for index, method in enumerate(hallucinated_methods):
                if not isinstance(method, str):
                    errors.append(
                        f"{task_id}/{trial_id}: judge hallucinated_methods[{index}] "
                        "must be a string"
                    )
        notes = trial.get("notes")
        if not isinstance(notes, str) or not notes.strip():
            errors.append(f"{task_id}/{trial_id}: judge notes must be a non-empty string")

    missing_trials = sorted(expected_trial_ids - set(actual_trial_ids))
    duplicate_trials = sorted({
        trial_id for trial_id in actual_trial_ids if actual_trial_ids.count(trial_id) > 1
    })
    if missing_trials:
        errors.append(f"{task_id}: missing judge trial verdicts: " + ", ".join(missing_trials))
    if duplicate_trials:
        errors.append(f"{task_id}: duplicate judge trial verdicts: " + ", ".join(duplicate_trials))

    failure_analysis = verdict.get("failure_analysis")
    if not isinstance(failure_analysis, str) or not failure_analysis.strip():
        errors.append(f"{task_id}: judge failure_analysis must be a non-empty string")
    doc_gaps = verdict.get("doc_gaps")
    if not isinstance(doc_gaps, list):
        errors.append(f"{task_id}: judge doc_gaps must be an array")
    else:
        for index, gap in enumerate(doc_gaps):
            if not isinstance(gap, dict):
                errors.append(f"{task_id}: judge doc_gaps[{index}] must be an object")
                continue
            for key in ("location", "problem", "suggestion"):
                if not isinstance(gap.get(key), str) or not gap.get(key).strip():
                    errors.append(
                        f"{task_id}: judge doc_gaps[{index}].{key} must be a non-empty string"
                    )

    return errors


def validate_summary_reproducibility(results_dir: Path, summary: dict) -> list[str]:
    proc = subprocess.run(
        [
            "python3",
            str(EXPERIMENT_ROOT / "tools" / "aggregate-round.py"),
            str(results_dir),
        ],
        cwd=REPO_ROOT,
        text=True,
        capture_output=True,
        check=False,
    )
    if proc.returncode != 0:
        message = (proc.stderr or proc.stdout).strip()
        return [f"round-summary reproducibility failed: {message}"]

    try:
        expected = json.loads(proc.stdout)
    except json.JSONDecodeError as exc:
        return [f"round-summary reproducibility produced invalid JSON: {exc}"]

    errors = []
    keys = (
        "round_score",
        "core_score",
        "by_split",
        "by_concept",
        "tasks",
        "round_metadata",
        "subject_isolation",
    )
    for key in keys:
        if summary.get(key) != expected.get(key):
            errors.append(f"round-summary mismatch for {key}")
    return errors


def validate_round(results_dir: Path) -> dict:
    metadata, metadata_tasks, metadata_trials = expected_from_metadata(results_dir)
    summary, summary_tasks, summary_trials = expected_from_summary(results_dir)

    expected_tasks = metadata_tasks or summary_tasks
    expected_trials = metadata_trials or summary_trials or 3
    errors = []
    warnings = []

    if not results_dir.exists():
        errors.append(f"results directory missing: {results_dir}")
    if not metadata and not summary:
        errors.append("missing both round-metadata.json and round-summary.json")

    if metadata and metadata.get("task_count") != len(metadata_tasks):
        errors.append("metadata task_count does not match task_ids length")
    if summary and metadata_tasks and set(summary_tasks) != set(metadata_tasks):
        errors.append("round-summary task set does not match metadata task_ids")

    errors.extend(validate_scratch(metadata))
    errors.extend(validate_source_digests(metadata))
    errors.extend(validate_corpus_digests(metadata))

    task_status = {}
    total_trials = 0
    present_trial_artifacts = 0
    complete_trials = 0
    tasks_with_all_trials = 0
    tasks_with_judges = 0

    for task_id in expected_tasks:
        task_dir = results_dir / task_id
        expected_trial_names = [f"trial-{i}" for i in range(1, expected_trials + 1)]
        missing_trials = []
        incomplete_trials = []
        invalid_trials = []
        present_trials = []

        for trial_name in expected_trial_names:
            total_trials += 1
            trial_dir = task_dir / trial_name
            files = {
                "candidate.php": trial_dir / "candidate.php",
                "response.json": trial_dir / "response.json",
                "execution.json": trial_dir / "execution.json",
            }
            if not trial_dir.exists():
                missing_trials.append(trial_name)
                continue
            present_trial_artifacts += 1
            present_trials.append(trial_name)
            missing_files = [name for name, path in files.items() if not path.exists()]
            if missing_files:
                incomplete_trials.append(
                    {"trial": trial_name, "missing_files": missing_files}
                )
            else:
                trial_errors = validate_trial_artifacts(trial_dir)
                if trial_errors:
                    errors.extend(trial_errors)
                    invalid_trials.append(trial_name)
                else:
                    complete_trials += 1

        judge_file = task_dir / "judge.json"
        has_judge = judge_file.exists()
        valid_judge = False
        if has_judge:
            judge_errors = validate_judge_artifact(judge_file, expected_trials)
            if judge_errors:
                errors.extend(judge_errors)
            else:
                tasks_with_judges += 1
                valid_judge = True

        if not missing_trials and not incomplete_trials and not invalid_trials:
            tasks_with_all_trials += 1

        task_status[task_id] = {
            "present_trials": present_trials,
            "missing_trials": missing_trials,
            "incomplete_trials": incomplete_trials,
            "invalid_trials": invalid_trials,
            "has_judge": has_judge,
            "valid_judge": valid_judge,
        }

    has_trials = present_trial_artifacts > 0
    trials_complete = bool(expected_tasks) and complete_trials == total_trials
    judged = trials_complete and tasks_with_judges == len(expected_tasks)
    scored = judged and (results_dir / "round-summary.json").exists()

    if summary and not judged:
        errors.append("round-summary.json exists before all trials are judged")
    if has_trials and not trials_complete:
        warnings.append("some trial files are missing or incomplete")
    if metadata and has_trials:
        errors.extend(validate_subject_isolation_artifact(results_dir))
    if trials_complete and not judged:
        warnings.append("trials are complete but one or more judge.json files are missing")
    if judged and not scored:
        warnings.append("judges are complete but round-summary.json is missing")
    if metadata and scored and not errors:
        errors.extend(validate_summary_reproducibility(results_dir, summary))

    if scored:
        lifecycle = "scored"
    elif judged:
        lifecycle = "judged"
    elif trials_complete:
        lifecycle = "trials-complete"
    elif has_trials:
        lifecycle = "trials-partial"
    elif metadata:
        lifecycle = "prepared"
    else:
        lifecycle = "unknown"

    return {
        "round": results_dir.name,
        "lifecycle": lifecycle,
        "expected_task_count": len(expected_tasks),
        "expected_trials_per_task": expected_trials,
        "complete_trials": complete_trials,
        "present_trial_artifacts": present_trial_artifacts,
        "expected_trials": total_trials,
        "tasks_with_all_trials": tasks_with_all_trials,
        "tasks_with_judges": tasks_with_judges,
        "has_summary": (results_dir / "round-summary.json").exists(),
        "metadata": {
            "mode": metadata.get("mode") if metadata else None,
            "subject": metadata.get("subject") if metadata else None,
            "judge": metadata.get("judge") if metadata else None,
            "scratch": metadata.get("scratch") if metadata else None,
        },
        "task_status": task_status,
        "warnings": warnings,
        "errors": errors,
    }


def print_text(report: dict) -> None:
    print(f"{report['round']}: {report['lifecycle']}")
    print(
        f"- tasks: {report['expected_task_count']}, "
        f"trials: {report['complete_trials']}/{report['expected_trials']}, "
        f"judges: {report['tasks_with_judges']}/{report['expected_task_count']}, "
        f"summary: {report['has_summary']}"
    )
    if report["metadata"]["mode"]:
        print(f"- mode: {report['metadata']['mode']}")
        print(f"- subject: {report['metadata']['subject']}")
        print(f"- judge: {report['metadata']['judge']}")
    for warning in report["warnings"]:
        print(f"WARNING: {warning}")
    for error in report["errors"]:
        print(f"ERROR: {error}")
    if report["lifecycle"] != "scored":
        missing = [
            task_id
            for task_id, status in report["task_status"].items()
            if (
                status["missing_trials"]
                or status["incomplete_trials"]
                or status["invalid_trials"]
                or not status["valid_judge"]
            )
        ]
        if missing:
            print("- incomplete tasks: " + ", ".join(missing[:12]))
            if len(missing) > 12:
                print(f"  ... and {len(missing) - 12} more")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("round", help="Round name, e.g. round-18")
    parser.add_argument("--json", action="store_true")
    parser.add_argument(
        "--require-scored",
        action="store_true",
        help="Exit non-zero unless the round is fully scored",
    )
    parser.add_argument(
        "--require-judged",
        action="store_true",
        help="Exit non-zero unless the round has complete trials and judges",
    )
    parser.add_argument(
        "--require-trials-complete",
        action="store_true",
        help="Exit non-zero unless every expected trial has execution results",
    )
    args = parser.parse_args()

    report = validate_round(round_dir(args.round))
    if args.json:
        print(json.dumps(report, indent=2))
    else:
        print_text(report)

    if report["errors"]:
        return 1
    if args.require_trials_complete and report["lifecycle"] not in {
        "trials-complete",
        "judged",
        "scored",
    }:
        return 1
    if args.require_judged and report["lifecycle"] not in {"judged", "scored"}:
        return 1
    if args.require_scored and report["lifecycle"] != "scored":
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
