#!/bin/bash
# down.sh           remove every pdo_net test server and the generated certs
# down.sh --extras  remove only the extra servers (mc-my-iso, mc-my-ssl, mc-pg-ssl, mc-my-native, mc-maria); keep mc-pg and mc-my
if [ "${1:-}" = "--extras" ]; then
    docker rm -f mc-my-iso mc-my-ssl mc-pg-ssl mc-my-native mc-maria >/dev/null 2>&1 || true
else
    docker rm -f mc-pg mc-pg-ssl mc-my mc-my-ssl mc-my-native mc-my-iso mc-maria >/dev/null 2>&1 || true
fi
rm -rf "$(cd "$(dirname "$0")" && pwd)/.certs"
