#!/usr/bin/env bash
#
# Walks every one of Tape's control panel screens with a real logged-in session, and posts through
# the write paths. Run inside the plugin-testing container, from the site root:
#
#     ddev exec bash /var/www/craft-tape/tests/integration/cp-smoke.sh
#
# The checks script exercises services directly; this exercises the templates, which is where a
# missing variable, a renamed macro or a nested form turns into a 500 that no unit test would see.
#
# Credentials come from TAPE_CP_USER / TAPE_CP_PASS, defaulting to the local harness's admin.

set -uo pipefail

BASE="${TAPE_BASE_URL:-http://localhost}"
USER="${TAPE_CP_USER:-admin}"
PASS="${TAPE_CP_PASS:-claudepassword}"
JAR="$(mktemp)"
FAILED=0
PASSED=0

cleanup() { rm -f "$JAR"; }
trap cleanup EXIT

get() { curl -sS -b "$JAR" -c "$JAR" -o /tmp/tape-body -w '%{http_code}' "$BASE$1"; }

# Read from one of Tape's own screens rather than the dashboard: the harness has enough plugins
# installed that the dashboard itself 500s, which has nothing to do with anything here.
csrf() {
    curl -sS -b "$JAR" -c "$JAR" "$BASE/admin/tape/destinations" \
        | grep -o 'csrfTokenValue":"[^"]*' | head -1 | cut -d'"' -f3
}

expect() {
    local label="$1" path="$2" needle="${3:-}"
    local code
    code="$(get "$path")"

    if [ "$code" != "200" ]; then
        echo "  ✗ $label — HTTP $code"
        grep -o 'Exception[^<]*\|Error[^<]*' /tmp/tape-body | head -2 | sed 's/^/      /'
        FAILED=$((FAILED + 1))
        return
    fi

    if [ -n "$needle" ] && ! grep -q "$needle" /tmp/tape-body; then
        echo "  ✗ $label — 200 but “$needle” is missing"
        FAILED=$((FAILED + 1))
        return
    fi

    echo "  ✓ $label"
    PASSED=$((PASSED + 1))
}

echo
echo "Tape control panel smoke test"
echo

# ── Log in ─────────────────────────────────────────────────────────────────────────────────────
LOGIN_TOKEN="$(curl -sS -c "$JAR" "$BASE/admin/login" | grep -o 'csrfTokenValue":"[^"]*' | head -1 | cut -d'"' -f3)"

LOGIN="$(curl -sS -b "$JAR" -c "$JAR" -X POST "$BASE/index.php" \
    -H 'Accept: application/json' \
    -H 'X-Requested-With: XMLHttpRequest' \
    --data-urlencode "action=users/login" \
    --data-urlencode "loginName=$USER" \
    --data-urlencode "password=$PASS" \
    --data-urlencode "CRAFT_CSRF_TOKEN=$LOGIN_TOKEN")"

# Craft 5 answers a successful login with the user model, not with `success: true`.
if echo "$LOGIN" | grep -q '"error"' || ! echo "$LOGIN" | grep -q '"user"'; then
    echo "  ✗ could not log in as $USER"
    echo "      $LOGIN" | head -c 300
    exit 1
fi

echo "  ✓ logged in as $USER"
PASSED=$((PASSED + 1))

# ── Read every screen ──────────────────────────────────────────────────────────────────────────
expect "Destinations index"        "/admin/tape/destinations"        "Destinations"
expect "Platform chooser"          "/admin/tape/destinations/new"    "Google Tag Manager"
expect "Google Ads edit screen"    "/admin/tape/destinations/new?platform=googleAds" "Conversion label"
expect "GA4 edit screen"           "/admin/tape/destinations/new?platform=ga4"       "Measurement ID"
expect "Meta edit screen"          "/admin/tape/destinations/new?platform=meta"      "Conversions API"
expect "Webhook edit screen"       "/admin/tape/destinations/new?platform=webhook"   "Signing secret"
expect "Custom snippet screen"     "/admin/tape/destinations/new?platform=custom"    "Head HTML"
expect "Triggers index"            "/admin/tape/triggers"            "Triggers"
expect "Trigger edit screen"       "/admin/tape/triggers/new"        "CSS selector"
expect "Events log"                "/admin/tape/events"              "Event"
expect "Accuracy report"           "/admin/tape/accuracy"            "coverage"
expect "Plugin settings"           "/admin/settings/plugins/tape"    "Consent"

# ── Write paths ────────────────────────────────────────────────────────────────────────────────
TOKEN="$(csrf)"
SUFFIX="smoke$$"

