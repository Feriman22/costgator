#!/usr/bin/env bash
# Opt-in release gate (needs network). Runs check-old.php on WordPress 6.5-6.8 with PHP 7.4 and WooCommerce 8.0.
set -euo pipefail
cd "$(dirname "$0")/../.."
C="docker compose -f dev/compat/docker-compose.yml -p costgator-compat"
$C up -d --wait >/dev/null 2>&1
for v in 6.5.5 6.6.2 6.7.2 6.8.3; do
	$C run --rm -T cli bash -c "
		set -e
		wp core is-installed 2>/dev/null || { wp core download --version=$v --force --skip-content >/dev/null && wp core install --url=http://127.0.0.1:8089 --title=Compat --admin_user=admin --admin_password=compat --admin_email=a@example.com --skip-email >/dev/null; }
		wp core download --version=$v --force --skip-content >/dev/null 2>&1
		wp core update-db >/dev/null
		wp plugin install woocommerce --version=8.0.0 --force >/dev/null 2>&1
		wp plugin activate woocommerce costgator >/dev/null 2>&1
		wp eval-file /dev-scripts/compat/check-old.php
	" 2>&1 | grep -v Container
done
