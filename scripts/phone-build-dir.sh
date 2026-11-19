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
