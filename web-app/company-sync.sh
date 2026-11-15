#!/usr/bin/env bash
#
# One-time follow-up to ./deploy.sh for servers that were first installed from a
# customer branch before the brand moved into the settings table (for example
# production/armanskitchen). Follows docs/production/deployment.md, section
# "Updating the server later".
#
# It installs the company brand (settings table plus the logo set in the
# git-ignored public/images/customer folder), re-syncs the outlet and menu from
# the company catalog, and adds the .env keys a current install expects. After
# the first run it is safe to run again.
#
# Usage:
#   ./company-sync.sh                     default company: ArmansKitchen
#   ./company-sync.sh -c HillsTable       another folder of database/seeders/production
#   ./company-sync.sh --dry-run           print every step without running anything
#
# Notes:
#   - Re-syncing the catalog overwrites names, prices and photos of the seeded
#     dishes; menu edits made in the admin panel are reset to the catalog.
#   - The pre-order columns are ordinary migrations: php artisan migrate --force
#     inside deploy.sh already adds pre_order_required and drops the old
#     is_preorder_only columns where they exist.
#   - SEED_PROFILE is only read on a fresh database (it answers the company
#     question of the first db:seed). It is set here so a future re-install
#     seeds this company without a terminal, not because deploys need it.

set -Eeuo pipefail

COMPANY="ArmansKitchen"
DRY_RUN=false

usage() {
    sed -n '3,/^set -E/p' "$0" | sed '$d' | sed 's/^# \{0,1\}//'
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        -c|--company) COMPANY="${2:?--company needs a name}"; shift 2 ;;
        --dry-run) DRY_RUN=true; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown option: $1" >&2; usage >&2; exit 2 ;;
    esac
done

cd "$(dirname "${BASH_SOURCE[0]}")"

if [ -t 1 ]; then
    BOLD=$'\033[1m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; RED=$'\033[31m'; RESET=$'\033[0m'
else
    BOLD=''; GREEN=''; YELLOW=''; RED=''; RESET=''
fi

step() { printf '\n%s==> %s%s\n' "$BOLD" "$1" "$RESET"; }
ok() { printf '%s%s%s\n' "$GREEN" "$1" "$RESET"; }
warn() { printf '%s%s%s\n' "$YELLOW" "$1" "$RESET"; }
fail() { printf '%s%s%s\n' "$RED" "$1" "$RESET" >&2; exit 1; }

run() {
    printf '  $ %s\n' "$*"

    if [ "$DRY_RUN" = false ]; then
        "$@"
    fi
}

SEED_CLASS="Database\\Seeders\\Production\\${COMPANY}\\CompanySeeder"

step "Checking the server"

[ -f artisan ] || fail "Run this from the project root (artisan not found)."
[ -f .env ] || fail ".env is missing. Follow docs/production/deployment.md, section First install."
[ -f "database/seeders/production/${COMPANY}/CompanySeeder.php" ] \
    || fail "No company seeder for '${COMPANY}'. Check the folders in database/seeders/production."

APP_ENV_VALUE=$(grep -E '^APP_ENV=' .env | tail -n 1 | cut -d= -f2- | tr -d '"' || true)
if [ "$APP_ENV_VALUE" != "production" ]; then
    warn "APP_ENV is '${APP_ENV_VALUE:-unset}', not production."
fi

if grep -Eq '^IS_DEMO=true' .env; then
    fail "IS_DEMO=true: a demo server always runs the DT Restora brand, never a customer seeder."
fi

# ---------------------------------------------------------------- .env keys

step "Checking .env keys"

ENV_CHANGED=false
ENV_BACKUP=""

env_backup() {
    if [ -z "$ENV_BACKUP" ]; then
        ENV_BACKUP=".env.backup-$(date +%Y%m%d-%H%M%S)"
        run cp .env "$ENV_BACKUP"
        warn "  A copy of .env was saved as $ENV_BACKUP."
    fi
}

if grep -qE '^APP_TIMEZONE=' .env; then
    echo "  APP_TIMEZONE is set."
else
    env_backup
    run sh -c "printf '\n# Invoices and reports use the restaurant clock, not UTC.\nAPP_TIMEZONE=Asia/Dhaka\n' >> .env"
    warn "  APP_TIMEZONE=Asia/Dhaka added."
    ENV_CHANGED=true
fi

if grep -qE '^SEED_PROFILE=' .env; then
    echo "  SEED_PROFILE is set."
else
    env_backup
    SLUG=$(printf '%s' "$COMPANY" | sed -E 's/([a-z0-9])([A-Z])/\1-\2/g' | tr '[:upper:]' '[:lower:]')
    run sh -c "printf '\n# Which company a fresh database seeds (see database/seeders/production/README.md).\nSEED_PROFILE=%s\n' '$SLUG' >> .env"
    warn "  SEED_PROFILE=$SLUG added."
    ENV_CHANGED=true
fi

if grep -qE '^BRAND_' .env; then
    warn "  Old BRAND_* values are present. They are only a legacy fallback now; the brand"
    warn "  comes from the settings table, so they can be removed."
fi

# ---------------------------------------------------------------- brand and catalog

step "Installing the ${COMPANY} brand and catalog (safe to repeat)"

run php artisan db:seed --class="$SEED_CLASS" --force

step "Brand status"

run php artisan brand:status

if [ "$COMPANY" = "ArmansKitchen" ]; then
    step "Pre-order categories"

    printf '  $ php artisan tinker --execute=...\n'

    if [ "$DRY_RUN" = false ]; then
        php artisan tinker --execute="echo '  Categories that need a pre-order: ', App\Models\MenuCategory::query()->where('pre_order_required', true)->pluck('name')->implode(', '), PHP_EOL;"
    fi
fi

# ---------------------------------------------------------------- caches

step "Refreshing caches"

# The configuration is cached with the .env values, so this runs after the edits above.
run php artisan optimize:clear
run php artisan optimize

step "Restarting workers"

run php artisan queue:restart
run php artisan reverb:restart

printf '\n'

if [ "$DRY_RUN" = true ]; then
    ok "Dry run finished. Nothing was changed."
else
    ok "Company ${COMPANY} synced: brand in the settings table, logo set installed, catalog re-synced."
fi

if [ "$ENV_CHANGED" = true ] && [ "$DRY_RUN" = false ]; then
    warn ".env changed: the scheduler (schedule:work) must be restarted to see APP_TIMEZONE."
fi

warn "The scheduler (schedule:work) is not restarted here. If it runs under a supervisor, restart it so it loads the new code."
