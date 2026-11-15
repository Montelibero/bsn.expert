#!/bin/sh
set -eu

project=$(CDPATH= cd -- "$(dirname "$0")/../.." && pwd)
fixture=$(mktemp -d "${TMPDIR:-/tmp}/bsn-hook-test.XXXXXX")
trap 'rm -rf "$fixture"' EXIT
trap 'exit 1' HUP INT TERM
mkdir -p "$fixture/repo/app/src" "$fixture/repo/node_modules" "$fixture/tmp"
cd "$fixture/repo"
git init --quiet
cat >app/composer.json <<'JSON'
{
  "name": "bsn/hook-fixture",
  "description": "Staged snapshot regression fixture",
  "license": "CC0-1.0",
  "autoload": {"psr-4": {"Fixture\\": "src/"}},
  "scripts": {"check": "@php check.php"}
}
JSON
cat >app/src/Value.php <<'PHP'
<?php
namespace Fixture;
class Value { public static function get(): string { return 'staged'; } }
PHP
cat >app/check.php <<'PHP'
<?php
require __DIR__ . '/vendor/autoload.php';
fwrite(STDOUT, "Successful test output should stay in the log.\n");
fwrite(STDERR, "Expected error from a passing negative test.\n");
if (Fixture\Value::get() !== 'staged') {
    fwrite(STDERR, "Loaded an unstaged application class.\n");
    exit(1);
}
if (file_exists(__DIR__ . '/audit-failure')) {
    for ($i = 0; $i < 50; $i++) {
        fwrite(STDOUT, "Earlier test passed: $i\n");
    }
    fwrite(STDERR, "Simulated audit failure.\n");
    exit(7);
}
require __DIR__ . '/required.php';
PHP
echo '<?php // Required fixture.' >app/required.php
cat >package.json <<'JSON'
{"name":"hook-fixture","private":true,"scripts":{"fa_test":"node check.mjs"}}
JSON
echo "if (process.env.HOOK_TEST_NODE_FAILURE === '1') process.exit(8);" >check.mjs
git add app package.json check.mjs
(cd app && composer --no-interaction dump-autoload --no-scripts --no-plugins >/dev/null)
mkdir -p app/vendor/bin
printf '#!/bin/sh\nexit 0\n' >app/vendor/bin/phpstan
chmod +x app/vendor/bin/phpstan

# An unstaged class change must not leak through vendor/autoload.php.
echo "<?php namespace Fixture; class Value { public static function get(): string { return 'unstaged'; } }" >app/src/Value.php

run_hook() {
    git diff --binary >"$fixture/worktree.before"
    git ls-files --stage >"$fixture/index.before"
    status=0
    TMPDIR="$fixture/tmp" sh "$project/.githooks/pre-commit" >"$fixture/output" 2>&1 || status=$?
    git diff --binary >"$fixture/worktree.after"
    git ls-files --stage >"$fixture/index.after"
    cmp "$fixture/worktree.before" "$fixture/worktree.after"
    cmp "$fixture/index.before" "$fixture/index.after"
    for snapshot in "$fixture/tmp"/bsn-pre-commit.*; do
        [ ! -e "$snapshot" ]
    done
}

run_hook
if [ "$status" -ne 0 ]; then cat "$fixture/output"; exit 1; fi
! grep -q 'Successful test output\|Expected error from a passing\|could not detect the root package' "$fixture/output"
grep -q 'all checks passed' "$fixture/output"
echo 'PASS: staged classes are checked; live files and index stay intact.'
echo 'PASS: successful checks have compact output without expected test errors or Composer version warnings.'

# Git uses a temporary index for commands such as git commit --only.
cp .git/index "$fixture/alternate-index"
export GIT_INDEX_FILE="$fixture/alternate-index"
git add app/src/Value.php
run_hook
[ "$status" -ne 0 ]
grep -q 'Loaded an unstaged application class' "$fixture/output"
unset GIT_INDEX_FILE
echo 'PASS: the hook respects an alternate Git index.'

# The file still exists locally but is absent from the proposed commit.
git rm --cached --quiet app/required.php
run_hook
[ "$status" -ne 0 ]
grep -q 'required.php' "$fixture/output"
git add app/required.php
echo 'PASS: a required file missing from the index blocks the commit.'

touch app/audit-failure
git add app/audit-failure
run_hook
[ "$status" -ne 0 ]
grep -q 'Simulated audit failure' "$fixture/output"
! grep -q 'Earlier test passed: 0$' "$fixture/output"
log=$(sed -n 's/^Full log: //p' "$fixture/output")
test -f "$log"
grep -q 'Earlier test passed: 0$' "$log"
grep -q 'Simulated audit failure' "$log"
grep -q 'Only the selected (staged) versions were checked' "$fixture/output"
git rm --force --quiet app/audit-failure
echo 'PASS: a failed Composer check blocks the commit.'
echo 'PASS: failures show a short excerpt and preserve the complete log after snapshot cleanup.'

export HOOK_TEST_NODE_FAILURE=1
run_hook
[ "$status" -ne 0 ]
unset HOOK_TEST_NODE_FAILURE
echo 'PASS: a failed Node check blocks the commit.'

rm app/vendor/bin/phpstan
run_hook
[ "$status" -ne 0 ]
grep -q 'development dependencies are missing' "$fixture/output"
echo 'PASS: missing PHPStan blocks the commit with setup instructions.'
