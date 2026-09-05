#!/usr/bin/env bash
#
# LIVE bytes per CALL STACK — the question neither existing tool answers.
#
#   bash tools/prof/live.sh --build                  # once: the no-pool,
#                                                    # frame-pointer binary
#   bash tools/prof/live_stacks.sh 'realloc in __mir_str_append' 8
#
# WHY THIS EXISTS. The two tools next door each answer half of it:
#
#   tools/prof/live.sh   `heap` — LIVE bytes per ALLOCATING FUNCTION. It names
#                        the allocator, never the caller. It found
#                        `InferCalls::genericReturnType` holding 830 279 blocks.
#   malloc_history       CUMULATIVE bytes per CALL STACK. Every allocation ever
#                        made, so for a runtime helper the answer is "every
#                        emitter called it", which says nothing about what is
#                        still held.
#
# `heap --addresses=<pattern>` lists the addresses of the LIVE blocks matching
# a derived type name; `malloc_history <pid> <addr>` turns one address into the
# stack that made it. Together they give live-bytes-per-stack. The process is
# SIGSTOPped for the duration so the two agree on one heap state.
#
# ⚠ THE PATTERN IS THE DERIVED TYPE NAME, NOT THE SYMBOL. `heap` prints rows as
# `realloc in __mir_str_append` / `malloc in Parser\Parser::span`, and
# `--addresses=__mir_str_append` matches NOTHING. Copy the name out of a
# `live.sh` table verbatim, quoted.
#
# ⚠ Needs the binary from `live.sh --build` (MANTICORE_POOL=0 and, decisively,
# MANTICORE_FRAME_POINTERS=1). Without frame pointers every stack stops at the
# allocator with no caller at all. That build leaves `bin/manticore` and
# `lib/*.o` no-pool in the worktree — rebuild before any gate or A/B.
#
# Worked example (self-compile, peak ~505 MB): the largest live
# `__mir_str_append` buffers are 180–295 KB each and come from
# `EmitLlvm::emitIf`, `EmitLlvm::visitBlock` and `EmitLlvm::emitForeach` — the
# per-block IR text accumulators, still resident long after emission ended.
set -u

FILTER="${1:?usage: live_stacks.sh '<heap type name>' [count] [-- <manticore args>]}"
N="${2:-8}"
BIN="${BIN:-bin/manticore.nopool}"
OUT="${OUTDIR:-$(mktemp -d)}"
RSS_MB="${RSS_MB:-450}"
shift 2 2>/dev/null || true
ARGS=(build --apps-only manticore.json)
if [[ "${1:-}" == "--" ]]; then shift; ARGS=("$@"); fi

[[ -x "$BIN" ]] || { echo "prof: no $BIN — run: bash tools/prof/live.sh --build" >&2; exit 1; }
mkdir -p "$OUT"
echo "prof: $BIN ${ARGS[*]}"
echo "prof: artifacts in $OUT"

MallocStackLogging=1 MallocStackLoggingDirectory="$OUT" \
    "$BIN" "${ARGS[@]}" >"$OUT/build.log" 2>&1 &
PID=$!

while kill -0 "$PID" 2>/dev/null; do
    rss_kb="$(ps -o rss= -p "$PID" 2>/dev/null | tr -d ' ')"
    [[ -z "$rss_kb" ]] && break
    if (( rss_kb > RSS_MB * 1024 )); then
        echo "prof: rss $(( rss_kb / 1024 )) MB — freezing the target"
        # One heap state for both tools, or the addresses go stale under us.
        kill -STOP "$PID"
        heap "$PID" --addresses="$FILTER" --noContent -s >"$OUT/addrs.txt" 2>"$OUT/heap.err"
        n_found="$(grep -cE '^ *0x[0-9a-f]+' "$OUT/addrs.txt" || true)"
        echo "prof: $n_found live blocks match '$FILTER'"
        if [[ "$n_found" == "0" ]]; then
            echo "prof: no match — the pattern is heap's DERIVED TYPE NAME," >&2
            echo "prof: e.g. 'realloc in __mir_str_append'. See $OUT/addrs.txt." >&2
        fi
        grep -oE '^ *0x[0-9a-f]+' "$OUT/addrs.txt" | tr -d ' ' | head -"$N" >"$OUT/pick.txt"
        : >"$OUT/stacks.txt"
        while read -r a; do
            echo "===== $a =====" >>"$OUT/stacks.txt"
            malloc_history "$PID" "$a" >>"$OUT/stacks.txt" 2>&1
        done <"$OUT/pick.txt"
        kill -CONT "$PID"
        kill -TERM "$PID" 2>/dev/null
        break
    fi
    sleep 1
done
wait "$PID" 2>/dev/null

echo
echo "prof: the allocating stack of each live block —"
grep -h 'ALLOC ' "$OUT/stacks.txt" 2>/dev/null \
    | sed -E 's/.*\[size=([0-9]+)\]: *0x[0-9a-f]+ \(.*\) ([^|]*)\|.*/\1  \2/' \
    | sort -rn | head -"$N"
echo
echo "prof: full stacks in $OUT/stacks.txt · addresses in $OUT/addrs.txt"
