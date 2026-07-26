#!/usr/bin/env bash
# Build the async demos. Each one is a single self-contained file now: the
# runtime lives in prelude/async.php and is pulled in by the demand gate, so
# there is no library to link and no manifest to drive.
set -euo pipefail
cd "$(dirname "$0")/../.."
MC=${MC:-bin/manticore}

demos=(smoke chan_demo echo_server http_server http_transparent load_client capture spawncost async-io plain-req)
for d in "${demos[@]}"; do
    [ -f "poc/async/$d.php" ] || continue
    printf '%-18s' "$d"
    if "$MC" compile "poc/async/$d.php" -o "poc/async/${d}_bin" >/tmp/mc_async_$d.log 2>&1; then
        echo "ok"
    else
        echo "FAILED (see /tmp/mc_async_$d.log)"
        tail -5 "/tmp/mc_async_$d.log"
    fi
done
