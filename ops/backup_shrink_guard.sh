#!/usr/bin/env bash
# Add a shrink guard to the MYPL-CMS backup script: a dump less than half the size of the previous one
# (an emptied database once produced a "valid" 58 KB backup) is set aside and raises the failure alert,
# and the previous good backups are kept.
# Run:  ssh root@139.59.85.216 'bash -s' < ops/backup_shrink_guard.sh
set -euo pipefail
F=/usr/local/bin/backup-mypl-cms-to-drive.sh
grep -q 'SUSPECT-shrunk' "$F" && { echo "already applied"; exit 0; }
cp "$F" /root/backup-ops-20261015/backup-mypl-cms-to-drive.sh.bak
python3 - <<'PY'
p = '/usr/local/bin/backup-mypl-cms-to-drive.sh'
s = open(p).read()
old = 'mv "$TMP/$FILE" "$BACKUP_DIR/$FILE"\n'
new = '''# A dump far smaller than the previous good one means the database was emptied or truncated. Keep it aside
# for forensics but never count it as a good backup.
PREV=$(ls -1t "$BACKUP_DIR"/mypl_cms_*.sql.gz.gpg 2>/dev/null | head -1 || true)
if [ -n "$PREV" ]; then
    PREV_SIZE=$(stat -c%s "$PREV")
    if [ "$SIZE" -lt $((PREV_SIZE / 2)) ]; then
        mv "$TMP/$FILE" "$BACKUP_DIR/SUSPECT-shrunk-$FILE"
        fail "new dump is $SIZE bytes vs previous $PREV_SIZE - database may have been emptied; previous backups kept"
    fi
fi

mv "$TMP/$FILE" "$BACKUP_DIR/$FILE"
'''
assert s.count(old) == 1
open(p, 'w').write(s.replace(old, new, 1))
PY
bash -n "$F" && echo "guard installed, syntax ok"
