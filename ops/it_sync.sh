#!/usr/bin/env bash
# push the FULL local backend source (git HEAD + uncommitted work, no vendor/.env/storage) to the isolated-test overlay
cd "$(dirname "$0")/../backend"
ssh idpd-do 'rm -rf /tmp/iso-overlay; mkdir -p /tmp/iso-overlay'
tar czf - --exclude=vendor --exclude=node_modules --exclude=.env --exclude='.env.*' --exclude=storage --exclude='bootstrap/cache' --exclude=public/build --exclude=public/hot . | ssh idpd-do 'tar xzf - -C /tmp/iso-overlay'
ssh idpd-do 'mkdir -p /tmp/iso-overlay/storage; ls /tmp/iso-overlay | head -20'
