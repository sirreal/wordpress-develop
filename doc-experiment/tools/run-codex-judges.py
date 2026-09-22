#!/usr/bin/env python3
"""Run round judges through local `codex exec`.

This is the autonomous fallback for environments where the Workflow UI runner
is unavailable. It writes the same judge workflow-output envelope consumed by
`ingest-judges.py`.

Each judge runs from the repository root with a read-only sandbox. Judges are
allowed to inspect the task, reference, hidden tests, trial artifacts, rendered
docs, and HTML API source, and may run read-only/ad-hoc PHP probes through the
documented harness bootstrap.
"""

import argparse
import concurrent.futures
import json
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path


EXPERIMENT_ROOT = Path(__file__).resolve().parent.parent
REPO_ROOT = EXPERIMENT_ROOT.parent
CODEX = shutil.which("codex") or "codex"

JUDGE_SCHEMA = {
    "type": "object",
    "properties": {
        "trials": {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {
                    "trial_id": {"type": "string", "description": "e.g. trial-1"},
                    "adherence": {"type": "integer", "minimum": 0, "maximum": 100},
                    "hallucinated_methods": {
                        "type": "array",
                        "items": {"type": "string"},
                    },
                    "notes": {"type": "string", "minLength": 1},
                },
                "required": [
                    "trial_id",
                    "adherence",
                    "hallucinated_methods",
                    "notes",
                ],
                "additionalProperties": False,
            },
        },
        "failure_analysis": {"type": "string", "minLength": 1},
        "doc_gaps": {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {
                    "location": {"type": "string", "minLength": 1},
                    "problem": {"type": "string", "minLength": 1},
                    "suggestion": {"type": "string", "minLength": 1},
                },
                "required": ["location", "problem", "suggestion"],
                "additionalProperties": False,
            },
        },
    },
    "required": ["trials", "failure_analysis", "doc_gaps"],
    "additionalProperties": False,
}


def results_dir(round_name: str) -> Path:
    name = round_name if round_name.startswith("round-") else f"round-{int(round_name):02d}"
    return EXPERIMENT_ROOT / "results" / name


def load_metadata(round_name: str) -> dict:
    metadata_path = results_dir(round_name) / "round-metadata.json"
    if not metadata_path.exists():
        raise FileNotFoundError(f"missing round metadata: {metadata_path}")
    return json.loads(metadata_path.read_text())


def run_checked(command: list[str]) -> None:
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


def preflight(round_name: str, task_ids: list[str]) -> None:
    run_checked(
        [
            "python3",
            str(EXPERIMENT_ROOT / "tools" / "validate-round.py"),
            round_name,
            "--require-trials-complete",
        ]
    )
    corpus_command = ["python3", str(EXPERIMENT_ROOT / "tools" / "validate-corpus.py")]
    for task_id in task_ids:
        corpus_command.extend(["--task", task_id])
    run_checked(corpus_command)


