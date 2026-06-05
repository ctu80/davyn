#!/usr/bin/env bash
#
# Davyn release check. Runs the build/typecheck and configuration validations that
# must pass before cutting a release, plus a live health + smoke check when the
# stack is already running.
#
#   bash scripts/release-check.sh
#
# Exit non-zero on the first hard failure. Live checks are skipped (not failed)
# when the stack is not up.

set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$(pwd)"
BASE_URL="${BASE_URL:-http://localhost:8080}"
EXPECTED_VERSION="$(tr -d '[:space:]' < VERSION)"

pass() { printf '  \033[32m✓\033[0m %s\n' "$1"; }
info() { printf '  \033[36mi\033[0m %s\n' "$1"; }
fail() { printf '  \033[31m✗ %s\033[0m\n' "$1"; exit 1; }
head() { printf '\n\033[1m== %s ==\033[0m\n' "$1"; }

head "Version consistency ($EXPECTED_VERSION)"
[ "$(tr -d '[:space:]' < app/bin/VERSION)" = "$EXPECTED_VERSION" ] \
  || fail "app/bin/VERSION != $EXPECTED_VERSION"
pkg_version="$(node -p "require('./app/frontend/package.json').version" 2>/dev/null || echo '?')"
[ "$pkg_version" = "$EXPECTED_VERSION" ] \
  || fail "app/frontend/package.json version ($pkg_version) != $EXPECTED_VERSION"
pass "VERSION, app/bin/VERSION and package.json all report $EXPECTED_VERSION"

head "Frontend build & typecheck"
( cd app/frontend && npm ci --silent && npx tsc --noEmit && npm run build >/dev/null )
pass "npm ci + tsc --noEmit + vite build"

head "Docker Compose config"
docker compose config -q && pass "docker compose config valid (production)"
docker compose -f docker-compose.yml config --services | grep -qx davyn && pass "service 'davyn' present"
docker compose -f docker-compose.yml -f docker-compose.build.yml config -q && pass "build override valid"
docker compose -f docker-compose.yml -f docker-compose.dev.yml config -q   && pass "dev override valid"

head "Live health & smoke (skipped if stack is down)"
if curl -fsS "$BASE_URL/health" >/tmp/davyn-health.json 2>/dev/null; then
  cat /tmp/davyn-health.json; echo
  if grep -q "\"version\":\"$EXPECTED_VERSION\"" /tmp/davyn-health.json; then
    pass "/health reports version $EXPECTED_VERSION"
  else
    fail "/health version does not match $EXPECTED_VERSION"
  fi
  if [ -x tests/smoke.sh ] || [ -f tests/smoke.sh ]; then
    head "Smoke tests"
    bash tests/smoke.sh && pass "smoke tests passed"
  fi
else
  info "Stack not reachable at $BASE_URL — start it with 'docker compose up -d' to run live checks."
fi

printf '\n\033[1;32mRelease check complete.\033[0m\n'
