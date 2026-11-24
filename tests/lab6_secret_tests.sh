#!/usr/bin/env bash
# Lab 6, Task 4: secrets and SAST checks.  bash tests/lab6_secret_tests.sh
set -u
cd "$(dirname "$0")/.."
source tests/lib.sh
SHIP="$(mktemp -d)"; REPO="$(mktemp -d)"; trap 'rm -rf "$SHIP" "$REPO"' EXIT
tar --exclude=.env --exclude=evidence --exclude=api/logs -cf - . | tar -C "$SHIP" -xf -          # the tree as it is packaged and submitted
echo "=== 1. secret scan of the packaged tree (no .env, no logs)"
php tools/secret_scan.php "$SHIP"; expect "packaged tree: 0 findings" $?
echo "=== 2. .gitignore blocks secrets (git check-ignore)"
( cd "$REPO" && git init -q . && cp "$OLDPWD/.gitignore" . && touch .env zamove.env localhost-key.pem rootCA.pem api.log && mkdir -p api/logs && touch api/logs/csp.log )
for f in .env zamove.env localhost-key.pem rootCA.pem api/logs/csp.log; do ( cd "$REPO" && git check-ignore -q "$f" ); expect "$f is git-ignored" $?; done
( cd "$REPO" && git check-ignore -q .env.example ); [ $? -ne 0 ]; expect ".env.example is NOT ignored (it is the committed template)" $?
echo "=== 3. history scan: a secret committed and later deleted is still found"
( cd "$REPO" && git config user.email t@example.test && git config user.name test \
  && printf 'AWS_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE\n' > leak.txt && git add -f leak.txt && git commit -qm "oops" \
  && git rm -q leak.txt && git commit -qm "remove the key" )
( cd "$REPO" && php "$OLDPWD/tools/secret_scan.php" --git . ); [ $? -eq 1 ]; expect "git history scan reports the deleted AWS example key (exit 1)" $?
echo "=== 4. pre-commit hook blocks a staged .env"
( cd "$REPO" && cp "$OLDPWD/tools/pre-commit.sh" . && mkdir -p tools && cp "$OLDPWD/tools/secret_scan.php" tools/ && git add -f .env && bash pre-commit.sh >/dev/null 2>&1 ); [ $? -ne 0 ]; expect "hook refuses a commit that stages .env" $?
echo "=== 5. vendored PHP libraries (the project has no npm packages, so npm audit does not apply)"
for d in api/vendor/opis/*; do echo "  $d: $(grep -m1 -o '"version": *"[^"]*"' $d/composer.json 2>/dev/null || echo 'version file not shipped')"; done
ls package.json package-lock.json 2>/dev/null || echo "  no package.json: nothing for npm audit to check"
expect "no JavaScript dependency manifest in the project" $([ -e package.json ] && echo 1 || echo 0)
summary
