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
# One client: OpenSSH's sftp
# --------------------------
# This used to drive lftp, and lftp is why deployments kept dying with
#
#     mirror: Fatal error: max-retries exceeded (Connection closed by ... port 22)
#
# The same host accepts an upload from OpenSSH `sftp` over exactly the same
# `ssh` and `sshpass` without a murmur, so the connection was never the
# problem: lftp keeps many requests in flight and opens a second connection
# when it feels like it, and this server tolerates neither. `sftp` asks for one
# thing at a time.
#
# An intermediate version kept lftp for the recursive deletes, which `sftp`
# cannot do. That was worse than useless: those deletes ran through `lftp_try`,
# which ignored failures, so on a host lftp could not talk to at all the old
# version was silently never moved aside — and the deployment then failed at
# the *next* step, with a message about permissions that had nothing to do with
# the real cause. A step that is allowed to fail silently is a step that cannot
# be trusted to have happened.
#
# So there is no lftp. Recursive deletion is done from a manifest: every upload
# writes .portal-deploy-manifest listing what it contains, before it writes
# anything else, and that file is what tells a later run exactly which paths to
# remove. Deleting a known list needs no directory listing, no recursion and no
# second tool.
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
#   SFTP_MAX_ATTEMPTS  how many times to retry a step whose connection was
#                      dropped, default 4
#   DRY_RUN            "true" to print what would be uploaded and connect to
#                      nothing
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
MAX_ATTEMPTS="${SFTP_MAX_ATTEMPTS:-4}"

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
STAGING_PATH="${REMOTE_PARENT}/${REMOTE_NAME}.upload"
PREVIOUS_PATH="${REMOTE_PARENT}/${REMOTE_NAME}.previous"

# The list of what an upload contains, written into the upload itself. A dot
# file, so webtrees' file scans ignore it, and small enough not to matter.
MANIFEST_NAME='.portal-deploy-manifest'

KNOWN_HOSTS="${WORK_DIR}/known_hosts"
printf '%s\n' "${SFTP_KNOWN_HOSTS}" > "${KNOWN_HOSTS}"
chmod 600 "${KNOWN_HOSTS}"

SFTP_OPTIONS=(
    -o "StrictHostKeyChecking=yes"
    -o "UserKnownHostsFile=${KNOWN_HOSTS}"
    -o "ConnectTimeout=20"
    # Three tries at getting the connection up before ssh gives up. The first
    # refusal from a busy shared host is often the only one.
    -o "ConnectionAttempts=3"
    # Keep the connection alive across the pauses between files. An idle SFTP
    # session is a session some hosts decide to reap, and the symptom is
    # indistinguishable from a network fault.
    -o "TCPKeepAlive=yes"
    -o "ServerAliveInterval=15"
    -o "ServerAliveCountMax=6"
    -P "${SFTP_PORT}"
)

if [ -n "${SFTP_PRIVATE_KEY:-}" ]; then
    AUTH_METHOD="private key"
    KEY_FILE="${WORK_DIR}/id_deploy"
    printf '%s\n' "${SFTP_PRIVATE_KEY}" > "${KEY_FILE}"
    chmod 600 "${KEY_FILE}"

    # BatchMode: fail rather than hang on a prompt. Nobody is at the keyboard.
    SFTP_OPTIONS+=(
        -o "BatchMode=yes"
        -o "PreferredAuthentications=publickey"
        -o "IdentitiesOnly=yes"
        -i "${KEY_FILE}"
    )
    SFTP_COMMAND=(sftp)
else
    AUTH_METHOD="password"

    if ! command -v sshpass >/dev/null 2>&1; then
        echo "error: SFTP_PASSWORD is set but sshpass is not installed." >&2
        echo "       ssh will not read a password from anywhere but a terminal," >&2
        echo "       so a non-interactive password login needs it." >&2
        echo "       Debian/Ubuntu: sudo apt-get install sshpass" >&2
        exit 69
    fi

    # sshpass reads the password from the SSHPASS environment variable rather
    # than the command line, so it never appears in the process list.
    SFTP_OPTIONS+=(
        # BatchMode=no, deliberately: it is the password prompt that sshpass
        # exists to answer, and BatchMode would suppress it. sftp's own -b
        # still makes the session non-interactive.
        -o "BatchMode=no"
        # PubkeyAuthentication=no stops ssh offering agent or default keys
        # first, which on a server with a low MaxAuthTries can use up the
        # attempts before the password is ever tried.
        -o "PreferredAuthentications=password,keyboard-interactive"
        -o "PubkeyAuthentication=no"
        # sshpass answers exactly one prompt. Without this, a rejected password
        # makes ssh ask twice more and sshpass has nothing left to say, which
        # reads as a hang rather than as a wrong password.
        -o "NumberOfPasswordPrompts=1"
    )
    SFTP_COMMAND=(sshpass -e sftp)
    export SSHPASS="${SFTP_PASSWORD}"
