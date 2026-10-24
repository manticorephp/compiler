#!/usr/bin/env bash
# Refresh the vendored highlight.js. The version is pinned here and nowhere else.
#
#   site/vendor/update.sh            # fetch HLJS_VERSION
#   HLJS_VERSION=11.13.0 site/vendor/update.sh
#
# highlight.min.js is the "common" bundle (php, bash, json, ...); llvm is the one extra language
# the docs use. After updating, rebuild the site and check a code block in a browser.
set -euo pipefail

HLJS_VERSION="${HLJS_VERSION:-11.12.0}"
cd "$(dirname "$0")"
base="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/$HLJS_VERSION"

fetch() {
    curl -fsSL --retry 3 "$1" -o "$2.tmp"
    mv "$2.tmp" "$2"
}

fetch "$base/highlight.min.js" highlight.min.js
fetch "$base/languages/llvm.min.js" llvm.min.js

got="$(head -3 highlight.min.js | grep -o 'v[0-9][0-9.]*' | head -1)"
[ "$got" = "v$HLJS_VERSION" ] || { echo "update.sh: asked for $HLJS_VERSION, file says $got" >&2; exit 1; }
echo "highlight.js $HLJS_VERSION: $(wc -c < highlight.min.js | tr -d ' ') + $(wc -c < llvm.min.js | tr -d ' ') bytes"
