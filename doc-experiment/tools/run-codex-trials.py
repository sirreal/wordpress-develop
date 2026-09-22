#!/usr/bin/env python3
"""Run documentation-only subject trials through local `codex exec`.

This is the autonomous fallback for environments where the Workflow UI runner
is unavailable. It writes the same trials workflow-output envelope consumed by
`ingest-trials.py`.

Each subject run executes from a private non-repo working directory containing
only:

- html-tag-processor.md
- html-processor.md
- task.md
- output-schema.json

The task and rendered docs are embedded directly in the prompt because local
`codex exec` does not expose the experiment's Read/Grep-only agent tools. The
Codex process is launched with project rules and user config ignored, read-only
sandboxing, and approval policy `never`. This is a different isolation
mechanism than the Workflow runner's Read/Grep-only agent type, so the emitted
`subject_isolation` attestation records `isolated-workdir` mode.
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

TRIAL_SCHEMA = {
    "type": "object",
    "properties": {
        "code": {
            "type": "string",
            "minLength": 1,
            "pattern": "^\\s*<\\?php",
            "description": (
                "Complete PHP file contents defining exactly the requested "
                "function, starting with <?php"
            ),
        },
        "explanation": {
            "type": "string",
            "minLength": 1,
            "description": (
                "One short paragraph describing the approach and documented APIs used"
            ),
        },
        "confidence": {
            "type": "integer",
            "minimum": 0,
            "maximum": 100,
            "description": "Confidence the implementation passes strict hidden tests",
        },
    },
    "required": ["code", "explanation", "confidence"],
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
    run_checked(["python3", str(EXPERIMENT_ROOT / "tools" / "validate-round.py"), round_name])
    corpus_command = ["python3", str(EXPERIMENT_ROOT / "tools" / "validate-corpus.py")]
    for task_id in task_ids:
        corpus_command.extend(["--task", task_id])
    run_checked(corpus_command)


def write_json_atomic(path: Path, payload: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(f".{path.name}.tmp")
    temporary.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n")
    temporary.replace(path)


def copy_subject_inputs(scratch: Path, task_id: str, trial_dir: Path) -> None:
    trial_dir.mkdir(parents=True, exist_ok=True)
    for filename in ("html-tag-processor.md", "html-processor.md"):
        shutil.copyfile(scratch / filename, trial_dir / filename)
    shutil.copyfile(scratch / "tasks" / f"{task_id}.md", trial_dir / "task.md")
    write_json_atomic(trial_dir / "output-schema.json", TRIAL_SCHEMA)


def prompt(task_text: str, tag_processor_doc: str, html_processor_doc: str) -> str:
    return f"""You are a test subject in a documentation-quality experiment.

Implement the PHP function requested in TASK using the WordPress HTML API.

Your ONLY allowed information sources about the API are embedded below:

- TASK
- HTML_TAG_PROCESSOR_DOC
- HTML_PROCESSOR_DOC

Do not read any file or directory. Do not run code or commands. Do not use web
search. Do not rely on memory of WordPress source code; if the
documentation contradicts your memory, trust the documentation. Methods not
documented in the two embedded markdown documents do not exist.

Return structured output matching the supplied schema:

- code: a complete PHP file starting with <?php and defining exactly the
  requested function
- explanation: one short paragraph describing your approach and which
  documented APIs you used
- confidence: an integer from 0 to 100

TASK:
```text
{task_text}
```

HTML_TAG_PROCESSOR_DOC:
```markdown
{tag_processor_doc}
```