fi

if ! command -v sftp >/dev/null 2>&1; then
    echo "error: sftp is not installed" >&2
    echo "       Debian/Ubuntu: sudo apt-get install openssh-client" >&2
    exit 69
fi

# -----------------------------------------------------------------
# Talking to the server
# -----------------------------------------------------------------

# Escape a value for an sftp batch file, where paths are double-quoted.
sftp_quote() {
    printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'
}

# Run a batch of sftp commands in one session.
#
# The batch goes in a 0600 file rather than on the command line, which every
# user on the machine can read out of `ps`.
#
# A command prefixed with `-` is allowed to fail; anything else stops the batch
# and fails the session. That is the only error handling sftp has, and it is
# used here to say exactly which steps are optional — never to make a step that
# matters fail quietly.
sftp_batch() {
    local commands="$1"
    local batch_file="${WORK_DIR}/sftp.batch"

    touch "${batch_file}"
    chmod 600 "${batch_file}"
    printf '%s\n' "${commands}" > "${batch_file}"

    "${SFTP_COMMAND[@]}" "${SFTP_OPTIONS[@]}" -b "${batch_file}" \
        "${SFTP_USERNAME}@${SFTP_HOST}"
}

# Run a step, and try again with a widening delay when the server hangs up.
#
# The first argument is either empty or the name of a function answering "is
# this already done?". It exists for the swap, which is the one step that is
# not safe to simply repeat: a connection dropped *after* the server carried it
# out would otherwise turn a success into a failure, because the second attempt
# finds nothing left to rename.
with_retries() {
    local already_done="$1"
    shift

    local attempt=1
    local delay=5

    while true; do
        if "$@"; then
            return 0
        fi

        if [ -n "${already_done}" ] && "${already_done}"; then
            echo "    the connection dropped, but the server had already done it"
            return 0
        fi

        if [ "${attempt}" -ge "${MAX_ATTEMPTS}" ]; then
            echo "    giving up after ${MAX_ATTEMPTS} attempts" >&2
            return 1
        fi

        echo "    attempt ${attempt} of ${MAX_ATTEMPTS} failed; trying again in ${delay}s" >&2
        sleep "${delay}"
        attempt=$((attempt + 1))
        delay=$((delay * 3))
    done
}

# Does this remote path exist?
#
# `ls -1` and not `ls -d`: sftp's ls takes [-1afhlnrSt] and nothing else, and an
# invalid flag would make this answer "no" for every path, quietly disabling
# whichever check is relying on it.
remote_exists() {
    sftp_batch "ls -1 \"$(sftp_quote "$1")\"" >/dev/null 2>&1
}

# -----------------------------------------------------------------
# What goes up
# -----------------------------------------------------------------

# The manifest for the local directory: one line per path, deepest last.
#
#   d src/Http
#   f src/Http/Json.php
#
# It is written into the upload before any of the files it describes, so even
# an upload the server cut short leaves behind a complete list of what might be
# in there — which is exactly what a later run needs in order to clean it up.
local_manifest() {
    ( cd "${LOCAL_DIR}" && find . -mindepth 1 -type d -printf 'd %P\n' | sort )
    ( cd "${LOCAL_DIR}" && find . -mindepth 1 -type f -printf 'f %P\n' | sort )
}

# The batch that uploads the local directory into remote directory $1.
#
# Every directory and every file is named explicitly rather than left to
# `put -r` and a shell glob. Two reasons, both of which have bitten this
# repository already: a glob skips dotfiles, and portal/dist contains an
# .htaccess; and an explicit list makes the log say exactly what went where.
upload_batch() {
    local remote_dir="$1"

    printf -- '-mkdir "%s"\n' "$(sftp_quote "${remote_dir}")"

    local kind path
    while read -r kind path; do
        case "${kind}" in
            d) printf -- '-mkdir "%s"\n' "$(sftp_quote "${remote_dir}/${path}")" ;;
        esac
    done < "${MANIFEST_FILE}"

    # The manifest first, so that it describes even an upload that dies
    # halfway. `-rm` on a file that does not exist is fine, so a stale manifest
    # from a previous attempt is simply replaced.
    printf 'put "%s" "%s"\n' \
        "$(sftp_quote "${MANIFEST_FILE}")" \
        "$(sftp_quote "${remote_dir}/${MANIFEST_NAME}")"

    while read -r kind path; do
        case "${kind}" in
            f) printf 'put "%s" "%s"\n' \
                   "$(sftp_quote "${LOCAL_DIR}/${path}")" \
                   "$(sftp_quote "${remote_dir}/${path}")" ;;
        esac
    done < "${MANIFEST_FILE}"
}

