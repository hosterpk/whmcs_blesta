#!/usr/bin/env bash
set -Eeuo pipefail

usage() {
    cat <<'USAGE'
Usage:
  scripts/import_whmcs_large.sh --database DBNAME --user DBUSER --key CCENCRYPTIONHASH [options]

Options:
  --host HOST              WHMCS database host. Default: localhost
  --database DBNAME        WHMCS database name
  --user DBUSER            WHMCS database user
  --pass DBPASS            WHMCS database password. Prefer WHMCS_DB_PASS env var or prompt.
  --key HASH               WHMCS cc_encryption_hash
  --version VERSION        WHMCS migrator version. Default: 8.0
  --balance-credit BOOL    true/false. Default: true
  --debug BOOL             true/false. Default: true
  --memory LIMIT           PHP memory_limit. Default: 4G
  --log-dir DIR            Directory for logs. Default: logs/import_manager
  --php PHP_BIN            PHP binary. Default: php
  -h, --help               Show this help

Example:
  WHMCS_DB_PASS='secret' scripts/import_whmcs_large.sh \
    --database whmcs --user whmcs_user --key 'cc_hash'
USAGE
}

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="php"
HOST="localhost"
DATABASE=""
USER=""
PASS="${WHMCS_DB_PASS:-}"
KEY=""
VERSION="8.0"
BALANCE_CREDIT="true"
DEBUG="true"
MEMORY_LIMIT="4G"
LOG_DIR="$ROOT_DIR/logs/import_manager"

while (($#)); do
    case "$1" in
        --host) HOST="${2:-}"; shift 2 ;;
        --database) DATABASE="${2:-}"; shift 2 ;;
        --user) USER="${2:-}"; shift 2 ;;
        --pass) PASS="${2:-}"; shift 2 ;;
        --key) KEY="${2:-}"; shift 2 ;;
        --version) VERSION="${2:-}"; shift 2 ;;
        --balance-credit) BALANCE_CREDIT="${2:-}"; shift 2 ;;
        --debug) DEBUG="${2:-}"; shift 2 ;;
        --memory) MEMORY_LIMIT="${2:-}"; shift 2 ;;
        --log-dir) LOG_DIR="${2:-}"; shift 2 ;;
        --php) PHP_BIN="${2:-}"; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown option: $1" >&2; usage >&2; exit 2 ;;
    esac
done

require_value() {
    local name="$1"
    local value="$2"
    if [[ -z "$value" ]]; then
        echo "Missing required option: $name" >&2
        usage >&2
        exit 2
    fi
}

normalize_bool() {
    case "$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')" in
        true|t|yes|y|1) printf 'true' ;;
        false|f|no|n|0) printf 'false' ;;
        *) echo "Invalid boolean value: $1" >&2; exit 2 ;;
    esac
}

require_value "--database" "$DATABASE"
require_value "--user" "$USER"
require_value "--key" "$KEY"

if [[ -z "$PASS" ]]; then
    read -rsp "WHMCS database password: " PASS
    printf '\n'
fi

BALANCE_CREDIT="$(normalize_bool "$BALANCE_CREDIT")"
DEBUG="$(normalize_bool "$DEBUG")"

mkdir -p "$LOG_DIR"
LOG_FILE="$LOG_DIR/whmcs-${VERSION}-$(date +%Y%m%d-%H%M%S).log"

on_exit() {
    local status=$?
    {
        echo
        echo "Exit status: $status"
        echo "Finished at: $(date -Is)"
        if command -v dmesg >/dev/null 2>&1; then
            echo
            echo "Recent OOM/PHP kernel messages:"
            dmesg -T 2>/dev/null | grep -i -E 'killed|oom|php' | tail -20 || true
        fi
    } | tee -a "$LOG_FILE"
}
trap on_exit EXIT

{
    echo "Starting WHMCS import at: $(date -Is)"
    echo "Root: $ROOT_DIR"
    echo "Host: $HOST"
    echo "Database: $DATABASE"
    echo "User: $USER"
    echo "Version: $VERSION"
    echo "Balance credit: $BALANCE_CREDIT"
    echo "Debug: $DEBUG"
    echo "PHP memory_limit: $MEMORY_LIMIT"
    echo "Log file: $LOG_FILE"
    echo
} | tee "$LOG_FILE"

cd "$ROOT_DIR"

"$PHP_BIN" \
    -d memory_limit="$MEMORY_LIMIT" \
    -d max_execution_time=0 \
    -d display_errors=1 \
    -d display_startup_errors=1 \
    -d error_reporting=8191 \
    index.php admin/plugin/import_manager/admin_manage_plugin/index \
    --type whmcs \
    --version "$VERSION" \
    --host "$HOST" \
    --database "$DATABASE" \
    --user "$USER" \
    --pass "$PASS" \
    --key "$KEY" \
    --balance_credit "$BALANCE_CREDIT" \
    --enable_debug "$DEBUG" \
    2>&1 | tee -a "$LOG_FILE"

exit "${PIPESTATUS[0]}"
