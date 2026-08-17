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
# Two connections, and not one more
# ---------------------------------
# This host also refuses connections outright — every session in a run dying
# with `Connection closed by <ip> port 22`, before sftp has echoed a single
# command, which is what being turned away at the door looks like rather than a
# transfer going wrong. A burst of SSH connections from a CI runner is a thing
# shared hosting rate-limits, and once that trips, everything after it fails
# too.
#
# So a run opens exactly two sessions: one to read the manifests it needs, and
# one that does the entire deployment — clean, upload, swap, tidy up. That is
# the same budget as the deployment from another project that works against
# this server. An earlier version of this script needed nine.
#
# The second session is written so that repeating it is safe. If it dies part
# way through, running it again from the top reaches the same end state, which
# is what makes retrying with a long delay a real remedy rather than a hope.
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
#   SFTP_MAX_ATTEMPTS  how many times to retry a session whose connection was
#                      refused, default 4
#   SFTP_RETRY_DELAY   seconds before the first retry, tripling each time.
#                      Default 30, so 30s, 90s and 270s — long enough to
#                      outlast a host that is briefly refusing connections,
#                      which a few seconds is not.
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
RETRY_DELAY="${SFTP_RETRY_DELAY:-30}"

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

# Run a session, and try again with a widening delay when it is refused.
#
# The delays are minutes rather than seconds on purpose. What this is waiting
# out is a host that has decided to stop accepting connections from this
# address for a while; five seconds does not outlast that, and four attempts
# five seconds apart are really one attempt.
with_retries() {
    local attempt=1
    local delay="${RETRY_DELAY}"

    while true; do
        if "$@"; then
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

# The commands that remove the tree $2, according to the manifest in file $1.
#
# sftp has no recursive `rm`, and asking it for a recursive listing means
# parsing `ls -l` output, which differs between servers. Neither is needed: an
# upload carries its own list of contents.
#
# Files first, then directories in reverse lexicographic order, which puts
# src/Http/RequestHandlers before src/Http before src — the order rmdir needs.
# Every line is tolerant: this is a best effort, and a leftover directory with a
# dot in its name is invisible to webtrees.
delete_commands() {
    local manifest="$1"
    local remote_dir="$2"

    [ -s "${manifest}" ] || return 0

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
}

# Session one: fetch the manifests of everything this run may have to delete.
#
# All three are optional — a first deploy has none of them — so every command
# is tolerant and the session's exit status means only one thing: whether the
# server let us in at all. That makes this the connection test as well.
MANIFEST_LIVE="${WORK_DIR}/manifest.live"
MANIFEST_STAGING="${WORK_DIR}/manifest.staging"
MANIFEST_PREVIOUS="${WORK_DIR}/manifest.previous"

fetch_manifests() {
    rm -f "${MANIFEST_LIVE}" "${MANIFEST_STAGING}" "${MANIFEST_PREVIOUS}"

    sftp_batch "$(
        printf -- '-get "%s" "%s"\n' "$(sftp_quote "${REMOTE_PATH}/${MANIFEST_NAME}")"    "${MANIFEST_LIVE}"
        printf -- '-get "%s" "%s"\n' "$(sftp_quote "${STAGING_PATH}/${MANIFEST_NAME}")"   "${MANIFEST_STAGING}"
        printf -- '-get "%s" "%s"\n' "$(sftp_quote "${PREVIOUS_PATH}/${MANIFEST_NAME}")"  "${MANIFEST_PREVIOUS}"
    )" >/dev/null 2>&1
}

