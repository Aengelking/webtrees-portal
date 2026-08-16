#!/usr/bin/env bash
#
# Upload a directory to an SFTP server, atomically.
#
# Used by .github/workflows/deploy.yml, and runnable by hand for the same
# result. The logic lives here rather than inline in the workflow so that a
# deployment can be rehearsed locally before it is trusted to CI.
#
# Why not a plain recursive put: the webtrees host serves requests while the
# upload runs. Overwriting modules_v4/portal_api/ in place means there is a
# window in which half the module is the new version and half is the old one,
# and webtrees will load whatever is there on the next request. So the upload
# goes to a staging directory beside the target and is swapped in with two
# renames, which take microseconds.
#
# The staging and rollback directories are named with a dot in them
# (portal_api.upload, portal_api.previous). That is deliberate: webtrees'
# ModuleService skips any directory under modules_v4/ whose name contains a
# dot, so a half-uploaded or rolled-back copy is never loaded as a module.
#
# Required environment:
#   SFTP_HOST          hostname of the SFTP server
#   SFTP_USERNAME      login name
#   SFTP_REMOTE_PATH   absolute path of the directory to replace,
#                      e.g. /var/www/webtrees/modules_v4/portal_api
#   SFTP_KNOWN_HOSTS   the server's public host key(s), as in a known_hosts
#                      file. Get it with:  ssh-keyscan -p 22 your.host
#
# Authentication, one of:
#   SFTP_PRIVATE_KEY   an OpenSSH private key (preferred)
#   SFTP_PASSWORD      a password (requires sshpass; see below)
#
# Optional:
#   SFTP_PORT          default 22
#   DRY_RUN            "true" to list what would change and upload nothing
#
# Usage:  module/tools/deploy-sftp.sh <local-directory>

set -euo pipefail

LOCAL_DIR="${1:-}"

if [ -z "${LOCAL_DIR}" ]; then
    echo "usage: $0 <local-directory>" >&2
    exit 64
fi

if [ ! -d "${LOCAL_DIR}" ]; then
    echo "error: ${LOCAL_DIR} is not a directory" >&2
    exit 66
fi

require() {
    if [ -z "${!1:-}" ]; then
        echo "error: ${1} is not set" >&2
        exit 78
    fi
}

require SFTP_HOST
require SFTP_USERNAME
require SFTP_REMOTE_PATH
# Host verification is not optional. An unverified SFTP connection can be
# intercepted, and this one is being handed the code that reads a family's
# genealogy database.
require SFTP_KNOWN_HOSTS

SFTP_PORT="${SFTP_PORT:-22}"
DRY_RUN="${DRY_RUN:-false}"

