#!/usr/bin/env bash
#
# Create a webtrees checkout for the module's tests to run against.
#
# The module runs inside webtrees and uses webtrees' own classes, so its tests
# need a real webtrees to boot. This clones one into module/.webtrees (which is
# gitignored), installs its dependencies, and symlinks the module into its
# modules_v4/ directory so that webtrees discovers and boots it.
#
# Usage:  module/tools/setup-test-env.sh [webtrees-version]

set -euo pipefail

WEBTREES_VERSION="${1:-${WEBTREES_VERSION:-2.2.1}}"

MODULE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WEBTREES_DIR="${MODULE_DIR}/.webtrees"

if [ ! -d "${WEBTREES_DIR}/.git" ]; then
    echo "==> Cloning webtrees ${WEBTREES_VERSION}"
    git clone --depth 1 --branch "${WEBTREES_VERSION}" \
        https://github.com/fisharebest/webtrees.git "${WEBTREES_DIR}"
else
    echo "==> Reusing ${WEBTREES_DIR}"
fi

echo "==> Installing webtrees dependencies"
(cd "${WEBTREES_DIR}" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --no-progress)

echo "==> Linking the module into modules_v4/"
mkdir -p "${WEBTREES_DIR}/modules_v4"
ln -sfn "${MODULE_DIR}/portal_api" "${WEBTREES_DIR}/modules_v4/portal_api"

cat <<TEXT

Done. Run the module's tests with:

    cd ${MODULE_DIR}
    .webtrees/vendor/bin/phpunit

TEXT