def write_json_atomic(path: Path, payload: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(f".{path.name}.tmp")
    temporary.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n")
    temporary.replace(path)


def parse_structured_message(path: Path) -> dict:
    raw = path.read_text().strip()
    if raw.startswith("```"):
        lines = raw.splitlines()
        if lines and lines[0].startswith("```"):
            lines = lines[1:]
        if lines and lines[-1].startswith("```"):
            lines = lines[:-1]
        raw = "\n".join(lines).strip()
    return json.loads(raw)


def prompt(round_name: str, scratch: Path, task_id: str) -> str:
    return f"""You are the judge in a documentation-quality experiment.
Less capable "test subject" models implemented a PHP function using ONLY two
rendered documentation files plus a task description: no source access and no
code execution. You score how they used the API and diagnose which
documentation gaps caused failures.

Locations:
- Task spec subjects saw: {REPO_ROOT}/doc-experiment/corpus/{task_id}/task.md
- Canonical reference: {REPO_ROOT}/doc-experiment/corpus/{task_id}/reference.php
- Hidden tests + frozen expectations: {REPO_ROOT}/doc-experiment/corpus/{task_id}/tests.json
- Trials: {REPO_ROOT}/doc-experiment/results/{round_name}/{task_id}/trial-N/ directories, each containing candidate.php, response.json, and execution.json
- The exact docs subjects saw: {scratch}/html-tag-processor.md and {scratch}/html-processor.md
- HTML API source files: {REPO_ROOT}/src/wp-includes/html-api/class-wp-html-tag-processor.php and {REPO_ROOT}/src/wp-includes/html-api/class-wp-html-processor.php

Score each trial's ADHERENCE 0-100 by this rubric:
- Correct processor choice for the job (max 30)
- No hallucinated or undocumented API usage (max 30). Verify every method the
  candidate calls exists in the two markdown files. `_doing_it_wrong` records
  in execution.json also indicate misuse.
- Idiomatic use of documented patterns: token walking, bookmarks,
  breadcrumbs, get_updated_html, serialize_token (max 25)
- Graceful handling of edge cases the docs describe: null/true/'' attribute
  semantics, decoded vs raw text, incomplete input (max 15)

Functional correctness is measured separately by execution.json. Do not
double-count correctness in adherence, but use failing cases to identify the
misunderstanding.

Write failure_analysis: for each failed hidden case across trials, identify
the specific misconception and the documentation passage, heading, or absence
responsible. If all trials passed everything, analyze what the docs did well
and any near-misses in the explanations.

List doc_gaps as concrete, generalizable docblock improvements. Never suggest
embedding this task's solution into the docs; suggest the general contract or
example that would have prevented the failure.

You may verify actual API behavior with read-only probes:
  php -r 'require "{REPO_ROOT}/doc-experiment/harness/bootstrap.php"; <probe code>'

Do not modify files. Return structured output matching the supplied schema.
"""


def run_judge(
    *,
    round_name: str,
    scratch: Path,
    work_root: Path,
    task_id: str,
    model: str,
    reasoning_effort: str,
    service_tier: str,
    timeout_seconds: int,
) -> dict:
    judge_dir = work_root / task_id
    judge_dir.mkdir(parents=True, exist_ok=True)
    schema_file = judge_dir / "output-schema.json"
    write_json_atomic(schema_file, JUDGE_SCHEMA)
    last_message = judge_dir / "codex-last-message.json"
    stdout_file = judge_dir / "codex-stdout.jsonl"
    stderr_file = judge_dir / "codex-stderr.txt"

    command = [
        CODEX,
        "exec",
        "--ephemeral",
        "--ignore-user-config",
        "--ignore-rules",
        "--sandbox",
        "read-only",
        "--cd",
        str(REPO_ROOT),
        "-m",
        model,
        "-c",
        'approval_policy="never"',
        "-c",
        f"model_reasoning_effort={json.dumps(reasoning_effort)}",
        "-c",
        f"service_tier={json.dumps(service_tier)}",
        "--output-schema",
        str(schema_file),
        "--output-last-message",
        str(last_message),
        "--json",
        "-",
    ]

    proc = subprocess.run(
        command,
        input=prompt(round_name, scratch, task_id),
        text=True,
        capture_output=True,
        timeout=timeout_seconds,
        check=False,
    )
    stdout_file.write_text(proc.stdout)
    stderr_file.write_text(proc.stderr)
    if proc.returncode != 0:
        message = proc.stderr.strip() or proc.stdout.strip()
        raise RuntimeError(f"{task_id}: codex exec judge failed: {message}")
    if not last_message.exists():
        raise RuntimeError(f"{task_id}: codex did not write final judge message")

    return {"id": task_id, "verdict": parse_structured_message(last_message)}


def selected_tasks(metadata: dict, requested_tasks: list[str] | None) -> list[str]:
    task_ids = metadata["task_ids"]
    if not requested_tasks:
        return task_ids
    unknown = sorted(set(requested_tasks) - set(task_ids))
    if unknown:
        raise ValueError("unknown task ids for this round: " + ", ".join(unknown))
    return [task_id for task_id in task_ids if task_id in set(requested_tasks)]


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("round", help="Round name, e.g. round-18")
    parser.add_argument(
        "--output",
        type=Path,
        help="Judge workflow-output JSON file to write",
    )
    parser.add_argument(
        "--work-root",
        type=Path,
        help="Directory for isolated per-task judge workspaces",
    )
    parser.add_argument(
        "--task",
        action="append",
        dest="tasks",
        help="Restrict to one task id; repeat for multiple tasks",
    )
    parser.add_argument("--jobs", type=int, default=1, help="Concurrent codex exec jobs")
    parser.add_argument("--timeout", type=int, default=1800, help="Timeout per judge in seconds")
    parser.add_argument("--force", action="store_true", help="Overwrite output file if it exists")
    parser.add_argument("--dry-run", action="store_true", help="Print planned judges only")
    args = parser.parse_args()

    metadata = load_metadata(args.round)
    round_name = metadata["round"]
    task_ids = selected_tasks(metadata, args.tasks)
    if not task_ids:
        raise RuntimeError("no judges selected")
    if args.jobs < 1:
        raise ValueError("--jobs must be at least 1")

    preflight(round_name, task_ids)

    output_path = args.output or (results_dir(round_name) / "codex-judges-output.json")
    default_work_root = Path(tempfile.gettempdir()) / "html-api-docs-eval" / round_name / "codex-cli-judges"
    work_root = args.work_root or default_work_root
    scratch = Path(metadata["scratch"])

    if args.dry_run:
        print(
            json.dumps(
                {
                    "round": round_name,
                    "work_root": str(work_root),
                    "output": str(output_path),
                    "judges": task_ids,
                },
                indent=2,
            )
        )
        return 0

    if output_path.exists() and not args.force:
        raise FileExistsError(f"refusing to overwrite existing output: {output_path}")

    judge = metadata.get("judge") or {}
    results = []
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.jobs) as executor:
        futures = {
            executor.submit(
                run_judge,
                round_name=round_name,
                scratch=scratch,
                work_root=work_root,
                task_id=task_id,
                model=judge.get("model", "gpt-5.5"),
                reasoning_effort=judge.get("reasoning_effort", "xhigh"),
                service_tier=judge.get("service_tier", "priority"),
                timeout_seconds=args.timeout,
            ): task_id
            for task_id in task_ids
        }
        for future in concurrent.futures.as_completed(futures):
            task_id = futures[future]
            try:
                results.append(future.result())
                print(f"OK {task_id}", file=sys.stderr)
            except Exception as exc:
                print(f"ERROR {task_id}: {exc}", file=sys.stderr)
                raise

    order = {task_id: index for index, task_id in enumerate(task_ids)}
    results.sort(key=lambda entry: order[entry["id"]])
    payload = {"result": results}
    write_json_atomic(output_path, payload)
    print(json.dumps(payload, indent=2, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as exc:
        print(f"run-codex-judges.py: {exc}", file=sys.stderr)
        sys.exit(1)
