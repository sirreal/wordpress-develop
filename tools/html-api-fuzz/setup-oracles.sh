#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd -P)"

"$SCRIPT_DIR/oracles/lexbor/build.sh"

"$SCRIPT_DIR/oracles/html5ever/install-rust.sh"
export CARGO_HOME="$REPO_ROOT/.cache/html5ever/rust/cargo"
export RUSTUP_HOME="$REPO_ROOT/.cache/html5ever/rust/rustup"
export PATH="$CARGO_HOME/bin:$PATH"
"$SCRIPT_DIR/oracles/html5ever/build.sh"

"$SCRIPT_DIR/oracles/chrome/install.sh"

printf '%s\n' 'All pinned HTML API fuzz oracles are ready.'
