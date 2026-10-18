#!/usr/bin/env bash
# Runs the whole local test suite against the docker compose site.
set -euo pipefail
cd "$(dirname "$0")/.."
W="docker compose run --rm -T cli wp"
[ -f dev/phpunit.phar ] || curl -sSL -o dev/phpunit.phar https://phar.phpunit.de/phpunit-11.phar
docker run --rm -v "$PWD":/app -w /app wordpress:php8.3-apache php dev/phpunit.phar --bootstrap tests/bootstrap.php tests/unit
$W eval-file /dev-scripts/seed.php
$W eval-file /dev-scripts/check.php
$W eval-file /dev-scripts/batch-check.php
$W plugin check costgator
python3 dev/smoke.py
python3 dev/xss-check.py
$W eval-file /dev-scripts/lifecycle-check.php
# lifecycle-check runs uninstall.php, so restore the deterministic store last.
$W eval-file /dev-scripts/seed.php
