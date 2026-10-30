#!/bin/sh
# ./run.sh inbox [rows] | native | webview | desktop | form | logview [file] | markdown-notes [folder] | notes | drag-drop | glass | widgets | photos [folder] | video [file] | http | titlebar [hidden|hiddenInset] [x,y] | files [folder] | code-editor | flyout | router | drag | transfer | richtext | terminal | sqlite | watch [folder] | websocket [url] | updater [--demo] [--native] | game | services | documents [file.md…] | protocol | charts | chart-streaming [polyline|path|raw] | piano | hello | counter-native | text-buffer [lines] | text-input | frameless | clipboard | timeline | markdown-chat | embed-webview | menubar-flyout | menubar-native | launcher | vibrancy | todo | confetti | effort-slider | farm | mayoi | gallery | gen [out.ts]
cd "$(dirname "$0")"
PHP="${PHP:-php}"
$PHP -m | grep -qi '^ffi$' || { echo "php needs the FFI extension (php -m)"; exit 1; }
OPTS="-d ffi.enable=1 -d opcache.enable_cli=1 -d opcache.jit_buffer_size=64M -d opcache.jit=1255 -d memory_limit=512M"
case "$1" in
  inbox)   exec $PHP $OPTS examples/inbox/main.php "${2:-300}" ;;
  native)  exec $PHP $OPTS examples/native/main.php ;;
  webview) exec $PHP $OPTS examples/webview/main.php ;;
  desktop) exec $PHP $OPTS examples/desktop/main.php ;;
  form)    exec $PHP $OPTS examples/form/main.php ;;
  logview) exec $PHP $OPTS examples/logview/main.php ${2:+"$2"} ;;
  markdown-notes) exec $PHP $OPTS examples/markdown-notes/main.php ${2:+"$2"} ;;
  notes)   exec $PHP $OPTS examples/notes/main.php ;;
  drag-drop) exec $PHP $OPTS examples/drag-drop/main.php ;;
  glass)   exec $PHP $OPTS examples/glass/main.php ;;
  widgets) exec $PHP $OPTS examples/widgets/main.php ;;
  photos)  exec $PHP $OPTS examples/photos/main.php ${2:+"$2"} ;;
  video)   MYPHP_VIDEO_LOG=1 exec $PHP $OPTS examples/video/main.php ${2:+"$2"} ;;
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
  services) exec $PHP $OPTS examples/services/main.php "${@:2}" ;;
  game) exec $PHP $OPTS examples/game/main.php ;;
  charts) exec $PHP $OPTS examples/charts/main.php ;;
  chart-streaming) exec $PHP $OPTS examples/chart-streaming/main.php "${2:-polyline}" ;;
  piano)  exec $PHP $OPTS examples/piano/main.php ;;
  protocol) exec $PHP $OPTS examples/protocol/main.php ;;
  documents) exec $PHP $OPTS examples/documents/main.php "${@:2}" ;;
  updater) exec $PHP $OPTS examples/updater/main.php "${@:2}" ;;
  hello)   exec $PHP $OPTS examples/hello/main.php ;;
  counter-native) exec $PHP $OPTS examples/counter-native/main.php ;;
  text-buffer) exec $PHP $OPTS examples/text-buffer/main.php ${2:+"$2"} ;;
  text-input) exec $PHP $OPTS examples/text-input/main.php ;;
  frameless) exec $PHP $OPTS examples/frameless/main.php ;;
  clipboard) exec $PHP $OPTS examples/clipboard/main.php ;;
  timeline) exec $PHP $OPTS examples/timeline/main.php ;;
  markdown-chat) exec $PHP $OPTS examples/markdown-chat/main.php ;;
  embed-webview) exec $PHP $OPTS examples/embed-webview/main.php ;;
  menubar-flyout) exec $PHP $OPTS examples/menubar-flyout/main.php ;;
  menubar-native) exec $PHP $OPTS examples/menubar-native/main.php ;;
  launcher) exec $PHP $OPTS examples/launcher/main.php ;;
  vibrancy) exec $PHP $OPTS examples/vibrancy/main.php ;;
  todo)    exec $PHP bin/myphp dev examples/todo ;;
  confetti) exec $PHP $OPTS examples/confetti/main.php ;;
  effort-slider) exec $PHP $OPTS examples/effort-slider/main.php ;;
  farm)    exec $PHP $OPTS examples/farm/main.php ;;
  mayoi)   exec $PHP $OPTS examples/mayoi/main.php ;;
  gallery) exec $PHP $OPTS examples/gallery/main.php ;;
  gen)     MYPHP_GENERATE="${2:-examples/webview/myphp.ts}" exec $PHP $OPTS examples/webview/main.php ;;
  *) echo "usage: $0 inbox [rows] | native | webview | desktop | form | logview [file] | markdown-notes [folder] | notes | drag-drop | glass | widgets | photos [folder] | video [file] | http | titlebar [hidden|hiddenInset] [x,y] | files [folder] | code-editor | flyout | router | drag | transfer | richtext | terminal | sqlite | watch [folder] | websocket [url] | updater [--demo] [--native] | game | services | documents [file.md…] | protocol | charts | chart-streaming [polyline|path|raw] | piano | hello | counter-native | text-buffer [lines] | text-input | frameless | clipboard | timeline | markdown-chat | embed-webview | menubar-flyout | menubar-native | launcher | vibrancy | todo | confetti | effort-slider | farm | mayoi | gallery | gen [out.ts]"; exit 1 ;;
esac
