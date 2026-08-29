#!/usr/bin/env python3
"""Prepare a documentation experiment round.

This wraps the deterministic docs staging step, copies only task prompts into
the scratch directory, and records round metadata plus source/corpus digests in
the results directory. It does not run subjects, execute candidates, or judge
trials.
"""

import argparse
import datetime as dt
import hashlib
import json
import subprocess
import sys
from pathlib import Path


EXPERIMENT_ROOT = Path(__file__).resolve().parent.parent
REPO_ROOT = EXPERIMENT_ROOT.parent

MODE_SPLITS = {
    "scored-train": ["train"],
    "checkpoint": ["train", "holdout"],
    "weak-tier-calibration": ["train"],
    "discoverability-probe": [],
    "shadow-doc-a/b": ["train"],
}

DEFAULT_SUBJECT = {
    "model": "gpt-5.4",
    "reasoning_effort": "medium",
    "service_tier": "priority",
}

DEFAULT_JUDGE = {
    "model": "gpt-5.5",
    "reasoning_effort": "xhigh",
    "service_tier": "priority",
}


def round_parts(value: str) -> tuple[str, str]:
    raw = value.removeprefix("round-")
    try:
        number = int(raw, 10)
    except ValueError as exc:
        raise argparse.ArgumentTypeError(
            "round must be a number or round-NN"
        ) from exc
    return str(number), f"round-{number:02d}"


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


def source_digests(ref: str | None = None) -> dict:
    command = [
        "php",
        str(EXPERIMENT_ROOT / "tools" / "source-digests.php"),
        "--json",
    ]
    if ref:
        command.extend(["--ref", ref])

    return json.loads(
        run_text(command)
    )


def file_sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def active_tasks() -> dict[str, dict]:
    tasks = {}
    for tests_file in sorted((EXPERIMENT_ROOT / "corpus").glob("*/tests.json")):
        task_dir = tests_file.parent
        meta = json.loads(tests_file.read_text())
        task_id = meta.get("id") or task_dir.name
        if task_id != task_dir.name:
            raise RuntimeError(
                f"{tests_file} id {task_id!r} does not match directory {task_dir.name!r}"
            )
        task_md = task_dir / "task.md"
        if not task_md.exists():
            raise RuntimeError(f"Missing task prompt: {task_md}")
        reference_php = task_dir / "reference.php"
        if not reference_php.exists():
            raise RuntimeError(f"Missing reference implementation: {reference_php}")
        tasks[task_id] = {
            "id": task_id,
            "split": meta.get("split"),
            "role": meta.get("role"),
            "commonness": meta.get("commonness"),
            "concept": meta.get("concept"),
            "processor": meta.get("processor"),
            "task_md": task_md,
            "tests_json": tests_file,
            "reference_php": reference_php,
        }
    return tasks


def select_tasks(tasks: dict[str, dict], mode: str, explicit: list[str]) -> list[dict]:
    if explicit:
        missing = sorted(set(explicit) - set(tasks))
        if missing:
            raise RuntimeError(f"Unknown task ids: {', '.join(missing)}")
        return [tasks[task_id] for task_id in explicit]

    splits = set(MODE_SPLITS[mode])
    return [task for task in tasks.values() if task["split"] in splits]


def counts_by(items: list[dict], key: str) -> dict[str, int]:
    counts = {}
    for item in items:
        value = item.get(key) or "unknown"
        counts[value] = counts.get(value, 0) + 1
    return dict(sorted(counts.items()))


