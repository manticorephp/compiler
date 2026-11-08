#!/usr/bin/env bash
# Prepare symfony/demo for `manticore build`: fetch the upstream application at the pinned
# commit around the files kept here, install its packages, and run the build-time steps
# that need php (see README.md). Safe to run again: it fills in only what is missing,
# then redoes the php steps.
set -euo pipefail
cd "$(dirname "$0")"

UPSTREAM=https://github.com/symfony/demo
REF=8d2e2ef75c3e18df173d8bf2379a14abb58c4c31   # 2026-09-16, "Use DatePoint Doctrine Type"

if [ ! -f composer.json ]; then
    tmp=$(mktemp -d)
    git clone -q "$UPSTREAM" "$tmp"
    git -C "$tmp" checkout -q "$REF"
    rm -rf "$tmp/.git"
    # No-clobber: the files kept in this directory replace upstream's.
    rsync -a --ignore-existing "$tmp"/ ./
    rm -rf "$tmp"
fi
# The kernel registers its bundles itself; a binary does not `require` this file.
rm -f config/bundles.php

composer install --no-dev --no-scripts --no-interaction

# Build-time steps under php. Each writes files the binary uses:
php bin/console importmap:install --env=prod      # assets/vendor
php bin/console sass:build --env=prod             # var/sass
php bin/warm-assets.php "$PWD"                    # public/assets (served as static files)
php bin/warm-twig.php "$PWD"                      # var/cache/prod/{twig,translations} (compiled in)

echo "ready: ../../bin/manticore build -O 0 manticore.json"
