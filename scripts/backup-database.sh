#!/usr/bin/env bash
#
# Dump the application database to a compressed, timestamped file and rotate
# old ones. You are self-hosting MySQL, so nothing else is doing this.
#
# Schedule it from the host's crontab (not inside a container, which is
# replaced on every deploy). 02:30 daily:
#
#   30 2 * * * /path/to/BLIS-system/scripts/backup-database.sh >> /var/log/blis-backup.log 2>&1
#
# Override any of these with environment variables:
#   BLIS_BACKUP_DIR        where dumps are written   (default /var/backups/blis)
#   BLIS_BACKUP_KEEP_DAYS  how long to keep them     (default 14)
#   BLIS_COMPOSE_FILE      which stack to dump from  (default docker-compose.prod.yml)
#
# RESTORING -- test this at least once, because a backup you have never
# restored is a guess, not a backup:
#
#   gunzip -c blis-2026-09-19-0230.sql.gz | \
#     docker compose -f docker-compose.prod.yml exec -T db \
#       mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

BACKUP_DIR="${BLIS_BACKUP_DIR:-/var/backups/blis}"
KEEP_DAYS="${BLIS_BACKUP_KEEP_DAYS:-14}"
COMPOSE_FILE="${BLIS_COMPOSE_FILE:-docker-compose.prod.yml}"

if [[ ! -f .env.docker ]]; then
  echo "error: .env.docker not found in $REPO_ROOT" >&2
  exit 1
fi

# Credentials live only in .env.docker, never in this script or the crontab.
# shellcheck disable=SC1091
set -a; . ./.env.docker; set +a

: "${MYSQL_USER:?MYSQL_USER missing from .env.docker}"
: "${MYSQL_PASSWORD:?MYSQL_PASSWORD missing from .env.docker}"
: "${MYSQL_DATABASE:?MYSQL_DATABASE missing from .env.docker}"

mkdir -p "$BACKUP_DIR"
STAMP="$(date +%Y-%m-%d-%H%M)"
TARGET="$BACKUP_DIR/blis-$STAMP.sql.gz"
WORKING="$TARGET.partial"

cleanup() { rm -f "$WORKING"; }
trap cleanup EXIT

echo "[$(date -Is)] dumping $MYSQL_DATABASE -> $TARGET"

# --single-transaction gives a consistent snapshot of InnoDB tables without
# locking writers out, so the site stays usable while this runs.
docker compose -f "$COMPOSE_FILE" exec -T db \
  mysqldump \
    --single-transaction \
    --quick \
    --routines \
    --events \
    --no-tablespaces \
    -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" \
    "$MYSQL_DATABASE" \
  | gzip -9 > "$WORKING"

# Verify before promoting. A truncated dump that still gzips cleanly is the
# classic silent backup failure -- you only discover it when restoring.
if ! gzip -t "$WORKING" 2>/dev/null; then
  echo "error: dump failed gzip integrity check, keeping previous backups" >&2
  exit 1
fi

SIZE="$(stat -c %s "$WORKING")"
if (( SIZE < 1024 )); then
  echo "error: dump is only ${SIZE} bytes, refusing to treat it as valid" >&2
  exit 1
fi

if ! gunzip -c "$WORKING" | tail -5 | grep -q "Dump completed"; then
  echo "error: dump lacks mysqldump's completion marker, so it is truncated" >&2
  exit 1
fi

mv "$WORKING" "$TARGET"
trap - EXIT
echo "[$(date -Is)] wrote $TARGET ($(du -h "$TARGET" | cut -f1))"

# Rotation happens only after a verified new backup exists, so a run of
# failures can never leave you with nothing.
DELETED="$(find "$BACKUP_DIR" -name 'blis-*.sql.gz' -type f -mtime "+$KEEP_DAYS" -print -delete | wc -l)"
echo "[$(date -Is)] retention ${KEEP_DAYS}d, removed ${DELETED} old backup(s), $(find "$BACKUP_DIR" -name 'blis-*.sql.gz' | wc -l) remaining"
