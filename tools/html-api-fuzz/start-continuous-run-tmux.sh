#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd -P)"

SESSION="${SESSION:-html-api-fuzz-$(date -u +%Y%m%dT%H%M%SZ)}"
RUN_DIR="${RUN_DIR:-artifacts/html-api-fuzz/run-$(date -u +%Y%m%dT%H%M%SZ)}"
TRIAGE_DIR="${TRIAGE_DIR:-$RUN_DIR/triage}"

LANES="${LANES:-4}"
MIN_FREE_GB="${MIN_FREE_GB:-25}"
MAX_INPUT_BYTES="${MAX_INPUT_BYTES:-4096}"
PAYLOAD_POLICY="${PAYLOAD_POLICY:-}"
DOM_ORACLE="${DOM_ORACLE:-lexbor-source}"
LEXBOR_ORACLE_BIN="${LEXBOR_ORACLE_BIN:-}"
HTML5EVER_ORACLE_BIN="${HTML5EVER_ORACLE_BIN:-}"
CHROME_ORACLE_SCRIPT="${CHROME_ORACLE_SCRIPT:-}"
CHROME_EXECUTABLE="${CHROME_EXECUTABLE:-}"
NODE_BIN="${NODE_BIN:-}"
MAX_MINIMIZE="${MAX_MINIMIZE:-1}"
WATCHER_INTERVAL_SECONDS="${WATCHER_INTERVAL_SECONDS:-10}"
ORCHESTRATOR_INTERVAL_SECONDS="${ORCHESTRATOR_INTERVAL_SECONDS:-120}"
ORCHESTRATOR_MAX_CONCURRENT="${ORCHESTRATOR_MAX_CONCURRENT:-1}"
ORCHESTRATOR_MODE="${ORCHESTRATOR_MODE:-classify}"
if [[ -z "${ORCHESTRATOR_SANDBOX+x}" ]]; then
	if [[ "$ORCHESTRATOR_MODE" == "fix" ]]; then
		ORCHESTRATOR_SANDBOX="workspace-write"
	else
		ORCHESTRATOR_SANDBOX="read-only"
	fi
fi
CODEX_BIN="${CODEX_BIN:-codex}"
CODEX_MODEL="${CODEX_MODEL:-}"

if ! command -v tmux >/dev/null 2>&1; then
	echo "tmux is required." >&2
	exit 1
fi

if ! command -v php >/dev/null 2>&1; then
	echo "php is required." >&2
	exit 1
fi

if tmux has-session -t "$SESSION" 2>/dev/null; then
	echo "tmux session already exists: $SESSION" >&2
	exit 1
fi

available_kb="$(df -Pk "$REPO_ROOT" | awk 'NR == 2 { print $4 }')"
required_kb="$(( MIN_FREE_GB * 1024 * 1024 ))"
if (( available_kb < required_kb )); then
	echo "Refusing to start: only $(( available_kb / 1024 / 1024 )) GiB free under $REPO_ROOT; need at least ${MIN_FREE_GB} GiB." >&2
	echo "Free space or lower MIN_FREE_GB if this is intentional." >&2
	exit 1
fi

shell_join() {
	local out=""
	local arg
	for arg in "$@"; do
		printf -v arg "%q" "$arg"
		out+=" $arg"
	done
	printf "%s" "${out# }"
}

pane_command() {
	local label="$1"
	shift

	local command
	command="$(shell_join "$@")"
	printf "export RUN_DIR=%q TRIAGE_DIR=%q; echo '[%s] starting'; echo '[%s] RUN_DIR=%s'; %s; status=\$?; echo; echo '[%s] exited with status '\$status; exec \"\${SHELL:-/bin/zsh}\" -l" \
		"$RUN_DIR" \
		"$TRIAGE_DIR" \
		"$label" \
		"$label" \
		"$RUN_DIR" \
		"$command" \
		"$label"
}

launcher_command=(
	php tools/html-api-fuzz/launcher.php
	--lanes "$LANES"
	--duration-seconds 0
	--max-seeds 0
	--output-dir "$RUN_DIR"
	--dom-oracle "$DOM_ORACLE"
)

if [[ "$MAX_INPUT_BYTES" != "0" ]]; then
	launcher_command+=( --max-input-bytes "$MAX_INPUT_BYTES" )
