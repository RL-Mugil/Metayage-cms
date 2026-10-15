#!/usr/bin/env bash
# push the FULL local backend source (git HEAD + uncommitted work, no vendor/.env/storage) to the isolated-test overlay
cd "$(dirname "$0")/../backend"
ssh root@139.59.85.216 'rm -rf /tmp/iso-overlay; mkdir -p /tmp/iso-overlay'
tar czf - --exclude=vendor --exclude=node_modules --exclude=.env --exclude='.env.*' --exclude=storage --exclude='bootstrap/cache' --exclude=public/build --exclude=public/hot . | ssh root@139.59.85.216 'tar xzf - -C /tmp/iso-overlay'
ssh root@139.59.85.216 'mkdir -p /tmp/iso-overlay/storage; ls /tmp/iso-overlay | head -20'
