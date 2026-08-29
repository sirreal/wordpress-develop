#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
VERSION=$(tr -d '[:space:]' < "$SCRIPT_DIR/VERSION")
INSTALL_ROOT=${HTML_API_FUZZ_CHROME_INSTALL_ROOT:-"$SCRIPT_DIR/.chrome-for-testing"}
CHECKSUM_MANIFEST="$SCRIPT_DIR/SHA256SUMS"

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

if [ "${1:-}" = '--print-path' ]; then
	printf '%s\n' "$EXECUTABLE"
	exit 0
fi

if [ ! -f "$CHECKSUM_MANIFEST" ]; then
	echo "Missing Chrome checksum manifest: $CHECKSUM_MANIFEST" >&2
	exit 1
fi

ENTRY_COUNT=$(awk -v name="$ARCHIVE_NAME" '$2 == name { count++ } END { print count + 0 }' "$CHECKSUM_MANIFEST")
if [ "$ENTRY_COUNT" -ne 1 ]; then
	echo "Expected exactly one checksum for $ARCHIVE_NAME; found $ENTRY_COUNT." >&2
	exit 1
fi

ENTRY_FIELD_COUNT=$(awk -v name="$ARCHIVE_NAME" '$2 == name { print NF }' "$CHECKSUM_MANIFEST")
EXPECTED_SHA256=$(awk -v name="$ARCHIVE_NAME" '$2 == name { print $1 }' "$CHECKSUM_MANIFEST")
if [ "$ENTRY_FIELD_COUNT" -ne 2 ] || [ "${#EXPECTED_SHA256}" -ne 64 ]; then
	echo "Malformed checksum entry for $ARCHIVE_NAME." >&2
	exit 1
fi
case "$EXPECTED_SHA256" in
	*[!0-9a-f]*)
		echo "Malformed checksum entry for $ARCHIVE_NAME." >&2
		exit 1
		;;
esac

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

marker_matches() {
	[ -f "$MARKER" ] || return 1
	actual=$(cat "$MARKER"; printf '%s' '__HTML_API_FUZZ_MARKER_END__')
	expected=$(printf 'version=%s\nplatform=%s\narchive_sha256=%s\n%s' \
		"$VERSION" "$PLATFORM" "$EXPECTED_SHA256" '__HTML_API_FUZZ_MARKER_END__')
	[ "$actual" = "$expected" ]
}

if marker_matches && [ -x "$EXECUTABLE" ]; then
	if ! INSTALLED_VERSION_OUTPUT=$("$EXECUTABLE" --version); then
		echo "Chrome at $EXECUTABLE failed its version check." >&2
		exit 1
	fi
	INSTALLED_VERSION=$(printf '%s\n' "$INSTALLED_VERSION_OUTPUT" | sed -n 's/.* \([0-9][0-9.]*\)[[:space:]]*$/\1/p')
	if [ "$INSTALLED_VERSION" = "$VERSION" ]; then
		printf '%s\n' "$EXECUTABLE"
		exit 0
	fi
	echo "Chrome at $EXECUTABLE is version $INSTALLED_VERSION; expected $VERSION." >&2
	exit 1
fi

mkdir -p "$INSTALL_ROOT/.downloads" "$DESTINATION"
URL="https://storage.googleapis.com/chrome-for-testing-public/$VERSION/$PLATFORM/chrome-$PLATFORM.zip"

if [ ! -f "$ARCHIVE" ]; then
	PARTIAL="$ARCHIVE.partial.$$"
	trap 'rm -f "$PARTIAL"' EXIT HUP INT TERM
	curl --fail --location --proto '=https' --tlsv1.2 --retry 3 --output "$PARTIAL" "$URL"
	mv "$PARTIAL" "$ARCHIVE"
	trap - EXIT HUP INT TERM
fi

ACTUAL_SHA256=$(sha256_file "$ARCHIVE")
if [ "$ACTUAL_SHA256" != "$EXPECTED_SHA256" ]; then
	echo "Chrome archive SHA-256 mismatch: expected $EXPECTED_SHA256, got $ACTUAL_SHA256" >&2
	exit 1
fi

TMP_DESTINATION="$DESTINATION.installing.$$"
rm -rf "$TMP_DESTINATION"
mkdir -p "$TMP_DESTINATION"
trap 'rm -rf "$TMP_DESTINATION"' EXIT HUP INT TERM
unzip -q "$ARCHIVE" -d "$TMP_DESTINATION"
TMP_EXECUTABLE="$TMP_DESTINATION/$ARCHIVE_DIR/$EXECUTABLE_RELATIVE"

if [ ! -x "$TMP_EXECUTABLE" ]; then
	echo "Chrome archive did not contain the expected executable: $TMP_EXECUTABLE" >&2
	exit 1
fi

if ! INSTALLED_VERSION_OUTPUT=$("$TMP_EXECUTABLE" --version); then
	echo "Extracted Chrome failed its version check: $TMP_EXECUTABLE" >&2
	exit 1
fi
INSTALLED_VERSION=$(printf '%s\n' "$INSTALLED_VERSION_OUTPUT" | sed -n 's/.* \([0-9][0-9.]*\)[[:space:]]*$/\1/p')
if [ "$INSTALLED_VERSION" != "$VERSION" ]; then
	echo "Extracted Chrome version $INSTALLED_VERSION does not match pin $VERSION." >&2
	exit 1
fi

rm -rf "$DESTINATION"
mv "$TMP_DESTINATION" "$DESTINATION"
trap - EXIT HUP INT TERM

MARKER_PARTIAL="$MARKER.partial.$$"
trap 'rm -f "$MARKER_PARTIAL"' EXIT HUP INT TERM
printf 'version=%s\nplatform=%s\narchive_sha256=%s\n' \
	"$VERSION" "$PLATFORM" "$EXPECTED_SHA256" > "$MARKER_PARTIAL"
mv "$MARKER_PARTIAL" "$MARKER"
trap - EXIT HUP INT TERM

printf '%s\n' "$EXECUTABLE"