fi

if [[ "$PAYLOAD_POLICY" != "" ]]; then
	launcher_command+=( --payload-policy "$PAYLOAD_POLICY" )
fi

if [[ "$LEXBOR_ORACLE_BIN" != "" ]]; then
	launcher_command+=( --lexbor-oracle-bin "$LEXBOR_ORACLE_BIN" )
fi

if [[ "$HTML5EVER_ORACLE_BIN" != "" ]]; then
	launcher_command+=( --html5ever-oracle-bin "$HTML5EVER_ORACLE_BIN" )
fi

if [[ "$CHROME_ORACLE_SCRIPT" != "" ]]; then
	launcher_command+=( --chrome-oracle-script "$CHROME_ORACLE_SCRIPT" )
fi

if [[ "$CHROME_EXECUTABLE" != "" ]]; then
	launcher_command+=( --chrome-executable "$CHROME_EXECUTABLE" )
fi

if [[ "$NODE_BIN" != "" ]]; then
	launcher_command+=( --node-bin "$NODE_BIN" )
fi

watcher_command=(
	php tools/html-api-fuzz/watcher.php
	--run-dir "$RUN_DIR"
	--state-dir "$TRIAGE_DIR"
	--interval-seconds "$WATCHER_INTERVAL_SECONDS"
	--max-minimize "$MAX_MINIMIZE"
)

orchestrator_command=(
	php tools/html-api-fuzz/codex-triage-orchestrator.php
	--triage-dir "$TRIAGE_DIR"
	--diagnostics-dir "$RUN_DIR/diagnostics"
	--repo-root "$REPO_ROOT"
	--codex-bin "$CODEX_BIN"
	--mode "$ORCHESTRATOR_MODE"
	--sandbox "$ORCHESTRATOR_SANDBOX"
	--max-concurrent "$ORCHESTRATOR_MAX_CONCURRENT"
	--interval-seconds "$ORCHESTRATOR_INTERVAL_SECONDS"
)

if [[ "$CODEX_MODEL" != "" ]]; then
	orchestrator_command+=( --model "$CODEX_MODEL" )
fi

launcher_pane="$(
	tmux new-session -d -P -F '#{pane_id}' -s "$SESSION" -n fuzz -c "$REPO_ROOT" \
		"$(pane_command launcher "${launcher_command[@]}")"
)"

for _ in {1..30}; do
	if [[ -f "$REPO_ROOT/$RUN_DIR/launcher-state.json" ]]; then
		break
	fi
	sleep 1
done

if [[ ! -f "$REPO_ROOT/$RUN_DIR/launcher-state.json" ]]; then
	echo "Launcher did not create $RUN_DIR/launcher-state.json within 30 seconds." >&2
	echo "Inspect with: tmux attach -t $SESSION" >&2
	exit 1
fi

watcher_pane="$(
	tmux split-window -P -F '#{pane_id}' -t "$launcher_pane" -h -c "$REPO_ROOT" \
		"$(pane_command watcher "${watcher_command[@]}")"
)"

for _ in {1..30}; do
	if [[ -f "$REPO_ROOT/$TRIAGE_DIR/state.json" ]]; then
		break
	fi
	sleep 1
done

if [[ ! -f "$REPO_ROOT/$TRIAGE_DIR/state.json" ]]; then
	echo "Watcher did not create $TRIAGE_DIR/state.json within 30 seconds." >&2
	echo "Inspect with: tmux attach -t $SESSION" >&2
	exit 1
fi

tmux split-window -P -F '#{pane_id}' -t "$watcher_pane" -v -c "$REPO_ROOT" \
	"$(pane_command orchestrator "${orchestrator_command[@]}")" >/dev/null

tmux select-layout -t "$SESSION" tiled >/dev/null
tmux set-environment -t "$SESSION" RUN_DIR "$RUN_DIR"
tmux set-environment -t "$SESSION" TRIAGE_DIR "$TRIAGE_DIR"

echo "session=$SESSION"
echo "run_dir=$RUN_DIR"
echo "triage_dir=$TRIAGE_DIR"
echo "attach=tmux attach -t $SESSION"
tmux list-panes -t "$SESSION" -F '#{pane_index}: #{pane_current_command} dead=#{pane_dead}'
