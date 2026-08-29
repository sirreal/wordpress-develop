#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
VERSION_FILE="$SCRIPT_DIR/VERSION"
if [ ! -f "$VERSION_FILE" ]; then
	echo "Missing Chrome VERSION file: $VERSION_FILE" >&2
	exit 1
fi
VERSION=$(sed -n '1p' "$VERSION_FILE")
is_canonical_version() {
	case "$1" in
		''|*[!0-9.]*) return 1 ;;
	esac
	old_ifs=$IFS
	IFS=.
	set -- $1
	IFS=$old_ifs
	[ "$#" -eq 4 ] || return 1
	for component do
		case "$component" in
			''|*[!0-9]*) return 1 ;;
		esac
	done
}
version_snapshot=$(cat "$VERSION_FILE"; printf '%s' '__HTML_API_FUZZ_VERSION_END__')
expected_version_snapshot=$(printf '%s\n%s' "$VERSION" '__HTML_API_FUZZ_VERSION_END__')
if ! is_canonical_version "$VERSION" || [ "$version_snapshot" != "$expected_version_snapshot" ]; then
	echo "Chrome VERSION must contain exactly one canonical four-component version and a final newline." >&2
	exit 1
fi
INSTALL_ROOT=${HTML_API_FUZZ_CHROME_INSTALL_ROOT:-"$SCRIPT_DIR/.chrome-for-testing"}
ARCHIVE_MANIFEST="$SCRIPT_DIR/SHA256SUMS"
EXECUTABLE_MANIFEST="$SCRIPT_DIR/EXECUTABLE_SHA256SUMS"
LOCK_DIRECTORY="$INSTALL_ROOT/.install.lock"
LOCK_OWNER="$LOCK_DIRECTORY/owner"
LOCK_TOKEN="installer-$$-$(date +%s)"
LOCK_HELD=0
PARTIAL=
STAGING=
BACKUP=
PUBLISHING=0
COMMITTED=0
WATCHDOG_PID=
PROBE_RESULT=
PROBE_STATUS=

case "$(uname -s):$(uname -m)" in
	Darwin:arm64)
		PLATFORM=mac-arm64
		ARCHIVE_DIR=chrome-mac-arm64
		EXECUTABLE_RELATIVE='Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing'
		;;
	Darwin:x86_64)
		PLATFORM=mac-x64
		ARCHIVE_DIR=chrome-mac-x64
		EXECUTABLE_RELATIVE='Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing'
		;;
	Linux:x86_64|Linux:amd64)
		PLATFORM=linux64
		ARCHIVE_DIR=chrome-linux64
		EXECUTABLE_RELATIVE=chrome
		;;
	*)
		echo "Unsupported Chrome for Testing platform: $(uname -s) $(uname -m)" >&2
		exit 1
		;;
esac

DESTINATION="$INSTALL_ROOT/$VERSION/$PLATFORM"
EXECUTABLE="$DESTINATION/$ARCHIVE_DIR/$EXECUTABLE_RELATIVE"
ARCHIVE_NAME="chrome-$VERSION-$PLATFORM.zip"
ARCHIVE="$INSTALL_ROOT/.downloads/$ARCHIVE_NAME"
MARKER="$DESTINATION/.html-api-fuzz-verified"

if [ "$#" -gt 1 ] || { [ "$#" -eq 1 ] && [ "$1" != '--print-path' ]; }; then
	echo 'Usage: install.sh [--print-path]' >&2
	exit 2
fi
if [ "${1:-}" = '--print-path' ]; then
	printf '%s\n' "$EXECUTABLE"
	exit 0
fi

sha256_file() {
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum "$1" | awk '{ print $1 }'
	elif command -v shasum >/dev/null 2>&1; then
		shasum -a 256 "$1" | awk '{ print $1 }'
	else
		echo 'sha256sum or shasum is required.' >&2
		return 1
	fi
}

