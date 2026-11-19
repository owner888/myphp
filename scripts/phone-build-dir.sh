# Sourced by the phone scripts, after ROOT_DIR: sets PHONE_BUILD, where the phone runtimes (PHP, OpenSSL, libcurl, the
# launcher) and the Android debug keystore are kept, as android/, ios/, ios-simulator/ under it.
#
# build/ of MyPHP's own folder, which a git checkout and an archive keep through `myphp upgrade`. A Composer package's
# folder is replaced whole by every update, and with it what was built or downloaded there (10-20 minutes of PHP, and the
# debug key that signs the apps): there they are in ~/.myphp/phone, shared by every project that uses that MyPHP.
# MYPHP_PHONE_BUILD sets it by hand.

if [ -n "${MYPHP_PHONE_BUILD:-}" ]; then
    PHONE_BUILD="${MYPHP_PHONE_BUILD}"
elif [[ "$(cd "${ROOT_DIR}" && pwd -P)" == */vendor/owner888/myphp ]]; then
    PHONE_BUILD="${HOME:?HOME is not set}/.myphp/phone"
else
    PHONE_BUILD="${ROOT_DIR}/build"
fi

# The SHA-256 of a file, as Linux's sha256sum or macOS's shasum make it.
file_sha256() {
    if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi
}

# A runtime (libphp) is made again when the script that made it has changed since, not when the script's file is newer: an
# upgrade of MyPHP writes every file anew, which would make every runtime stale and compile PHP again (10-20 minutes).
# The script's SHA-256 is kept beside the runtime when it is made or downloaded. $1 is the runtime, $2 the script; true
# when it must be made again. A runtime with no stamp (made before it was kept) is taken as made by this script.
runtime_is_stale() {
    local lib="$1" stamp
    stamp="$(dirname "$1")/.build-script-sha256"
    [ -f "$lib" ] || return 1
    if [ ! -s "$stamp" ]; then
        runtime_stamp "$1" "$2"
        return 1
    fi
    [ "$(cat "$stamp")" != "$(file_sha256 "$2")" ]
}

# Keeps the SHA-256 of the script $2 beside the runtime $1: it is the one that made it.
runtime_stamp() {
    local stamp="$(dirname "$1")/.build-script-sha256"
    file_sha256 "$2" > "$stamp.tmp" && mv "$stamp.tmp" "$stamp" # whole or not there at all, whatever ends the script
}