# -----------------------------------------------------------------
# What comes down: recursive delete, from a manifest
# -----------------------------------------------------------------

# Remove a directory tree that a previous run of this script uploaded.
#
# sftp has no recursive `rm`, and asking it for a recursive listing means
# parsing `ls -l` output, which differs between servers. Neither is needed: the
# tree carries its own list of contents. Fetch it, delete what it names
# deepest-first, and the directory is gone in two sessions.
#
# Returns 0 when the directory is gone, non-zero when something is left.
remove_tree() {
    local remote_dir="$1"
    local manifest="${WORK_DIR}/remote-manifest"
    local batch

    rm -f "${manifest}"

    if ! sftp_batch "get \"$(sftp_quote "${remote_dir}/${MANIFEST_NAME}")\" \"${manifest}\"" >/dev/null 2>&1 \
        || [ ! -s "${manifest}" ]; then
        # No manifest: either the directory is not there at all, or it predates
        # this script's manifest (an upload by the old lftp-based version).
        if ! remote_exists "${remote_dir}"; then
            return 0
        fi

        echo "    ${remote_dir} has no manifest — it was left by an older version of" >&2
        echo "    this script. Removing what this version knows about; anything from" >&2
        echo "    an older release may remain, and can be deleted by hand." >&2
        local_manifest > "${manifest}"
    fi

    # Files first, then directories deepest-first: reverse lexicographic order
    # puts src/Http/RequestHandlers before src/Http before src, which is what
    # rmdir needs. Everything is tolerant — this is a best effort.
    batch="$(
        awk '$1 == "f" { $1 = ""; sub(/^ /, ""); print }' "${manifest}" |
            while IFS= read -r path; do
                printf -- '-rm "%s"\n' "$(sftp_quote "${remote_dir}/${path}")"
            done

        printf -- '-rm "%s"\n' "$(sftp_quote "${remote_dir}/${MANIFEST_NAME}")"

        awk '$1 == "d" { $1 = ""; sub(/^ /, ""); print }' "${manifest}" | sort -r |
            while IFS= read -r path; do
                printf -- '-rmdir "%s"\n' "$(sftp_quote "${remote_dir}/${path}")"
            done

        printf -- '-rmdir "%s"\n' "$(sftp_quote "${remote_dir}")"
    )"

    sftp_batch "${batch}" >/dev/null 2>&1 || true

    ! remote_exists "${remote_dir}"
}

# Make sure a path is free, so that a rename onto it can succeed.
#
# This is not fussiness. A rollback directory that cannot be removed — because
# it holds a file from a release older than any manifest — would block the
# rename on *every* future deployment, permanently, with an error about
# permissions that is not true. So if the tree will not delete, it gets moved
# out of the way instead. What is left has a dot in its name, so webtrees does
# not load it; it is untidy, and it can be deleted by hand whenever.
ensure_absent() {
    local path="$1"

    if remove_tree "${path}"; then
        return 0
    fi

    local orphan="${path}.orphan-$(date +%s)"

    echo "    ${path} would not delete completely; moving it aside to" >&2
    echo "    ${orphan} so it cannot block the deployment. Delete it by hand" >&2
    echo "    when convenient — webtrees ignores it." >&2

    sftp_batch "rename \"$(sftp_quote "${path}")\" \"$(sftp_quote "${orphan}")\"" >/dev/null 2>&1 || true

    ! remote_exists "${path}"
}

# -----------------------------------------------------------------
# Off we go
# -----------------------------------------------------------------

# sftp's `put` expands globs in the local path, and there is no way to turn that
# off. A file called `[[path]].ts` — this repository has one, under
# portal/functions/ — would be read as a pattern and quietly not uploaded.
# Refuse rather than deploy something incomplete.
OFFENDING="$( ( cd "${LOCAL_DIR}" && find . -mindepth 1 -name '*[][*?]*' -printf '%P\n' ) | head -5 )"

