#!/bin/sh
set -eu

cd "$(dirname "$0")"

cargo rustc --target wasm32-unknown-unknown --release --lib -- --crate-type=cdylib

mkdir -p wasm/dist
wasm_file="$(ls -t target/wasm32-unknown-unknown/release/deps/wp_html_api_rust_core-*.wasm | head -n 1)"
cp "$wasm_file" wasm/dist/wp_html_api_rust_core.wasm

echo "WASM build complete: ext/html-api-rust/wasm/dist/wp_html_api_rust_core.wasm"
