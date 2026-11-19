#!/bin/bash
set -euo pipefail

# Writes BundleConfig.pb, the bundle's settings, to the path given: the one bundletool writes by default, a Bundletool
# message (field 1) holding a version string (its field 2), no optimizations named, so that Google Play applies its own
# defaults (APKs split by ABI, screen density and language). Protobuf by hand: it is 11 bytes.
# Usage: android-bundle-config.sh <BundleConfig.pb>

VERSION="1.18.1"
{
    printf '\x0a'
    printf "\\x$(printf '%02x' $(( ${#VERSION} + 2 )))"   # the Bundletool message's length
    printf '\x12'
    printf "\\x$(printf '%02x' ${#VERSION})"              # the version string's length
    printf '%s' "$VERSION"
} > "$1"
