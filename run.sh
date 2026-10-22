#!/bin/sh
# ./run.sh native [rows] | webview | desktop | form | logview [file] | notes [folder] | glass | widgets | photos [folder] | http | titlebar [hidden|hiddenInset] [x,y] | files [folder] | code-editor | flyout | router | drag | transfer | richtext | terminal | sqlite | watch [folder] | websocket [url] | updater [--demo] [--native] | gen [out.ts]
cd "$(dirname "$0")"
PHP="${PHP:-php}"
$PHP -m | grep -qi '^ffi$' || { echo "php needs the FFI extension (php -m)"; exit 1; }
OPTS="-d ffi.enable=1 -d opcache.enable_cli=1 -d opcache.jit_buffer_size=64M -d opcache.jit=1255 -d memory_limit=512M"
case "$1" in
  native)  exec $PHP $OPTS examples/native/main.php "${2:-300}" ;;
  webview) exec $PHP $OPTS examples/webview/main.php ;;
  desktop) exec $PHP $OPTS examples/desktop/main.php ;;
  form)    exec $PHP $OPTS examples/form/main.php ;;
  logview) exec $PHP $OPTS examples/logview/main.php ${2:+"$2"} ;;
  notes)   exec $PHP $OPTS examples/notes/main.php ${2:+"$2"} ;;
  glass)   exec $PHP $OPTS examples/glass/main.php ;;
  widgets) exec $PHP $OPTS examples/widgets/main.php ;;
  photos)  exec $PHP $OPTS examples/photos/main.php ${2:+"$2"} ;;
  http)    exec $PHP $OPTS examples/http/main.php ;;
  titlebar) exec $PHP $OPTS examples/titlebar/main.php ${2:+"$2"} ${3:+"$3"} ;;
  files)   exec $PHP $OPTS examples/files/main.php ${2:+"$2"} ;;
  code-editor) exec $PHP $OPTS examples/code-editor/main.php ;;
  flyout)  exec $PHP $OPTS examples/flyout/main.php ;;
  router)  exec $PHP $OPTS examples/router/main.php ;;
  drag)    exec $PHP $OPTS examples/drag/main.php ;;
  transfer) exec $PHP $OPTS examples/transfer/main.php ;;
  richtext) exec $PHP $OPTS examples/richtext/main.php ;;
  terminal) exec $PHP $OPTS examples/terminal/main.php ;;
  sqlite)  exec $PHP $OPTS examples/sqlite/main.php ;;
  watch)   exec $PHP $OPTS examples/watch/main.php ${2:+"$2"} ;;
  websocket) exec $PHP $OPTS examples/websocket/main.php ${2:+"$2"} ;;
  updater) exec $PHP $OPTS examples/updater/main.php "${@:2}" ;;
  gen)     MYPHP_GENERATE="${2:-examples/webview/myphp.ts}" exec $PHP $OPTS examples/webview/main.php ;;
  *) echo "usage: $0 native [rows] | webview | desktop | form | logview [file] | notes [folder] | glass | widgets | photos [folder] | http | titlebar [hidden|hiddenInset] [x,y] | files [folder] | code-editor | flyout | router | drag | transfer | richtext | terminal | sqlite | watch [folder] | websocket [url] | updater [--demo] [--native] | gen [out.ts]"; exit 1 ;;
esac
