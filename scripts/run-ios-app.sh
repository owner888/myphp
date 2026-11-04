#!/bin/bash
set -euo pipefail

# Run a MyPHP app in the iOS Simulator.
# Usage: ./scripts/run-ios-app.sh [example_dir_or_entry] [device_name]

TARGET="${1:-examples/counter-native}"
DEVICE="${2:-booted}"

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"

# 1. Determine app path or build it
if [[ "${TARGET}" == *.app ]]; then
    APP_PATH="${TARGET}"
    APP_NAME="$(basename "${APP_PATH}" .app)"
    BUNDLE_ID="com.owner888.${APP_NAME}"
else
    echo "==> Building ${TARGET} for iOS..."
    "${ROOT_DIR}/bin/myphp" build -platform ios/arm64 "${TARGET}"
    # Find built .app in target dist
    if [ -d "${TARGET}/dist" ]; then
        APP_PATH="$(find "${TARGET}/dist" -name "*.app" -maxdepth 1 | head -n 1)"
    else
        APP_PATH="$(find "${ROOT_DIR}/dist" -name "*.app" | head -n 1)"
    fi
    APP_NAME="$(basename "${APP_PATH}" .app)"
    BUNDLE_ID="com.myphp.${APP_NAME}"
fi

if [ ! -d "${APP_PATH}" ]; then
    echo "Error: App bundle not found at ${APP_PATH}" >&2
    exit 1
fi

echo "==> Target App: ${APP_PATH}"

# 2. Check or boot simulator
BOOTED="$(xcrun simctl list devices booted | grep -E "iPhone|iPad" | head -n 1 || true)"
if [ -z "${BOOTED}" ]; then
    echo "==> Opening iOS Simulator..."
    open -a Simulator
    echo "==> Waiting for simulator to boot..."
    for i in {1..30}; do
        BOOTED="$(xcrun simctl list devices booted | grep -E "iPhone|iPad" | head -n 1 || true)"
        if [ -n "${BOOTED}" ]; then
            break
        fi
        sleep 1
    done
fi

if [ -z "${BOOTED}" ]; then
    echo "Error: Could not boot iOS simulator" >&2
    exit 1
fi

DEVICE_ID="$(echo "${BOOTED}" | grep -oE "\([A-F0-9-]+\)" | tr -d '()')"
echo "==> Using Simulator: ${DEVICE_ID}"

# Read Bundle ID from Info.plist
if [ -f "${APP_PATH}/Info.plist" ]; then
    PLIST_ID="$(/usr/libexec/PlistBuddy -c "Print :CFBundleIdentifier" "${APP_PATH}/Info.plist" 2>/dev/null || true)"
    if [ -n "${PLIST_ID}" ]; then
        BUNDLE_ID="${PLIST_ID}"
    fi
fi

# 3. Install and Launch
echo "==> Installing app (${BUNDLE_ID})..."
xcrun simctl install "${DEVICE_ID}" "${APP_PATH}"

echo "==> Launching app (${BUNDLE_ID})..."
xcrun simctl launch "${DEVICE_ID}" "${BUNDLE_ID}"

echo "==> App is now running in iOS Simulator!"