HTML_PROCESSOR_DOC:
```markdown
{html_processor_doc}
```
"""


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


def run_trial(
    *,
    scratch: Path,
    work_root: Path,
    task_id: str,
    trial_number: int,
    model: str,
    reasoning_effort: str,
    service_tier: str,
    timeout_seconds: int,
) -> dict:
    trial_dir = work_root / task_id / f"trial-{trial_number}"
    copy_subject_inputs(scratch, task_id, trial_dir)
    task_text = (trial_dir / "task.md").read_text()
    tag_processor_doc = (trial_dir / "html-tag-processor.md").read_text()
    html_processor_doc = (trial_dir / "html-processor.md").read_text()
    last_message = trial_dir / "codex-last-message.json"
    stdout_file = trial_dir / "codex-stdout.jsonl"
    stderr_file = trial_dir / "codex-stderr.txt"

    command = [
        CODEX,
        "exec",
        "--ephemeral",
        "--ignore-user-config",
        "--ignore-rules",
        "--skip-git-repo-check",
        "--sandbox",
        "read-only",
        "--cd",
        str(trial_dir),
        "-m",
        model,
        "-c",
        'approval_policy="never"',
        "-c",
        f"model_reasoning_effort={json.dumps(reasoning_effort)}",
        "-c",
        f"service_tier={json.dumps(service_tier)}",
        "--output-schema",
        str(trial_dir / "output-schema.json"),
        "--output-last-message",
        str(last_message),
        "--json",
        "-",
    ]

    proc = subprocess.run(
        command,
        input=prompt(task_text, tag_processor_doc, html_processor_doc),
        text=True,
        capture_output=True,
        timeout=timeout_seconds,
        check=False,
    )
    stdout_file.write_text(proc.stdout)
    stderr_file.write_text(proc.stderr)
    if proc.returncode != 0:
        message = proc.stderr.strip() or proc.stdout.strip()
        raise RuntimeError(f"{task_id}/trial-{trial_number}: codex exec failed: {message}")
    if not last_message.exists():
        raise RuntimeError(f"{task_id}/trial-{trial_number}: codex did not write final message")

    response = parse_structured_message(last_message)
    return {
        "id": task_id,
        "trial": trial_number,
        "ok": True,
        "code": response.get("code"),
        "explanation": response.get("explanation"),
        "confidence": response.get("confidence"),
    }


def selected_pairs(
    metadata: dict,
    requested_tasks: list[str] | None,
    requested_trials: list[int] | None,
) -> list[tuple[str, int]]:
    task_ids = metadata["task_ids"]
    if requested_tasks:
        unknown = sorted(set(requested_tasks) - set(task_ids))
        if unknown:
            raise ValueError("unknown task ids for this round: " + ", ".join(unknown))
        task_ids = [task_id for task_id in task_ids if task_id in set(requested_tasks)]

    trials_per_task = int(metadata["trials_per_task"])
    trial_numbers = list(range(1, trials_per_task + 1))
    if requested_trials:
        unknown_trials = sorted(
            trial for trial in requested_trials if trial < 1 or trial > trials_per_task
        )
        if unknown_trials:
            raise ValueError(
                "trial numbers outside this round's range: "
                + ", ".join(str(trial) for trial in unknown_trials)
            )
        trial_numbers = requested_trials

    return [
        (task_id, trial_number)
        for task_id in task_ids
        for trial_number in trial_numbers
    ]


def isolation_attestation(work_root: Path) -> dict:
    return {
        "enforced": True,
        "agent_type": "codex-cli-isolated-workdir",
        "isolation_mode": "isolated-workdir",
        "runner": "codex exec",
        "input_delivery": "prompt-embedded-docs",
        "sandbox_mode": "read-only",
        "approval_policy": "never",
        "project_rules_loaded": False,
        "user_config_loaded": False,
        "repo_available_to_subject": False,
        "input_files": [
            "html-processor.md",
            "html-tag-processor.md",
            "task.md",
        ],
        "work_root": str(work_root),
        "equivalent_boundary_notes": (
            "Each subject process runs from a private non-repo directory containing "
            "only the two staged rendered docs, one task prompt, and the output "
            "schema. The task and rendered docs are embedded directly in the "
            "subject prompt because local codex exec does not expose the "
            "experiment's Read/Grep-only tools. Codex project rules and user "
            "config are ignored; the process uses a read-only sandbox and "
            "approval policy never."
        ),
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("round", help="Round name, e.g. round-18")
    parser.add_argument(
        "--output",
        type=Path,
        help="Workflow-output JSON file to write",
    )
    parser.add_argument(
        "--work-root",
        type=Path,
        help="Directory for isolated per-trial Codex workspaces",
    )
    parser.add_argument(
        "--task",
        action="append",
        dest="tasks",
        help="Restrict to one task id; repeat for multiple tasks",
    )
    parser.add_argument(
        "--trial",
        action="append",
        type=int,
        dest="trials",
        help="Restrict to one trial number; repeat for multiple trials",
    )
    parser.add_argument("--jobs", type=int, default=1, help="Concurrent codex exec jobs")
    parser.add_argument("--timeout", type=int, default=900, help="Timeout per trial in seconds")
    parser.add_argument("--force", action="store_true", help="Overwrite output file if it exists")
    parser.add_argument("--dry-run", action="store_true", help="Print planned trials only")
    args = parser.parse_args()

    metadata = load_metadata(args.round)
    round_name = metadata["round"]
    pairs = selected_pairs(metadata, args.tasks, args.trials)
    task_ids = sorted({task_id for task_id, _ in pairs})
    if not pairs:
        raise RuntimeError("no trials selected")
    if args.jobs < 1:
        raise ValueError("--jobs must be at least 1")

    preflight(round_name, task_ids)

    output_path = args.output or (results_dir(round_name) / "codex-trials-output.json")

    default_work_root = Path(tempfile.gettempdir()) / "html-api-docs-eval" / round_name / "codex-cli-trials"
    work_root = args.work_root or default_work_root
    scratch = Path(metadata["scratch"])

    if args.dry_run:
        print(
            json.dumps(
                {
                    "round": round_name,
                    "work_root": str(work_root),
                    "output": str(output_path),
                    "trials": [
                        {"id": task_id, "trial": trial_number}
                        for task_id, trial_number in pairs
                    ],
                },
                indent=2,
            )
        )
        return 0

    if output_path.exists() and not args.force:
        raise FileExistsError(f"refusing to overwrite existing output: {output_path}")

    subject = metadata.get("subject") or {}
    results = []
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.jobs) as executor:
        futures = {
            executor.submit(
                run_trial,
                scratch=scratch,
                work_root=work_root,
                task_id=task_id,
                trial_number=trial_number,
                model=subject.get("model", "gpt-5.4"),
                reasoning_effort=subject.get("reasoning_effort", "medium"),
                service_tier=subject.get("service_tier", "priority"),
                timeout_seconds=args.timeout,
            ): (task_id, trial_number)
            for task_id, trial_number in pairs
        }
        for future in concurrent.futures.as_completed(futures):
            task_id, trial_number = futures[future]
            try:
                results.append(future.result())
                print(f"OK {task_id}/trial-{trial_number}", file=sys.stderr)
            except Exception as exc:
                print(f"ERROR {task_id}/trial-{trial_number}: {exc}", file=sys.stderr)
                raise

    order = {pair: index for index, pair in enumerate(pairs)}
    results.sort(key=lambda entry: order[(entry["id"], entry["trial"])])
    payload = {
        "subject_isolation": isolation_attestation(work_root),
        "result": results,
    }
    write_json_atomic(output_path, payload)
    print(json.dumps(payload, indent=2, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as exc:
        print(f"run-codex-trials.py: {exc}", file=sys.stderr)
        sys.exit(1)
