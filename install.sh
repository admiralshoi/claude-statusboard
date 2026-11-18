#!/usr/bin/env bash
# Statusboard installer. Run it from the checkout: ./install.sh [--base-url URL] [--bin-dir DIR] [--data-dir DIR] [--force] [--yes]
# Idempotent: re-running keeps an existing config.local.php unless --force is given. --yes installs missing requirements without asking.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_URL="http://127.0.0.1:8787/statusboard"
BIN_DIR=""
DATA_DIR=""
FORCE=0
YES=0
while [ $# -gt 0 ]; do
    case "$1" in
        --base-url) BASE_URL="${2%/}"; shift 2 ;;
        --bin-dir) BIN_DIR="$2"; shift 2 ;;
        --data-dir) DATA_DIR="$2"; shift 2 ;;
        --force) FORCE=1; shift ;;
        --yes|-y) YES=1; shift ;;
        -h|--help) sed -n '2,3p' "$0"; exit 0 ;;
        *) echo "unknown option $1" >&2; exit 2 ;;
    esac
done

ok() { printf '  ok   %s\n' "$1"; }
bad() { printf '  FAIL %s\n' "$1" >&2; FAILED=1; }
FAILED=0

# Offers to install a missing requirement with Homebrew (macOS), asking first unless --yes.
brew_install() {
    local pkg="$1"
    command -v brew >/dev/null 2>&1 || return 1
    if [ "$YES" = 0 ]; then
        [ -t 0 ] || return 1
        printf '  ?    %s is missing. Install it with Homebrew (brew install %s)? [y/N] ' "$pkg" "$pkg"
        read -r answer
        case "$answer" in y|Y|yes) ;; *) return 1 ;; esac
    fi
    brew install "$pkg" || return 1
    # A fresh Homebrew's bin dir may not be on this shell's PATH yet.
    PATH="$(brew --prefix)/bin:$PATH"
    hash -r
}

echo "Checking prerequisites"
MAC=0
[ "$(uname)" = "Darwin" ] && MAC=1
[ "$MAC" = 1 ] && ok "macOS" || echo "  warn not macOS: the board's Terminal buttons (start, resume, type into a session) use Terminal.app via osascript and will not work"
if [ "$MAC" = 1 ] && ! command -v brew >/dev/null 2>&1; then
    echo "  warn Homebrew not found: missing requirements are listed, not installed (https://brew.sh)"
fi
command -v git >/dev/null 2>&1 || brew_install git || true
command -v git >/dev/null 2>&1 && ok "git $(git --version | awk '{print $3}')" || bad "git not found (on macOS: xcode-select --install, or brew install git)"
command -v php >/dev/null 2>&1 || brew_install php || true
PHP="$(command -v php || true)"
if [ -z "$PHP" ]; then bad "php not found (PHP 8.1+ with pdo_sqlite, pcntl and posix)"; else
    "$PHP" -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' && ok "php $("$PHP" -r 'echo PHP_VERSION;')" || bad "PHP 8.1+ needed, found $("$PHP" -r 'echo PHP_VERSION;')"
    for ext in pdo_sqlite pcntl posix json mbstring; do
        "$PHP" -r "exit(extension_loaded('$ext') ? 0 : 1);" && ok "php $ext" || bad "php extension $ext is missing"
    done
fi
CLAUDE="$(command -v claude || true)"
if [ -z "$CLAUDE" ]; then bad "claude not found: install Claude Code first (https://docs.claude.com/en/docs/claude-code)"; else
    CLAUDE_VER="$("$CLAUDE" --version 2>/dev/null | awk '{print $1}' || true)"
    # The board's typed messages need at least this version (config.php); older ones work with a real Enter key press only.
    MIN_VER="$([ -n "$PHP" ] && "$PHP" -r '$c = include $argv[1]; echo $c["typed_enter_min_version"] ?? "";' "$DIR/config.php" 2>/dev/null || true)"
    if [ -n "$MIN_VER" ] && [ -n "$PHP" ] && ! "$PHP" -r 'exit(version_compare($argv[1], $argv[2], ">=") ? 0 : 1);' "${CLAUDE_VER:-0}" "$MIN_VER"; then
        echo "  warn Claude Code ${CLAUDE_VER:-unknown} is older than $MIN_VER: update it (claude update), or the board needs Accessibility permission to press Enter in sessions"
    else ok "Claude Code $CLAUDE_VER ($CLAUDE)"; fi
    # auth status prints JSON with "loggedIn"; an older CLI without the command is not treated as logged out.
    AUTH="$("$CLAUDE" auth status 2>/dev/null || true)"
    if printf '%s' "$AUTH" | grep -q '"loggedIn": *true'; then ok "Claude Code is logged in"
    elif [ -n "${ANTHROPIC_API_KEY:-}" ]; then ok "Claude Code uses ANTHROPIC_API_KEY"
    elif printf '%s' "$AUTH" | grep -q '"loggedIn"'; then bad "Claude Code is not logged in: run \`claude auth login\` (or start \`claude\` once), then run ./install.sh again"
    else echo "  warn could not check the Claude Code login: make sure \`claude\` starts and is signed in"; fi
