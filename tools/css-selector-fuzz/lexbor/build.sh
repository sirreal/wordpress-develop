#!/bin/sh
#
# Builds the lexbor differential harness.
#
# Pinned lexbor version: v3.0.0 (2ae88a1c6b5261830eff73ee12bb3cdf805f3cfe).
# Note: lexbor issue #368 ("Class/ID selectors are ASCII case-insensitive
# even in no-quirks mode") is still OPEN at this version; the PHP adapter
# detects it at startup and compensates (see LexborOracle.php).
#
# Usage:
#   sh tools/css-selector-fuzz/lexbor/build.sh [lexbor-src-dir]
#
# Produces tools/css-selector-fuzz/lexbor/harness.

set -e

HERE="$(cd "$(dirname "$0")" && pwd)"
SRC="${1:-/tmp/lexbor-src}"
PIN="2ae88a1c6b5261830eff73ee12bb3cdf805f3cfe"

if [ ! -d "$SRC" ]; then
    echo "Cloning lexbor into $SRC ..."
    git clone https://github.com/lexbor/lexbor "$SRC"
fi

git -C "$SRC" checkout --quiet "$PIN"

if [ ! -f "$SRC/build/liblexbor_static.a" ]; then
    echo "Building liblexbor_static ..."
    mkdir -p "$SRC/build"
    cd "$SRC/build"
    cmake -DCMAKE_BUILD_TYPE=Release -DLEXBOR_BUILD_SHARED=OFF \
          -DLEXBOR_BUILD_STATIC=ON -DLEXBOR_BUILD_TESTS=OFF \
          -DLEXBOR_BUILD_EXAMPLES=OFF .. > /dev/null
    make -j8 lexbor_static > /dev/null
    cd "$HERE"
fi

cc -O2 -Wall -Wextra -o "$HERE/harness" "$HERE/harness.c" \
   -I "$SRC/source" "$SRC/build/liblexbor_static.a"

echo "Built $HERE/harness (lexbor $PIN)"
