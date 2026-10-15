#!/usr/bin/env bash
# usage: ops/it.sh <filter>  -- isolated run, condensed output (needs /tmp/iso-overlay refreshed by ops/it_sync.sh)
cd "$(dirname "$0")/.."
ssh root@139.59.85.216 'bash -s' -- < ops/isolated_test.sh "$1" 2>&1 | sed 's/\x1b\[[0-9;]*m//g' | sed -n '/=== tests/,$p' | grep -vE "^\s+[0-9]+▕|vendor frames|^\s*$"
