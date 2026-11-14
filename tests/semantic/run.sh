#!/usr/bin/env bash
# Runs every tests/semantic/*.php (except bootstrap.php) under Zend.
cd "$(dirname "$0")" || exit 1
rc=0
for f in *.php; do
    [ "$f" = bootstrap.php ] && continue
    echo "== $f"
    php -d xdebug.mode=off "$f" || rc=1
done
exit $rc
