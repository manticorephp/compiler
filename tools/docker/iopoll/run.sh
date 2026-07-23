#!/usr/bin/env bash
# Io\Poll Linux-parity difftest: build a php-8.6 + toolchain image, cold-seed
# Manticore on Linux, compile the Io\Poll cases and diff vs php 8.6.
#
#   bash tools/docker/iopoll/run.sh
#
# Docker on Apple Silicon runs linux/arm64 — matches the aarch64 epoll_event
# layout the backend assumes.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../../.." && pwd)"
PLATFORM="${PLATFORM:-linux/arm64}"
IMG=manticore-iopoll:8.6

echo "== build image ($PLATFORM) =="
docker build --platform "$PLATFORM" -t "$IMG" -f "$HERE/Dockerfile" "$HERE"

echo "== run parity (php 8.6 oracle in-container) =="
docker run --rm --platform "$PLATFORM" -v "$ROOT":/repo:ro "$IMG" \
    bash /repo/tools/docker/iopoll/in_container.sh
