#!/bin/sh
set -eu

cd "$(dirname "$0")"

cargo build --target wasm32-unknown-unknown --release --lib

mkdir -p wasm/dist
wasm_file="target/wasm32-unknown-unknown/release/wp_html_api_rust_core.wasm"
cp "$wasm_file" wasm/dist/wp_html_api_rust_core.wasm

echo "WASM build complete: ext/html-api-rust/wasm/dist/wp_html_api_rust_core.wasm"
