#!/bin/bash
docker rm -f mc-pg mc-pg-ssl mc-my mc-my-ssl mc-my-native mc-my-iso >/dev/null 2>&1 || true
rm -rf "$(cd "$(dirname "$0")" && pwd)/.certs"
