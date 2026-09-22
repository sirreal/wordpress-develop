#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
FUZZ_ROOT="$(cd "$SCRIPT_DIR/.." && pwd -P)"
CHROME_DIR="$FUZZ_ROOT/oracles/chrome"
INSTALLER="$CHROME_DIR/install.sh"
VERSION='150.0.7871.114'
NODE_BIN="$(dirname "$(command -v node)")"

fail() {
	printf 'FAIL: %s\n' "$*" >&2
	exit 1
}

sha256_file() {
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum "$1" | awk '{ print $1 }'
	else
		shasum -a 256 "$1" | awk '{ print $1 }'
	fi
}

tree_digest() {
	tar -cf - -C "$(dirname "$1")" "$(basename "$1")" | shasum -a 256 | awk '{ print $1 }'
}

tmp_root="$(mktemp -d "${TMPDIR:-/tmp}/chrome-install-integrity.XXXXXX")"
background_pids=()
cleanup() {
	for pid in "${background_pids[@]}"; do
		kill "$pid" 2>/dev/null || true
	done
	for pid in "${background_pids[@]}"; do
		wait "$pid" 2>/dev/null || true
	done
	rm -rf "$tmp_root"
}
trap cleanup EXIT HUP INT TERM

for manifest in SHA256SUMS EXECUTABLE_SHA256SUMS; do
	entries="$(awk 'NF && $1 !~ /^#/ { count++ } END { print count + 0 }' "$CHROME_DIR/$manifest")"
	[[ "$entries" -eq 3 ]] || fail "$manifest must have exactly three entries"
	for checked_platform in mac-arm64 mac-x64 linux64; do
		if [[ "$manifest" = SHA256SUMS ]]; then
			suffix="chrome-$VERSION-$checked_platform.zip"
		else
			suffix="$checked_platform.executable"
		fi
		count="$(awk -v name="$suffix" '$2 == name { count++ } END { print count + 0 }' "$CHROME_DIR/$manifest")"
		fields="$(awk -v name="$suffix" '$2 == name { print NF }' "$CHROME_DIR/$manifest")"
		digest="$(awk -v name="$suffix" '$2 == name { print $1 }' "$CHROME_DIR/$manifest")"
		[[ "$count" -eq 1 && "$fields" -eq 2 && "$digest" =~ ^[0-9a-f]{64}$ ]] || fail "invalid $manifest entry for $checked_platform"
	done
done

setup_fixture() {
	local name="$1"
	local selected_platform="${2:-mac-arm64}"
	fixture="$tmp_root/$name"
	oracle_dir="$fixture/oracle"
	install_root="$fixture/install"
	fake_bin="$fixture/bin"
	log_dir="$fixture/log"
	payload_root="$fixture/payload"
	mkdir -p "$oracle_dir" "$fake_bin" "$log_dir" "$payload_root"
	cp "$INSTALLER" "$oracle_dir/install.sh"
	chmod 0755 "$oracle_dir/install.sh"
	printf '%s\n' "$VERSION" >"$oracle_dir/VERSION"

	case "$selected_platform" in
		mac-arm64)
			fake_os=Darwin
			fake_arch=arm64
			platform=mac-arm64
			archive_dir=chrome-mac-arm64
			executable_relative='Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing'
			;;
		mac-x64)
			fake_os=Darwin
			fake_arch=x86_64
			platform=mac-x64
			archive_dir=chrome-mac-x64
			executable_relative='Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing'
			;;
		linux64)
			fake_os=Linux
			fake_arch=x86_64
			platform=linux64
			archive_dir=chrome-linux64
			executable_relative=chrome
			;;
		*) fail "unknown fixture platform $selected_platform" ;;
	esac
	archive_name="chrome-$VERSION-$platform.zip"
	archive_source="$fixture/download-source"
	payload_executable="$payload_root/$archive_dir/$executable_relative"
	destination="$install_root/$VERSION/$platform"
	installed_executable="$destination/$archive_dir/$executable_relative"
	marker="$destination/.html-api-fuzz-verified"
	mkdir -p "$(dirname "$payload_executable")"
	printf '%s\n' 'authenticated archive fixture' >"$archive_source"

	cat >"$payload_executable" <<'SH'