manifest_digest() {
	manifest=$1
	name=$2
	label=$3
	if [ ! -f "$manifest" ]; then
		echo "Missing Chrome $label checksum manifest: $manifest" >&2
		return 1
	fi
	count=$(awk -v name="$name" '$2 == name { count++ } END { print count + 0 }' "$manifest")
	if [ "$count" -ne 1 ]; then
		echo "Expected exactly one $label checksum for $name; found $count." >&2
		return 1
	fi
	fields=$(awk -v name="$name" '$2 == name { print NF }' "$manifest")
	digest=$(awk -v name="$name" '$2 == name { print $1 }' "$manifest")
	if [ "$fields" -ne 2 ] || [ "${#digest}" -ne 64 ]; then
		echo "Malformed $label checksum entry for $name." >&2
		return 1
	fi
	case "$digest" in
		*[!0-9a-f]*)
			echo "Malformed $label checksum entry for $name." >&2
			return 1
			;;
	esac
	printf '%s\n' "$digest"
}

EXPECTED_ARCHIVE_SHA256=$(manifest_digest "$ARCHIVE_MANIFEST" "$ARCHIVE_NAME" archive)
EXPECTED_EXECUTABLE_SHA256=$(manifest_digest "$EXECUTABLE_MANIFEST" "$PLATFORM.executable" executable)
EXPECTED_MARKER=$(printf 'schema=1\nversion=%s\nplatform=%s\narchive_sha256=%s\nexecutable_sha256=%s' \
	"$VERSION" "$PLATFORM" "$EXPECTED_ARCHIVE_SHA256" "$EXPECTED_EXECUTABLE_SHA256")

release_lock() {
	if [ "$LOCK_HELD" -ne 1 ]; then
		return
	fi
	release_attempt=0
	while [ -d "$LOCK_DIRECTORY" ] && ! mkdir "$LOCK_DIRECTORY/.reaper" 2>/dev/null; do
		release_attempt=$((release_attempt + 1))
		if [ "$release_attempt" -ge 100 ]; then
			echo 'Timed out serializing Chrome install lock release.' >&2
			return 1
		fi
		sleep 0.01
	done
	if [ -f "$LOCK_OWNER" ]; then
		owner_snapshot=$(cat "$LOCK_OWNER"; printf '%s' '__HTML_API_FUZZ_LOCK_END__')
		expected_owner=$(printf 'schema=1\npid=%s\ntoken=%s\n%s' "$$" "$LOCK_TOKEN" '__HTML_API_FUZZ_LOCK_END__')
		if [ "$owner_snapshot" = "$expected_owner" ]; then
			released="$INSTALL_ROOT/.install.lock.released.$LOCK_TOKEN"
			if [ "${HTML_API_FUZZ_CHROME_TEST_FAIL_RELEASE_MV:-0}" = 1 ] || ! mv "$LOCK_DIRECTORY" "$released" 2>/dev/null; then
				rmdir "$LOCK_DIRECTORY/.reaper" 2>/dev/null || true
				echo 'Could not atomically release the authenticated Chrome install lock.' >&2
				return 1
			fi
			if ! rm -rf "$released"; then
				echo 'Could not remove the released Chrome install lock tombstone.' >&2
				return 1
			fi
		else
			rmdir "$LOCK_DIRECTORY/.reaper" 2>/dev/null || true
			echo 'Chrome install lock owner identity changed before release.' >&2
			return 1
		fi
	else
		rmdir "$LOCK_DIRECTORY/.reaper" 2>/dev/null || true
		echo 'Chrome install lock owner metadata disappeared before release.' >&2
		return 1
	fi
	LOCK_HELD=0
}

