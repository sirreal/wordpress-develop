#!/usr/bin/env python3
"""Emit workflow arguments from a prepared round's metadata."""

import argparse
import json
import sys
from pathlib import Path


EXPERIMENT_ROOT = Path(__file__).resolve().parent.parent
REPO_ROOT = EXPERIMENT_ROOT.parent


def results_dir(round_name: str) -> Path:
    name = round_name if round_name.startswith("round-") else f"round-{int(round_name):02d}"
    return EXPERIMENT_ROOT / "results" / name


def load_metadata(round_name: str) -> dict:
    metadata_file = results_dir(round_name) / "round-metadata.json"
    if not metadata_file.exists():
        raise FileNotFoundError(f"missing round metadata: {metadata_file}")
    return json.loads(metadata_file.read_text())


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


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("phase", choices=["trials", "judges"])
    parser.add_argument("round", help="Round number or name, e.g. 18 or round-18")
    parser.add_argument(
        "--compact",
        action="store_true",
        help="Print one-line JSON for copy/paste into workflow runners",
    )
    args = parser.parse_args()

    metadata = load_metadata(args.round)
    payload = trial_args(metadata) if args.phase == "trials" else judge_args(metadata)
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
