#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
VERSION=$(tr -d '[:space:]' < "$SCRIPT_DIR/VERSION")
INSTALL_ROOT=${HTML_API_FUZZ_CHROME_INSTALL_ROOT:-"$SCRIPT_DIR/.chrome-for-testing"}

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

if [ "${1:-}" = '--print-path' ]; then
	printf '%s\n' "$EXECUTABLE"
	exit 0
fi

if [ -x "$EXECUTABLE" ]; then
	INSTALLED_VERSION=$("$EXECUTABLE" --version | sed -n 's/.* \([0-9][0-9.]*\)[[:space:]]*$/\1/p')
	if [ "$INSTALLED_VERSION" = "$VERSION" ]; then
		printf '%s\n' "$EXECUTABLE"
		exit 0
	fi
	echo "Chrome at $EXECUTABLE is version $INSTALLED_VERSION; expected $VERSION." >&2
	exit 1
fi

mkdir -p "$INSTALL_ROOT/.downloads" "$DESTINATION"
ARCHIVE="$INSTALL_ROOT/.downloads/chrome-$VERSION-$PLATFORM.zip"
URL="https://storage.googleapis.com/chrome-for-testing-public/$VERSION/$PLATFORM/chrome-$PLATFORM.zip"

if [ ! -f "$ARCHIVE" ]; then
	PARTIAL="$ARCHIVE.partial.$$"
	trap 'rm -f "$PARTIAL"' EXIT HUP INT TERM
	curl --fail --location --proto '=https' --tlsv1.2 --retry 3 --output "$PARTIAL" "$URL"
	mv "$PARTIAL" "$ARCHIVE"
	trap - EXIT HUP INT TERM
fi

TMP_DESTINATION="$DESTINATION.installing.$$"
rm -rf "$TMP_DESTINATION"
mkdir -p "$TMP_DESTINATION"
trap 'rm -rf "$TMP_DESTINATION"' EXIT HUP INT TERM
unzip -q "$ARCHIVE" -d "$TMP_DESTINATION"
rm -rf "$DESTINATION"
mv "$TMP_DESTINATION" "$DESTINATION"
trap - EXIT HUP INT TERM

if [ ! -x "$EXECUTABLE" ]; then
	echo "Chrome archive did not contain the expected executable: $EXECUTABLE" >&2
	exit 1
fi

INSTALLED_VERSION=$("$EXECUTABLE" --version | sed -n 's/.* \([0-9][0-9.]*\)[[:space:]]*$/\1/p')
if [ "$INSTALLED_VERSION" != "$VERSION" ]; then
	echo "Installed Chrome version $INSTALLED_VERSION does not match pin $VERSION." >&2
	exit 1
fi

printf '%s\n' "$EXECUTABLE"
