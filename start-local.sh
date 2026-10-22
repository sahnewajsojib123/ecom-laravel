#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
if command -v php >/dev/null 2>&1; then
  commerce_php="$(command -v php)"
elif [ -x ../../work/runtime/php ]; then
  commerce_php="../../work/runtime/php"
else
  echo 'Install PHP 8.3+ and Composer, then follow README.md.'
  exit 1
fi
if [ ! -f vendor/autoload.php ] || [ ! -f .env ]; then
  echo 'Follow the fresh installation steps in README.md first.'
  exit 1
fi
exec "$commerce_php" artisan serve --host=127.0.0.1 --port="${PORT:-8000}"
