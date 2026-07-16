#!/usr/bin/env bash
# Tier 1: probe each distro's libc for the symbols manticore binds by name, and
# for the constants / struct layouts src/Runtime/Stdlib/Stat.php hard-codes.
#
# Re-runnable: raw per-image output lands in tools/docker/probe-raw/ and the
# rendered table in tools/docker/PROBE_RESULTS.md. Both are overwritten.
#
#   bash tools/docker/probe_libc.sh              # arm64 only (native, fast)
#   bash tools/docker/probe_libc.sh --amd64      # arm64 + amd64 (qemu, slow)
#   bash tools/docker/probe_libc.sh --amd64-only
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RAW="$HERE/probe-raw"
OUT="$HERE/PROBE_RESULTS.md"

IMAGES=(
    ubuntu:20.04
    ubuntu:22.04
    ubuntu:24.04
    debian:12
    alpine:3.20
)

PLATFORMS=(linux/arm64)
case "${1:-}" in
    --amd64)      PLATFORMS=(linux/arm64 linux/amd64) ;;
    --amd64-only) PLATFORMS=(linux/amd64) ;;
    "")           ;;
    *) echo "usage: $0 [--amd64|--amd64-only]" >&2; exit 2 ;;
esac

command -v docker >/dev/null || { echo "docker not found" >&2; exit 1; }

# Raw output ACCUMULATES across runs, keyed by image+arch: probing amd64 must
# not discard an earlier arm64 sweep, since the rendered table wants both.
# Only the tags about to be re-probed are cleared.
mkdir -p "$RAW"

for platform in "${PLATFORMS[@]}"; do
    arch="${platform#linux/}"
    for image in "${IMAGES[@]}"; do
        tag="$(echo "${image}-${arch}" | tr ':/' '__')"
        echo "==> $image ($arch)" >&2
        # A failed probe must not abort the sweep -- an image that cannot run
        # (no such platform, no network) is itself a reportable result.
        if ! docker run --rm --platform "$platform" \
                -v "$HERE":/probe:ro \
                "$image" /bin/sh /probe/probe_in_container.sh \
                > "$RAW/$tag.txt" 2>"$RAW/$tag.err"; then
            echo "    FAILED (see $RAW/$tag.err)" >&2
            { echo "run.status	FAILED"
              sed 's/^/run.error	/' "$RAW/$tag.err" | head -20
            } >> "$RAW/$tag.txt"
        fi
        { echo "meta.image	$image"; echo "meta.platform	$platform"; } >> "$RAW/$tag.txt"
    done
done

php "$HERE/render_results.php" "$RAW" > "$OUT"
echo "wrote $OUT" >&2
