#!/usr/bin/env sh
set -eu

commit="${LEXBOR_COMMIT:-481c444261a132190a3fb746d6d2f60824af3717}"
script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
repo_root="$(CDPATH= cd -- "$script_dir/../../../.." && pwd)"
cache_dir="${LEXBOR_CACHE_DIR:-$repo_root/.cache/lexbor/$commit}"
source_dir="${LEXBOR_SOURCE_DIR:-$cache_dir/source}"
build_dir="${LEXBOR_BUILD_DIR:-$cache_dir/build}"
install_dir="${LEXBOR_INSTALL_DIR:-$cache_dir/install}"
oracle_build_dir="$script_dir/build"
oracle_bin="$oracle_build_dir/lexbor-tree-oracle"

if [ ! -d "$source_dir/.git" ]; then
	mkdir -p "$(dirname "$source_dir")"
	git clone https://github.com/lexbor/lexbor.git "$source_dir"
fi

current_commit="$(git -C "$source_dir" rev-parse HEAD 2>/dev/null || true)"
if [ "$current_commit" != "$commit" ]; then
	git -C "$source_dir" fetch --tags origin
	git -C "$source_dir" checkout "$commit"
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