SAVE="$(curl -sS -b "$JAR" -c "$JAR" -o /tmp/tape-save -w '%{http_code}' -X POST "$BASE/admin/actions/tape/destinations/save" \
    --data-urlencode "action=tape/destinations/save" \
    --data-urlencode "CRAFT_CSRF_TOKEN=$TOKEN" \
    --data-urlencode "platform=ga4" \
    --data-urlencode "name=Smoke $SUFFIX" \
    --data-urlencode "handle=$SUFFIX" \
    --data-urlencode "enabled=1" \
    --data-urlencode "consentCategory=analytics" \
    --data-urlencode "settings[measurementId]=G-SMOKE12345" \
    --data-urlencode "events[]=*")"

if [ "$SAVE" = "302" ] || [ "$SAVE" = "200" ]; then
    echo "  ✓ saved a destination"
    PASSED=$((PASSED + 1))
else
    echo "  ✗ saving a destination — HTTP $SAVE"
    grep -o 'Exception[^<]*' /tmp/tape-save | head -2 | sed 's/^/      /'
    FAILED=$((FAILED + 1))
fi

UID_FOUND="$(php /var/www/html/craft tape/destinations 2>/dev/null | grep -c "$SUFFIX")"

if [ "$UID_FOUND" -ge 1 ]; then
    echo "  ✓ the saved destination is in project config"
    PASSED=$((PASSED + 1))
else
    echo "  ✗ the saved destination did not reach project config"
    FAILED=$((FAILED + 1))
fi

# The edit screen for a real destination is a different code path from the new one.
DEST_UID="$(php -r '
require "/var/www/html/bootstrap.php";
$app = require CRAFT_VENDOR_PATH . "/craftcms/cms/bootstrap/console.php";
$d = justinholtweb\tape\Plugin::getInstance()->destinations->getDestinationByHandle("'"$SUFFIX"'");
echo $d ? $d->uid : "";
' 2>/dev/null)"

if [ -n "$DEST_UID" ]; then
    expect "Existing destination edit screen" "/admin/tape/destinations/$DEST_UID" "G-SMOKE12345"

    DELETE="$(curl -sS -b "$JAR" -o /tmp/tape-del -w '%{http_code}' -X POST "$BASE/admin/actions/tape/destinations/delete" \
        -H 'Accept: application/json' -H 'X-Requested-With: XMLHttpRequest' \
        --data-urlencode "action=tape/destinations/delete" \
        --data-urlencode "CRAFT_CSRF_TOKEN=$TOKEN" \
        --data-urlencode "uid=$DEST_UID")"

    if [ "$DELETE" = "200" ]; then
        echo "  ✓ deleted it again"
        PASSED=$((PASSED + 1))
    else
        echo "  ✗ deleting it — HTTP $DELETE"
        FAILED=$((FAILED + 1))
    fi
else
    echo "  ✗ could not find the destination to edit or delete"
    FAILED=$((FAILED + 2))
fi

# ── Front-end endpoints ────────────────────────────────────────────────────────────────────────
# Exactly the headers the runtime sends, and no others. Adding an `Accept` header here that the
# runtime does not send is how a 400 from `requireAcceptsJson()` stays hidden.
RUNTIME_HEADERS=$(grep -c "'Accept': 'application/json'" /var/www/craft-tape/src/web/assets/tape/dist/tape.js)

if [ "$RUNTIME_HEADERS" -ge 1 ]; then
    echo "  ✓ the runtime sends an Accept header"
    PASSED=$((PASSED + 1))
else
    echo "  ✗ the runtime does not send Accept: application/json — the map endpoint will 400"
    FAILED=$((FAILED + 1))
fi

MAP="$(curl -sS -o /tmp/tape-map -w '%{http_code}' -X POST "$BASE/index.php?p=actions/tape/events/map" \
    -H 'Content-Type: application/json' -H 'Accept: application/json' -H 'X-Requested-With: XMLHttpRequest' \
    -d '{"event":"view_item","searchTerm":"x"}')"

if [ "$MAP" = "200" ]; then
    echo "  ✓ the map endpoint answers anonymously"
    PASSED=$((PASSED + 1))
else
    echo "  ✗ the map endpoint — HTTP $MAP"
    head -c 300 /tmp/tape-map | sed 's/^/      /'
    FAILED=$((FAILED + 1))
fi

REFUSED="$(curl -sS -o /dev/null -w '%{http_code}' -X POST "$BASE/index.php?p=actions/tape/events/map" \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"event":"purchase","value":99999}')"

if [ "$REFUSED" = "400" ]; then
    echo "  ✓ a browser cannot ask for a purchase event"
    PASSED=$((PASSED + 1))
else
    echo "  ✗ the map endpoint accepted a purchase — HTTP $REFUSED"
    FAILED=$((FAILED + 1))
fi

echo
echo "$PASSED passed, $FAILED failed"
[ "$FAILED" -eq 0 ]