cleanup() {
	status=$?
	if [ -n "$WATCHDOG_PID" ]; then
		kill -TERM "$WATCHDOG_PID" 2>/dev/null || true
		cleanup_wait=0
		while kill -0 "$WATCHDOG_PID" 2>/dev/null && [ "$cleanup_wait" -lt 500 ]; do
			cleanup_wait=$((cleanup_wait + 1))
			sleep 0.01
		done
		if kill -0 "$WATCHDOG_PID" 2>/dev/null; then
			kill -KILL "$WATCHDOG_PID" 2>/dev/null || true
		fi
		wait "$WATCHDOG_PID" 2>/dev/null || true
		WATCHDOG_PID=
	fi
	if [ -n "$PROBE_RESULT" ]; then
		rm -f "$PROBE_RESULT"
		PROBE_RESULT=
	fi
	if [ -n "$PROBE_STATUS" ]; then
		rm -f "$PROBE_STATUS"
		PROBE_STATUS=
	fi
	if [ -n "$PARTIAL" ] && [ -e "$PARTIAL" ]; then
		rm -f "$PARTIAL"
	fi
	if [ -n "$STAGING" ] && [ -d "$STAGING" ]; then
		rm -rf "$STAGING"
	fi
	if [ "$COMMITTED" -ne 1 ]; then
		if [ "$PUBLISHING" -eq 1 ]; then
			rm -rf "$DESTINATION"
		fi
		if [ -n "$BACKUP" ] && [ -d "$BACKUP" ]; then
			rm -rf "$DESTINATION"
			mv "$BACKUP" "$DESTINATION"
		fi
	elif [ -n "$BACKUP" ] && [ -d "$BACKUP" ]; then
		rm -rf "$BACKUP"
	fi
	release_failed=0
	if ! release_lock; then
		release_failed=1
	fi
	trap - EXIT HUP INT TERM
	if [ "$release_failed" -eq 1 ] && [ "$status" -eq 0 ]; then
		status=1
	fi
	exit "$status"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

mkdir -p "$INSTALL_ROOT"
attempt=0
max_attempts=${HTML_API_FUZZ_CHROME_LOCK_ATTEMPTS:-100}
case "$max_attempts" in
	''|*[!0-9]*) echo 'HTML_API_FUZZ_CHROME_LOCK_ATTEMPTS must be a positive integer.' >&2; exit 2 ;;
esac
if [ "$max_attempts" -lt 1 ]; then
	echo 'HTML_API_FUZZ_CHROME_LOCK_ATTEMPTS must be a positive integer.' >&2
	exit 2
fi

try_reap_stale_lock() {
	reaper="$LOCK_DIRECTORY/.reaper"
	if ! mkdir "$reaper" 2>/dev/null; then
		return 1
	fi
	if [ ! -f "$LOCK_OWNER" ]; then
		rmdir "$reaper" 2>/dev/null || true
		return 1
	fi
	first=$(cat "$LOCK_OWNER"; printf '%s' '__HTML_API_FUZZ_LOCK_END__')
	first_body=${first%__HTML_API_FUZZ_LOCK_END__}
	stale_pid=$(printf '%s' "$first_body" | sed -n 's/^pid=//p')
	stale_token=$(printf '%s' "$first_body" | sed -n 's/^token=//p')
	expected=$(printf 'schema=1\npid=%s\ntoken=%s\n%s' "$stale_pid" "$stale_token" '__HTML_API_FUZZ_LOCK_END__')
	case "$stale_pid" in
		''|*[!0-9]*) stale_pid= ;;
	esac
	if [ -z "$stale_pid" ] || [ -z "$stale_token" ] || [ "$first" != "$expected" ]; then
		rmdir "$reaper" 2>/dev/null || true
		return 1
	fi
	if kill -0 "$stale_pid" 2>/dev/null; then
		rmdir "$reaper" 2>/dev/null || true
		return 1
	fi
	if [ -n "${HTML_API_FUZZ_CHROME_TEST_REAPER_PAUSE_FILE:-}" ]; then
		printf '%s\n' "$stale_token" > "$HTML_API_FUZZ_CHROME_TEST_REAPER_PAUSE_FILE"
		while [ -e "$HTML_API_FUZZ_CHROME_TEST_REAPER_PAUSE_FILE" ]; do
			sleep 0.01
		done
	fi
	unchanged=0
	if [ ! -f "$LOCK_OWNER" ]; then
		# The exact dead owner may have been released by its surviving cleanup
		# supervisor after this reaper was elected.
		unchanged=1
	else
		second=$(cat "$LOCK_OWNER"; printf '%s' '__HTML_API_FUZZ_LOCK_END__')
		if [ "$first" = "$second" ]; then
			unchanged=1
		fi
	fi
	if [ "$unchanged" -ne 1 ]; then
		rmdir "$reaper" 2>/dev/null || true
		return 1
	fi
	tombstone="$INSTALL_ROOT/.install.lock.stale.$LOCK_TOKEN"
	if ! mv "$LOCK_DIRECTORY" "$tombstone" 2>/dev/null; then
		rmdir "$reaper" 2>/dev/null || true
		return 1
	fi
	rm -rf "$tombstone"
	return 0
}

