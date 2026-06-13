#!/usr/bin/env python3
"""Emit workflow arguments from a prepared round's metadata."""

import argparse
import json
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
    return {
        "round": round_name,
        "mode": metadata.get("mode"),
        "workflow_runner": "Workflow tool environment with agent() and parallel() globals",
        "subject_isolation": {
            "required_agent_type": "docs-test-subject",
            "allowed_tools": ["Read", "Grep"],
            "trusted_only_if_enforced": True,
        },
        "scripts": {
            "trials": str(EXPERIMENT_ROOT / "tools" / "trials-workflow.js"),
            "judges": str(EXPERIMENT_ROOT / "tools" / "judge-workflow.js"),
        },
        "args": {
            "trials": trial_args(metadata),
            "judges": judge_args(metadata),
        },
        "commands": {
            "preflight": [
                f"python3 doc-experiment/tools/validate-corpus.py --split train",
                f"python3 doc-experiment/tools/validate-round.py {round_name}",
                f"python3 doc-experiment/tools/workflow-args.py manifest {round_name}",
            ],
            "after_trials_workflow": [
                f"python3 doc-experiment/tools/validate-workflow-output.py trials <trials-output.json> {round_name}",
                f"python3 doc-experiment/tools/ingest-trials.py <trials-output.json> {round_name}",
                f"python3 doc-experiment/tools/validate-round.py {round_name} --require-trials-complete",
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
        help="Emit metadata-derived args without verifying staged round artifacts",
    )
    parser.add_argument(
        "--skip-scratch-check",
        dest="skip_round_check",
        action="store_true",
        help=argparse.SUPPRESS,
    )
    args = parser.parse_args()

    metadata = load_metadata(args.round)
    if not args.skip_round_check:
        verify_round(args.round)
    if args.phase == "trials":
        payload = trial_args(metadata)
    elif args.phase == "judges":
        payload = judge_args(metadata)
    else:
        payload = launch_manifest(metadata)
    print(
        json.dumps(
            payload,
            separators=(",", ":") if args.compact else None,
            indent=None if args.compact else 2,
        )
    )
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as exc:
        print(f"workflow-args.py: {exc}", file=sys.stderr)
        sys.exit(1)