#!/bin/sh
printf '%s\n' invoked >> "$FAKE_EXECUTION_LOG"
call_count=$(wc -l < "$FAKE_EXECUTION_LOG" | tr -d '[:space:]')
if [ -n "${FAKE_VERSION_SLEEP:-}" ]; then
	sleep "$FAKE_VERSION_SLEEP"
fi
if [ -n "${FAKE_VERSION_FORK_PID_FILE:-}" ]; then
	(
		trap '' TERM
		while :; do sleep 1; done
	) &
	printf '%s\n' "$!" > "$FAKE_VERSION_FORK_PID_FILE"
fi
if [ -n "${FAKE_VERSION_OUTPUT_BYTES:-}" ]; then
	dd if=/dev/zero bs="$FAKE_VERSION_OUTPUT_BYTES" count=1 2>/dev/null | tr '\000' x
fi
if [ "${FAKE_VERSION_HANG:-0}" = 1 ]; then
	if [ -n "${FAKE_VERSION_PAUSE_FILE:-}" ]; then
		printf '%s\n' paused > "$FAKE_VERSION_PAUSE_FILE"
	fi
	trap '' TERM
	while :; do sleep 1; done
fi
if [ -n "${FAKE_VERSION_PAUSE_FILE:-}" ] &&
	{ [ -z "${FAKE_VERSION_PAUSE_ON_CALL:-}" ] || [ "$FAKE_VERSION_PAUSE_ON_CALL" = "$call_count" ]; }; then
	printf '%s\n' paused > "$FAKE_VERSION_PAUSE_FILE"
	while [ -e "$FAKE_VERSION_PAUSE_FILE" ]; do
		sleep 0.01
	done
fi
if [ "${FAKE_MUTATE_SELF:-0}" = 1 ] || [ "${FAKE_MUTATE_SELF_ON_CALL:-0}" = "$call_count" ]; then
	printf '%s\n' '# mutation' >> "$0"
fi
if [ "${FAKE_MUTATE_MARKER:-0}" = 1 ]; then
	printf '%s\n' changed > "$FAKE_MARKER_PATH"
fi
printf 'Google Chrome for Testing %s\n' "${FAKE_REPORTED_VERSION:-150.0.7871.114}"
SH
	chmod 0755 "$payload_executable"

	cat >"$fake_bin/uname" <<'SH'
#!/bin/sh
case "${1:-}" in
	-s) printf '%s\n' "$FAKE_UNAME_S" ;;
	-m) printf '%s\n' "$FAKE_UNAME_M" ;;
	*) exit 2 ;;
esac
SH
cat >"$fake_bin/curl" <<'SH'
#!/bin/sh
printf '%s\n' curl >> "$FAKE_CURL_LOG"
printf '%s\n' "$*" >> "$FAKE_CURL_ARGS_LOG"
output=
while [ "$#" -gt 0 ]; do
	if [ "$1" = --output ]; then
		output=$2
		shift 2
	else
		shift
	fi
done
[ -n "$output" ]
if [ "${FAKE_CURL_FAIL:-0}" = 1 ]; then
	printf '%s\n' partial >"$output"
	exit 22
fi
cp "$FAKE_ARCHIVE_SOURCE" "$output"
SH
	cat >"$fake_bin/unzip" <<'SH'
#!/bin/sh
printf '%s\n' unzip >> "$FAKE_UNZIP_LOG"
destination=
while [ "$#" -gt 0 ]; do
	if [ "$1" = -d ]; then
		destination=$2
		shift 2
	else
		shift
	fi
done
[ -n "$destination" ]
if [ "${FAKE_WRONG_LAYOUT:-0}" = 1 ]; then
	exit 0
fi
target="$destination/$FAKE_ARCHIVE_DIR/$FAKE_EXECUTABLE_RELATIVE"
mkdir -p "$(dirname "$target")"
cp "$FAKE_PAYLOAD_EXECUTABLE" "$target"
chmod 0755 "$target"
SH
	chmod 0755 "$fake_bin/uname" "$fake_bin/curl" "$fake_bin/unzip"
	refresh_manifests
}

