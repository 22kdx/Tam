#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

BACKUP_ROOT="${BACKUP_ROOT:-/root/backups/automatic}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
MYSQL_CONTAINER="${MYSQL_CONTAINER:-invoiceninja_clean-mysql-1}"
PUBLIC_VOLUME="${PUBLIC_VOLUME:-invoiceninja_clean_app_public}"
STORAGE_VOLUME="${STORAGE_VOLUME:-invoiceninja_clean_app_storage}"
STAMP="$(date -u +%Y%m%d_%H%M%S)"
DEST="$BACKUP_ROOT/$STAMP"
LOG="$BACKUP_ROOT/backup.log"
STATUS="$BACKUP_ROOT/status.json"
LOCK="/var/lock/tam-treasury-backup.lock"

mkdir -p "$BACKUP_ROOT"
exec 9>"$LOCK"
flock -n 9 || { echo "$(date -Is) skipped: backup already running" >> "$LOG"; exit 0; }

on_error() {
  local code=$?
  printf '{"ok":false,"finished_at":"%s","backup":"%s","exit_code":%d}\n' "$(date -Is)" "$DEST" "$code" > "$STATUS"
  echo "$(date -Is) FAILED $DEST code=$code" >> "$LOG"
  exit "$code"
}
trap on_error ERR

mkdir -p "$DEST"
echo "$(date -Is) START $DEST" >> "$LOG"

docker exec "$MYSQL_CONTAINER" sh -lc \
  'mysqldump --no-tablespaces -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" --single-transaction --routines --triggers "$MYSQL_DATABASE"' \
  | gzip -9 > "$DEST/database.sql.gz"

docker run --rm -v "$PUBLIC_VOLUME":/src:ro -v "$DEST":/backup alpine \
  sh -c 'tar czf /backup/app_public.tar.gz -C /src .'
docker run --rm -v "$STORAGE_VOLUME":/src:ro -v "$DEST":/backup alpine \
  sh -c 'tar czf /backup/app_storage.tar.gz -C /src .'

cp -a /root/invoiceninja_app/debian/docker-compose.yml "$DEST/docker-compose.yml"
cp -a /root/invoiceninja_app/debian/nginx "$DEST/nginx"

gzip -t "$DEST/database.sql.gz"
tar tzf "$DEST/app_public.tar.gz" >/dev/null
tar tzf "$DEST/app_storage.tar.gz" >/dev/null
sha256sum "$DEST"/database.sql.gz "$DEST"/app_public.tar.gz "$DEST"/app_storage.tar.gz > "$DEST/SHA256SUMS"

find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d -mtime "+$RETENTION_DAYS" -print -exec rm -rf {} + >> "$LOG"
printf '{"ok":true,"finished_at":"%s","backup":"%s","retention_days":%d}\n' "$(date -Is)" "$DEST" "$RETENTION_DAYS" > "$STATUS"
echo "$(date -Is) SUCCESS $DEST" >> "$LOG"