fi
# Recommended, not required: sessions test web UIs through Claude in Chrome.
if [ "$MAC" = 1 ]; then
    if [ -d "/Applications/Google Chrome.app" ] || [ -d "$HOME/Applications/Google Chrome.app" ]; then ok "Google Chrome (add the Claude in Chrome extension so sessions can test in the browser)"
    else echo "  warn Google Chrome not found: recommended, so sessions can test web pages with the Claude in Chrome extension (https://www.google.com/chrome/)"; fi
elif command -v google-chrome >/dev/null 2>&1 || command -v google-chrome-stable >/dev/null 2>&1; then ok "Google Chrome"
else echo "  warn Google Chrome not found: recommended for the Claude in Chrome extension"; fi
[ "$FAILED" = 0 ] || { echo "Fix the above, then run ./install.sh again." >&2; exit 1; }

echo "Putting sb on PATH"
if [ -z "$BIN_DIR" ]; then
    if [ -w /usr/local/bin ]; then BIN_DIR=/usr/local/bin; else BIN_DIR="$HOME/.local/bin"; fi
fi
mkdir -p "$BIN_DIR"
chmod +x "$DIR/bin/sb"
ln -sfn "$DIR/bin/sb" "$BIN_DIR/sb"
ok "$BIN_DIR/sb -> $DIR/bin/sb"
case ":$PATH:" in *":$BIN_DIR:"*) ;; *) echo "  warn $BIN_DIR is not on your PATH: add it to your shell profile" ;; esac

echo "Writing config.local.php"
if [ -f "$DIR/config.local.php" ] && [ "$FORCE" = 0 ]; then
    ok "kept the existing config.local.php (--force rewrites it)"
else
    # The PATH the board uses for what it starts from the web server, which has a bare PATH of its own.
    NODE_DIR="$(dirname "$(command -v node 2>/dev/null || echo /usr/bin/node)")"
    BOARD_PATH="$(printf '%s' "$BIN_DIR:$(dirname "$CLAUDE"):$(dirname "$PHP"):$NODE_DIR:/usr/local/bin:/opt/homebrew/bin:/usr/bin:/bin:/usr/sbin:/sbin" | tr ':' '\n' | awk '!seen[$0]++' | paste -sd: -)"
    {
        echo "<?php"
        echo "// This machine's settings (written by install.sh, never committed). Overrides config.php."
        echo "return ["
        echo "    'base_url' => '$BASE_URL',"
        echo "    'sb_bin' => '$BIN_DIR/sb',"
        echo "    'claude_bin' => '$CLAUDE',"
        echo "    'path' => '$BOARD_PATH',"
        [ -n "$DATA_DIR" ] && echo "    'data_dir' => '$DATA_DIR',"
        echo "    // Files a validator may never read, relative to each project's root, e.g. 'config/prod.php':"
        echo "    'validator_secret_paths' => [],"
        echo "    // Extra validator guard rules (regex => why), e.g. your production hosts:"
        echo "    'guard_rules' => [],"
        echo "];"
    } > "$DIR/config.local.php"
    ok "base_url $BASE_URL"
fi

echo "Creating the data dir, the database and the pairing secret"
"$PHP" "$DIR/bin/sb" setup | sed 's/^/  ok   /'

echo "Installing the statusboard's own hooks (the developer session runs in $DIR)"
"$PHP" "$DIR/bin/sb" install-hooks "$DIR" | sed 's/^/  /'

cat <<EOF

Done. Next:
  1. Serve the board:  sb serve        (PHP's built-in server on $BASE_URL)
     Or serve $(dirname "$DIR") with nginx/Apache + php-fpm and pass --base-url to match.
  2. Pair your browser: sb pair        (opens the index of boards and sets the board cookie)
  3. Create a board for a project from the index: give its root directory and keep
     "Install the statusboard into the project" ticked. Then run \`claude\` once in that folder and
     accept the trust prompt, so its sessions may run \`sb\` without asking each time.
  4. Optional: the usage meter. Add this to ~/.claude/settings.json:
       "statusLine": { "type": "command", "command": "$BIN_DIR/sb statusline" }
EOF
