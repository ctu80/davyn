#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-http://localhost:8080}"
USERNAME="${USERNAME:-admin}"
PASSWORD="${PASSWORD:-ChangeMe123!}"
CONTAINER="${CONTAINER:-davyn}"
# Path to the SQLite DB inside the container (all-in-one image default).
DB_FILE="${DB_FILE:-/var/lib/davyn/davyn.sqlite}"

TS=$(date +%s)
CAL_URI="smoke-event-${TS}.ics"
CARD_URI="smoke-contact-${TS}.vcf"

pass() { echo "  PASS: $1"; }
fail() { echo "  FAIL: $1"; exit 1; }

check_status() {
    local label="$1" expected="$2" actual="$3"
    if [ "$actual" = "$expected" ]; then
        pass "$label → $actual"
    else
        fail "$label expected $expected, got $actual"
    fi
}

echo "=== Davyn Smoke Tests ==="
echo "BASE_URL: $BASE_URL"
echo ""

# ── Health ──────────────────────────────────────────────────────────────────
echo "-- Health --"
resp=$(curl -s -o /tmp/smoke_health.json -w "%{http_code}" "$BASE_URL/health")
check_status "GET /health" "200" "$resp"
if grep -q '"status":"ok"' /tmp/smoke_health.json; then
    pass "Health body contains status:ok"
else
    fail "Health body missing status:ok ($(cat /tmp/smoke_health.json))"
fi

# ── Auth guards ─────────────────────────────────────────────────────────────
echo ""
echo "-- Auth guards --"
resp=$(curl -s -o /dev/null -w "%{http_code}" -X PROPFIND "$BASE_URL/dav/" -H "Depth: 0")
check_status "PROPFIND /dav/ no credentials" "401" "$resp"

resp=$(curl -s -o /dev/null -w "%{http_code}" -u "$USERNAME:wrongpassword" -X PROPFIND "$BASE_URL/dav/" -H "Depth: 0")
check_status "PROPFIND /dav/ wrong password" "401" "$resp"

resp=$(curl -s -o /dev/null -w "%{http_code}" -u "$USERNAME:$PASSWORD" -X PROPFIND "$BASE_URL/dav/" -H "Depth: 0")
check_status "PROPFIND /dav/ correct credentials" "207" "$resp"

# ── Principal discovery ──────────────────────────────────────────────────────
echo ""
echo "-- Principal discovery --"
propfind_body() {
    local prop="$1"
    printf '<D:propfind xmlns:D="DAV:"><D:prop><%s/></D:prop></D:propfind>' "$prop"
}

resp=$(curl -s -o /tmp/smoke_principal.xml -w "%{http_code}" \
    -u "$USERNAME:$PASSWORD" \
    -X PROPFIND "$BASE_URL/dav/" \
    -H "Depth: 0" \
    -H "Content-Type: application/xml" \
    --data '<D:propfind xmlns:D="DAV:"><D:prop><D:current-user-principal/></D:prop></D:propfind>')
check_status "current-user-principal PROPFIND" "207" "$resp"
if grep -q "principals/$USERNAME" /tmp/smoke_principal.xml; then
    pass "current-user-principal contains principals/$USERNAME"
else
    fail "current-user-principal missing in response"
fi

