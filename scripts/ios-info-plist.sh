#!/bin/bash
set -euo pipefail

# Adds keys to an iOS Info.plist, in place: ITSAppUsesNonExemptEncryption false, as the app's encryption (TLS by OpenSSL and
# libcurl) is of the exempt kind, so that App Store Connect does not ask about it at every upload; then the keys of the JSON
# object in MYPHP_IOS_INFO_PLIST (the app's ios.infoPlist: the usage descriptions of the camera, the microphone, the local
# network, … which the system asks for, and ends the app without), which replace any key of the same name.
# Usage: ios-info-plist.sh <Info.plist>

PLIST="${1:?usage: ios-info-plist.sh <Info.plist>}"
JSON="$(mktemp)"
trap 'rm -f "$JSON"' EXIT
plutil -convert json -o "$JSON" "$PLIST"
php -r '
$info = (array) json_decode((string) file_get_contents($argv[1])); // objects stay objects: the empty dictionary of UILaunchScreen
$info["ITSAppUsesNonExemptEncryption"] = false;
foreach ((array) json_decode((string) (getenv("MYPHP_IOS_INFO_PLIST") ?: "{}")) as $k => $v) {
    $info[$k] = $v;
}
file_put_contents($argv[1], json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
' "$JSON"
plutil -convert xml1 -o "$PLIST" "$JSON"