# Session two: the whole deployment.
#
# Every step in one batch, in an order that is safe to repeat from the top.
# Running it twice reaches the same end state, which is what makes retrying a
# remedy rather than a hope:
#
#   * the deletes and mkdirs are tolerant, so a second run skips what is done;
#   * the puts overwrite;
#   * the renames come last, after a complete copy is staged. If the session
#     dies between them the module is missing for as long as it takes to run
#     the batch again — the only window in the whole design, and the next
#     attempt closes it.
#
# The one non-tolerant line is the rename that puts the new version live. If
# anything before it went wrong, that is where the run stops, with the old
# version still serving.
deploy_batch() {
    # Clear a staging directory left by a failed run. sftp merges into whatever
    # is already there, so a file this version no longer has would otherwise
    # ride along into the swap.
    if [ -s "${MANIFEST_STAGING}" ]; then
        delete_commands "${MANIFEST_STAGING}" "${STAGING_PATH}"
    else
        # No manifest: either nothing is there, or it predates this script. Both
        # are handled by removing what this version knows about — every line is
        # tolerant, so deleting what was never there costs nothing.
        delete_commands "${MANIFEST_FILE}" "${STAGING_PATH}"
    fi

    # A rollback directory left by a failed run is moved out of the way first
    # and emptied second, rather than the other way round. Renaming always
    # frees the name; deleting might not — and a rollback directory that will
    # not delete, say one holding a file from a release older than any
    # manifest, would otherwise block the rename below on every future
    # deployment, permanently, with an error about permissions that is not
    # true. Doing it in this order, the name is free either way, and the
    # contents still get cleared when we know what they are, so nothing is
    # left lying about in the normal case.
    local orphan="${PREVIOUS_PATH}.orphan-${RUN_STAMP}"

    printf -- '-rename "%s" "%s"\n' \
        "$(sftp_quote "${PREVIOUS_PATH}")" \
        "$(sftp_quote "${orphan}")"

    if [ -s "${MANIFEST_PREVIOUS}" ]; then
        delete_commands "${MANIFEST_PREVIOUS}" "${orphan}"
    else
        delete_commands "${MANIFEST_FILE}" "${orphan}"
    fi

    upload_batch "${STAGING_PATH}"

    # Move the old version aside. Tolerant: on a first deploy there is nothing
    # to move. If it fails for a real reason the rename below fails too,
    # because its target still exists — which is the failure we want.
    printf -- '-rename "%s" "%s"\n' \
        "$(sftp_quote "${REMOTE_PATH}")" \
        "$(sftp_quote "${PREVIOUS_PATH}")"

    # The one line that must work.
    printf 'rename "%s" "%s"\n' \
        "$(sftp_quote "${STAGING_PATH}")" \
        "$(sftp_quote "${REMOTE_PATH}")"

    # Tidy up the version just replaced, using the manifest it shipped with. On
    # a first run after this change there is none, and the fallback is the
    # current file list — close enough to clear almost all of it.
    if [ -s "${MANIFEST_LIVE}" ]; then
        delete_commands "${MANIFEST_LIVE}" "${PREVIOUS_PATH}"
    else
        delete_commands "${MANIFEST_FILE}" "${PREVIOUS_PATH}"
    fi
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

RUN_STAMP="$(date +%s)"

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
    echo "==> Dry run: this is the second session's batch, and nothing else"
    echo
    upload_batch "${STAGING_PATH}"
    printf -- '-rename "%s" "%s"\n' "${REMOTE_PATH}" "${PREVIOUS_PATH}"
    printf 'rename "%s" "%s"\n' "${STAGING_PATH}" "${REMOTE_PATH}"
    echo
    echo "    (the deletes are left out here: they come from manifests on the"
    echo "     server, which a dry run does not fetch)"
    echo "==> Dry run finished. The server was not contacted."
    exit 0
fi

echo "==> Reading what is on the server"

if ! with_retries fetch_manifests; then
    echo >&2
    echo "error: could not open an SFTP session at all." >&2
    echo >&2
    echo "       Nothing was changed. This is the connection being refused, not" >&2
    echo "       a problem with the files: sftp had not run a single command" >&2
    echo "       when it was cut off." >&2
    echo >&2
    echo "       Worth checking, roughly in this order:" >&2
    echo "         * SFTP_HOST, SFTP_USERNAME and SFTP_PASSWORD, by connecting" >&2
    echo "           by hand from a terminal:" >&2
    echo "             sftp -P ${SFTP_PORT} ${SFTP_USERNAME}@${SFTP_HOST}" >&2
    echo "         * SFTP_KNOWN_HOSTS, if the host key has been rotated." >&2
    echo "         * whether the host is refusing this runner for a while." >&2
    echo "           Shared hosting rate-limits bursts of SSH connections, and" >&2
    echo "           a CI runner looks like a burst. Re-running later, or" >&2
    echo "           raising SFTP_RETRY_DELAY (now ${RETRY_DELAY}s) and" >&2
    echo "           SFTP_MAX_ATTEMPTS (now ${MAX_ATTEMPTS}), is the remedy." >&2
    exit 75
fi

echo "==> Uploading and swapping in one session"

if ! with_retries sftp_batch "$(deploy_batch)"; then
    echo >&2
    echo "error: the deployment did not finish." >&2
    echo >&2
    echo "       If it stopped before the last line of the batch, the live" >&2
    echo "       module was not touched and the site is still serving the" >&2
    echo "       previous version. Running this again is safe and picks up" >&2
    echo "       where it left off — the batch is written to be repeatable." >&2
    echo >&2
    echo "       If the log above shows the renames running, check the module" >&2
    echo "       directory on the server before re-running:" >&2
    echo "         ${REMOTE_PATH}" >&2
    exit 74
fi

echo "==> Done."
echo
echo "    webtrees creates or updates the module's tables on its next request."
echo "    If this was a first install, enable the module in"
echo "    Control panel -> Modules -> All modules, then set the family tree in"
echo "    its settings."
