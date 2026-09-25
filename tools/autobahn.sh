#!/usr/bin/env bash
# Autobahn|Testsuite harness (manual gate — the user runs the full case list).
#
#   bash tools/autobahn.sh server    # our ws_echo server vs. Autobahn's fuzzingclient
#   bash tools/autobahn.sh client    # our autobahn_client vs. Autobahn's fuzzingserver
#
# AUTOBAHN_CASES overrides the case list (a JSON array string), e.g.
#   AUTOBAHN_CASES='["1.*"]' bash tools/autobahn.sh server
#
# AUTOBAHN_TIMEOUT bounds the server-mode fuzzing run in seconds (default 1800).
#
# Exits 1 when any case's behavior is FAILED, or on a timeout.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

MODE="${1:-}"
if [[ "$MODE" != "server" && "$MODE" != "client" ]]; then
    echo "usage: $0 {server|client}" >&2
    exit 2
fi

if ! command -v docker >/dev/null 2>&1; then
    echo "docker not found — Autobahn harness needs it, skipping" >&2
    exit 0
fi

CASES="${AUTOBAHN_CASES:-[\"1.*\",\"2.*\",\"3.*\",\"4.*\",\"5.*\",\"6.*\",\"7.*\",\"9.*\",\"12.*\",\"13.*\"]}"

TMP="$(mktemp -d "${TMPDIR:-/tmp}/autobahn.XXXXXX")"

SERVER_PID=""
CONTAINER=""

cleanup() {
    local ec=$?
    if [[ -n "$SERVER_PID" ]] && kill -0 "$SERVER_PID" 2>/dev/null; then
        kill "$SERVER_PID" 2>/dev/null || true
        wait "$SERVER_PID" 2>/dev/null || true
    fi
    if [[ -n "$CONTAINER" ]]; then
        docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
    fi
    rm -rf "$TMP"
    exit "$ec"
}
trap cleanup EXIT

mkdir -p "$TMP/reports"

# Poll until something answers on host:port, up to (max * 0.25s).
wait_for_port() {
    local host="$1" port="$2" max="${3:-80}" n=0
    while ! (exec 3<>"/dev/tcp/$host/$port") 2>/dev/null; do
        n=$((n + 1))
        if [[ "$n" -ge "$max" ]]; then
            echo "timed out waiting for $host:$port" >&2
            return 1
        fi
        sleep 0.25
    done
    exec 3>&- 3<&- || true
    return 0
}

# Print OK/NON-STRICT/INFORMATIONAL/UNIMPLEMENTED/FAILED counts + FAILED ids
# from an Autobahn index.json; exits 1 if any case FAILED.
summarize() {
    local index_json="$1" agent="$2"
    php -r '
        $data = json_decode(file_get_contents($argv[1]), true);
        if (!is_array($data) || !isset($data[$argv[2]])) {
            fwrite(STDERR, "no report for agent \"" . $argv[2] . "\" in " . $argv[1] . "\n");
            exit(1);
        }
        $cases = $data[$argv[2]];
        $counts = [];
        $failed = [];
        foreach ($cases as $id => $c) {
            $b = $c["behavior"] ?? "UNKNOWN";
            $counts[$b] = ($counts[$b] ?? 0) + 1;
            if ($b === "FAILED") {
                $failed[] = $id;
            }
        }
        if (count($counts) === 0) {
            fwrite(STDERR, "no cases reported\n");
            exit(1);
        }
        ksort($counts);
        foreach ($counts as $b => $n) {
            echo "$b: $n\n";
        }
        if (count($failed) > 0) {
            echo "FAILED cases: " . implode(", ", $failed) . "\n";
            exit(1);
        }
        exit(0);
    ' -- "$index_json" "$agent"
}

if [[ "$MODE" == "server" ]]; then
    printf 'building ws_echo... '
    if ! "$ROOT/bin/manticore" compile "$ROOT/examples/http/ws_echo.php" -o "$TMP/ws_echo" >"$TMP/build.log" 2>&1; then
        echo "FAILED"
        tail -40 "$TMP/build.log"
        exit 1
    fi
    echo "ok"

    "$TMP/ws_echo" 9001 &
    SERVER_PID=$!
    wait_for_port 127.0.0.1 9001

    cat >"$TMP/fuzzingclient.json" <<EOF
{
    "outdir": "/reports/servers",
    "servers": [{"agent": "manticore", "url": "ws://host.docker.internal:9001"}],
    "cases": $CASES,
    "exclude-cases": []
}
EOF

    # Detached and bounded: a case list that matches nothing (or a server that
    # stops answering) leaves wstest waiting forever in the foreground.
    CONTAINER="$(docker run -d \
        -v "$TMP:/config" \
        -v "$TMP/reports:/reports" \
        --add-host=host.docker.internal:host-gateway \
        crossbario/autobahn-testsuite \
        wstest -m fuzzingclient -s /config/fuzzingclient.json)"
    limit="${AUTOBAHN_TIMEOUT:-1800}"
    waited=0
    while [[ "$(docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null)" == "true" ]]; do
        if [[ "$waited" -ge "$limit" ]]; then
            docker logs "$CONTAINER" 2>&1 | tail -40
            docker kill "$CONTAINER" >/dev/null 2>&1 || true
            echo "autobahn: fuzzingclient still running after ${limit}s (AUTOBAHN_TIMEOUT), killed" >&2
            exit 1
        fi
        sleep 2
        waited=$((waited + 2))
    done
    docker logs "$CONTAINER" 2>&1 | tail -40
    docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
    CONTAINER=""

    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
    SERVER_PID=""

    summarize "$TMP/reports/servers/index.json" manticore
else
    cat >"$TMP/fuzzingserver.json" <<EOF
{
    "outdir": "/reports/clients",
    "url": "ws://0.0.0.0:9001",
    "cases": $CASES,
    "exclude-cases": []
}
EOF

    CONTAINER="$(docker run -d --rm \
        -v "$TMP:/config" \
        -v "$TMP/reports:/reports" \
        -p 9001:9001 \
        crossbario/autobahn-testsuite \
        wstest -m fuzzingserver -s /config/fuzzingserver.json)"
    wait_for_port 127.0.0.1 9001

    printf 'building autobahn_client... '
    if ! "$ROOT/bin/manticore" compile "$ROOT/tools/autobahn_client.php" -o "$TMP/autobahn_client" >"$TMP/build.log" 2>&1; then
        echo "FAILED"
        tail -40 "$TMP/build.log"
        exit 1
    fi
    echo "ok"

    "$TMP/autobahn_client" 127.0.0.1 2>&1 | tail -40

    docker kill "$CONTAINER" >/dev/null 2>&1 || true
    CONTAINER=""

    summarize "$TMP/reports/clients/index.json" manticore
fi
