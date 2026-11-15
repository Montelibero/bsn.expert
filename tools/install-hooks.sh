#!/bin/sh
set -eu

cd "$(dirname "$0")/.."
current=$(git config --get core.hooksPath || true)
if [ -n "$current" ] && [ "$current" != '.githooks' ]; then
    echo "An existing core.hooksPath is configured: $current. Integrate .githooks/pre-commit with it before replacing it." >&2
    exit 1
fi
legacy=$(git rev-parse --git-path hooks/pre-commit)
if [ -z "$current" ] && [ -f "$legacy" ]; then
    echo "An existing hook is present at $legacy. Integrate it with .githooks/pre-commit first." >&2
    exit 1
fi
chmod +x .githooks/pre-commit
git config --local core.hooksPath .githooks
echo 'Enabled pre-commit checks for this checkout.'
