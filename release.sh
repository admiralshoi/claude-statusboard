#!/usr/bin/env bash
# Exports this checkout's publishable files into a separate clone of the public repo: ./release.sh <clone dir> [--commit "message"]
# Never pushes and never touches this checkout's history. Personal patterns to refuse (one regex per line) go in release-deny.local.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEST="${1:-}"
[ -n "$DEST" ] && [ -d "$DEST/.git" ] || { echo "usage: ./release.sh <clone of the public repo> [--commit \"message\"]" >&2; exit 2; }
DEST="$(cd "$DEST" && pwd)"
[ "$DEST" != "$DIR" ] || { echo "the destination must be a separate clone, not this checkout" >&2; exit 2; }
MSG=""
[ "${2:-}" = "--commit" ] && MSG="${3:?--commit needs a message}"

# Publishable = tracked plus untracked files git does not ignore, minus this machine's notes and the deny list itself.
LIST="$(mktemp)"
trap 'rm -f "$LIST"' EXIT
{ git -C "$DIR" ls-files; git -C "$DIR" ls-files --others --exclude-standard; } | sort -u \
    | grep -vxE 'DEVELOPER\.local\.md|release-deny\.local' | while read -r f; do [ -f "$DIR/$f" ] && echo "$f"; done > "$LIST"

# Mirror them: files the list no longer has are removed from the clone too (its .git stays).
rsync -a --files-from="$LIST" "$DIR/" "$DEST/"
(cd "$DEST" && find . -path ./.git -prune -o -type f -print | sed 's|^\./||' | sort) | comm -23 - "$LIST" | while read -r f; do rm -f "$DEST/$f"; done
# -delete ignores -prune, so .git is kept out by path instead.
find "$DEST" -mindepth 1 -type d -empty -not -path "$DEST/.git" -not -path "$DEST/.git/*" -delete

# Refuse anything personal: home paths other than the docs' placeholder, email addresses, and the local deny list.
PATTERNS='/Users/[A-Za-z0-9._-]+|/home/[a-z][a-z0-9_-]+|[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}'
if [ -f "$DIR/release-deny.local" ]; then
    PATTERNS="$PATTERNS|$(grep -vE '^\s*(#|$)' "$DIR/release-deny.local" | paste -sd'|' -)"
fi
HITS="$(cd "$DEST" && grep -rnIoE "$PATTERNS" --exclude-dir=.git . | grep -vE ':/Users/YOU\b|noreply@' || true)"
if [ -n "$HITS" ]; then
    echo "Personal references found, nothing committed:" >&2
    echo "$HITS" >&2
    exit 1
fi

echo "Exported $(wc -l < "$LIST" | tr -d ' ') files to $DEST, no personal references found."
git -C "$DEST" add -A
git -C "$DEST" status --short
if [ -n "$MSG" ]; then
    git -C "$DEST" commit -q -m "$MSG" && echo "Committed as $(git -C "$DEST" log -1 --format='%an <%ae>'). Review it, then push it yourself: git -C $DEST push"
fi
