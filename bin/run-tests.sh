#!/bin/sh
# Run the tests inside WordPress's own PHPUnit suite, with WP Updates Notifier checked out beside this plugin.
#
# Needs git, php (with mysqli and gd), composer and a MySQL or MariaDB server. CI runs this script. Locally:
#
#   WP_DB_HOST=127.0.0.1 WP_DB_USER=root WP_DB_PASSWORD=root bin/run-tests.sh
#
# The database is emptied by the tests: use one that holds nothing you want to keep.
#
# Environment:
#   WP_REF     wordpress-develop branch or tag to test against (default 7.1; "trunk" is the development version)
#   WPUN_REF   WP Updates Notifier tag or branch to test against (default v2.0.0)
#   WPUN_REPO  Where to clone WP Updates Notifier from (default the upstream repository)
#   WP_DB_NAME, WP_DB_USER, WP_DB_PASSWORD, WP_DB_HOST  Database connection (defaults wordpress_test, root, root, 127.0.0.1)
#
# Arguments are passed to PHPUnit, for example --filter test_escape.
set -eu

root=$(cd "$(dirname "$0")/.." && pwd)
work="${WORK_DIR:-$root/build/wp}"
wp_ref="${WP_REF:-7.1}"
wpun_ref="${WPUN_REF:-v2.0.0}"
wpun_repo="${WPUN_REPO:-https://github.com/l3rady/wp-updates-notifier.git}"

if [ ! -d "$work/wordpress-develop" ]; then
	mkdir -p "$work"
	git clone --quiet --depth 1 --branch "$wp_ref" https://github.com/WordPress/wordpress-develop.git "$work/wordpress-develop"
	( cd "$work/wordpress-develop" && composer update --no-interaction --no-progress --prefer-dist )
fi

wp="$work/wordpress-develop"
plugins="$wp/src/wp-content/plugins"

# WP Updates Notifier, at the requested version.
rm -rf "$plugins/wp-updates-notifier"
git clone --quiet --depth 1 --branch "$wpun_ref" "$wpun_repo" "$plugins/wp-updates-notifier"
echo "WordPress ref $wp_ref, WP Updates Notifier ref $wpun_ref ($(git -C "$plugins/wp-updates-notifier" rev-parse --short HEAD))"

# The test configuration, from the sample.
sed \
	-e "s/youremptytestdbnamehere/${WP_DB_NAME:-wordpress_test}/" \
	-e "s/yourusernamehere/${WP_DB_USER:-root}/" \
	-e "s/yourpasswordhere/${WP_DB_PASSWORD:-root}/" \
	-e "s/'localhost'/'${WP_DB_HOST:-127.0.0.1}'/" \
	"$wp/wp-tests-config-sample.php" > "$wp/wp-tests-config.php"

WP_TESTS_DIR="$wp/tests/phpunit"
export WP_TESTS_DIR

cd "$wp"
exec php ./vendor/bin/phpunit -c "$root/phpunit.xml.dist" "$@"
