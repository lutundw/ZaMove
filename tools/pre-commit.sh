#!/usr/bin/env bash
# Git pre-commit hook (Lab 6): refuse the commit when the secret scan or environment schema check fails.
# Install once:   cp tools/pre-commit.sh .git/hooks/pre-commit && chmod +x .git/hooks/pre-commit
set -e
cd "$(git rev-parse --show-toplevel)"
if git diff --cached --name-only | grep -Eq '(^|/)\.env$|\.(pem|key|p12|pfx)$'; then
  echo "Blocked: a .env, key or certificate file is staged." >&2; exit 1
fi
php tools/secret_scan.php . || { echo "Blocked: secret_scan found something. Remove it, rotate the secret, then commit." >&2; exit 1; }
