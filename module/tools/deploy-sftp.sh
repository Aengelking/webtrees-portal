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
#   SFTP_REMOTE_PATH   path of the directory to replace, e.g.
#                      /var/www/webtrees/modules_v4/portal_api
#   SFTP_KNOWN_HOSTS   the server's public host key(s), as in a known_hosts
#                      file. Get it with:  ssh-keyscan -p 22 your.host
#
# Authentication, one of:
#   SFTP_PASSWORD      the account password (needs sshpass installed)
#   SFTP_PRIVATE_KEY   an OpenSSH private key; used in preference to the
#                      password when both are set
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
# Host verification is not optional. An unverified connection can be
# intercepted — and with password authentication, intercepting it hands over
# the password itself, not just this session.
require SFTP_KNOWN_HOSTS

if [ -z "${SFTP_PASSWORD:-}" ] && [ -z "${SFTP_PRIVATE_KEY:-}" ]; then
    echo "error: set SFTP_PASSWORD, or SFTP_PRIVATE_KEY" >&2
    exit 78
fi

SFTP_PORT="${SFTP_PORT:-22}"
DRY_RUN="${DRY_RUN:-false}"

# Strip any trailing slash, so dirname/basename below behave.
REMOTE_PATH="${SFTP_REMOTE_PATH%/}"

case "${REMOTE_PATH}" in
    ''|'/'|'.'|'..')
        echo "error: refusing to deploy to '${SFTP_REMOTE_PATH}' — give the path of the" >&2
        echo "       module directory itself, e.g. /var/www/webtrees/modules_v4/portal_api" >&2
        exit 78
        ;;
esac

WORK_DIR="$(mktemp -d)"
trap 'rm -rf "${WORK_DIR}"' EXIT

REMOTE_PARENT="$(dirname "${REMOTE_PATH}")"
REMOTE_NAME="$(basename "${REMOTE_PATH}")"
STAGING_NAME="${REMOTE_NAME}.upload"
PREVIOUS_NAME="${REMOTE_NAME}.previous"

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
    AUTH_METHOD="private key"
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
else
    AUTH_METHOD="password"

    if ! command -v sshpass >/dev/null 2>&1; then
        echo "error: SFTP_PASSWORD is set but sshpass is not installed." >&2
        echo "       ssh will not read a password from anywhere but a terminal," >&2
        echo "       so a non-interactive password login needs it." >&2
        echo "       Debian/Ubuntu: sudo apt-get install sshpass" >&2
        exit 69
    fi

    # No BatchMode here: it would suppress the very prompt sshpass exists to
    # answer. sshpass reads the password from the SSHPASS environment variable
    # rather than the command line, so it never appears in the process list.
    #
    # PubkeyAuthentication=no stops ssh offering agent or default keys first,
    # which on a server with a low MaxAuthTries can use up the attempts before
    # the password is ever tried.
    SSH_OPTIONS+=(
        -o "PreferredAuthentications=password,keyboard-interactive"
        -o "PubkeyAuthentication=no"
        # sshpass answers exactly one prompt. Without this, a rejected password
        # makes ssh ask twice more and sshpass has nothing left to say, which
        # reads as a hang rather than as a wrong password.
        -o "NumberOfPasswordPrompts=1"
    )
    export SSHPASS="${SFTP_PASSWORD}"
    CONNECT_PROGRAM="sshpass -e ssh ${SSH_OPTIONS[*]}"
fi

if ! command -v lftp >/dev/null 2>&1; then
    echo "error: lftp is not installed" >&2
    echo "       Debian/Ubuntu: sudo apt-get install lftp openssh-client" >&2
    exit 69
fi

# Escape a value for lftp's double-quoted strings. `printf` is a shell builtin,
# so the value never becomes a separate process's argv.
lftp_quote() {
    printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'
}

SFTP_USERNAME_Q="$(lftp_quote "${SFTP_USERNAME}")"
CONNECT_PROGRAM_Q="$(lftp_quote "${CONNECT_PROGRAM}")"

# lftp insists on having *a* password for the account, even over sftp, where it
# never uses one: authentication happens entirely inside the connect program
# above, which is ssh. Given only a username it tries to prompt, finds no
# terminal, prints "GetPass() failed -- assume anonymous login" and then logs in
# as `anonymous` — which the server rejects, closing the connection.
#
# So give it a placeholder. It is never sent anywhere. The real password stays
# in SSHPASS, out of this file and out of the process list.
LFTP_PLACEHOLDER_PASSWORD='unused-ssh-handles-authentication'