refresh_manifests() {
	archive_sha="$(sha256_file "$archive_source")"
	executable_sha="$(sha256_file "$payload_executable")"
	printf '%s  %s\n' "$archive_sha" "$archive_name" >"$oracle_dir/SHA256SUMS"
	printf '%s  %s.executable\n' "$executable_sha" "$platform" >"$oracle_dir/EXECUTABLE_SHA256SUMS"
}

run_installer() {
	env \
		PATH="$fake_bin:$NODE_BIN:/usr/bin:/bin" \
		FAKE_UNAME_S="$fake_os" \
		FAKE_UNAME_M="$fake_arch" \
		FAKE_ARCHIVE_SOURCE="$archive_source" \
		FAKE_ARCHIVE_DIR="$archive_dir" \
		FAKE_EXECUTABLE_RELATIVE="$executable_relative" \
		FAKE_PAYLOAD_EXECUTABLE="$payload_executable" \
		FAKE_CURL_LOG="$log_dir/curl.log" \
		FAKE_CURL_ARGS_LOG="$log_dir/curl-args.log" \
		FAKE_UNZIP_LOG="$log_dir/unzip.log" \
		FAKE_EXECUTION_LOG="$log_dir/execution.log" \
		FAKE_MARKER_PATH="$marker" \
		HTML_API_FUZZ_CHROME_INSTALL_ROOT="$install_root" \
		HTML_API_FUZZ_CHROME_LOCK_ATTEMPTS=100 \
		"$@" \
		"$oracle_dir/install.sh"
}

run_print_path() {
	env \
		PATH="$fake_bin:$NODE_BIN:/usr/bin:/bin" \
		FAKE_UNAME_S="$fake_os" \
		FAKE_UNAME_M="$fake_arch" \
		HTML_API_FUZZ_CHROME_INSTALL_ROOT="$install_root" \
		"$oracle_dir/install.sh" --print-path
}