def corpus_file_digests(tasks: list[dict], ref: str | None) -> dict:
    result = {
        "ref": ref or "working-tree",
        "algorithm": "sha256",
        "tasks": {},
    }

    for task in tasks:
        files = {}
        for key in ("task_md", "reference_php", "tests_json"):
            relpath = task[key].relative_to(REPO_ROOT).as_posix()
            files[relpath] = file_sha256(task[key])

        result["tasks"][task["id"]] = {
            "labels": {
                "split": task.get("split"),
                "role": task.get("role"),
                "commonness": task.get("commonness"),
                "concept": task.get("concept"),
                "processor": task.get("processor"),
            },
            "files": files,
        }

    return result


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("round", help="Round number, e.g. 18 or round-18")
    parser.add_argument(
        "--mode",
        choices=sorted(MODE_SPLITS),
        default="weak-tier-calibration",
        help="Round mode from PROTOCOL.md",
    )
    parser.add_argument("--task", dest="tasks", action="append", default=[])
    parser.add_argument("--trials-per-task", type=int, default=3)
    parser.add_argument("--subject-model", default=DEFAULT_SUBJECT["model"])
    parser.add_argument(
        "--subject-reasoning-effort",
        default=DEFAULT_SUBJECT["reasoning_effort"],
    )
    parser.add_argument("--subject-service-tier", default=DEFAULT_SUBJECT["service_tier"])
    parser.add_argument("--judge-model", default=DEFAULT_JUDGE["model"])
    parser.add_argument(
        "--judge-reasoning-effort",
        default=DEFAULT_JUDGE["reasoning_effort"],
    )
    parser.add_argument("--judge-service-tier", default=DEFAULT_JUDGE["service_tier"])
    parser.add_argument(
        "--force",
        action="store_true",
        help="Overwrite an existing round-metadata.json file",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Validate task selection and print metadata without staging or writing",
    )
    args = parser.parse_args()

    if args.trials_per_task < 1:
        raise RuntimeError("--trials-per-task must be positive")

    round_number, round_name = round_parts(args.round)
    tasks = active_tasks()
    selected = select_tasks(tasks, args.mode, args.tasks)
    git_head = run_text(["git", "rev-parse", "HEAD"])
    git_status_short = run_text(["git", "status", "--short"])
    source_ref = None if git_status_short else git_head

    metadata = {
        "round": round_name,
        "mode": args.mode,
        "task_ids": [task["id"] for task in selected],
        "task_count": len(selected),
        "splits": counts_by(selected, "split"),
        "concepts": counts_by(selected, "concept"),
        "trials_per_task": args.trials_per_task,
        "subject": {
            "model": args.subject_model,
            "reasoning_effort": args.subject_reasoning_effort,
            "service_tier": args.subject_service_tier,
        },
        "judge": {
            "model": args.judge_model,
            "reasoning_effort": args.judge_reasoning_effort,
            "service_tier": args.judge_service_tier,
        },
        "git_head": git_head,
        "git_status_short": git_status_short,
        "source_file_digests": source_digests(source_ref),
        "corpus_file_digests": corpus_file_digests(selected, source_ref),
        "created_at_utc": dt.datetime.now(dt.UTC).isoformat(timespec="seconds"),
        "isolation": {
            "scratch_contains": [
                "html-tag-processor.md",
                "html-processor.md",
                "tasks/<task-id>.md",
            ],
            "subjects_must_not_read": [
                "reference.php",
                "tests.json",
                "source files",
                "logs",
                "plans",
                "hypothesis docs",
            ],
        },
    }

    if args.dry_run:
        print(json.dumps(metadata, indent=2))
        return 0

    results_dir = EXPERIMENT_ROOT / "results" / round_name
    metadata_file = results_dir / "round-metadata.json"
    if metadata_file.exists() and not args.force:
        raise RuntimeError(f"{metadata_file} already exists; use --force to overwrite")

    scratch = run_text(["sh", str(EXPERIMENT_ROOT / "tools" / "stage-round.sh"), round_number])
    scratch_dir = Path(scratch)
    tasks_dir = scratch_dir / "tasks"
    tasks_dir.mkdir(parents=True, exist_ok=True)
    for task in selected:
        (tasks_dir / f"{task['id']}.md").write_text(task["task_md"].read_text())

    verify_command = [
        "python3",
        str(EXPERIMENT_ROOT / "tools" / "verify-scratch-isolation.py"),
        str(scratch_dir),
        "--json",
    ]
    for task in selected:
        verify_command.extend(["--task-id", task["id"]])
    isolation = json.loads(run_text(verify_command))

    results_dir.mkdir(parents=True, exist_ok=True)
    metadata["scratch"] = str(scratch_dir)
    metadata["staged_task_files"] = [f"tasks/{task['id']}.md" for task in selected]
    metadata["scratch_isolation_check"] = (
        f"OK: {scratch_dir} exposes 2 docs and {len(selected)} task prompt(s), "
        "with no forbidden files."
    )
    metadata["scratch_file_sha256"] = isolation["scratch_file_sha256"]
    metadata_file.write_text(json.dumps(metadata, indent=2) + "\n")

    print(json.dumps(metadata, indent=2))
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as exc:
        print(f"prepare-round.py: {exc}", file=sys.stderr)
        sys.exit(1)
