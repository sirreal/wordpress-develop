#!/usr/bin/env sh
set -eu

script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
ref="${LEXBOR_COMMIT:-$(tr -d '[:space:]' < "$script_dir/COMMIT")}"
repo_root="$(CDPATH= cd -- "$script_dir/../../../.." && pwd)"
cache_dir="${LEXBOR_CACHE_DIR:-$repo_root/.cache/lexbor/$ref}"
source_dir="${LEXBOR_SOURCE_DIR:-$cache_dir/source}"
build_dir="${LEXBOR_BUILD_DIR:-$cache_dir/build}"
install_dir="${LEXBOR_INSTALL_DIR:-$cache_dir/install}"
oracle_build_dir="$script_dir/build"
oracle_bin="$oracle_build_dir/lexbor-tree-oracle"

case "$ref" in
	*[!0-9a-f]*|'')
		printf '%s\n' "LEXBOR_COMMIT must be a full hexadecimal commit hash: $ref" >&2
		exit 1
		;;
esac
if [ "${#ref}" -ne 40 ]; then
	printf '%s\n' "LEXBOR_COMMIT must contain exactly 40 hexadecimal characters: $ref" >&2
	exit 1
fi

if [ ! -d "$source_dir/.git" ]; then
	mkdir -p "$(dirname "$source_dir")"
	git clone --no-checkout https://github.com/lexbor/lexbor.git "$source_dir"
fi

if ! git -C "$source_dir" cat-file -e "$ref^{commit}" 2>/dev/null; then
	git -C "$source_dir" fetch --no-tags origin "$ref"
fi
git -C "$source_dir" checkout --detach "$ref"
commit="$(git -C "$source_dir" rev-parse HEAD)"
if [ "$commit" != "$ref" ]; then
	printf '%s\n' "Resolved Lexbor commit $commit does not match requested pin $ref." >&2
	exit 1
fi

cmake -S "$source_dir" -B "$build_dir" \
	-DLEXBOR_BUILD_SHARED=OFF \
	-DLEXBOR_BUILD_STATIC=ON \
	-DLEXBOR_BUILD_SEPARATELY=OFF \
	-DLEXBOR_BUILD_EXAMPLES=OFF \
	-DLEXBOR_BUILD_TESTS=OFF \
	-DLEXBOR_BUILD_UTILS=OFF \
	-DCMAKE_INSTALL_PREFIX="$install_dir"

cmake --build "$build_dir" --target lexbor_static
cmake --install "$build_dir" --prefix "$install_dir"

mkdir -p "$oracle_build_dir"

cc ${CFLAGS:-} \
	-std=c99 \
	-Wall \
	-Wextra \
	-Werror \
	-I"$install_dir/include" \
	-DHTML_API_FUZZ_LEXBOR_COMMIT="\"$commit\"" \
	"$script_dir/lexbor-tree-oracle.c" \
	"$install_dir/lib/liblexbor_static.a" \
	-o "$oracle_bin" \
	${LDFLAGS:-}

printf '%s\n' "$oracle_bin"