clear_logs() {
	rm -f "$log_dir"/*.log
}

wait_for_path_gone() {
	local target="$1"
	for _ in {1..500}; do
		[[ ! -e "$target" ]] && return 0
		sleep 0.01
	done
	fail "path survived bounded cleanup: $target"
}

wait_for_pid_gone() {
	local pid="$1"
	for _ in {1..500}; do
		if ! kill -0 "$pid" 2>/dev/null; then
			return 0
		fi
		sleep 0.01
	done
	fail "process survived bounded cleanup: $pid"
}

assert_no_execution() {
	[[ ! -e "$log_dir/execution.log" ]] || fail "$1 executed Chrome unexpectedly"
}

for selected in mac-arm64 mac-x64 linux64; do
	setup_fixture "print-$selected" "$selected"
	printed="$(run_print_path)"
	[[ "$printed" = "$installed_executable" ]] || fail "--print-path mismatch for $selected"
	[[ ! -e "$log_dir/curl.log" && ! -e "$log_dir/unzip.log" && ! -e "$log_dir/execution.log" ]] || fail "--print-path executed a tool"
done

for mutation in missing embedded-space extra-line missing-newline; do
	setup_fixture "version-$mutation"
	case "$mutation" in
		missing) rm "$oracle_dir/VERSION" ;;
		embedded-space) printf '%s\n' '150.0.7871. 114' >"$oracle_dir/VERSION" ;;
		extra-line) printf '%s\n%s\n' "$VERSION" extra >"$oracle_dir/VERSION" ;;
		missing-newline) printf '%s' "$VERSION" >"$oracle_dir/VERSION" ;;
	esac
	if run_installer >"$fixture/out" 2>"$fixture/err"; then
		fail "VERSION $mutation mutation was accepted"
	fi
	[[ ! -e "$log_dir/curl.log" && ! -e "$log_dir/unzip.log" ]] || fail "VERSION $mutation reached external tools"
	assert_no_execution "VERSION $mutation"
done

for manifest in SHA256SUMS EXECUTABLE_SHA256SUMS; do
	for mutation in missing duplicate malformed; do
		setup_fixture "manifest-$manifest-$mutation"
		case "$mutation" in
			missing) : >"$oracle_dir/$manifest" ;;
			duplicate) cp "$oracle_dir/$manifest" "$oracle_dir/$manifest.copy"; cat "$oracle_dir/$manifest.copy" >>"$oracle_dir/$manifest" ;;
			malformed) sed 's/^[0-9a-f][0-9a-f]*/not-a-digest/' "$oracle_dir/$manifest" >"$oracle_dir/$manifest.tmp"; mv "$oracle_dir/$manifest.tmp" "$oracle_dir/$manifest" ;;
		esac
		if run_installer >"$fixture/out" 2>"$fixture/err"; then
			fail "$manifest $mutation mutation was accepted"
		fi
		[[ ! -e "$log_dir/curl.log" && ! -e "$log_dir/unzip.log" ]] || fail "$manifest $mutation reached external tools"
		assert_no_execution "$manifest $mutation"
	done
done

setup_fixture corrupt-archive
good_sha="$(printf '%s\n' good | shasum -a 256 | awk '{ print $1 }')"
printf '%s  %s\n' "$good_sha" "$archive_name" >"$oracle_dir/SHA256SUMS"
if run_installer >"$fixture/out" 2>"$fixture/err"; then
	fail 'corrupt archive was accepted'
fi
[[ -e "$log_dir/curl.log" && ! -e "$log_dir/unzip.log" ]] || fail 'corrupt archive crossed authentication boundary'
assert_no_execution 'corrupt archive'

setup_fixture curl-failure
if run_installer FAKE_CURL_FAIL=1 >"$fixture/out" 2>"$fixture/err"; then
	fail 'failing curl unexpectedly succeeded'
fi
[[ -e "$log_dir/curl.log" && ! -e "$log_dir/unzip.log" ]] || fail 'failing curl crossed the download boundary'
assert_no_execution 'failing curl'
[[ ! -d "$install_root/.install.lock" ]] || fail 'failing curl left its install lock'
if find "$install_root" -name '*.partial.*' -o -name '.installing.*' -o -name '*.previous.*' | grep -q .; then
	fail 'failing curl left transaction residue'
fi

setup_fixture wrong-layout
if run_installer FAKE_WRONG_LAYOUT=1 >"$fixture/out" 2>"$fixture/err"; then
	fail 'wrong archive layout was accepted'
fi
[[ -e "$log_dir/unzip.log" ]] || fail 'wrong-layout test did not reach extraction'
assert_no_execution 'wrong layout'

setup_fixture executable-mismatch
printf '%064d  %s.executable\n' 0 "$platform" >"$oracle_dir/EXECUTABLE_SHA256SUMS"
if run_installer >"$fixture/out" 2>"$fixture/err"; then
	fail 'wrong executable hash was accepted'
fi
assert_no_execution 'executable mismatch'

setup_fixture wrong-version
if run_installer FAKE_REPORTED_VERSION=149.0.0.0 >"$fixture/out" 2>"$fixture/err"; then
	fail 'wrong authenticated version was accepted'
fi
if [[ ! -e "$log_dir/execution.log" ]]; then
	cat "$fixture/err" >&2
	fail 'wrong-version test did not reach authenticated probe'
fi

setup_fixture probe-output-limit
started_at=$SECONDS
if run_installer FAKE_VERSION_OUTPUT_BYTES=16385 >"$fixture/out" 2>"$fixture/err"; then
	fail 'oversized Chrome version-probe output was accepted'
fi
elapsed=$((SECONDS - started_at))
[[ "$elapsed" -lt 5 ]] || fail 'oversized version probe did not fail within its bound'
[[ -e "$log_dir/execution.log" ]] || fail 'oversized-output test did not execute authenticated probe'
[[ ! -e "$installed_executable" && ! -d "$install_root/.install.lock" ]] || fail 'oversized probe published or left its lock'
if find "$install_root" \( -name '.installing.*' -o -name '.version-probe.*' \) | grep -q .; then
	fail 'oversized probe left staging or result residue'
fi

setup_fixture probe-timeout-fork
pause_file="$fixture/probe.pause"
fork_pid_file="$fixture/fork.pid"
started_at=$SECONDS
if run_installer \
	HTML_API_FUZZ_CHROME_TEST_PROBE_TIMEOUT_MS=200 \
	FAKE_VERSION_HANG=1 \
	FAKE_VERSION_PAUSE_FILE="$pause_file" \
	FAKE_VERSION_FORK_PID_FILE="$fork_pid_file" \
	>"$fixture/out" 2>"$fixture/err"; then
	fail 'hanging/forking Chrome version probe was accepted'
fi
elapsed=$((SECONDS - started_at))
[[ "$elapsed" -lt 5 ]] || fail 'hanging/forking version probe exceeded its cleanup bound'
[[ -f "$fork_pid_file" ]] || fail 'forking version probe did not publish its descendant PID'
wait_for_pid_gone "$(cat "$fork_pid_file")"
[[ ! -e "$installed_executable" && ! -d "$install_root/.install.lock" ]] || fail 'timed-out probe published or left its lock'
if find "$install_root" \( -name '.installing.*' -o -name '.version-probe.*' \) | grep -q .; then
	fail 'timed-out probe left staging or result residue'
fi
setup_fixture valid-cache
run_installer >"$fixture/first.out"
grep -q -- '--connect-timeout 15' "$log_dir/curl-args.log" || fail 'curl connect timeout was omitted'
grep -q -- '--max-time 300' "$log_dir/curl-args.log" || fail 'curl overall timeout was omitted'
if grep -q -- '--retry' "$log_dir/curl-args.log"; then
	fail 'curl retries made the 300-second transfer bound per-attempt instead of overall'
fi
[[ -x "$installed_executable" && -f "$marker" ]] || fail 'valid install was not published'
grep -qx 'schema=1' "$marker" || fail 'marker schema missing'
grep -qx "executable_sha256=$executable_sha" "$marker" || fail 'marker executable hash missing'
clear_logs
run_installer >"$fixture/cache.out"
[[ ! -e "$log_dir/curl.log" && ! -e "$log_dir/unzip.log" ]] || fail 'valid cache reached download/extraction'
[[ "$(wc -l < "$log_dir/execution.log" | tr -d '[:space:]')" -eq 1 ]] || fail 'valid cache probe count mismatch'

setup_fixture coordinated-tamper
run_installer >/dev/null
clear_logs
cat >"$installed_executable" <<'SH'
#!/bin/sh
printf evil >> "$FAKE_EVIL_LOG"
printf 'Google Chrome for Testing 150.0.7871.114\n'
SH
chmod 0755 "$installed_executable"
evil_sha="$(sha256_file "$installed_executable")"
printf 'schema=1\nversion=%s\nplatform=%s\narchive_sha256=%s\nexecutable_sha256=%s\n' "$VERSION" "$platform" "$archive_sha" "$evil_sha" >"$marker"
run_installer FAKE_EVIL_LOG="$log_dir/evil.log" >/dev/null
[[ ! -e "$log_dir/evil.log" ]] || fail 'coordinated marker+executable tamper was executed'
[[ "$(sha256_file "$installed_executable")" = "$executable_sha" ]] || fail 'coordinated tamper was not replaced'

setup_fixture unmarked-corrupt-archive
run_installer >/dev/null
printf '%s\n' malformed >"$marker"
printf '%s\n' corrupt >"$install_root/.downloads/$archive_name"
clear_logs
if run_installer >"$fixture/out" 2>"$fixture/err"; then
	fail 'unmarked install with corrupt archive was accepted'
fi
assert_no_execution 'unmarked existing install'

setup_fixture cache-self-race
run_installer >/dev/null
clear_logs
if run_installer FAKE_MUTATE_SELF=1 >"$fixture/out" 2>"$fixture/err"; then
	fail 'self-replacing cache probe was accepted'
fi
grep -q 'changed or failed' "$fixture/err" || fail 'self-replacement lacked fail-closed diagnostic'
[[ ! -e "$log_dir/unzip.log" ]] || fail 'self-replacing cache probe fell through to reinstall'

setup_fixture cache-marker-race
run_installer >/dev/null
clear_logs
if run_installer FAKE_MUTATE_MARKER=1 >"$fixture/out" 2>"$fixture/err"; then
	fail 'marker-replacing cache probe was accepted'
fi
[[ ! -e "$log_dir/unzip.log" ]] || fail 'marker-replacing cache probe fell through to reinstall'

for phase in after-backup after-publish after-marker; do
	setup_fixture "interrupt-$phase"
	run_installer >/dev/null
	printf '%s\n' stale >"$marker"
	before="$(tree_digest "$destination")"
	if run_installer HTML_API_FUZZ_CHROME_TEST_INTERRUPT_PHASE="$phase" >"$fixture/out" 2>"$fixture/err"; then
		fail "$phase interruption reported success"
	fi
	after="$(tree_digest "$destination")"
	[[ "$before" = "$after" ]] || fail "$phase interruption did not restore prior destination"
done

setup_fixture staged-auth-preservation
run_installer >/dev/null
printf '%s\n' stale >"$marker"
before="$(tree_digest "$destination")"
clear_logs
if run_installer FAKE_MUTATE_SELF_ON_CALL=1 >"$fixture/out" 2>"$fixture/err"; then
	fail 'staged authenticated snapshot mutation was accepted'
fi
after="$(tree_digest "$destination")"
[[ "$before" = "$after" ]] || fail 'staged-auth failure changed prior destination'

setup_fixture hard-owner-death-before-probe-child
owner_pid_file="$fixture/owner.pid"
watchdog_pid_file="$fixture/watchdog.pid"
startup_pause_file="$fixture/watchdog-startup.pause"
run_installer \
	HTML_API_FUZZ_CHROME_TEST_OWNER_PID_FILE="$owner_pid_file" \
	HTML_API_FUZZ_CHROME_TEST_WATCHDOG_PID_FILE="$watchdog_pid_file" \
	HTML_API_FUZZ_CHROME_TEST_WATCHDOG_PRE_CHILD_PAUSE_FILE="$startup_pause_file" \
	>"$fixture/out" 2>"$fixture/err" &
installer_job=$!
background_pids+=( "$installer_job" )
for _ in {1..500}; do
	[[ -f "$owner_pid_file" && -f "$watchdog_pid_file" && -f "$startup_pause_file" ]] && break
	sleep 0.01
done
[[ -f "$owner_pid_file" && -f "$watchdog_pid_file" && -f "$startup_pause_file" ]] || fail 'pre-child hard-death fixture did not reach watchdog startup pause'
owner_pid="$(cat "$owner_pid_file")"
watchdog_pid="$(cat "$watchdog_pid_file")"
kill -KILL "$owner_pid"
rm -f "$startup_pause_file"
wait "$installer_job" 2>/dev/null || true
background_pids=()
wait_for_pid_gone "$watchdog_pid"
wait_for_path_gone "$install_root/.install.lock"
[[ ! -e "$installed_executable" ]] || fail 'pre-child hard owner death published Chrome'
[[ ! -e "$log_dir/execution.log" ]] || fail 'pre-child hard owner death executed the probe binary'
if find "$install_root" \( -name '.installing.*' -o -name '.version-probe.*' \) | grep -q .; then
	fail 'pre-child hard owner death left staging or result residue'
fi

setup_fixture hard-owner-death
run_installer >/dev/null
printf '%s\n' stale >"$marker"
before="$(tree_digest "$destination")"
clear_logs
owner_pid_file="$fixture/owner.pid"
watchdog_pid_file="$fixture/watchdog.pid"
fork_pid_file="$fixture/fork.pid"
pause_file="$fixture/probe.pause"
run_installer \
	HTML_API_FUZZ_CHROME_TEST_OWNER_PID_FILE="$owner_pid_file" \
	HTML_API_FUZZ_CHROME_TEST_WATCHDOG_PID_FILE="$watchdog_pid_file" \
	HTML_API_FUZZ_CHROME_TEST_PROBE_TIMEOUT_MS=10000 \
	FAKE_VERSION_HANG=1 \
	FAKE_VERSION_PAUSE_FILE="$pause_file" \
	FAKE_VERSION_FORK_PID_FILE="$fork_pid_file" \
	>"$fixture/out" 2>"$fixture/err" &
installer_job=$!
background_pids+=( "$installer_job" )
for _ in {1..500}; do
	[[ -f "$owner_pid_file" && -f "$watchdog_pid_file" && -f "$fork_pid_file" && -f "$pause_file" ]] && break
	sleep 0.01
done
[[ -f "$owner_pid_file" && -f "$watchdog_pid_file" && -f "$fork_pid_file" && -f "$pause_file" ]] || fail 'hard-death fixture did not reach its forking probe'
owner_pid="$(cat "$owner_pid_file")"
watchdog_pid="$(cat "$watchdog_pid_file")"
fork_pid="$(cat "$fork_pid_file")"
kill -KILL "$owner_pid"
wait "$installer_job" 2>/dev/null || true
background_pids=()
wait_for_pid_gone "$watchdog_pid"
wait_for_pid_gone "$fork_pid"
wait_for_path_gone "$install_root/.install.lock"
if find "$install_root" \( -name '.installing.*' -o -name '.version-probe.*' \) | grep -q .; then
	fail 'hard owner death left staging or result residue'
fi
after="$(tree_digest "$destination")"
[[ "$before" = "$after" ]] || fail 'hard owner death changed the prior installation'

setup_fixture signal-during-probe
run_installer >/dev/null
printf '%s\n' stale >"$marker"
before="$(tree_digest "$destination")"
clear_logs
owner_pid_file="$fixture/owner.pid"
watchdog_pid_file="$fixture/watchdog.pid"
fork_pid_file="$fixture/fork.pid"
pause_file="$fixture/probe.pause"
run_installer \
	HTML_API_FUZZ_CHROME_TEST_OWNER_PID_FILE="$owner_pid_file" \
	HTML_API_FUZZ_CHROME_TEST_WATCHDOG_PID_FILE="$watchdog_pid_file" \
	HTML_API_FUZZ_CHROME_TEST_PROBE_TIMEOUT_MS=10000 \
	FAKE_VERSION_HANG=1 \
	FAKE_VERSION_PAUSE_FILE="$pause_file" \
	FAKE_VERSION_FORK_PID_FILE="$fork_pid_file" \
	>"$fixture/out" 2>"$fixture/err" &
installer_job=$!
background_pids+=( "$installer_job" )
for _ in {1..500}; do
	[[ -f "$owner_pid_file" && -f "$watchdog_pid_file" && -f "$fork_pid_file" && -f "$pause_file" ]] && break
	sleep 0.01
done
[[ -f "$owner_pid_file" && -f "$watchdog_pid_file" && -f "$fork_pid_file" && -f "$pause_file" ]] || fail 'signal fixture did not reach its forking probe'
owner_pid="$(cat "$owner_pid_file")"
watchdog_pid="$(cat "$watchdog_pid_file")"
fork_pid="$(cat "$fork_pid_file")"
kill -TERM "$owner_pid"
wait "$installer_job" 2>/dev/null || true
background_pids=()
wait_for_pid_gone "$watchdog_pid"
wait_for_pid_gone "$fork_pid"
wait_for_path_gone "$install_root/.install.lock"
if find "$install_root" \( -name '.installing.*' -o -name '.version-probe.*' \) | grep -q .; then
	fail 'signal during probe left staging or result residue'
fi
after="$(tree_digest "$destination")"
[[ "$before" = "$after" ]] || fail 'signal during probe changed the prior installation'

for failure in layout version; do
	setup_fixture "preserve-$failure"
	run_installer >/dev/null
	printf '%s\n' stale >"$marker"
	before="$(tree_digest "$destination")"
	if [[ "$failure" = layout ]]; then
		run_installer FAKE_WRONG_LAYOUT=1 >"$fixture/out" 2>"$fixture/err" && fail 'wrong layout unexpectedly succeeded over old install'
	else
		run_installer FAKE_REPORTED_VERSION=149.0.0.0 >"$fixture/out" 2>"$fixture/err" && fail 'wrong version unexpectedly succeeded over old install'
	fi
	after="$(tree_digest "$destination")"
	[[ "$before" = "$after" ]] || fail "$failure failure changed prior destination"
done

setup_fixture stale-reaper
mkdir -p "$install_root/.install.lock"
printf 'schema=1\npid=999999\ntoken=dead-owner\n' >"$install_root/.install.lock/owner"
pause_file="$fixture/reaper.pause"
run_installer HTML_API_FUZZ_CHROME_TEST_REAPER_PAUSE_FILE="$pause_file" >"$fixture/one.out" 2>"$fixture/one.err" &
first_pid=$!
background_pids+=( "$first_pid" )
for _ in {1..500}; do
	[[ -e "$pause_file" ]] && break
	sleep 0.01
done
[[ -e "$pause_file" && -d "$install_root/.install.lock/.reaper" ]] || fail 'stale reaper did not acquire election'
if mkdir "$install_root/.install.lock" 2>/dev/null; then
	fail 'contender replaced lock while stale reaper held election'
fi
run_installer >"$fixture/two.out" 2>"$fixture/two.err" &
second_pid=$!
background_pids+=( "$second_pid" )
sleep 0.1
[[ -d "$install_root/.install.lock/.reaper" ]] || fail 'second contender disturbed elected reaper'
rm -f "$pause_file"
wait "$first_pid"
wait "$second_pid"
background_pids=()
[[ -x "$installed_executable" && ! -d "$install_root/.install.lock" ]] || fail 'stale reaper/concurrent installer did not converge'

setup_fixture empty-lock
mkdir -p "$install_root/.install.lock"
if run_installer HTML_API_FUZZ_CHROME_LOCK_ATTEMPTS=1 >"$fixture/out" 2>"$fixture/err"; then
	fail 'ownerless acquisition-gap lock was unsafely reaped'
fi
[[ -d "$install_root/.install.lock" && ! -e "$installed_executable" ]] || fail 'ownerless lock did not fail closed for manual recovery'

# Exercise a live-owner contender through publication and atomic lock release.
setup_fixture release-vs-reaper
version_pause="$fixture/version.pause"
run_installer FAKE_VERSION_PAUSE_FILE="$version_pause" FAKE_VERSION_PAUSE_ON_CALL=1 >"$fixture/one.out" 2>"$fixture/one.err" &
first_pid=$!
background_pids+=( "$first_pid" )
for _ in {1..500}; do
	[[ -e "$version_pause" ]] && break
	sleep 0.01
done
[[ -e "$version_pause" ]] || fail 'live owner did not reach version pause'
run_installer >"$fixture/two.out" 2>"$fixture/two.err" &
second_pid=$!
background_pids+=( "$second_pid" )
sleep 0.2
[[ -d "$install_root/.install.lock" ]] || fail 'live-owner contender disturbed the held lock'
rm -f "$version_pause"
wait "$first_pid"
wait "$second_pid"
background_pids=()
[[ -x "$installed_executable" && ! -d "$install_root/.install.lock" ]] || fail 'release-vs-reaper interleaving left a lock or incomplete install'

setup_fixture stuck-reaper
started_at=$SECONDS
if run_installer HTML_API_FUZZ_CHROME_TEST_STICK_REAPER_ON_RELEASE=1 >"$fixture/out" 2>"$fixture/err"; then
	fail 'stuck release reaper reported success'
fi
elapsed=$((SECONDS - started_at))
[[ "$elapsed" -lt 5 ]] || fail 'stuck release reaper did not fail within its bound'
grep -q 'Timed out serializing' "$fixture/err" || fail 'stuck release reaper lacked bounded-failure diagnostic'

setup_fixture failed-release-move
if run_installer HTML_API_FUZZ_CHROME_TEST_FAIL_RELEASE_MV=1 >"$fixture/out" 2>"$fixture/err"; then
	fail 'failed authenticated lock move reported success'
fi
grep -q 'Could not atomically release' "$fixture/err" || fail 'failed lock move lacked fail-closed diagnostic'
[[ -d "$install_root/.install.lock" ]] || fail 'failed lock move discarded the authenticated lock'
run_installer >"$fixture/recovered.out"
[[ ! -d "$install_root/.install.lock" ]] || fail 'stale failed-release lock was not recoverable'

setup_fixture concurrent
run_installer FAKE_VERSION_SLEEP=0.2 >"$fixture/one.out" 2>"$fixture/one.err" &
first_pid=$!
background_pids+=( "$first_pid" )
run_installer FAKE_VERSION_SLEEP=0.2 >"$fixture/two.out" 2>"$fixture/two.err" &
second_pid=$!
background_pids+=( "$second_pid" )
wait "$first_pid"
wait "$second_pid"
background_pids=()
[[ -x "$installed_executable" && -f "$marker" && ! -d "$install_root/.install.lock" ]] || fail 'concurrent installers left incomplete state'
[[ "$(cat "$fixture/one.out")" = "$installed_executable" && "$(cat "$fixture/two.out")" = "$installed_executable" ]] || fail 'concurrent installers disagreed'

printf '%s\n' 'OK chrome-install-integrity-smoke'
