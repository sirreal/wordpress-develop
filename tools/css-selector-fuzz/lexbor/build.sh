#!/bin/sh
#
# Builds the lexbor differential harness.
#
# Builds against upstream lexbor master. The exact commit is printed after
# each build and recorded in the build cache.
# Note: lexbor issue #368 ("Class/ID selectors are ASCII case-insensitive
# even in no-quirks mode") is detected at startup and compensated for when
# present (see LexborOracle.php).
#
# Usage:
#   sh tools/css-selector-fuzz/lexbor/build.sh [lexbor-src-dir]
#
# Produces tools/css-selector-fuzz/lexbor/harness.

set -e

HERE="$(cd "$(dirname "$0")" && pwd)"
SRC="${1:-/tmp/lexbor-src}"
BRANCH="master"

if [ ! -d "$SRC" ]; then
    echo "Cloning lexbor into $SRC ..."
    git clone https://github.com/lexbor/lexbor "$SRC"
fi

git -C "$SRC" fetch --quiet origin "$BRANCH"
git -C "$SRC" checkout --quiet -B "$BRANCH" "origin/$BRANCH"
REV="$(git -C "$SRC" rev-parse --verify HEAD)"
STAMP="$SRC/build/.lexbor-rev"

if [ ! -f "$SRC/build/liblexbor_static.a" ] || [ ! -f "$STAMP" ] || [ "$(cat "$STAMP")" != "$REV" ]; then
    echo "Building liblexbor_static ($BRANCH $REV) ..."
    mkdir -p "$SRC/build"
    cd "$SRC/build"
    cmake -DCMAKE_BUILD_TYPE=Release -DLEXBOR_BUILD_SHARED=OFF \
          -DLEXBOR_BUILD_STATIC=ON -DLEXBOR_BUILD_TESTS=OFF \
          -DLEXBOR_BUILD_EXAMPLES=OFF .. > /dev/null
    make -j8 lexbor_static > /dev/null
    printf '%s\n' "$REV" > "$STAMP"
    cd "$HERE"
fi

cc -O2 -Wall -Wextra -o "$HERE/harness" "$HERE/harness.c" \
   -I "$SRC/source" "$SRC/build/liblexbor_static.a"

echo "Built $HERE/harness (lexbor $BRANCH $REV)"
