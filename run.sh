#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
cd "$REPO_ROOT"

if [[ "${SETUP_ORACLES:-0}" == "1" ]]; then
	tools/html-api-fuzz/setup-oracles.sh
fi

exec tools/html-api-fuzz/preflight.sh
