#!/usr/bin/env python3
"""Run citation-only discoverability probes through local `codex exec`.

Probe subjects receive only the staged rendered docs and a question. They do
not see source files, hidden tests, experiment plans, logs, or previous
results. This is the autonomous fallback for the protocol's
discoverability-probe mode when the external Workflow runner is unavailable.
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

PROBE_SCHEMA = {
    "type": "object",
    "properties": {
        "answer": {"type": "string", "minLength": 1},
        "citations": {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {
                    "file": {"type": "string", "minLength": 1},
                    "heading": {"type": "string", "minLength": 1},
                    "support": {"type": "string", "minLength": 1},
                },
                "required": ["file", "heading", "support"],
                "additionalProperties": False,
            },
        },
        "rationale": {"type": "string", "minLength": 1},
        "confidence": {"type": "integer", "minimum": 0, "maximum": 100},
    },
    "required": ["answer", "citations", "rationale", "confidence"],
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


def validate_round(round_name: str) -> None:
    run_checked(["python3", str(EXPERIMENT_ROOT / "tools" / "validate-round.py"), round_name])


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


def read_docs(scratch: Path) -> dict[str, str]:
    docs = {}
    for filename in ("html-tag-processor.md", "html-processor.md"):
        path = scratch / filename
        if not path.exists():
            raise FileNotFoundError(f"missing staged rendered doc: {path}")
        docs[filename] = path.read_text()
    return docs


def prompt(question: str, docs: dict[str, str]) -> str:
    return f"""You are answering a citation-only discoverability probe for
WordPress HTML API documentation.

Use ONLY the rendered documentation included below. Do not rely on memory,
source code, hidden tests, experiment notes, or external knowledge. Do not run
code. If the docs do not directly answer part of the question, say what is
missing and cite the nearest relevant heading.

Question:
{question}

Return structured output matching the supplied schema:
- answer: concise direct answer.
- citations: rendered doc file, heading, and the sentence or local contract
  supporting the answer.
- rationale: one sentence explaining how the citations support the answer or
  what gap remains.
- confidence: 0-100.

--- BEGIN html-tag-processor.md ---
{docs["html-tag-processor.md"]}
--- END html-tag-processor.md ---

--- BEGIN html-processor.md ---
{docs["html-processor.md"]}
--- END html-processor.md ---
"""


def run_probe(
    *,
    round_name: str,
    scratch: Path,
    work_root: Path,
    question_id: str,
    question: str,
    trial_number: int,
    model: str,
    reasoning_effort: str,
    service_tier: str,
    timeout_seconds: int,
) -> dict:
    probe_dir = work_root / question_id / f"probe-{trial_number}"
    probe_dir.mkdir(parents=True, exist_ok=True)
    schema_file = probe_dir / "output-schema.json"
    write_json_atomic(schema_file, PROBE_SCHEMA)
    docs = read_docs(scratch)
    for filename, content in docs.items():
        (probe_dir / filename).write_text(content)

    last_message = probe_dir / "codex-last-message.json"
    stdout_file = probe_dir / "codex-stdout.jsonl"
    stderr_file = probe_dir / "codex-stderr.txt"

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
        str(probe_dir),
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
        input=prompt(question, docs),
        text=True,
        capture_output=True,
        timeout=timeout_seconds,
        check=False,
    )
    stdout_file.write_text(proc.stdout)
    stderr_file.write_text(proc.stderr)
    if proc.returncode != 0:
        message = proc.stderr.strip() or proc.stdout.strip()
        raise RuntimeError(f"{question_id} probe-{trial_number}: codex exec failed: {message}")
    if not last_message.exists():
        raise RuntimeError(f"{question_id} probe-{trial_number}: codex wrote no final message")

    return {
        "id": question_id,
        "trial_id": f"probe-{trial_number}",
        "response": parse_structured_message(last_message),
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("round", help="Round name, e.g. round-18")
    parser.add_argument("--question-id", required=True, help="Stable probe identifier")
    parser.add_argument("--question", required=True, help="Citation-only probe question")
    parser.add_argument("--output", type=Path, help="Probe output JSON path")
    parser.add_argument("--work-root", type=Path, help="Directory for isolated probe workspaces")
    parser.add_argument("--trials", type=int, default=3, help="Number of independent probe subjects")
    parser.add_argument("--jobs", type=int, default=1, help="Concurrent codex exec jobs")
    parser.add_argument("--model", help="Subject model override")
    parser.add_argument("--reasoning-effort", help="Subject reasoning effort override")
    parser.add_argument("--service-tier", help="Subject service tier override")
    parser.add_argument("--timeout", type=int, default=600, help="Timeout per probe in seconds")
    parser.add_argument("--force", action="store_true", help="Overwrite output file if it exists")
    parser.add_argument("--dry-run", action="store_true", help="Print planned probes only")
    args = parser.parse_args()

    if args.trials < 1:
        raise ValueError("--trials must be at least 1")
    if args.jobs < 1:
        raise ValueError("--jobs must be at least 1")

    metadata = load_metadata(args.round)
    round_name = metadata["round"]
    validate_round(round_name)
    scratch = Path(metadata["scratch"])
    subject = metadata.get("subject") or {}
    model = args.model or subject.get("model", "gpt-5.4")
    reasoning_effort = args.reasoning_effort or subject.get("reasoning_effort", "medium")
    service_tier = args.service_tier or subject.get("service_tier", "priority")
    output_path = args.output or (
        EXPERIMENT_ROOT / "results" / "probes" / f"{round_name}-{args.question_id}.json"
    )
    default_work_root = Path(tempfile.gettempdir()) / "html-api-docs-eval" / round_name / "codex-cli-probes"
    work_root = args.work_root or default_work_root

    plan = {
        "round": round_name,
        "question_id": args.question_id,
        "question": args.question,
        "trials": args.trials,
        "jobs": args.jobs,
        "model": model,
        "reasoning_effort": reasoning_effort,
        "service_tier": service_tier,
        "work_root": str(work_root),
        "output": str(output_path),
    }
    if args.dry_run:
        print(json.dumps(plan, indent=2, ensure_ascii=False))
        return 0

    if output_path.exists() and not args.force:
        raise FileExistsError(f"refusing to overwrite existing output: {output_path}")

    results = []
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.jobs) as executor:
        futures = {
            executor.submit(
                run_probe,
                round_name=round_name,
                scratch=scratch,
                work_root=work_root,
                question_id=args.question_id,
                question=args.question,
                trial_number=trial_number,
                model=model,
                reasoning_effort=reasoning_effort,
                service_tier=service_tier,
                timeout_seconds=args.timeout,
            ): trial_number
            for trial_number in range(1, args.trials + 1)
        }
        for future in concurrent.futures.as_completed(futures):
            trial_number = futures[future]
            try:
                results.append(future.result())
                print(f"OK probe-{trial_number}", file=sys.stderr)
            except Exception as exc:
                print(f"ERROR probe-{trial_number}: {exc}", file=sys.stderr)
                raise

    results.sort(key=lambda entry: int(entry["trial_id"].split("-")[1]))
    payload = {
        "round": round_name,
        "mode": "discoverability-probe",
        "question_id": args.question_id,
        "question": args.question,
        "subject": {
            "model": model,
            "reasoning_effort": reasoning_effort,
            "service_tier": service_tier,
        },
        "subject_isolation": {
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
                "probe question",
            ],
            "work_root": str(work_root),
        },
        "result": results,
    }
    write_json_atomic(output_path, payload)
    print(json.dumps(payload, indent=2, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as exc:
        print(f"run-codex-probes.py: {exc}", file=sys.stderr)
        sys.exit(1)
