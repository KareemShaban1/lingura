#!/usr/bin/env bash
# Nightly logical backup to a separate bucket (complements managed point-in-time recovery).
# Usage: DATABASE_URL=postgres://... BACKUP_BUCKET=s3://lf-backups ./ops/backup.sh
set -euo pipefail
: "${DATABASE_URL:?}" "${BACKUP_BUCKET:?}"
stamp=$(date -u +%Y%m%dT%H%M%SZ)
file="/tmp/linguaforge-${stamp}.dump"
pg_dump --format=custom --no-owner --dbname="$DATABASE_URL" --file="$file"
aws s3 cp "$file" "${BACKUP_BUCKET}/daily/linguaforge-${stamp}.dump" --sse AES256
rm -f "$file"
echo "backup ${stamp} uploaded"
# Retention (30 daily / 12 monthly) is enforced by a bucket lifecycle rule, not by this script.
