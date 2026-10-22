#!/bin/sh
set -eu

# Refresh signatures for the ephemeral cron container. Failure remains fail-closed:
# FileScanService records scan_error and never releases an unscanned upload.
if [ "${SCAN_DRIVER:-none}" = "clamav" ]; then
    freshclam --quiet || true
fi

php artisan config:cache
exec php artisan schedule:run --no-interaction