resp=$(curl -s -o /tmp/smoke_homeset.xml -w "%{http_code}" \
    -u "$USERNAME:$PASSWORD" \
    -X PROPFIND "$BASE_URL/dav/principals/$USERNAME/" \
    -H "Depth: 0" \
    -H "Content-Type: application/xml" \
    --data '<D:propfind xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav" xmlns:CARD="urn:ietf:params:xml:ns:carddav">
              <D:prop>
                <C:calendar-home-set/>
                <CARD:addressbook-home-set/>
              </D:prop>
            </D:propfind>')
check_status "calendar-home-set + addressbook-home-set PROPFIND" "207" "$resp"
if grep -q "calendars/$USERNAME" /tmp/smoke_homeset.xml; then
    pass "calendar-home-set contains calendars/$USERNAME"
else
    fail "calendar-home-set missing in response"
fi
if grep -q "addressbooks/$USERNAME" /tmp/smoke_homeset.xml; then
    pass "addressbook-home-set contains addressbooks/$USERNAME"
else
    fail "addressbook-home-set missing in response"
fi

# ── CalDAV Object CRUD ───────────────────────────────────────────────────────
echo ""
echo "-- CalDAV Object CRUD --"
ICS_DATA="BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Davyn//Smoke//EN
BEGIN:VEVENT
UID:smoke-event-${TS}@smoke.local
DTSTAMP:$(date -u +%Y%m%dT%H%M%SZ)
DTSTART:20260602T100000Z
DTEND:20260602T110000Z
SUMMARY:Smoke Test Event ${TS}
END:VEVENT
END:VCALENDAR"

resp=$(printf '%s' "$ICS_DATA" | curl -s -o /dev/null -w "%{http_code}" \
    -u "$USERNAME:$PASSWORD" \
    -X PUT \
    --data-binary @- \
    -H "Content-Type: text/calendar; charset=utf-8" \
    "$BASE_URL/dav/calendars/$USERNAME/default/$CAL_URI")
check_status "PUT $CAL_URI" "201" "$resp"

resp=$(curl -s -o /tmp/smoke_cal_propfind.xml -w "%{http_code}" \
    -u "$USERNAME:$PASSWORD" \
    -X PROPFIND "$BASE_URL/dav/calendars/$USERNAME/default/" \
    -H "Depth: 1")
check_status "PROPFIND calendars after PUT" "207" "$resp"
if grep -q "$CAL_URI" /tmp/smoke_cal_propfind.xml; then
    pass "PROPFIND lists $CAL_URI"
else
    fail "PROPFIND does not list $CAL_URI"
fi

resp=$(curl -s -o /tmp/smoke_cal_get.ics -w "%{http_code}" \
    -u "$USERNAME:$PASSWORD" \
    "$BASE_URL/dav/calendars/$USERNAME/default/$CAL_URI")
check_status "GET $CAL_URI" "200" "$resp"
if grep -q "BEGIN:VCALENDAR" /tmp/smoke_cal_get.ics; then
    pass "GET returns valid ICS"
else
    fail "GET response missing BEGIN:VCALENDAR"
fi

resp=$(curl -s -o /dev/null -w "%{http_code}" \
    -u "$USERNAME:$PASSWORD" \
    -X DELETE \
    "$BASE_URL/dav/calendars/$USERNAME/default/$CAL_URI")
check_status "DELETE $CAL_URI" "204" "$resp"

resp=$(curl -s -o /dev/null -w "%{http_code}" \
    -u "$USERNAME:$PASSWORD" \
    "$BASE_URL/dav/calendars/$USERNAME/default/$CAL_URI")
check_status "GET $CAL_URI after DELETE" "404" "$resp"

# Re-create the same URI after deletion: the soft-deleted row must be resurrected,
# not collide with the UNIQUE(calendar_id, uri) constraint (regression for moving an
# event back into a calendar / delete-then-recreate over DAV).
resp=$(printf '%s' "$ICS_DATA" | curl -s -o /dev/null -w "%{http_code}" \
    -u "$USERNAME:$PASSWORD" \
    -X PUT --data-binary @- \
    -H "Content-Type: text/calendar; charset=utf-8" \
    "$BASE_URL/dav/calendars/$USERNAME/default/$CAL_URI")
check_status "Re-PUT $CAL_URI after DELETE (resurrect)" "201" "$resp"
resp=$(curl -s -o /dev/null -w "%{http_code}" \
    -u "$USERNAME:$PASSWORD" \
    "$BASE_URL/dav/calendars/$USERNAME/default/$CAL_URI")
check_status "GET $CAL_URI after re-PUT" "200" "$resp"
curl -s -o /dev/null -u "$USERNAME:$PASSWORD" -X DELETE \
    "$BASE_URL/dav/calendars/$USERNAME/default/$CAL_URI"

# ── CardDAV Object CRUD ──────────────────────────────────────────────────────
echo ""
echo "-- CardDAV Object CRUD --"
VCF_DATA="BEGIN:VCARD
VERSION:3.0
UID:smoke-contact-${TS}@smoke.local
FN:Smoke Test Contact ${TS}
N:Contact;Smoke;;;
EMAIL:smoke-${TS}@test.local
END:VCARD"

resp=$(printf '%s' "$VCF_DATA" | curl -s -o /dev/null -w "%{http_code}" \
    -u "$USERNAME:$PASSWORD" \
    -X PUT \
    --data-binary @- \
    -H "Content-Type: text/vcard; charset=utf-8" \
    "$BASE_URL/dav/addressbooks/$USERNAME/default/$CARD_URI")
check_status "PUT $CARD_URI" "201" "$resp"

resp=$(curl -s -o /tmp/smoke_card_propfind.xml -w "%{http_code}" \
    -u "$USERNAME:$PASSWORD" \
    -X PROPFIND "$BASE_URL/dav/addressbooks/$USERNAME/default/" \
    -H "Depth: 1")
check_status "PROPFIND addressbooks after PUT" "207" "$resp"
if grep -q "$CARD_URI" /tmp/smoke_card_propfind.xml; then
    pass "PROPFIND lists $CARD_URI"
else
    fail "PROPFIND does not list $CARD_URI"
fi

resp=$(curl -s -o /tmp/smoke_card_get.vcf -w "%{http_code}" \
    -u "$USERNAME:$PASSWORD" \
    "$BASE_URL/dav/addressbooks/$USERNAME/default/$CARD_URI")
check_status "GET $CARD_URI" "200" "$resp"
if grep -q "BEGIN:VCARD" /tmp/smoke_card_get.vcf; then
    pass "GET returns valid VCF"
else
    fail "GET response missing BEGIN:VCARD"
fi

resp=$(curl -s -o /dev/null -w "%{http_code}" \
    -u "$USERNAME:$PASSWORD" \
    -X DELETE \
    "$BASE_URL/dav/addressbooks/$USERNAME/default/$CARD_URI")
check_status "DELETE $CARD_URI" "204" "$resp"

resp=$(curl -s -o /dev/null -w "%{http_code}" \
    -u "$USERNAME:$PASSWORD" \
    "$BASE_URL/dav/addressbooks/$USERNAME/default/$CARD_URI")
check_status "GET $CARD_URI after DELETE" "404" "$resp"

# ── DB checks ────────────────────────────────────────────────────────────────
echo ""
echo "-- DB change-log checks --"
cal_changes=$(docker exec "$CONTAINER" sqlite3 "$DB_FILE" \
    "SELECT change_type FROM calendar_changes WHERE object_uri = '${CAL_URI}' ORDER BY id;")
if echo "$cal_changes" | grep -q "created" && echo "$cal_changes" | grep -q "deleted"; then
    pass "calendar_changes has created + deleted for $CAL_URI"
else
    fail "calendar_changes missing entries for $CAL_URI (got: $cal_changes)"
fi

card_changes=$(docker exec "$CONTAINER" sqlite3 "$DB_FILE" \
    "SELECT change_type FROM addressbook_changes WHERE object_uri = '${CARD_URI}' ORDER BY id;")
if echo "$card_changes" | grep -q "created" && echo "$card_changes" | grep -q "deleted"; then
    pass "addressbook_changes has created + deleted for $CARD_URI"
else
    fail "addressbook_changes missing entries for $CARD_URI (got: $card_changes)"
fi

# ── Validation: invalid ICS ──────────────────────────────────────────────────
echo ""
echo "-- Validation --"
resp=$(printf 'NOT_A_CALENDAR' | curl -s -o /dev/null -w "%{http_code}" \
    -u "$USERNAME:$PASSWORD" \
    -X PUT \
    --data-binary @- \
    -H "Content-Type: text/calendar; charset=utf-8" \
    "$BASE_URL/dav/calendars/$USERNAME/default/invalid-smoke.ics")
if [ "$resp" = "400" ] || [ "$resp" = "415" ] || [ "$resp" = "422" ]; then
    pass "PUT invalid ICS rejected → $resp"
else
    fail "PUT invalid ICS expected 4xx, got $resp"
fi

resp=$(printf 'NOT_A_VCARD' | curl -s -o /dev/null -w "%{http_code}" \
    -u "$USERNAME:$PASSWORD" \
    -X PUT \
    --data-binary @- \
    -H "Content-Type: text/vcard; charset=utf-8" \
    "$BASE_URL/dav/addressbooks/$USERNAME/default/invalid-smoke.vcf")
if [ "$resp" = "400" ] || [ "$resp" = "415" ] || [ "$resp" = "422" ]; then
    pass "PUT invalid VCF rejected → $resp"
else
    fail "PUT invalid VCF expected 4xx, got $resp"
fi

# ── Unknown user paths ───────────────────────────────────────────────────────
echo ""
echo "-- Unknown user paths --"
resp=$(curl -s -o /dev/null -w "%{http_code}" \
    -u "$USERNAME:$PASSWORD" \
    -X PROPFIND "$BASE_URL/dav/calendars/unknown-user-xyz/" \
    -H "Depth: 1")
if [ "$resp" = "404" ] || [ "$resp" = "403" ] || [ "$resp" = "207" ]; then
    pass "PROPFIND calendars/unknown-user-xyz/ → $resp (no fatal error)"
else
    fail "PROPFIND calendars/unknown-user-xyz/ expected 404/403/207, got $resp"
fi

resp=$(curl -s -o /dev/null -w "%{http_code}" \
    -u "$USERNAME:$PASSWORD" \
    -X PROPFIND "$BASE_URL/dav/addressbooks/unknown-user-xyz/" \
    -H "Depth: 1")
if [ "$resp" = "404" ] || [ "$resp" = "403" ] || [ "$resp" = "207" ]; then
    pass "PROPFIND addressbooks/unknown-user-xyz/ → $resp (no fatal error)"
else
    fail "PROPFIND addressbooks/unknown-user-xyz/ expected 404/403/207, got $resp"
fi

# ── Holiday calendars (generated, read-only, rolling) ────────────────────────
echo ""
echo "-- Holiday calendars --"

# Generate via CLI (idempotent: safe to run repeatedly).
if docker exec "$CONTAINER" php bin/generate-holidays.php --user="$USERNAME" --provider=DE-BW >/dev/null 2>&1; then
    pass "CLI generate-holidays --provider=DE-BW"
else
    fail "CLI generate-holidays failed"
fi

# The generated holiday calendar is visible over CalDAV.
resp=$(curl -s -o /tmp/smoke_holidays.xml -w "%{http_code}" \
    -u "$USERNAME:$PASSWORD" \
    -X PROPFIND "$BASE_URL/dav/calendars/$USERNAME/holidays-de-bw/" \
    -H "Depth: 1")
check_status "PROPFIND holidays-de-bw/" "207" "$resp"
if grep -q "holiday-de-bw-" /tmp/smoke_holidays.xml; then
    pass "Holiday calendar contains generated events"
else
    fail "Holiday calendar has no generated events"
fi

# Read-only enforcement: writing into a generated calendar is forbidden.
resp=$(printf 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:smoke-holiday-hack\r\nDTSTAMP:20260101T000000Z\r\nDTSTART;VALUE=DATE:20260601\r\nSUMMARY:Hack\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n' | curl -s -o /dev/null -w "%{http_code}" \
    -u "$USERNAME:$PASSWORD" \
    -X PUT --data-binary @- \
    -H "Content-Type: text/calendar; charset=utf-8" \
    "$BASE_URL/dav/calendars/$USERNAME/holidays-de-bw/smoke-holiday-hack.ics")
check_status "PUT into read-only holiday calendar" "403" "$resp"

# ── Birthday calendar (generated from contacts' BDAY, auto-triggered) ────────
echo ""
echo "-- Birthday calendar --"

BDAY_CARD_URI="smoke-bday-${TS}.vcf"
BDAY_EVENT_URI="birthday-smoke-bday-${TS}.ics"
BDAY_VCF="BEGIN:VCARD
VERSION:3.0
UID:smoke-bday-${TS}@smoke.local
FN:Smoke Birthday ${TS}
N:Birthday;Smoke;;;
BDAY:19900315
END:VCARD"

# Creating a contact with a BDAY over CardDAV must auto-generate a birthday event.
resp=$(printf '%s' "$BDAY_VCF" | curl -s -o /dev/null -w "%{http_code}" \
    -u "$USERNAME:$PASSWORD" \
    -X PUT --data-binary @- \
    -H "Content-Type: text/vcard; charset=utf-8" \
    "$BASE_URL/dav/addressbooks/$USERNAME/default/$BDAY_CARD_URI")
check_status "PUT contact with BDAY" "201" "$resp"

resp=$(curl -s -o /tmp/smoke_birthdays.xml -w "%{http_code}" \
    -u "$USERNAME:$PASSWORD" \
    -X PROPFIND "$BASE_URL/dav/calendars/$USERNAME/birthdays/" \
    -H "Depth: 1")
check_status "PROPFIND birthdays/" "207" "$resp"
if grep -q "$BDAY_EVENT_URI" /tmp/smoke_birthdays.xml; then
    pass "Birthday auto-generated from contact"
else
    fail "Birthday event not generated for the contact"
fi

# The generated birthday VEVENT is all-day and yearly-recurring.
birthday_ics=$(docker exec "$CONTAINER" sqlite3 "$DB_FILE" \
    "SELECT ics FROM calendar_objects WHERE uri = '${BDAY_EVENT_URI}' AND deleted_at IS NULL;")
if echo "$birthday_ics" | grep -q "RRULE:FREQ=YEARLY" && echo "$birthday_ics" | grep -q "DTSTART;VALUE=DATE:"; then
    pass "Birthday event is all-day + RRULE:FREQ=YEARLY"
else
    fail "Birthday event missing RRULE/all-day (got: $birthday_ics)"
fi

# Read-only enforcement: writing into the generated birthday calendar is forbidden.
resp=$(printf 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:smoke-bday-hack\r\nDTSTAMP:20260101T000000Z\r\nDTSTART;VALUE=DATE:20260601\r\nSUMMARY:Hack\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n' | curl -s -o /dev/null -w "%{http_code}" \
    -u "$USERNAME:$PASSWORD" \
    -X PUT --data-binary @- \
    -H "Content-Type: text/calendar; charset=utf-8" \
    "$BASE_URL/dav/calendars/$USERNAME/birthdays/smoke-bday-hack.ics")
check_status "PUT into read-only birthday calendar" "403" "$resp"

# CLI regeneration is idempotent (no duplicates).
docker exec "$CONTAINER" php bin/regenerate-birthdays.php --user="$USERNAME" >/dev/null 2>&1 || true
count1=$(docker exec "$CONTAINER" sqlite3 "$DB_FILE" \
    "SELECT COUNT(*) FROM calendar_objects WHERE uri = '${BDAY_EVENT_URI}' AND deleted_at IS NULL;")
docker exec "$CONTAINER" php bin/regenerate-birthdays.php --user="$USERNAME" >/dev/null 2>&1 || true
count2=$(docker exec "$CONTAINER" sqlite3 "$DB_FILE" \
    "SELECT COUNT(*) FROM calendar_objects WHERE uri = '${BDAY_EVENT_URI}' AND deleted_at IS NULL;")
if [ "$count1" = "1" ] && [ "$count2" = "1" ]; then
    pass "Birthday regeneration idempotent (no duplicates)"
else
    fail "Birthday duplicated on regenerate (counts: $count1, $count2)"
fi

# Deleting the contact removes its birthday event.
resp=$(curl -s -o /dev/null -w "%{http_code}" \
    -u "$USERNAME:$PASSWORD" \
    -X DELETE \
    "$BASE_URL/dav/addressbooks/$USERNAME/default/$BDAY_CARD_URI")
check_status "DELETE contact with BDAY" "204" "$resp"

gone=$(docker exec "$CONTAINER" sqlite3 "$DB_FILE" \
    "SELECT COUNT(*) FROM calendar_objects WHERE uri = '${BDAY_EVENT_URI}' AND deleted_at IS NULL;")
if [ "$gone" = "0" ]; then
    pass "Birthday event removed when contact deleted"
else
    fail "Birthday event lingered after contact delete (count: $gone)"
fi

# ── Maintenance mode enforcement (DAV → 503; state restored on exit) ─────────
echo ""
echo "-- Maintenance mode --"

# Guarantee we never leave the instance stuck in maintenance, even on a later failure.
cleanup_maintenance() { docker exec "$CONTAINER" php bin/maintenance.php disable >/dev/null 2>&1 || true; }
trap cleanup_maintenance EXIT

if docker exec "$CONTAINER" php bin/maintenance.php enable --reason "smoke test" >/dev/null 2>&1; then
    pass "CLI maintenance enable"
else
    fail "CLI maintenance enable"
fi

resp=$(curl -s -o /dev/null -w "%{http_code}" -u "$USERNAME:$PASSWORD" -X PROPFIND "$BASE_URL/dav/" -H "Depth: 0")
check_status "PROPFIND /dav/ during maintenance" "503" "$resp"

if docker exec "$CONTAINER" php bin/maintenance.php disable >/dev/null 2>&1; then
    pass "CLI maintenance disable"
else
    fail "CLI maintenance disable"
fi

resp=$(curl -s -o /dev/null -w "%{http_code}" -u "$USERNAME:$PASSWORD" -X PROPFIND "$BASE_URL/dav/" -H "Depth: 0")
check_status "PROPFIND /dav/ after maintenance" "207" "$resp"

# ── First-run setup is locked on an initialized instance ────────────────────
echo ""
echo "-- First-run setup (locked) --"

SETUP_JAR=$(mktemp)

# Status reports the instance as already initialized and hands out a CSRF token.
resp=$(curl -s -o /tmp/smoke_setup_status.json -w "%{http_code}" -c "$SETUP_JAR" "$BASE_URL/api/setup/status")
check_status "GET /api/setup/status" "200" "$resp"
if grep -q '"initialized":true' /tmp/smoke_setup_status.json; then
    pass "Setup status initialized:true"
else
    fail "Setup status not initialized:true ($(cat /tmp/smoke_setup_status.json))"
fi

# Creating another admin via setup is rejected (CSRF-valid → blocked by the lock, 409).
SETUP_CSRF=$(grep -o '"csrf_token":"[^"]*"' /tmp/smoke_setup_status.json | sed 's/.*:"//;s/"//')
resp=$(curl -s -o /dev/null -w "%{http_code}" -b "$SETUP_JAR" \
    -X POST "$BASE_URL/api/setup/create-admin" \
    -H "Content-Type: application/json" -H "X-CSRF-Token: $SETUP_CSRF" \
    -d '{"username":"smoke-intruder","password":"sup3rsecret","password_confirm":"sup3rsecret"}')
check_status "POST /api/setup/create-admin when initialized" "409" "$resp"

# /setup itself redirects to /login once an admin exists.
resp=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/setup")
check_status "GET /setup when initialized" "302" "$resp"

rm -f "$SETUP_JAR"

# ── User self-service: profile preferences + owner-enforced sharing ─────────
echo ""
echo "-- User self-service (profile + sharing) --"

USER_JAR=$(mktemp)
# Establish an authenticated web session (form login), then read the CSRF token.
LOGIN_CSRF=$(curl -s -c "$USER_JAR" "$BASE_URL/login" | grep -o 'name="csrf_token" value="[^"]*"' | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$USER_JAR" -c "$USER_JAR" -X POST "$BASE_URL/login" \
    --data-urlencode "csrf_token=$LOGIN_CSRF" --data-urlencode "username=$USERNAME" --data-urlencode "password=$PASSWORD"
curl -s -b "$USER_JAR" -o /tmp/smoke_me.json "$BASE_URL/api/user/me"
TOK=$(grep -o '"csrf_token":"[^"]*"' /tmp/smoke_me.json | sed 's/.*:"//;s/"//')
ORIG_NAME=$(grep -o '"display_name":"[^"]*"' /tmp/smoke_me.json | sed 's/.*:"//;s/"//')

if [ -n "$TOK" ]; then pass "Authenticated web session established"; else fail "Could not establish web session"; fi

# me now exposes per-user locale/theme fields.
if grep -q '"locale":' /tmp/smoke_me.json && grep -q '"theme":' /tmp/smoke_me.json; then
    pass "me exposes locale/theme preference fields"
else
    fail "me missing locale/theme fields ($(cat /tmp/smoke_me.json))"
fi

# Share-target directory (other active users) is reachable.
resp=$(curl -s -o /dev/null -w "%{http_code}" -b "$USER_JAR" "$BASE_URL/api/user/share-targets")
check_status "GET /api/user/share-targets" "200" "$resp"

# Owner-enforced share listing: a collection the user owns → 200; a missing one → 404.
CAL_ID=$(curl -s -b "$USER_JAR" "$BASE_URL/api/user/calendars" | grep -o '"id":[0-9]*' | head -1 | sed 's/[^0-9]//g')
if [ -n "$CAL_ID" ]; then
    resp=$(curl -s -o /dev/null -w "%{http_code}" -b "$USER_JAR" "$BASE_URL/api/user/shares?type=calendar&id=$CAL_ID")
    check_status "GET /api/user/shares (owned calendar)" "200" "$resp"
fi
resp=$(curl -s -o /dev/null -w "%{http_code}" -b "$USER_JAR" "$BASE_URL/api/user/shares?type=calendar&id=999999")
check_status "GET /api/user/shares (missing collection)" "404" "$resp"

# Update own display name, then revert — exercises CSRF + the profile endpoint.
resp=$(curl -s -o /dev/null -w "%{http_code}" -b "$USER_JAR" -X POST "$BASE_URL/api/user/profile" \
    -H "Content-Type: application/json" -H "X-CSRF-Token: $TOK" -d '{"display_name":"Smoke Admin"}')
check_status "POST /api/user/profile (rename)" "200" "$resp"
resp=$(curl -s -o /dev/null -w "%{http_code}" -b "$USER_JAR" -X POST "$BASE_URL/api/user/profile" \
    -H "Content-Type: application/json" -H "X-CSRF-Token: $TOK" -d "{\"display_name\":\"${ORIG_NAME}\"}")
check_status "POST /api/user/profile (revert)" "200" "$resp"

# Set, then clear, a per-user theme preference (revert to instance default).
resp=$(curl -s -o /dev/null -w "%{http_code}" -b "$USER_JAR" -X POST "$BASE_URL/api/user/profile" \
    -H "Content-Type: application/json" -H "X-CSRF-Token: $TOK" -d '{"theme":"dark","locale":"en"}')
check_status "POST /api/user/profile (preferences)" "200" "$resp"

rm -f "$USER_JAR"

echo ""
echo "==========================="
echo "Smoke tests passed."
