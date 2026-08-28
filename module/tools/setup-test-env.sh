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

WEBTREES_VERSION="${1:-${WEBTREES_VERSION:-2.2.6}}"

MODULE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WEBTREES_DIR="${MODULE_DIR}/.webtrees"

# Which webtrees is actually sitting in that directory — read from the code
# rather than from git, because the code is what the tests run against and a
# tag name can be anything.
installed_version() {
    sed -n "s/^[[:space:]]*public const[[:space:]a-z]*VERSION = '\([^']*\)'.*/\1/p" \
        "${WEBTREES_DIR}/app/Webtrees.php" 2>/dev/null || true
}

if [ ! -d "${WEBTREES_DIR}/.git" ]; then
    echo "==> Cloning webtrees ${WEBTREES_VERSION}"
    git clone --depth 1 --branch "${WEBTREES_VERSION}" \
        https://github.com/fisharebest/webtrees.git "${WEBTREES_DIR}"
else
    # An existing checkout used to be reused whatever version it was, and that
    # is a trap worth spelling out: a developer who cloned this months ago goes
    # on testing against that release while CI — which restores a cache keyed
    # by version, or clones fresh — tests against this one. The suite passes in
    # one place and fails in the other, and the message says nothing about a
    # version. It cost an afternoon; see NOTES.md.
    INSTALLED="$(installed_version)"

    if [ "${INSTALLED}" = "${WEBTREES_VERSION}" ]; then
        echo "==> Reusing ${WEBTREES_DIR} (webtrees ${INSTALLED})"
    else
        echo "==> ${WEBTREES_DIR} holds webtrees ${INSTALLED:-an unknown version}; switching to ${WEBTREES_VERSION}"

        # Fetched by name rather than as a tag ref, so that a branch works here
        # too — and forced, because the working tree of a scratch checkout is
        # nobody's work in progress. `data/` and `vendor/` are ignored by
        # webtrees itself and are left alone; composer and the language files
        # are rebuilt below either way.
        git -C "${WEBTREES_DIR}" fetch --depth 1 --force origin "${WEBTREES_VERSION}"
        git -C "${WEBTREES_DIR}" checkout --force FETCH_HEAD

        SWITCHED="$(installed_version)"

        if [ "${SWITCHED}" != "${WEBTREES_VERSION}" ]; then
            echo "==> Warning: asked for ${WEBTREES_VERSION}, and the checkout now reports ${SWITCHED:-nothing}." >&2
            echo "    That is expected for a branch and a mistake for a release tag." >&2
        fi
    fi
fi

echo "==> Installing webtrees dependencies"
(cd "${WEBTREES_DIR}" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --no-progress)

# The distribution ZIP ships compiled translations (resources/lang/*/messages.php);
# a git checkout has only the .po sources, and webtrees silently falls back to
# untranslated English without them. The API's fact labels and dates come from
# these files, so the language tests need them.
echo "==> Compiling the language files"
(cd "${WEBTREES_DIR}" && php index.php compile-po-files >/dev/null)

echo "==> Linking the module into modules_v4/"
mkdir -p "${WEBTREES_DIR}/modules_v4"
ln -sfn "${MODULE_DIR}/portal_api" "${WEBTREES_DIR}/modules_v4/portal_api"

cat <<TEXT

Done. Run the module's tests with:

    cd ${MODULE_DIR}
    .webtrees/vendor/bin/phpunit

TEXT