while ! mkdir "$LOCK_DIRECTORY" 2>/dev/null; do
	if try_reap_stale_lock; then
		continue
	fi
	attempt=$((attempt + 1))
	if [ "$attempt" -ge "$max_attempts" ]; then
		echo "Timed out acquiring Chrome install lock: $LOCK_DIRECTORY" >&2
		exit 1
	fi
	sleep 0.1
done
LOCK_HELD=1
owner_partial="$LOCK_DIRECTORY/owner.$$"
printf 'schema=1\npid=%s\ntoken=%s\n' "$$" "$LOCK_TOKEN" > "$owner_partial"
mv "$owner_partial" "$LOCK_OWNER"
if [ -n "${HTML_API_FUZZ_CHROME_TEST_OWNER_PID_FILE:-}" ]; then
	printf '%s\n' "$$" > "$HTML_API_FUZZ_CHROME_TEST_OWNER_PID_FILE"
fi

marker_snapshot() {
	cat "$MARKER"
	printf '%s' '__HTML_API_FUZZ_MARKER_END__'
}

marker_matches_expected() {
	[ -f "$MARKER" ] || return 1
	actual=$(marker_snapshot)
	expected=$(printf '%s\n%s' "$EXPECTED_MARKER" '__HTML_API_FUZZ_MARKER_END__')
	[ "$actual" = "$expected" ]
}

