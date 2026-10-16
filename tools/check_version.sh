#!/usr/bin/env bash
# COMPILER_VERSION is the version source; fail on drift. See AGENTS.md.
set -euo pipefail
cd "$(dirname "$0")/.."
PAT='^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$'
fail() { echo "check_version: $*" >&2; exit 1; }

cv="$(cat COMPILER_VERSION)"
bv="$(cat BOOTSTRAP_VERSION)"
[[ "$cv" =~ $PAT ]] || fail "COMPILER_VERSION is not N.N.N[-suffix]: '$cv'"
[[ "$bv" =~ $PAT ]] || fail "BOOTSTRAP_VERSION is not N.N.N[-suffix]: '$bv'"

src="$(sed -n "s/^[[:space:]]*public const STRING = '\([^']*\)';.*/\1/p" src/Manticore/Version.php)"
[[ "$src" == "$cv" ]] || fail "src/Manticore/Version.php says '$src' but COMPILER_VERSION is '$cv'; run bin/build"

# 0 if $1 <= $2 by version order; a -suffix sorts before the same plain version.
le() {
    local a="${1%%-*}" b="${2%%-*}" x y i
    local -a A B
    IFS=. read -ra A <<< "$a"; IFS=. read -ra B <<< "$b"
    for i in 0 1 2; do
        x=$((10#${A[i]})); y=$((10#${B[i]}))
        (( x < y )) && return 0
        (( x > y )) && return 1
    done
    [[ "$1" == "$2" ]] && return 0
    [[ "$1" == *-* && "$2" != *-* ]] && return 0
    [[ "$1" != *-* && "$2" == *-* ]] && return 1
    [[ "$1" < "$2" ]]
}
le "$bv" "$cv" || fail "BOOTSTRAP_VERSION ($bv) is newer than COMPILER_VERSION ($cv)"
echo "ok: COMPILER_VERSION $cv (bootstrap $bv)"
