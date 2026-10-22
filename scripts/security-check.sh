#!/usr/bin/env bash
# Kalinga security self-check. Run before every release and in CI.
#   bash scripts/security-check.sh
# Exits non-zero when any check fails.
set -u
cd "$(dirname "$0")/.."
fail=0
step() { printf '\n== %s\n' "$1"; }

step "Dependency advisories (PHP)"
composer audit --no-interaction --locked || fail=1

step "Dependency advisories (JavaScript, production packages)"
npm audit --omit=dev --audit-level=moderate || fail=1

step "No dev-server marker or secrets in the release tree"
if [ -f public/hot ]; then echo "public/hot exists - delete it before deploying"; fail=1; fi
if git ls-files --error-unmatch .env >/dev/null 2>&1; then echo ".env is tracked by git"; fail=1; fi

step "Production settings in .env (when present)"
if [ -f .env ] && grep -q '^APP_ENV=production' .env; then
  grep -q '^APP_DEBUG=false' .env || { echo "APP_DEBUG must be false"; fail=1; }
  grep -q '^SESSION_SECURE_COOKIE=true' .env || { echo "SESSION_SECURE_COOKIE must be true"; fail=1; }
  grep -q '^SESSION_ENCRYPT=true' .env || { echo "SESSION_ENCRYPT must be true"; fail=1; }
  grep -qE '^FILESYSTEM_DISK=public' .env && { echo "FILESYSTEM_DISK must not be public"; fail=1; }
fi

step "Raw HTML rendering in the frontend (review each hit)"
grep -rn "v-html\|innerHTML" resources/js --include=*.vue --include=*.ts || true

step "Security regression tests"
php artisan test --filter='SecurityHardeningTest|AuthAuditTrailTest|BruteForceProtectionTest|CaseRouteAccessSweepTest|RoleAuthorizationTest|AggregatePrivacyTest|AccountEnumerationTest' || fail=1

[ $fail -eq 0 ] && echo -e "\nAll security checks passed." || echo -e "\nSecurity checks FAILED."
exit $fail
