#!/usr/bin/env python3
"""Emit workflow arguments from a prepared round's metadata."""

import argparse
import hashlib
import json
import shlex
import subprocess
import sys
from pathlib import Path


EXPERIMENT_ROOT = Path(__file__).resolve().parent.parent
REPO_ROOT = EXPERIMENT_ROOT.parent


def results_dir(round_name: str) -> Path:
    name = round_name if round_name.startswith("round-") else f"round-{int(round_name):02d}"
    return EXPERIMENT_ROOT / "results" / name


def metadata_file(round_name: str) -> Path:
    return results_dir(round_name) / "round-metadata.json"


def load_metadata(round_name: str) -> dict:
    path = metadata_file(round_name)
    if not path.exists():
        raise FileNotFoundError(f"missing round metadata: {path}")
    return json.loads(path.read_text())


def run_text(command: list[str]) -> str:
    proc = subprocess.run(
        command,
        cwd=REPO_ROOT,
        text=True,
        capture_output=True,
        check=False,
    )
    if proc.returncode != 0:
        message = (proc.stderr or proc.stdout).strip()
        raise RuntimeError(f"{' '.join(command)} failed: {message}")
    return proc.stdout.strip()


def file_sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def write_payload(path: Path, text: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(f".{path.name}.tmp")
    temporary.write_text(text)
    temporary.replace(path)


def verify_round(round_name: str) -> None:
    proc = subprocess.run(
        [
            "python3",
            str(EXPERIMENT_ROOT / "tools" / "validate-round.py"),
            round_name,
        ],
        cwd=REPO_ROOT,
        text=True,
        capture_output=True,
        check=False,
    )
    if proc.returncode != 0:
        message = (proc.stderr or proc.stdout).strip()
        raise RuntimeError(f"round preflight failed: {message}")


def verify_corpus(metadata: dict) -> None:
    task_ids = metadata.get("task_ids", [])
    if not task_ids:
        return

    command = [
        "python3",
        str(EXPERIMENT_ROOT / "tools" / "validate-corpus.py"),
    ]
    for task_id in task_ids:
        command.extend(["--task", task_id])

    proc = subprocess.run(
        command,
        cwd=REPO_ROOT,
        text=True,
        capture_output=True,
        check=False,
    )
    if proc.returncode != 0:
        message = (proc.stderr or proc.stdout).strip()
        raise RuntimeError(f"corpus preflight failed: {message}")


def corpus_validation_command(metadata: dict) -> str | None:
    task_ids = metadata.get("task_ids", [])
    if not task_ids:
        return None
    return (
        "python3 doc-experiment/tools/validate-corpus.py "
        + " ".join(f"--task {shlex.quote(task_id)}" for task_id in task_ids)
    )


def trial_args(metadata: dict) -> dict:
    subject = metadata.get("subject") or {}
    return {
        "scratch": metadata["scratch"],
        "taskIds": metadata["task_ids"],
        "trialsPerTask": metadata["trials_per_task"],
        "model": subject.get("model"),
        "reasoning_effort": subject.get("reasoning_effort"),
        "service_tier": subject.get("service_tier"),
    }


def judge_args(metadata: dict) -> dict:
    judge = metadata.get("judge") or {}
    return {
        "repoRoot": str(REPO_ROOT),
        "round": metadata["round"],
        "scratch": metadata["scratch"],
        "taskIds": metadata["task_ids"],
        "model": judge.get("model"),
        "reasoning_effort": judge.get("reasoning_effort"),
        "service_tier": judge.get("service_tier"),
    }


def launch_manifest(metadata: dict) -> dict:
    round_name = metadata["round"]
    trials_script = EXPERIMENT_ROOT / "tools" / "trials-workflow.js"
    judges_script = EXPERIMENT_ROOT / "tools" / "judge-workflow.js"
    preflight_commands = [
        corpus_validation_command(metadata),
        f"python3 doc-experiment/tools/validate-round.py {round_name}",
        f"python3 doc-experiment/tools/workflow-args.py manifest {round_name}",
    ]
    return {
        "round": round_name,
        "mode": metadata.get("mode"),
        "workflow_runner": "Workflow tool environment with agent() and parallel() globals",
        "launch_provenance": {
            "current_git_head": run_text(["git", "rev-parse", "HEAD"]),
            "current_git_status_short": run_text(["git", "status", "--short"]),
            "round_metadata_git_head": metadata.get("git_head"),
            "round_metadata_git_status_short": metadata.get("git_status_short"),
            "workflow_script_sha256": {
                "trials": file_sha256(trials_script),
                "judges": file_sha256(judges_script),
            },
        },
        "subject_isolation": {
            "required_agent_type": "docs-test-subject",
            "agent_option_key": "agent_type",
            "allowed_tools": ["Read", "Grep"],
            "accepted_isolation_modes": [
                "read-grep-tool-boundary",
                "isolated-workdir",
            ],
            "local_codex_fallback": {
                "agent_type": "codex-cli-isolated-workdir",
                "runner": "codex exec",
                "input_delivery": "prompt-embedded-docs",
                "sandbox_mode": "read-only",
                "approval_policy": "never",
                "input_files": [
                    "html-processor.md",
                    "html-tag-processor.md",
                    "task.md",
                ],
            },
            "trusted_only_if_enforced": True,
            "attestation_required_in_trials_output": True,
            "attestation_output_key": "subject_isolation",
            "accepted_trials_output_shapes": [
                "{subject_isolation, result}",
                "{result: {subject_isolation, result}}",
            ],
        },
        "scripts": {
            "trials": str(trials_script),
            "judges": str(judges_script),
        },
        "args": {
            "trials": trial_args(metadata),
            "judges": judge_args(metadata),
        },
        "commands": {
            "preflight": [command for command in preflight_commands if command],
            "local_codex_trials": [
                f"python3 doc-experiment/tools/run-codex-trials.py {round_name} "
                f"--output doc-experiment/results/{round_name}/codex-trials-output.json",
                f"python3 doc-experiment/tools/validate-workflow-output.py trials "
                f"doc-experiment/results/{round_name}/codex-trials-output.json {round_name}",
                f"python3 doc-experiment/tools/ingest-trials.py "
                f"doc-experiment/results/{round_name}/codex-trials-output.json {round_name}",
                f"python3 doc-experiment/tools/validate-round.py {round_name} "
                "--require-trials-complete",
            ],
            "after_trials_workflow": [
                f"python3 doc-experiment/tools/validate-workflow-output.py trials <trials-output.json> {round_name}",
                f"python3 doc-experiment/tools/ingest-trials.py <trials-output.json> {round_name}",
                f"python3 doc-experiment/tools/validate-round.py {round_name} --require-trials-complete",
            ],
            "local_codex_judges": [
                f"python3 doc-experiment/tools/run-codex-judges.py {round_name} "
                f"--output doc-experiment/results/{round_name}/codex-judges-output.json",
                f"python3 doc-experiment/tools/validate-workflow-output.py judges "
                f"doc-experiment/results/{round_name}/codex-judges-output.json {round_name}",
                f"python3 doc-experiment/tools/ingest-judges.py "
                f"doc-experiment/results/{round_name}/codex-judges-output.json {round_name}",
                f"python3 doc-experiment/tools/validate-round.py {round_name} --require-scored",
            ],
            "after_judges_workflow": [
                f"python3 doc-experiment/tools/validate-workflow-output.py judges <judges-output.json> {round_name}",
                f"python3 doc-experiment/tools/ingest-judges.py <judges-output.json> {round_name}",
                f"python3 doc-experiment/tools/validate-round.py {round_name} --require-scored",
            ],
        },
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("phase", choices=["trials", "judges", "manifest"])
    parser.add_argument("round", help="Round number or name, e.g. 18 or round-18")
    parser.add_argument(
        "--compact",
        action="store_true",
        help="Print one-line JSON for copy/paste into workflow runners",
    )
    parser.add_argument(
        "--skip-round-check",
        dest="skip_round_check",
        action="store_true",
        help=(
            "Emit metadata-derived args without verifying staged round artifacts "
            "or selected corpus references"
        ),
    )
    parser.add_argument(
        "--skip-scratch-check",
        dest="skip_round_check",
        action="store_true",
        help=argparse.SUPPRESS,
    )
    parser.add_argument(
        "--output",
        type=Path,
        help="Also write the emitted JSON payload to this file atomically",
    )
    args = parser.parse_args()

    metadata = load_metadata(args.round)
    if not args.skip_round_check:
        verify_round(args.round)
        verify_corpus(metadata)
    if args.phase == "trials":
        payload = trial_args(metadata)
    elif args.phase == "judges":
        payload = judge_args(metadata)
    else:
        payload = launch_manifest(metadata)
    output = json.dumps(
        payload,
        separators=(",", ":") if args.compact else None,
        indent=None if args.compact else 2,
    )
    if args.output:
        write_payload(args.output, output + "\n")
    print(output)
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as exc:
        print(f"workflow-args.py: {exc}", file=sys.stderr)
        sys.exit(1)