# Commands go in a 0600 file rather than in argv, which is visible to every
# user on the machine via `ps`.
lftp_script() {
    local fail_exit="$1"
    local commands="$2"
    local script_file="${WORK_DIR}/lftp.commands"

    touch "${script_file}"
    chmod 600 "${script_file}"

    {
        echo "set sftp:connect-program \"${CONNECT_PROGRAM_Q}\";"
        # Shared-host SFTP drops connections occasionally under load. Keep retrying
        # long enough for transient resets to clear instead of failing the deploy.
        echo "set net:max-retries 10;"
        echo "set net:reconnect-interval-base 5;"
        echo "set net:reconnect-interval-max 30;"
        echo "set net:timeout 30;"
        echo "set xfer:clobber true;"
        echo "set cmd:fail-exit ${fail_exit};"
        echo "open -u \"${SFTP_USERNAME_Q}\",\"${LFTP_PLACEHOLDER_PASSWORD}\" sftp://${SFTP_HOST}:${SFTP_PORT};"
        echo "${commands}"
        echo "bye;"
    } > "${script_file}"

    lftp -f "${script_file}"
}

# Steps that must succeed.
lftp_run() {
    lftp_script true "$1"
}

# Steps that are allowed to fail — removing something that was never there, or
# renaming a target that does not exist yet on a first deploy.
lftp_try() {
    lftp_script false "$1" >/dev/null 2>&1 || true
}

echo "==> ${LOCAL_DIR}  ->  ${SFTP_USERNAME}@${SFTP_HOST}:${REMOTE_PATH}"
echo "==> Authenticating with a ${AUTH_METHOD}; host key verification is on."

# A path with no directory part lands straight in the SFTP login directory.
# That is legal, and almost never what was meant: the module has to end up
# inside webtrees' modules_v4/, which by definition has a parent.
if [ "${REMOTE_PARENT}" = "." ]; then
    echo "WARNING: the remote path has no directory part, so this will upload to" >&2
    echo "         '${REMOTE_NAME}' directly in the SFTP login directory." >&2
    echo "         For the module that is almost certainly wrong — it needs to be" >&2
    echo "         inside webtrees' modules_v4/, e.g." >&2
    echo "           webtrees/modules_v4/portal_api" >&2
    echo "         Paths are relative to the SFTP login root, which on shared" >&2
    echo "         hosting is usually not the server's filesystem root." >&2
fi

if [ "${DRY_RUN}" = "true" ]; then
    echo "==> Dry run: comparing against the live directory, uploading nothing"
    lftp_run "mirror --reverse --dry-run --delete --no-perms --verbose \"${LOCAL_DIR}\" \"${REMOTE_PATH}\";"
    echo "==> Dry run finished. Nothing was changed."
    exit 0
fi

echo "==> Clearing any staging directory left by a failed run"
lftp_try "rm -rf \"${REMOTE_PARENT}/${STAGING_NAME}\";"

echo "==> Uploading to ${REMOTE_PARENT}/${STAGING_NAME}"
# --no-perms: shared hosting usually refuses chmod, and the files do not need
# any mode beyond what the account's umask gives them.
lftp_run "mirror --reverse --delete --no-perms --verbose \"${LOCAL_DIR}\" \"${REMOTE_PARENT}/${STAGING_NAME}\";"

echo "==> Swapping the new version in"
lftp_try "rm -rf \"${REMOTE_PARENT}/${PREVIOUS_NAME}\";"
# Expected to fail on a first deploy, when there is nothing to move aside.
lftp_try "cd \"${REMOTE_PARENT}\"; mv \"${REMOTE_NAME}\" \"${PREVIOUS_NAME}\";"

# If moving the old version aside failed for a real reason — permissions, say —
# then this rename fails too, because the target still exists. That is the
# failure mode we want: the live module is untouched, the upload is sitting in
# a directory webtrees ignores, and the site is still serving the old version.
if ! lftp_run "cd \"${REMOTE_PARENT}\"; mv \"${STAGING_NAME}\" \"${REMOTE_NAME}\";"; then
    echo >&2
    echo "error: could not put the new version in place." >&2
    echo "       The live module was NOT changed and the site is still running" >&2
    echo "       the previous version. The upload is at:" >&2
    echo "         ${REMOTE_PARENT}/${STAGING_NAME}" >&2
    echo "       Check that ${SFTP_USERNAME} may rename directories in" >&2
    echo "       ${REMOTE_PARENT}, then run this again." >&2
    exit 74
fi

echo "==> Removing the previous version"
lftp_try "rm -rf \"${REMOTE_PARENT}/${PREVIOUS_NAME}\";"

echo "==> Done."
echo
echo "    webtrees creates or updates the module's tables on its next request."
echo "    If this was a first install, enable the module in"
echo "    Control panel -> Modules -> All modules, then set the family tree in"
echo "    its settings."
