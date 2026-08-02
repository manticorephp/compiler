#!/usr/bin/env bash
# Build the Http\ demos. Each is one self-contained file: Http\ and Buffer\
# live in the prelude and are pulled in by the demand gate, so there is no
# library to link and no manifest to drive.
set -euo pipefail
cd "$(dirname "$0")/../.."
MC=${MC:-bin/manticore}

demos=(hello stream compat)
for d in "${demos[@]}"; do
    [ -f "examples/http/$d.php" ] || continue
    printf '%-10s' "$d"
    if "$MC" compile "examples/http/$d.php" -o "examples/http/${d}_bin" >/tmp/mc_http_$d.log 2>&1; then
        echo "ok"
    else
        echo "FAILED (see /tmp/mc_http_$d.log)"
        tail -5 "/tmp/mc_http_$d.log"
    fi
done