run_chrome_version_probe() {
	probe_executable=$1
	probe_staging=${2:-}
	if ! command -v node >/dev/null 2>&1; then
		echo 'Node.js is required for the bounded Chrome version probe.' >&2
		return 1
	fi
	probe_node=$(command -v node)
	probe_node_root=${probe_node%/bin/node}
	for direct_node in "$probe_node_root"/tools/image/node/*/bin/node; do
		if [ -x "$direct_node" ]; then
			probe_node=$direct_node
		fi
	done
	if [ ! -x "$probe_node" ]; then
		echo 'Could not resolve the Node.js executable for the Chrome version probe.' >&2
		return 1
	fi
	PROBE_RESULT="$INSTALL_ROOT/.version-probe.$$.$(date +%s)"
	PROBE_STATUS="$PROBE_RESULT.status"
	probe_timeout_ms=${HTML_API_FUZZ_CHROME_TEST_PROBE_TIMEOUT_MS:-10000}
	case "$probe_timeout_ms" in
		''|*[!0-9]*) echo 'Invalid Chrome version-probe timeout.' >&2; return 1 ;;
	esac
	if [ "$probe_timeout_ms" -lt 50 ] || [ "$probe_timeout_ms" -gt 10000 ]; then
		echo 'Invalid Chrome version-probe timeout.' >&2
		return 1
	fi
	rm -f "$PROBE_RESULT"
	(
		exec "$probe_node" - "$probe_executable" "$VERSION" "$PROBE_RESULT" "$PROBE_STATUS" "$$" "$LOCK_DIRECTORY" "$LOCK_TOKEN" "$probe_staging" "$INSTALL_ROOT" "$probe_timeout_ms"
	) <<'NODE' &
'use strict';
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { spawn } = require( 'node:child_process' );

const [ executable, expectedVersion, resultPath, statusPath, ownerText, lockDirectory, lockToken, stagingPath, installRoot, timeoutText ] = process.argv.slice( 2 );
const ownerPid = Number.parseInt( ownerText, 10 );
const initialParentPid = process.ppid;
const maximumOutputBytes = 16 * 1024;
const probeTimeoutMs = Number.parseInt( timeoutText, 10 );
let child = null;
let output = Buffer.alloc( 0 );
let settling = false;
let ownerLost = false;
let ownerResourcesReleased = false;
let wallTimer = null;
let ownerTimer = null;

const startupPausePath = process.env.HTML_API_FUZZ_CHROME_TEST_WATCHDOG_PRE_CHILD_PAUSE_FILE;
if ( startupPausePath ) {
	fs.writeFileSync( startupPausePath, String( process.pid ) + '\n', { flag: 'wx', mode: 0o600 } );
	const startupGate = new Int32Array( new SharedArrayBuffer( 4 ) );
	while ( fs.existsSync( startupPausePath ) ) {
		Atomics.wait( startupGate, 0, 0, 10 );
	}
}

function delay( milliseconds ) {
	return new Promise( ( resolve ) => setTimeout( resolve, milliseconds ) );
}

function groupAlive() {
	if ( ! child?.pid ) {
		return false;
	}
	try {
		process.kill( -child.pid, 0 );
		return true;
	} catch ( error ) {
		return 'EPERM' === error.code;
	}
}

function ownerPresent() {
	if ( process.ppid !== initialParentPid ) {
		return false;
	}
	try {
		process.kill( ownerPid, 0 );
		return true;
	} catch ( error ) {
		return 'EPERM' === error.code;
	}
}

async function terminateGroup() {
	if ( ! child?.pid ) {
		return;
	}
	try {
		process.kill( -child.pid, 'SIGTERM' );
	} catch ( error ) {
		if ( 'ESRCH' !== error.code ) {
			throw error;
		}
	}
	let deadline = Date.now() + 250;
	while ( groupAlive() && Date.now() < deadline ) {
		await delay( 10 );
	}
	if ( groupAlive() ) {
		try {
			process.kill( -child.pid, 'SIGKILL' );
		} catch ( error ) {
			if ( 'ESRCH' !== error.code ) {
				throw error;
			}
		}
	}
	deadline = Date.now() + 3000;
	while ( groupAlive() && Date.now() < deadline ) {
		await delay( 10 );
	}
	if ( groupAlive() ) {
		throw new Error( 'Chrome version-probe process group survived SIGKILL.' );
	}
}

function exactOwnerRecord() {
	return 'schema=1\npid=' + ownerPid + '\ntoken=' + lockToken + '\n';
}

async function releaseOwnerResources() {
	try {
		fs.rmSync( resultPath, { force: true } );
		fs.rmSync( statusPath, { force: true } );
	} catch ( _error ) {}
	if ( stagingPath ) {
		const resolvedRoot = path.resolve( installRoot ) + path.sep;
		const resolvedStaging = path.resolve( stagingPath );
		if ( resolvedStaging.startsWith( resolvedRoot ) && path.basename( resolvedStaging ).startsWith( '.installing.' ) ) {
			fs.rmSync( resolvedStaging, { recursive: true, force: true } );
		}
	}
	const expectedLock = path.join( path.resolve( installRoot ), '.install.lock' );
	if ( path.resolve( lockDirectory ) !== expectedLock ) {
		return;
	}
	const ownerPath = path.join( expectedLock, 'owner' );
	const reaperPath = path.join( expectedLock, '.reaper' );
	const deadline = Date.now() + 3000;
	while ( Date.now() < deadline ) {
		if ( ! fs.existsSync( expectedLock ) ) {
			return;
		}
		let owner;
		try {
			owner = fs.readFileSync( ownerPath, 'utf8' );
		} catch ( error ) {
			if ( 'ENOENT' === error.code ) {
				await delay( 10 );
				continue;
			}
			throw error;
		}
		if ( owner !== exactOwnerRecord() ) {
			return;
		}
		try {
			fs.mkdirSync( reaperPath, { mode: 0o700 } );
		} catch ( error ) {
			if ( 'EEXIST' === error.code || 'ENOENT' === error.code ) {
				await delay( 10 );
				continue;
			}
			throw error;
		}
		try {
			if ( fs.readFileSync( ownerPath, 'utf8' ) !== exactOwnerRecord() ) {
				return;
			}
			const tombstone = path.join( path.resolve( installRoot ), '.install.lock.abandoned.' + lockToken );
			fs.renameSync( expectedLock, tombstone );
			fs.rmSync( tombstone, { recursive: true, force: true } );
			return;
		} finally {
			if ( fs.existsSync( reaperPath ) ) {
				try {
					fs.rmdirSync( reaperPath );
				} catch ( _error ) {}
			}
		}
	}
	throw new Error( 'Owner-death watchdog could not release the authenticated installer lock.' );
}

async function finish( error = null ) {
	if ( settling ) {
		return;
	}
	settling = true;
	clearTimeout( wallTimer );
	try {
		await terminateGroup();
		ownerLost ||= ! ownerPresent();
		if ( ownerLost ) {
			await releaseOwnerResources();
			ownerResourcesReleased = true;
		}
		if ( error ) {
			throw error;
		}
		const text = output.toString( 'utf8' );
		const matches = [ ...text.matchAll( /(^|[^0-9])([0-9]+(?:\.[0-9]+){3})(?=[^0-9]|$)/g ) ];
		const version = matches.at( -1 )?.[ 2 ];
		if ( version !== expectedVersion ) {
			throw new Error( 'Authenticated Chrome version does not match its pin.' );
		}
		if ( ! ownerPresent() ) {
			ownerLost = true;
			if ( ! ownerResourcesReleased ) {
				await releaseOwnerResources();
				ownerResourcesReleased = true;
			}
			throw new Error( 'Chrome version probe installer owner disappeared.' );
		}
		fs.writeFileSync( resultPath, version + '\n', { flag: 'wx', mode: 0o600 } );
		if ( ! ownerPresent() ) {
			ownerLost = true;
			if ( ! ownerResourcesReleased ) {
				await releaseOwnerResources();
				ownerResourcesReleased = true;
			}
			throw new Error( 'Chrome version probe installer owner disappeared.' );
		}
		clearInterval( ownerTimer );
		process.exitCode = 0;
	} catch ( failure ) {
		if ( ! ownerPresent() && ! ownerResourcesReleased ) {
			ownerLost = true;
			try {
				await releaseOwnerResources();
				ownerResourcesReleased = true;
			} catch ( cleanupError ) {
				failure = new AggregateError( [ failure, cleanupError ], 'Chrome version probe and owner-death cleanup failed.' );
			}
		}
		clearInterval( ownerTimer );
		process.stderr.write( 'Chrome version probe failed: ' + ( failure.message || String( failure ) ) + '\n' );
		process.exitCode = 1;
	}
	if ( ! ownerLost ) {
		try {
			fs.writeFileSync( statusPath, String( process.exitCode || 0 ) + '\n', { flag: 'wx', mode: 0o600 } );
		} catch ( error ) {
			process.stderr.write( 'Chrome version watchdog could not publish completion: ' + error.message + '\n' );
			process.exitCode = 1;
		}
	}
}

let ownerAlive = true;
try {
	process.kill( ownerPid, 0 );
} catch ( error ) {
	ownerAlive = 'EPERM' === error.code;
}
if ( ! Number.isSafeInteger( ownerPid ) || ownerPid < 2 ) {
	process.stderr.write( 'Chrome version probe did not start under its authenticated installer owner.\n' );
	process.exit( 1 );
} else if ( initialParentPid !== ownerPid || ! ownerAlive ) {
	ownerLost = true;
	finish( new Error( 'Chrome version probe installer owner disappeared before probe spawn.' ) );
} else {
	process.once( 'SIGINT', () => finish( new Error( 'Chrome version watchdog interrupted.' ) ) );
	process.once( 'SIGTERM', () => finish( new Error( 'Chrome version watchdog terminated.' ) ) );
	child = spawn( executable, [ '--version' ], {
		detached: true,
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} );
	const collect = ( chunk ) => {
		if ( settling ) {
			return;
		}
		if ( output.length + chunk.length > maximumOutputBytes ) {
			finish( new Error( 'Chrome version probe exceeded 16 KiB of combined output.' ) );
			return;
		}
		output = Buffer.concat( [ output, chunk ] );
	};
	child.stdout.on( 'data', collect );
	child.stderr.on( 'data', collect );
	child.once( 'error', ( error ) => finish( error ) );
	child.once( 'close', ( code, signal ) => {
		if ( 0 === code ) {
			finish();
		} else {
			finish( new Error( 'Chrome version probe exited with code ' + code + ' and signal ' + signal + '.' ) );
		}
	} );
	wallTimer = setTimeout( () => finish( new Error( 'Chrome version probe exceeded its 10 second wall timeout.' ) ), probeTimeoutMs );
	ownerTimer = setInterval( () => {
		if ( ! ownerPresent() ) {
			ownerLost = true;
			finish( new Error( 'Chrome version probe installer owner disappeared.' ) );
		}
	}, 25 );
}
NODE
	WATCHDOG_PID=$!
	if [ -n "${HTML_API_FUZZ_CHROME_TEST_WATCHDOG_PID_FILE:-}" ]; then
		printf '%s\n' "$WATCHDOG_PID" > "$HTML_API_FUZZ_CHROME_TEST_WATCHDOG_PID_FILE"
	fi
	probe_wait=0
	while kill -0 "$WATCHDOG_PID" 2>/dev/null && [ "$probe_wait" -lt 1400 ]; do
		probe_wait=$((probe_wait + 1))
		sleep 0.01
	done
	if kill -0 "$WATCHDOG_PID" 2>/dev/null; then
		kill -TERM "$WATCHDOG_PID" 2>/dev/null || true
		probe_term_wait=0
		while kill -0 "$WATCHDOG_PID" 2>/dev/null && [ "$probe_term_wait" -lt 25 ]; do
			probe_term_wait=$((probe_term_wait + 1))
			sleep 0.01
		done
		if kill -0 "$WATCHDOG_PID" 2>/dev/null; then
			kill -KILL "$WATCHDOG_PID" 2>/dev/null || true
		fi
	fi
	wait "$WATCHDOG_PID" 2>/dev/null || true
	probe_status=1
	if [ -f "$PROBE_STATUS" ]; then
		probe_status=$(sed -n '1p' "$PROBE_STATUS")
	fi
	WATCHDOG_PID=
	rm -f "$PROBE_STATUS"
	PROBE_STATUS=
	if [ "$probe_status" -ne 0 ] || [ ! -f "$PROBE_RESULT" ]; then
		rm -f "$PROBE_RESULT"
		PROBE_RESULT=
		return 1
	fi
	PROBED_VERSION=$(sed -n '1p' "$PROBE_RESULT")
	result_snapshot=$(cat "$PROBE_RESULT"; printf '%s' '__HTML_API_FUZZ_PROBE_END__')
	expected_result=$(printf '%s\n%s' "$VERSION" '__HTML_API_FUZZ_PROBE_END__')
	rm -f "$PROBE_RESULT"
	PROBE_RESULT=
	[ "$result_snapshot" = "$expected_result" ] || return 1
}

validate_installed_snapshot() {
	marker_matches_expected || return 1
	[ -x "$EXECUTABLE" ] || return 1
	before_marker=$(marker_snapshot)
	before_hash=$(sha256_file "$EXECUTABLE")
	[ "$before_hash" = "$EXPECTED_EXECUTABLE_SHA256" ] || return 1
	run_chrome_version_probe "$EXECUTABLE" '' || return 2
	installed_version=$PROBED_VERSION
	[ "$installed_version" = "$VERSION" ] || return 2
	after_hash=$(sha256_file "$EXECUTABLE")
	after_marker=$(marker_snapshot)
	[ "$after_hash" = "$EXPECTED_EXECUTABLE_SHA256" ] || return 2
	[ "$before_marker" = "$after_marker" ] || return 2
}

if validate_installed_snapshot; then
	printf '%s\n' "$EXECUTABLE"
	exit 0
else
	cache_status=$?
	if [ "$cache_status" -eq 2 ]; then
		echo 'Authenticated Chrome installation changed or failed during its cache probe.' >&2
		exit 1
	fi
fi

interrupt_for_test() {
	if [ "${HTML_API_FUZZ_CHROME_TEST_INTERRUPT_PHASE:-}" = "$1" ]; then
		kill -TERM "$$"
		exit 143
	fi
}

mkdir -p "$INSTALL_ROOT/.downloads"
URL="https://storage.googleapis.com/chrome-for-testing-public/$VERSION/$PLATFORM/chrome-$PLATFORM.zip"
if [ ! -f "$ARCHIVE" ]; then
	PARTIAL="$ARCHIVE.partial.$$"
	rm -f "$PARTIAL"
	curl --fail --location --proto '=https' --tlsv1.2 --connect-timeout 15 --max-time 300 --output "$PARTIAL" "$URL"
	downloaded_hash=$(sha256_file "$PARTIAL")
	if [ "$downloaded_hash" != "$EXPECTED_ARCHIVE_SHA256" ]; then
		echo "Chrome archive SHA-256 mismatch: expected $EXPECTED_ARCHIVE_SHA256, got $downloaded_hash" >&2
		rm -f "$PARTIAL"
		exit 1
	fi
	mv "$PARTIAL" "$ARCHIVE"
	PARTIAL=
fi

ACTUAL_ARCHIVE_SHA256=$(sha256_file "$ARCHIVE")
if [ "$ACTUAL_ARCHIVE_SHA256" != "$EXPECTED_ARCHIVE_SHA256" ]; then
	echo "Chrome archive SHA-256 mismatch: expected $EXPECTED_ARCHIVE_SHA256, got $ACTUAL_ARCHIVE_SHA256" >&2
	exit 1
fi

STAGING=$(mktemp -d "$INSTALL_ROOT/.installing.$PLATFORM.XXXXXX")
unzip -q "$ARCHIVE" -d "$STAGING"
STAGED_EXECUTABLE="$STAGING/$ARCHIVE_DIR/$EXECUTABLE_RELATIVE"
if [ ! -x "$STAGED_EXECUTABLE" ]; then
	echo "Chrome archive did not contain the expected executable: $STAGED_EXECUTABLE" >&2
	exit 1
fi
staged_hash=$(sha256_file "$STAGED_EXECUTABLE")
if [ "$staged_hash" != "$EXPECTED_EXECUTABLE_SHA256" ]; then
	echo "Chrome executable SHA-256 mismatch: expected $EXPECTED_EXECUTABLE_SHA256, got $staged_hash" >&2
	exit 1
fi
run_chrome_version_probe "$STAGED_EXECUTABLE" "$STAGING" || {
	echo "Extracted Chrome failed its version check: $STAGED_EXECUTABLE" >&2
	exit 1
}
staged_version=$PROBED_VERSION
if [ "$staged_version" != "$VERSION" ]; then
	echo "Extracted Chrome version $staged_version does not match pin $VERSION." >&2
	exit 1
fi
staged_hash_after=$(sha256_file "$STAGED_EXECUTABLE")
if [ "$staged_hash_after" != "$EXPECTED_EXECUTABLE_SHA256" ]; then
	echo 'Extracted Chrome changed during its version probe.' >&2
	exit 1
fi

mkdir -p "$(dirname "$DESTINATION")"
BACKUP="$DESTINATION.previous.$$"
rm -rf "$BACKUP"
if [ -e "$DESTINATION" ]; then
	mv "$DESTINATION" "$BACKUP"
fi
interrupt_for_test after-backup
PUBLISHING=1
if ! mv "$STAGING" "$DESTINATION"; then
	exit 1
fi
STAGING=
interrupt_for_test after-publish
marker_partial="$DESTINATION/.html-api-fuzz-verified.partial.$$"
if ! printf '%s\n' "$EXPECTED_MARKER" > "$marker_partial" || ! mv "$marker_partial" "$MARKER"; then
	exit 1
fi
interrupt_for_test after-marker

if ! marker_matches_expected || [ ! -x "$EXECUTABLE" ] || [ "$(sha256_file "$EXECUTABLE")" != "$EXPECTED_EXECUTABLE_SHA256" ]; then
	echo 'Published Chrome installation failed its marker/hash snapshot check.' >&2
	exit 1
fi
COMMITTED=1
PUBLISHING=0
rm -rf "$BACKUP"
BACKUP=
if [ "${HTML_API_FUZZ_CHROME_TEST_STICK_REAPER_ON_RELEASE:-0}" = 1 ]; then
	mkdir "$LOCK_DIRECTORY/.reaper"
fi
printf '%s\n' "$EXECUTABLE"
