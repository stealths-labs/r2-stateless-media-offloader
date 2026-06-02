#!/usr/bin/env bash
#
# Local development environment bootstrap for r2-stateless-media-offload.
#
# Spins up WordPress + MariaDB, installs WP, injects R2 credentials from .env
# as wp-config constants, activates the plugin, and runs the validation gate.
#
# Usage:
#   cp .env.example .env   # fill in your R2 test bucket credentials
#   ./bin/dev-setup.sh
#
set -euo pipefail
cd "$(dirname "$0")/.."

if [ ! -f .env ]; then
  echo "ERROR: .env not found. Run: cp .env.example .env  and fill in your R2 credentials." >&2
  exit 1
fi

echo "==> Starting containers"
docker compose up -d db wordpress

echo "==> Waiting for database"
for _ in $(seq 1 30); do
  if docker compose run --rm wpcli wp db check >/dev/null 2>&1; then break; fi
  sleep 2
done

echo "==> Installing WordPress (idempotent)"
docker compose run --rm wpcli wp core install \
  --url=http://localhost:8765 \
  --title="R2 Offload Dev" \
  --admin_user=admin --admin_password=admin \
  --admin_email=dev@example.com --skip-email 2>/dev/null || true

echo "==> Injecting R2 credentials as wp-config constants"
docker compose run --rm wpcli sh -c '
  for c in R2OFFLOAD_ACCOUNT_ID R2OFFLOAD_BUCKET R2OFFLOAD_ACCESS_KEY R2OFFLOAD_SECRET_KEY R2OFFLOAD_CUSTOM_DOMAIN; do
    wp config set "$c" "$(printenv $c)" --type=constant >/dev/null 2>&1 || true
  done
'

echo "==> Activating plugin"
docker compose run --rm wpcli wp plugin activate r2-stateless-media-offload

echo "==> Running validation gate"
docker compose run --rm wpcli wp r2offload test

echo
echo "Done. WordPress: http://localhost:8765/wp-admin  (admin / admin)"