case "${SFTP_REMOTE_PATH}" in
    /*) ;;
    *)
        echo "error: SFTP_REMOTE_PATH must be an absolute path" >&2
        exit 78
        ;;
esac

if [ "${SFTP_REMOTE_PATH}" = "/" ] || [ "$(dirname "${SFTP_REMOTE_PATH}")" = "/" ]; then
    echo "error: refusing to deploy to ${SFTP_REMOTE_PATH}" >&2
    exit 78
fi

for tool in lftp ssh; do
    if ! command -v "${tool}" >/dev/null 2>&1; then
        echo "error: ${tool} is not installed" >&2
        echo "       Debian/Ubuntu: sudo apt-get install lftp openssh-client" >&2
        exit 69
    fi
done

WORK_DIR="$(mktemp -d)"
trap 'rm -rf "${WORK_DIR}"' EXIT

KNOWN_HOSTS="${WORK_DIR}/known_hosts"
printf '%s\n' "${SFTP_KNOWN_HOSTS}" > "${KNOWN_HOSTS}"
chmod 600 "${KNOWN_HOSTS}"

SSH_OPTIONS=(
    -a -x
    -o "StrictHostKeyChecking=yes"
    -o "UserKnownHostsFile=${KNOWN_HOSTS}"
    -o "ConnectTimeout=20"
    -p "${SFTP_PORT}"
)

if [ -n "${SFTP_PRIVATE_KEY:-}" ]; then
    KEY_FILE="${WORK_DIR}/id_deploy"
    printf '%s\n' "${SFTP_PRIVATE_KEY}" > "${KEY_FILE}"
    chmod 600 "${KEY_FILE}"

    # BatchMode: fail rather than hang on a prompt. Nobody is at the keyboard.
    SSH_OPTIONS+=(
        -o "BatchMode=yes"
        -o "PreferredAuthentications=publickey"
        -o "IdentitiesOnly=yes"
        -i "${KEY_FILE}"
    )
    CONNECT_PROGRAM="ssh ${SSH_OPTIONS[*]}"
elif [ -n "${SFTP_PASSWORD:-}" ]; then
    if ! command -v sshpass >/dev/null 2>&1; then
        echo "error: SFTP_PASSWORD is set but sshpass is not installed" >&2
        echo "       Prefer SFTP_PRIVATE_KEY. If the host really only offers" >&2
        echo "       password authentication: sudo apt-get install sshpass" >&2
        exit 69
    fi

    # No BatchMode here: it would suppress the very prompt sshpass exists to
    # answer. sshpass reads the password from SSHPASS rather than the command
    # line, so it never appears in the process list.
    SSH_OPTIONS+=(-o "PreferredAuthentications=password,keyboard-interactive")
    export SSHPASS="${SFTP_PASSWORD}"
    CONNECT_PROGRAM="sshpass -e ssh ${SSH_OPTIONS[*]}"
else
    echo "error: set SFTP_PRIVATE_KEY (preferred) or SFTP_PASSWORD" >&2
    exit 78
fi

REMOTE_PARENT="$(dirname "${SFTP_REMOTE_PATH}")"
REMOTE_NAME="$(basename "${SFTP_REMOTE_PATH}")"
STAGING="${SFTP_REMOTE_PATH}.upload"
PREVIOUS="${SFTP_REMOTE_PATH}.previous"

# lftp reads the password for `open -u` from stdin, which we never use; the
# authentication above happens inside the connect program instead.
lftp_run() {
    lftp -c "
        set sftp:connect-program \"${CONNECT_PROGRAM}\";
        set net:max-retries 3;
        set net:timeout 30;
        set cmd:fail-exit true;
        set xfer:clobber true;
        open -u \"${SFTP_USERNAME}\", sftp://${SFTP_HOST}:${SFTP_PORT};
        $1
    "
}

# Same, but for steps that are allowed to fail — removing something that was
# never there, or renaming a target that does not exist yet on a first deploy.
lftp_try() {
    lftp -c "
        set sftp:connect-program \"${CONNECT_PROGRAM}\";
        set cmd:fail-exit false;
        open -u \"${SFTP_USERNAME}\", sftp://${SFTP_HOST}:${SFTP_PORT};
        $1
    " >/dev/null 2>&1 || true
}

echo "==> ${LOCAL_DIR}  ->  ${SFTP_USERNAME}@${SFTP_HOST}:${SFTP_REMOTE_PATH}"

if [ "${DRY_RUN}" = "true" ]; then
    echo "==> Dry run: comparing against the live directory, uploading nothing"
    lftp_run "mirror --reverse --dry-run --delete --no-perms --verbose \"${LOCAL_DIR}\" \"${SFTP_REMOTE_PATH}\";"
    echo "==> Dry run finished. Nothing was changed."
    exit 0
fi

echo "==> Clearing any staging directory left by a failed run"
lftp_try "rm -rf \"${STAGING}\";"

echo "==> Uploading to ${STAGING}"
# --no-perms: shared hosting usually refuses chmod, and the files do not need
# any mode beyond what the account's umask gives them.
lftp_run "mirror --reverse --delete --no-perms --verbose \"${LOCAL_DIR}\" \"${STAGING}\";"

echo "==> Swapping the new version in"
lftp_try "rm -rf \"${PREVIOUS}\";"
# Expected to fail on a first deploy, when there is nothing to move aside.
lftp_try "cd \"${REMOTE_PARENT}\"; mv \"${REMOTE_NAME}\" \"$(basename "${PREVIOUS}")\";"

# If moving the old version aside failed for a real reason — permissions, say —
# then this rename fails too, because the target still exists. That is the
# failure mode we want: the live module is untouched, the upload is sitting in
# a directory webtrees ignores, and the site is still serving the old version.
if ! lftp_run "cd \"${REMOTE_PARENT}\"; mv \"$(basename "${STAGING}")\" \"${REMOTE_NAME}\";"; then
    echo >&2
    echo "error: could not put the new version in place." >&2
    echo "       The live module was NOT changed and the site is still running" >&2
    echo "       the previous version. The upload is at:" >&2
    echo "         ${STAGING}" >&2
    echo "       Check that ${SFTP_USERNAME} may rename directories in" >&2
    echo "       ${REMOTE_PARENT}, then run this again." >&2
    exit 74
fi

echo "==> Removing the previous version"
lftp_try "rm -rf \"${PREVIOUS}\";"

echo "==> Done."
echo
echo "    webtrees creates or updates the module's tables on its next request."
echo "    If this was a first install, enable the module in"
echo "    Control panel -> Modules -> All modules, then set the family tree in"
echo "    its settings."
