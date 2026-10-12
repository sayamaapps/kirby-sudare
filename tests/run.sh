#!/bin/sh
# Runs all PHP checks of the package: `composer test` or `sh tests/run.sh`.
# Every file is a standalone script (exit code 0 = pass), not PHPUnit — so
# they also run on a hoster PHP without a test framework. All files run even
# when one fails; the exit code is 1 if any failed.
cd "$(dirname "$0")/.." || exit 1
echo "PHP $(php -r 'echo PHP_VERSION;')"
failed=0
for f in tests/*Test.php; do
    [ -e "$f" ] || continue
    echo "→ $f"
    php "$f" || failed=1
done
exit "$failed"
