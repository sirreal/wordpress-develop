#!/usr/bin/env sh
set -eu

requested_ref="${LEXBOR_COMMIT:-master}"
script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
repo_root="$(CDPATH= cd -- "$script_dir/../../../.." && pwd)"
cache_root="${LEXBOR_CACHE_DIR:-$repo_root/.cache/lexbor}"
cache_dir="$cache_root/$requested_ref"
source_dir="${LEXBOR_SOURCE_DIR:-$cache_dir/source}"
oracle_build_dir="$script_dir/build"
oracle_bin="$oracle_build_dir/lexbor-tree-oracle"
manifest_path="$oracle_build_dir/build-manifest.json"
upstream_url="https://github.com/lexbor/lexbor.git"

if [ ! -d "$source_dir/.git" ]; then
	mkdir -p "$(dirname "$source_dir")"
	git clone "$upstream_url" "$source_dir"
fi

if [ -n "$(git -C "$source_dir" status --porcelain --untracked-files=normal)" ]; then
	printf '%s\n' "Refusing to build from a dirty Lexbor source checkout: $source_dir" >&2
	exit 1
fi

git -C "$source_dir" fetch --tags origin

checkout_ref="$requested_ref"
if git -C "$source_dir" rev-parse --verify --quiet "origin/$requested_ref^{commit}" >/dev/null; then
	checkout_ref="origin/$requested_ref"
fi
git -C "$source_dir" checkout --detach "$checkout_ref"
commit="$(git -C "$source_dir" rev-parse HEAD)"
if [ -n "$(git -C "$source_dir" status --porcelain --untracked-files=normal)" ]; then
	printf '%s\n' "Refusing to build from a dirty Lexbor source checkout: $source_dir" >&2
	exit 1
fi

commit_cache="$cache_root/commits/$commit"
build_dir="${LEXBOR_BUILD_DIR:-$commit_cache/build}"
install_dir="${LEXBOR_INSTALL_DIR:-$commit_cache/install}"

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

binary_sha256="$(shasum -a 256 "$oracle_bin" | awk '{print $1}')"
compiler_version="$(cc --version 2>/dev/null | sed -n '1p')"
cmake_version="$(cmake --version 2>/dev/null | sed -n '1p')"
php -r '
$manifest = array(
	"kind" => "html-api-fuzz-lexbor-build",
	"requestedRef" => $argv[1],
	"resolvedCommit" => $argv[2],
	"upstream" => $argv[3],
	"builtAt" => gmdate("c"),
	"binarySha256" => $argv[4],
	"compiler" => $argv[5],
	"cmake" => $argv[6],
);
$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (false === $json || strlen($json) + 1 !== file_put_contents($argv[7], $json . "\n")) {
	fwrite(STDERR, "Could not write Lexbor build manifest.\n");
	exit(1);
}
' "$requested_ref" "$commit" "$upstream_url" "$binary_sha256" "$compiler_version" "$cmake_version" "$manifest_path"

printf '%s\n' "$oracle_bin"
