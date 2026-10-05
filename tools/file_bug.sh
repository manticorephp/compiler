#!/usr/bin/env bash
#
# File a GitHub issue for a known-bug repro and write its number back into it.
#
#   tools/file_bug.sh tests/aot/repro/wrong/<name>.php [more.php …]
#   tools/file_bug.sh -n tests/aot/repro/wrong/<name>.php     # print the body, file nothing
#
# The repro must already be red (`tests/aot/xfail.sh -k <name>` says XFAIL) and
# carry its title as the comment on line 2. The issue body is the PHP source,
# the `.expected` beside it and what the binary printed HERE — so the issue can
# be read without a checkout. A repro that already names an issue is skipped.
# See AGENTS.md, "Reporting a bug you are not fixing now".
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
# shellcheck source=lib/limit.sh
source "$ROOT/tools/lib/limit.sh"

DRY=0
[[ "${1:-}" == "-n" ]] && { DRY=1; shift; }
[[ $# -gt 0 ]] || { sed -n '2,12p' "$0"; exit 2; }

WORK="$ROOT/tests/aot/.work/file_bug"
mkdir -p "$WORK"
COMMIT="$(git rev-parse --short HEAD 2>/dev/null || echo unknown)"
HOST="$(uname -s) $(uname -m)"
rc_all=0

for src in "$@"; do
    src="${src#"$ROOT"/}"
    expected="${src%.php}.expected"
    rel="${src#tests/aot/repro/}"; rel="${rel%.php}"
    kind="${rel%%/*}"
    if [[ ! -f "$src" || ! -f "$expected" || "$rel" == "$src" ]]; then
        echo "skip $src: not a tests/aot/repro/**/<name>.php with a .expected beside it" >&2
        rc_all=1; continue
    fi
    if grep -qE 'issue: *#[0-9]+' "$src"; then
        echo "skip $rel: already names $(grep -m1 -oE '#[0-9]+' "$src")"
        continue
    fi
    title="$(sed -n 2p "$src" | sed -e 's|^// *||' -e 's/^LEAK: /Leak: /')"
    if [[ -z "$title" || "$title" == "$(sed -n 2p "$src")" ]]; then
        echo "skip $rel: line 2 must be a '// one-sentence title' comment" >&2
        rc_all=1; continue
    fi

    crc=0; rc=0
    mc_limit 300 bin/manticore compile "$src" -o "$WORK/p.bin" > "$WORK/p.err" 2>&1 || crc=$?
    if [[ $crc -ne 0 ]]; then
        actual="$(printf 'compile failed (rc=%s):\n%s' "$crc" \
            "$(grep -v -E '^$|warning:|Found 0 error' "$WORK/p.err" | head -8 \
               | sed -e "s|$ROOT/||g" -e 's|/tmp/manticore_[0-9]*\.ll|/tmp/manticore_<pid>.ll|')")"
        grep -ho '/tmp/manticore_[0-9]*\.ll' "$WORK/p.err" | sort -u | xargs rm -f
    else
        mc_limit 30 "$WORK/p.bin" > "$WORK/p.out" 2> "$WORK/p.err" || rc=$?
        actual="$(head -30 "$WORK/p.out")"
        if [[ $rc -ne 0 ]]; then
            sig=""
            [[ $rc -eq 139 ]] && sig=" — SIGSEGV"
            [[ $rc -eq 138 ]] && sig=" — SIGBUS"
            [[ $rc -eq 124 ]] && sig=" — timeout"
            actual="$(printf '%s\n[exit code %s%s]\n%s' "$actual" "$rc" "$sig" "$(head -3 "$WORK/p.err")")"
        elif cmp -s "$expected" "$WORK/p.out"; then
            echo "skip $rel: it PASSES here — not a red repro" >&2
            rc_all=1; rm -f "$WORK"/p.*; continue
        elif [[ "$kind" == leak && -s "$WORK/p.err" ]]; then
            actual="$(printf '%s\n(stderr: %s)' "$actual" "$(tail -1 "$WORK/p.err")")"
        fi
    fi
    rm -f "$WORK"/p.bin "$WORK"/p.out "$WORK"/p.err

    label=wrong-answer
    [[ "$kind" == crash ]] && label=crash
    [[ "$kind" == leak ]] && label=leak
    body="$WORK/body.md"
    {
        printf '## Summary\n\n%s.\n\n## Repro\n\n```php\n' "${title%.}"
        sed 2d "$src"
        printf '```\n\n## Expected (php)\n\n```\n'
        head -30 "$expected"
        printf '```\n\n## Actual (manticore `%s`, %s)\n\n```\n%s\n```\n\n## Tracking\n\n' "$COMMIT" "$HOST" "$actual"
        printf 'Known-bug repro: `%s` — `bash tests/aot/xfail.sh -k %s` reports `XFAIL` while this is open and `XPASS` once it is fixed; then move the repro into `tests/aot/cases/` and close this issue.\n' "$src" "${rel##*/}"
    } > "$body"

    if [[ $DRY -eq 1 ]]; then
        printf '=== %s  [%s]\n' "$title" "$label"; cat "$body"; rm -f "$body"; continue
    fi
    url="$(gh issue create --title "$title" --body-file "$body" --label bug --label "$label" 2>&1 | tail -1)"
    rm -f "$body"
    num="${url##*/}"
    if [[ ! "$num" =~ ^[0-9]+$ ]]; then
        echo "FAILED $rel: $url" >&2
        rc_all=1; continue
    fi
    # Line 3, right under the title: xfail.sh greps for it.
    awk -v n="$num" 'NR==3{print "// issue: #" n} {print}' "$src" > "$src.tmp" && mv "$src.tmp" "$src"
    echo "#$num  $rel"
done
rmdir "$WORK" 2>/dev/null || true
exit $rc_all
