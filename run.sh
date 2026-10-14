#!/bin/sh
# ./run.sh native [rows] | webview
cd "$(dirname "$0")"
PHP="${PHP:-php}"
$PHP -m | grep -qi '^ffi$' || { echo "php needs the FFI extension (php -m)"; exit 1; }
OPTS="-d ffi.enable=1 -d opcache.enable_cli=1 -d opcache.jit_buffer_size=64M -d opcache.jit=1255 -d memory_limit=512M"
case "$1" in
  native)    exec $PHP $OPTS examples/native/main.php "${2:-300}" ;;
  webview) exec $PHP $OPTS examples/webview/main.php ;;
  *) echo "usage: $0 native [rows] | webview"; exit 1 ;;
esac