if [ -n "${OFFENDING}" ]; then
    echo "error: these paths contain characters sftp would treat as a glob:" >&2
    printf '         %s\n' ${OFFENDING} >&2
    echo "       Rename them, or this deployment would silently skip them." >&2
    exit 65
fi

MANIFEST_FILE="${WORK_DIR}/manifest"
local_manifest > "${MANIFEST_FILE}"

echo "==> ${LOCAL_DIR}  ->  ${SFTP_USERNAME}@${SFTP_HOST}:${REMOTE_PATH}"
echo "==> $(grep -c '^f ' "${MANIFEST_FILE}") files in $(( $(grep -c '^d ' "${MANIFEST_FILE}") + 1 )) directories"
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
    echo "==> Dry run: this is the batch that would be sent, and nothing else"
    echo
    upload_batch "${STAGING_PATH}"
    echo
    echo "==> Then: rename ${REMOTE_NAME} -> ${REMOTE_NAME}.previous,"
    echo "          rename ${REMOTE_NAME}.upload -> ${REMOTE_NAME},"
    echo "          remove ${REMOTE_NAME}.previous."
    echo "==> Dry run finished. The server was not contacted."
    exit 0
fi

# sftp merges into whatever is already in the staging directory, so a partial
# copy left by a failed run has to go first — otherwise a file this version no
# longer has would ride along into the swap.
echo "==> Clearing any staging directory left by a failed run"

if ! ensure_absent "${STAGING_PATH}"; then
    echo >&2
    echo "error: ${STAGING_PATH} is in the way and will not move." >&2
    echo "       Nothing was changed. Delete that directory by hand and run" >&2
    echo "       this again." >&2
    exit 74
fi

echo "==> Uploading to ${STAGING_PATH}"

if ! with_retries "" sftp_batch "$(upload_batch "${STAGING_PATH}")"; then
    echo >&2
    echo "error: the upload did not finish." >&2
    echo "       Nothing on the live site was touched — the module is still" >&2
    echo "       running the previous version. Run this again; a partial upload" >&2
    echo "       in ${STAGING_PATH} is cleared first." >&2
    exit 74
fi

# A rollback directory left by a failed run would block the rename below.
if ! ensure_absent "${PREVIOUS_PATH}"; then
    echo >&2
    echo "error: ${PREVIOUS_PATH} is in the way and will not move." >&2
    echo "       The upload is complete and waiting in ${STAGING_PATH};" >&2
    echo "       the live module is untouched. Delete that directory by hand" >&2
    echo "       and run this again." >&2
    exit 74
fi

# The swap is done when the module is back in place and the staging directory
# is gone. Checking both matters: the module directory also exists when the
# rename never started.
swap_already_done() {
    remote_exists "${REMOTE_PATH}" && ! remote_exists "${STAGING_PATH}"
}

echo "==> Swapping the new version in"

# Both renames in one session, and the second one is where the truth is. The
# first is tolerant because there is nothing to move aside on a first deploy —
# but if it fails for a real reason, permissions say, then the second fails too,
# because its target still exists. That is the failure we want: the live module
# untouched, the upload sitting in a directory webtrees ignores, and the site
# still serving the old version.
SWAP_BATCH="$(
    printf -- '-rename "%s" "%s"\n' "$(sftp_quote "${REMOTE_PATH}")" "$(sftp_quote "${PREVIOUS_PATH}")"
    printf 'rename "%s" "%s"\n' "$(sftp_quote "${STAGING_PATH}")" "$(sftp_quote "${REMOTE_PATH}")"
)"

if ! with_retries swap_already_done sftp_batch "${SWAP_BATCH}"; then
    echo >&2
    echo "error: could not put the new version in place." >&2
    echo "       The live module was NOT changed and the site is still running" >&2
    echo "       the previous version. The upload is at:" >&2
    echo "         ${STAGING_PATH}" >&2
    echo "       Check that ${SFTP_USERNAME} may rename directories in" >&2
    echo "       ${REMOTE_PARENT}, then run this again." >&2
    exit 74
fi

echo "==> Removing the previous version"
# Best effort: the new version is already live, and a leftover directory with a
# dot in its name is invisible to webtrees. The next run clears it.
remove_tree "${PREVIOUS_PATH}" || true

echo "==> Done."
echo
echo "    webtrees creates or updates the module's tables on its next request."
echo "    If this was a first install, enable the module in"
echo "    Control panel -> Modules -> All modules, then set the family tree in"
echo "    its settings."
