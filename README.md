# MyPHP

[English](#english) · [中文](#中文)

---

## English

**Desktop apps in PHP, with native UI or a web frontend.**

MyPHP is a port of the ideas in [mygo](https://github.com/egoist/mygo) to PHP 8:
one process, one language, no C extension of its own. The operating system's
libraries are reached through `ext-ffi`.

Each window shows one of two kinds of interface:

- **A web page** in the webview the OS already has (WKWebView on macOS). The
  page calls PHP services through a typed bridge.
- **Native UI**, written in PHP alone and drawn by MyPHP on the GPU: no HTML,
  no JavaScript, no webview. The view is a function of the app's state and
  runs every frame (immediate mode), as in mygo's `ui` package.

A window with native UI, whose view is a function of the app's state:

```php
use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, Context};

$n = 0;
App::whenReady(function () use (&$n) {
    new Window(new WindowOptions(title: 'Counter', width: 320, height: 200,
        content: Content::view(function (Context $c) use (&$n) {
            $c->column(24, 12, 1);
              $c->text("Count: $n", 20);
              if ($c->clicked($c->button('Increment'))) { $n++; }
            $c->end();
        })));
});
App::run();
```

### Status

Early. What works today, on macOS / Apple Silicon:

| piece | state |
|---|---|
| Objective-C bridge (`objc_msgSend`, classes with PHP methods) | works |
| WKWebView window, JS → PHP → JS | works (`examples/webview`) |
| Native UI: Metal renderer, CoreText text, flex layout, scrolling on either axis, clicks, hover | works (`examples/inbox`, `form`) |
| mygo's widgets and context, v1.08: toasts with actions, ids and `closeToast`; `openURLThen`; `onClick`, `onChange`, `onSubmit`, `onShortcut`; `Handle` and `focusBind`; toggle groups, step sliders with ticks, outlines and outline tables; a list's `followAnimation`, a table's `roundRows` and `headerMenu`; path gradients; `Preferences` (accent, Reduce Motion, Increase Contrast); registered fonts and `Font::families`; `Oklch`; `Image::update`; `TextLayout` for text controls of one's own; `TextBuffer`; every base widget without a look (`checkboxBase` … `tooltipBase`, `selectBase`, `comboboxBase`, the text inputs) | works |
| mygo's app, window, page and menus, v1.09: `Page` (`Window::page()`: loadFile, reload, back and forward, zoom, user agent, `eval`, window.open() handler, title, navigation, load and crash events, JavaScript panels as sheets); `Event` (`emit`, `emitPage`, `broadcast`); web views in native UI (`Window::newWebView`, `Context::webView`); the page's preferences, language and title bar room; `App` lifecycle events, activation policy, about panel, `path`, `exit`; `Dock`, `Shell`, `Screen`, `Theme`; `Power::keepAwake`; window state with its store; menu radio items, roles of the View, Format, Speech, Services, Window and Help menus; `Tray::popUpMenu` | works |
| mygo's element API: CSS flexbox (gaps, `alignContent`, `wrapReverse`, automatic margins, `justify`'s spaces, least and largest sizes, percentages, aspect ratios), absolute and relative insets, `attach`/`attachTo` anchors, grid tracks; per-corner radii, gradients at any angle (sRGB or Oklab), stripes, dashed borders, box shadows inside and out with spread, dividers, `debug`, `invisible`, `passThrough`, inherited `disabled`, `focusRing(false)`, scroll bars' insets, every cursor; `pointerPosition`, `dragVelocity`, `dragEnded`, `pressedOutside`, `overlayShortcut`, `dismissed`, a list's `rowWidth` with `scrollWith`, `TextLines`, `textRanges`, `setTextSelection`, `textSelection`, `textAnchorCaret` | works |
| Lists over a million rows: scroll bars, `scrollTo` glides, keyed rows, selection with keyboard navigation | works (`examples/inbox`) |
| Images (PNG, JPEG, HEIC, ICNS…) in native UI, rounded and faded, fitted (contain, cover, fill, scale-down, natural size), in grayscale | works (`examples/inbox`, `widgets`) |
| Bold, italic and monospace text; rich text spans with color, underline, highlight, wrapping | works (`examples/inbox`, `logview`) |
| Text styles inherited from the nodes around (font family, size, weight, italics, line height, alignment, letter spacing, OpenType features, language, underlines, strikethrough, background), mygo's text system (CoreText typesetter, carets, selection geometry, bidi, ellipsis), paragraphs with links and styled text inside them | works |
| Selectable text (mygo's Selectable): drag, double and triple clicks, Shift with the arrows across the paragraphs of a container, Copy and Select All, `selectedTexts()`, `copyData()` with data in several formats | works |
| Soft shadows, rings; materials (`UI\Material`); the glass plugin (mygo's plugins/glass, `Plugins\Glass`): Liquid Glass (blur, lens bend at the edge, lit rim, tint, interactive), progressive backdrop blur along a mask, scroll edges | works (`examples/glass`) |
| Text fields and text areas: input methods, selection, wrapping, undo, Edit menu, Tab focus; checkbox, radio, slider, select, link, progress, tooltips | works (`examples/form`) |
| Switch, stepper, segmented control, tabs, collapsible section, split panes, table (sorted by one column or several, columns resized, fitted to their cells, moved, scrolled sideways), sidebar with sections, tree, accordion, rating, range slider, combobox, autocomplete, token field, date input, calendar, time input, color picker and well, find bar, form with fields and fieldsets, checkbox group, breadcrumbs, alert dialog, grid view (virtualized), multiple selection (Cmd/Shift-click, Shift+arrows, Cmd+A), rows and grid items dragged to reorder, grid layout (fixed/fr/fit tracks), SVG icons, avatars, hidden title bar (titleBar + dragWindow), toolbar with toggles, modal dialogs, popovers, menu buttons, toasts, search field, number input, meter, spinner, badge, divider, spacer; radio groups, editable text in place (a table's names), lists whose section headers stay at the top and whose rows typing their labels chooses, trimmed paths, tinted bitmaps, glyphs shaped and drawn one by one | works (`examples/widgets`, `markdown-notes`, `http`, `gallery`) |
| Theme: light/dark after the system, its accent color, live switching | works |
| `myphp init / dev / build`: scaffold, run with restart, .app bundle (exec or static micro launcher) | works |
| Packaging as mygo's (v1.12): `-platform darwin/arm64,darwin/amd64,darwin/universal`, the resources directory and its platform directories (universal binaries combined), the icon from a PNG or an asset catalog, code signing inside out, the .dmg with its window laid out, notarization, `-upload` to GitHub releases or S3; `myphp doctor`, `install-skills`, `version`, `help` | works (signing, the .dmg and notarizing need the developer's Mac) |
| Development as mygo's (v1.13): `myphp dev` runs "<name> Dev", a bundle with the app's icon and resources, built again on changes, `r`/`c`/`q`/`h` on the terminal; SIGINT and SIGTERM quit the app as Quit; `myphp.config.ts`; the pages' packages `myphp-runtime` (`packages/runtime`, which the generated client and the plugins import) and `myphp-cli` (`packages/cli`); the web template with Vite and TypeScript | works (the development app needs the developer's Mac) |
| Widgets and keys as mygo's (v1.14): password fields; a dialog keeps the window's shortcuts; lists whose place, choice and rows' state follow their items' keys, and scroll by the step into rows never measured; the keys scroll what the pointer is over, Page Up/Down in a list scroll as AppKit's; stepper Left/Right, slider Page Up/Down; tooltips for the keyboard focus, hidden by a press or Escape; Control-K in text fields; JPEG photos upright; a sidebar's typeahead; shortcuts by the key's place on other layouts; `MYPHP_FRAME_STATS` | works (decoding photos, the frame statistics and other layouts' keys need the developer's Mac) |
| Lists as mygo's tests have them (v1.15): rows of any height without gaps, a step of the wheel or a key moving them by the step, `scrollTo` at the top, middle or bottom as measured, the end followed as rows come or grow (gliding with a FollowAnimation), the focused row kept out of view, Tab showing the next row, the scroll bar's thumb as far through the rows as along its track, rows under a pinned header, a list as high as its rows in a scrolling container | works |
| Text fields and toasts as mygo's tests have them (v1.16): undo step by step (a caret move ends a step), the app's changes undone with the typing, the view kept where the wheel put it as the app adds text, fields showing their start without the focus and aligned with `textAlign()`, text input clients unmarked as the focus leaves them, toasts' time stopping under the pointer and in the background, Escape closing the toast the focus is in | works |
| Primitives, styles and the window as mygo's tests have them (v1.17): the focus into a modal overlay of the app's own, disabled sliders, `onTextKey` for the keys that type no text, the hover held by a press, grids aligning their cells, text areas' placeholders wrapping, form labels on a text area's first line | works |
| Right to left, indicators, dates and comboboxes as mygo's tests have them (v1.18): texts aligned across their column, range sliders, splits, inputs, popovers and menus mirrored, word arrows the way they point in a right-to-left paragraph, steppers and range sliders taking quick clicks, color swatches named in the app's language, combobox popups read and scrolled as AppKit's | works |
| Scopes, transforms, SVGs and web views as mygo's tests have them (v1.19): selects with mygo's popup in the window, popovers in the Tab order after their anchors, clips that cut off the pointer through transforms, carets and input clients through transforms, nodes taking text of their own (`textCaret`), icons in the font size and color around them | works |
| Choosing, tables, widgets and inline text as mygo's tests have them (v1.20): lists reporting their choice, typing rows' letters as AppKit does, Shift extending from its item, presses showing in their frame, `clickModifiers()`, tables' section headers across and chosen rows rounded as one block, calendars and color pickers at their own width | works |
| Bases, forms, feedback, toggles and themes as mygo's tests have them (v1.21): Enter at a check box left to the window's shortcuts, Escape keeping the focus, an accordion's headers each a stop that the arrows move between, a toolbar's overflow menu with its toggles checked, fieldsets disabled after their build, accent colors as mygo's, text in the app's language, tooltips in the headless paint | works |
| Outlines, repaints, scrolling, selections and following as mygo's tests have them (v1.22): frames asked for by the frame's clock, spinners painted again for their next spoke only, transformed and absolute children of containers out of view painted, inputs' text backgrounds, selection and text colors and accent carets, content transitions of rich text, scroll offsets kept within their content and `scrollTo` with an animation, lists following their end through resizes and rows going | works |
| Pastes, text lines, inline objects and buffers as mygo's tests have them (v1.23): the Edit menu's commands through the runtime (`onPaste` by the menu and Cmd+V alike, Undo and Redo of fields), inline objects as wide as they are when the text is shaped, text lines placed before their layout, malformed UTF-8 counted right in text buffers, lists reordering without a choice | works |
| Text wrapping at the width layout gives it, as mygo's (v1.24): in columns, beside what takes a row's room down to its widest word, Chinese between its characters, up to `maxLines()`, anew at each width in the frame of a resize | works |
| Wide colors, as mygo's (v1.25): `Oklch::rgb` outside sRGB keeps its Display P3 color, drawn on a P3 screen in float16 drawables for fills, shadows, text and decorations, its nearest sRGB color elsewhere; `WideColor::srgb` for the sRGB twin | works (`examples/gallery`, Wide colors) |
| Go's random numbers (v1.27): `Random\Rand::pcg($a, $b)` draws what Go's `rand.New(rand.NewPCG(a, b))` draws (IntN, Float64, Shuffle, Perm, NormFloat64…), so seeded examples match mygo's; mygo's `native`, `notes` and `drag-drop` examples; text drawn with font smoothing (bolder the lighter it is, as AppKit's); borders taking room in layout; a button's label in its own style; dates in the system's time zone whatever php.ini names | works (`examples/farm`, `notes`, `native`, `drag-drop`) |
| Clicking through the examples on the Mac (v1.28): a tray stays in the menu bar; a drag's image shows the element's colors; a popover keeps the ids of what is built after it, so the notes' link field takes the typing; Go's generators (v1.27) taken out again, the examples drawing from PHP's engines; `-launcher micro` apps signable again (the phar inside the executable's `__LINKEDIT`) and with intl, xmlreader, curl, sockets and filter, which the framework calls (`myphp micro` rebuilds micro.sfx once), its catalogs found in its phar; an out inside frontendDist refused, the todo example built to `build` as mygo's; the first sound no longer crashes (a queue's address by `ObjC::addr`); a static app's terminal and frontend run from its phar; the 14 examples compared with mygo's side by side: fields as high as mygo's and taking `fontSize()`, their typing reported by `changed()`, a select's least width, a web view inside its box's border, a disabled button's border, a list's rows at its end with `justify(END)`; the flyout example now mygo's | works (`examples/native`, `drag-drop`, `notes`; `myphp build -launcher micro`) |
| Releasing as mygo does (v1.29): CI runs the PHP and Bun tests on every push (`.github/workflows/ci.yml`); a tag `v<version>` checks that bin/myphp and the npm packages have it (`php scripts/version.php`, which sets it), publishes myphp-runtime, myphp-cli and the plugins' `@myphp-plugins/*` with npm's trusted publishing, and makes the GitHub release (`release.yml`); myphp-cli installed from npm runs the project's Composer-installed MyPHP (`vendor/owner888/myphp/bin/myphp`); the packages leave their tests out | works (the first publish of the plugins and npm's trusted publishers are the developer's, on npmjs.com) |
| A frame's layout as fast as before text wrapping (v1.30): a text in a row too narrow for it keeps its widest word whole (its min-content width was one glyph's, laid out a line a glyph); a text's min-content width, which layout asks of every text that may wrap, taken from the line that measured it instead of laid out each frame: 2000 texts lay out in 4 ms instead of 14, a million-row list in 0.12 ms instead of 0.35; the tree a frame replaces freed at once instead of by PHP's collector, whose runs dropped frames while a list scrolled; frames paced by the view's display link, as mygo's, instead of drawn as soon as asked | works (`tests/wrap_test.php`, `tests/lists_test.php`) |
| Images decoded off the main thread (v1.31): `Image::fromBytes` and `fromFile` decode through CoreImage on an NSOperationQueue's thread, the main thread spending about 0.2 ms an image (a HEIC took over 200 ms there); in a fiber (`App::go`) they wait while the app draws, outside one they block as before; HEIC photos come upright as their orientation says (a portrait one showed sideways); the photos and files examples and the Markdown view load images in fibers, the Markdown view showing the description until then, as mygo's | works (`tests/image_test.php`, `tests/markdown_view_test.php`) |
| Underlines skipping ink (v1.32): as AppKit's and mygo's, an underline breaks where g, y, p and the like cross it, a line's thickness clear on either side; Han, Kana, Hangul and spaces do not break it; the glyphs' outlines come from CoreText (`CTFontCreatePathForGlyph`), kept by font and glyph | works (`tests/text_test.php`) |
| Video (v1.33): `Media\Player` plays MKV, WebM, MP4, MOV and M4V (H.264, HEVC; AAC, AC-3, E-AC-3, MP3), PHP reading the packets (Matroska in PHP) and the system decoding and showing them (`AVSampleBufferDisplayLayer`, an audio renderer, a synchronizer), no frame through PHP; a two-hour 1080p film opens in 15–20 ms and plays at 2–3.5 % of a core; seeks land on the time, another audio track goes on from where it was; `Context::video` lets the UI show over the picture (the `video` example: controls that hide, a seek bar, audio tracks, volume, full screen) | works (`tests/media_test.php`, `tests/player_test.php`) |
| Subtitles (v1.34): text tracks of a film (Matroska's SubRip, ASS and SSA, WebVTT; MP4's tx3g) and subtitle files (.srt, .ass, .ssa, .vtt; UTF-8, UTF-16 or GB18030), those beside the film named after it loaded, as IINA does; choosing a track, a delay; the `video` example draws them outlined over the picture and loads a file dropped on it | works (`tests/subtitles_test.php`, `tests/player_test.php`) |
| A player laid out as IINA's (v1.35): system materials under a node (`Node::systemMaterial`: the window's NSVisualEffectView materials, or macOS 26's glass, fading with the node), SF Symbols as images (`Image::symbol`), hiding the traffic lights (`Window::setWindowButtonVisibility`), keeping the window's shape (`Window::setAspectRatio`), `Player::videoTrack`; the `video` example as IINA's window: title bar, glass control bar, OSD, the quick settings sidebar, IINA's default keys | works (`tests/material_test.php`, `tests/image_test.php`, `tests/examples_test.php`) |
| Playing for less CPU (v1.36): the player's timer at four ticks a second, waking at a change of the subtitles between them; the `video` example draws a frame only when what it shows of the time changes, none while the controls are hidden | works (`tests/player_test.php`, `tests/subtitles_test.php`) |
| VP9 and AV1 in hardware, Opus (v1.37): Matroska, WebM and MP4 files of VP9 or AV1 play where the Mac decodes them in hardware (VP9 on Apple silicon, AV1 from M3), with the system's decoders (VP9's vpcC made from its first keyframe); Opus sound, as WebM has it | works (`tests/media_test.php`, `tests/player_test.php`) |
| A video player for apps (v1.38): `Context::videoPlayer` builds IINA's window over a `Media\Player` (title bar, glass control bar, messages, the quick settings sidebar, IINA's keys; `VideoPlayerState`, `VideoPlayerOptions`), in the app's language; `Player::setAspectRatio`, `setCrop`, `setRotation`, `setAudioDelay`; the `video` example is a few lines of it | works (`tests/videoplayer_test.php`, `tests/media_test.php`, `tests/player_test.php`) |
| The wheel and going on where a film was left (v1.39): the video player's wheel sets the volume (up and down) and seeks (across); it goes on from where a film was left (`Media\PlaybackPositions`, `Player::path`, `VideoPlayerOptions::$resume`) | works (`tests/videoplayer_test.php`) |
| The playlist, picture in picture (v1.40): `VideoPlayerState::open` opens films (`Context::videoPlayer($state)`), the films of its folder its playlist (`Media\Playlist`, Finder's order) in a sidebar from the left, the next at an end, Cmd+Right and Cmd+Left; picture in picture from the control bar | works (`tests/videoplayer_test.php`) |
| Chapters, the playlist's loop, shuffle and sort (v1.41): Matroska's and MP4's chapters (`Player::chapters`) in the playlist sidebar's tab, a double click going to one; the loop (the film, the playlist), shuffle and the sort menu as IINA's; menu headers (`UI\Menu::header`); the display kept awake while a film plays (`VideoPlayerOptions::$keepAwake`), the pointer hidden with the controls in full screen too | works (`tests/videoplayer_test.php`, `tests/media_test.php`, `tests/menu_test.php`) |
| FLAC (v1.42): FLAC sound in Matroska and MP4, decoded by the system (its magic cookie the dfLa box AVFoundation makes) | works (`tests/media_test.php`, `tests/player_test.php`) |
| Image subtitles (v1.43): Blu-ray's PGS and DVD's VobSub tracks of Matroska files, decoded in PHP (`Media\Pgs`, `Media\VobSub`) and shown over the picture where they are (`Player::subtitlePictures`, `subtitlePlane`) | works (`tests/subtitles_test.php`, `tests/player_test.php`, `tests/videoplayer_test.php`) |
| ASS in its styles (v1.44): ASS and SSA lines in their fonts, sizes, colors, outlines, shadows and boxes, where they are (alignment, margins, \pos), with their override tags, moving, fading, animated (\t) and sung (\k), turned, scaled and clipped, laid out as libass does (`Media\Ass`, `Player::subtitleLines`) | works (`tests/subtitles_test.php`, `tests/player_test.php`, `tests/videoplayer_test.php`) |
| Subtitles under the glass (v1.45): `Node::belowMaterials` paints a node in a layer of the window's under its system materials and over its videos; the video player's subtitles show under its control bar's glass, as IINA's; outlines and shadows of text (`Node::textOutline`, `textShadow`), its glyphs grown | works (`tests/material_test.php`, `tests/style_more_test.php`) |
| Gliding subtitles (v1.45.1): `animationFrame()` asked as a frame is built keeps the next frame coming (it was lost); a moving ASS line (a danmaku) is drawn at each refresh of the display and moved by a transform, in fractions of a pixel | works (`tests/repaint_test.php`, `tests/videoplayer_test.php`) |
| The player's events, keyframe seeks, Now Playing, frame steps, screenshots (v1.46): `Player::on` tells play, pause, rate, seek and track as they change; `Player::seek($s, exact: false)` goes to the nearest keyframe that way, as IINA's arrow keys, buttons and wheel do (`VideoPlayerState::seekBy`); the film in the system's Now Playing and the media keys, as IINA's (`VideoPlayerOptions::$nowPlaying`); frame steps (`Player::step`, `.` `,`) and screenshots of the frame showing (`Player::snapshot`, ⌘S to ~/Pictures/Screenshots) | works (`tests/player_test.php`, `tests/videoplayer_test.php`) |
| The seek bar's thumbnails (v1.47): over the bar of the time, the time there and a thumbnail of the film, as IINA's; `Player::thumbnail` decodes the keyframe at a time apart from what plays (VideoToolbox, some 5 ms a thumbnail of 1080p, made between frames); kept on disk as IINA keeps them (`Media\ThumbnailCache`) | works (`tests/player_test.php`, `tests/videoplayer_test.php`, `tests/thumbnail_cache_test.php`) |
| Boundaries and the A-B loop (v1.48): `Player::at` tells when it plays past a time, as AVPlayer's boundary observers; IINA's A-B loop (⌘L, `VideoPlayerState::abLoop`), its points knobs on the bar that drag | works (`tests/player_test.php`, `tests/videoplayer_test.php`) |
| Films over HTTP (v1.49): `new Player('https://…/film.mkv')` plays Matroska and WebM as they come, a cache of IINA's size read ahead by libcurl driven by the run loop (`Native\CurlLoop`, `Media\HttpBytes`); paused for the cache as mpv is (the buffering event, `bufferingPercent`), IINA's buffering indicator; File > Open URL in the example | works (`tests/http_bytes_test.php`, `tests/player_test.php`, `tests/videoplayer_test.php`) |
| MP4 over HTTP (v1.50): `new Player('https://…/film.mp4')` plays MP4 and MOV as they come, read in PHP as FFmpeg's mov demuxer reads them (`Media\Mp4`): the movie box at the start or the end, H.264, HEVC, VP9, AV1, AAC, MP3, AC-3, E-AC-3, Opus, FLAC, tx3g subtitles, the edit list, chapters | works (`tests/mp4_test.php`, `tests/player_test.php`) |
| HLS and MPEG-TS (v1.51): `new Player('https://…/master.m3u8')` plays HLS as mpv does: the variant of the highest bandwidth, its audio renditions as audio tracks (only the one played downloaded), MPEG-TS, fragmented MP4 or packed audio (AAC, AC-3) segments, AES-128, live playlists read again as they grow, discontinuities; segments downloaded ahead into IINA's 150 MiB. A local or remote .ts, .m2ts or .mts plays too (`Media\Ts`): H.264, HEVC, AAC, AC-3, E-AC-3, MP3 | works (`tests/hls_test.php`, `tests/ts_test.php`, `tests/player_test.php`) |
| yt-dlp and AV1 in software (v1.52): a web page's URL (YouTube's and the sites yt-dlp reads) plays as IINA plays it: yt-dlp's best video and audio as one (`Media\YtDlp`, `Media\Merged`), with the page's headers, title, chapters, subtitles and playlist; fragmented MP4 (DASH) read and seeked by its index; the two in IINA's one 150 MiB cache; AV1 decoded by libdav1d where the system has no decoder for it, or with `Player::$hardwareDecoding` off, which `myphp build` ships in the app | works (`tests/yt_dlp_test.php`, `tests/dav1d_test.php`, `tests/mp4_test.php`, `tests/player_test.php`) |
| Secondary subtitles and YouTube's ads (v1.53): a second text subtitle track at the top of the picture, plain, with its own delay and switch, as mpv's secondary-sid (`Player::setSecondarySubtitleTrack`); YouTube's and Twitch's pages read by yt-dlp first, as mpv's ytdl_hook, and a video's formats opened once the ad before it is over (yt-dlp's `available_at`), not refused; a node's id is mygo's, its parent's mixed with its place there, so what comes and goes renumbers only the siblings after it (a scroll, a field's focus keep their state) | works (`tests/player_test.php`, `tests/yt_dlp_test.php`, `tests/ids_test.php`) |
| The ad's countdown (v1.54): opening a web page's video after the ad before it, the indicator says the seconds left ("Skipping ad… 13s"), then "Opening stream…" | works (`tests/videoplayer_test.php`) |
| Vorbis and external audio (v1.55): Matroska and WebM's Vorbis audio, which the system has no decoder of, decoded in PHP by the sound API's own codec (`Audio\Vorbis\Codec`) and played as PCM, stereo to 7.1; an audio file loaded beside the film (`Player::loadAudio`, the sidebar's Load External Audio, as IINA's); IINA's ten-band equalizer and its presets (`Player::setEqualizer`), the audio decoded and filtered here only while a band has a gain | works (`tests/soft_audio_test.php`, `tests/equalizer_test.php`, `tests/player_test.php`) |
| The equalizer's profiles (v1.56): IINA's saving, renaming and removing of the equalizer's gains as a profile of one's own, its pop-up's, the name asked as IINA asks it (none, or a preset's or a profile's, cannot be OK'd); `./run.sh video`'s log no longer hangs the app when a dialog stays open over 3 s | works (`tests/videoplayer_test.php`) |
| 10-bit AV1 in software (v1.57): AV1 of 10 bits (HDR's, YouTube's above 1080p) decoded by libdav1d as 8-bit is, given to the display layer as x420 pixel buffers (VideoToolbox's own of HEVC Main 10) with the colors the film says, its thumbnails too; a hardware-decoded film's colors as its container says them (an HDR Matroska AV1 no longer washed out); 60-frame films decoded in software no longer drop frames (the layer asks for each picture as it takes more), and a seek no longer holds the window while libdav1d decodes from the keyframe; a menu button clicked in the player's sidebar no longer keeps Space from play and pause | works (`tests/dav1d_test.php`, `tests/player_test.php`, `tests/media_test.php`, `tests/menu_test.php`) |
| A slider dragged (v1.58): the video given its renderer 10 ms of the main thread at a time, hardware- and software-decoded alike, the window drawn between; an exact seek asked while the last one lands kept and made once it landed, the last of them, as mpv's; a slider's change reported only when its value changes, as mygo's | works (`tests/player_test.php`, `tests/primitives_test.php`) |
| An HDR film's metadata (v1.59): its mastering display and light levels (MaxCLL, MaxFALL), a Matroska's or an MP4's, or in software each AV1 picture's own, given the system's tone mapping, as AVFoundation's formats and mpv's frames carry them | works (`tests/media_test.php`, `tests/dav1d_test.php`) |
| Hardware decoding as it plays (v1.60): the video tab's switch, as IINA's quick setting, decodes an AV1 film on the system's decoder or by libdav1d from where it is, and the next film so too; fixed where only one way decodes; online subtitles of OpenSubtitles (the subtitles tab's Search Online, as IINA's: the film's hash and name, the preferred languages or the system's, IINA's list to choose from), of myphp's key or the app's (`VideoPlayerOptions::$openSubtitlesKey`), an optional account and the preferred languages in the player's settings (`Context::videoPlayerSettings`, IINA's Subtitles pane); the film looked up on TMDB first by what its file's name tells (agc_player's parser: name, year, season, episode), its subtitles by its TMDB id, IINA's search where that finds none | works (`tests/videoplayer_test.php`, `tests/opensubtitles_test.php`) |
| The player's settings window as IINA's (v1.61): its sidebar, search and section index, pages of cards; IINA's General page whole and applied (what a film opening does, pauses as the window is minimized, inactive or the Mac sleeps, keep the window open at the end, legacy full screen and blacked-out screens, the screen saver, resume, history and Open Recent, the playlist, repeat, screenshots' folder, format, clipboard and preview), its Video and Subtitles pages so far, IINA's strings in English and Chinese; the settings by IINA's names (`Media\PlayerSettings`); IINA's welcome and playback history windows (`Context::videoPlayerWelcome`, `Context::videoPlayerHistory`); examples/video in windows as IINA opens them | works (`tests/opensubtitles_test.php`, `tests/videoplayer_windows_test.php`) |
| Every page of the player's settings as IINA's (v1.62): the UI, Video, Audio, Subtitles, Network, Control, Key Bindings, Advanced and Utilities pages, applied (the OSC's layouts and toolbar, the window's size and place, the OSD, thumbnails, picture in picture, HDR, the output device, volume past 100%, ReplayGain, gapless audio, preferred languages, text subtitles' style and place, IINA's matching of subtitle files, encodings, the cache, User-Agent and proxy, yt-dlp, the clicks, pinch, Force Touch and wheel, Set as Default, clearing caches); music mode; IINA's key binding sets and editor carrying out mpv's commands; Live Text by Vision; the app's log (`MyPHP\Logger`) and its viewer (`Context::logViewer`) | works (`tests/videoplayer_test.php`, `tests/videoplayer_keys_test.php`, `tests/subtitle_matcher_test.php`, `tests/logger_test.php`) |
| A seek as the film plays off the main thread (v1.63): the audio renderer's flush and the clock's new time on a serial queue of the system's, no PHP on it, as mpv's play thread; a seek's main thread 74 ms → 12 ms (4K HDR AV1, hardware decoding) | works (`tests/player_test.php`, `tests/videoplayer_test.php`) |
| ASS as libass draws it (v1.64): drawings (`\p`, B-splines too), `\frx` `\fry` in perspective, `\fax` `\fay`, `\blur` `\be`, `\clip` and `\iclip` of rectangles and drawings (a GPU mask), the `\kf` sweep, each run with its own, outlines cut out where the fill is not opaque, BorderStyle 3's box per line; the settings' subtitle Blur; the glyph and image atlases evicting bitmap by bitmap instead of starting over | works (`tests/subtitles_test.php`, `tests/videoplayer_test.php`, `tests/transform_test.php`, `tests/atlas_test.php`) |
| Memos (v1.65): `Context::memo($key, $deps, $build)` keeps a part of the view from frame to frame, neither built, laid out, recorded nor painted again while what it is built from is the same and neither the pointer, the keys nor the focus reach it (a settings page about 6 → 1.3–2.2 ms a frame); the frame's hot spots made cheaper (a translated column of 1000 rows 44 → 37 ms) | works (`tests/memo_test.php`) |
| Metal draws only what changed (v1.66): a frame the same as the last presents nothing; otherwise the rectangles that changed since the drawable was drawn (up to eight, apart from each other) are cleared and drawn again over what it holds, as mygo's; `MYPHP_PARTIAL=0` draws every frame whole | works (`tests/damage_test.php`) |
| mygo's fixes, a first batch (v1.67): double clicks counted per element; documents follow files renamed right after another app saved them; a directory's renames paired by identity (20,000 files: 0.2 s); a row's percentage maximum binds; a diff fitting its content folds before it is drawn; tables, select popups, transitions, scroll bars and the tray as mygo's now; the updater checks at every launch, follows the app's language and relaunches into the update; flyouts' material and corners (`FlyoutOptions::$vibrancy`, `$cornerRadius`), `WindowOptions::$hideWindowButtons`, `PageOptions::$userAgentSuffix`, `Audio::setLatency`, a drag's `Result::$escaped` and `$point`, `ListState::$keepOffset`; wide colors as oklch() in the inspector | works (`tests/click_count_test.php`, `tests/documents_test.php`, `tests/file_watch_test.php`, `tests/flex_test.php`, `tests/code_editor_parts_test.php`, `tests/updater_test.php`) |
| Layout and text as CSS and mygo (v1.68): flexbox as Chrome lays it out (padding floors, shrinking by inner sizes, bases before the least and largest, minimums over maximums, a row's content by its items' bases); `Node::BASELINE` in rows and grids; `Node::hidden()`, out of the layout with its state kept; splits that give way in small windows and `Context::splitPercent`; each Chinese and Japanese character a word; a grid child's percentages of its area; paths past 4096 pixels drawn in tiles | works (`tests/flex_test.php`, `tests/widgets_more_test.php`, `tests/cjk_words_test.php`, `tests/svg_test.php`) |
| The feel of input (v1.69): a mouse wheel's notches glide as browsers scroll them, add up and give way to the app; the wheel passes from a container at its end to the one around it; pinches reach native UI as `InputEvent::ZOOM`; swipes go back and forward; a scroll's momentum is told, which the terminal's Command zoom ignores; `PageOptions::$swipeNavigation` and `$pinchZoom`; drag regions move the window after a quick press and drag; a native drag at a container's edge scrolls it | works (`tests/wheel_test.php`, `tests/window_drag_test.php`, `tests/datadrag_test.php`, `tests/terminal_test.php`) |
| Drawing vocabulary (v1.70): paths stroked with butt and square caps, miter and bevel joins and dashes that stay in place as a path draws itself in; radial gradients (`Node::radialGradient`, `Painter::fillRadialGradient`); `Context::setZoom`, a window's content zoomed sharply, the pointer, menus, drags, input methods and accessibility following; inline objects below the baseline drawn by `Node::drawObjects`, Markdown's math within text through `Options::$inlineMath`; justified and indented paragraphs, soft hyphens, spans shifted off the baseline and `TextLayout::lines()`; rich text's first-line indent, paragraph spacing, paragraph IDs and `Document::visibleParagraphs` | works (`tests/svg_test.php`, `tests/style_test.php`, `tests/zoom_test.php`, `tests/paragraph_test.php`, `tests/text_more_test.php`, `tests/markdown_view_test.php`, `tests/richtext_paragraphs_test.php`) |
| Menus drawn in the window (v1.71): `Node::popoverMenu` and `popoverMenuButton` draw context menus and menu buttons' menus with the theme's look, with the keys, type-ahead, submenus, focus and roles of the system's; radio items (`MenuItem::radio`); icons on menu items, tray menus included (`MenuIcon::of`, `MenuItem::setIcon`); a calendar that browses months without choosing (`Node::browse`), its month chevrons centered | works (`tests/popover_menu_test.php`, `tests/menu_icon_test.php`, `tests/datetime_test.php`) |
| Gradient stops, conic gradients, element blur (v1.72): linear and radial gradients through as many as 32 `GradientStop`s; conic gradients (`ConicGradient`, `Node::conicGradient`, `Painter::fillConicGradient`, `fillPathConicGradient`, `strokePathConicGradient`); borders painted with a gradient (`Node::borderLinearGradient`, `borderConicGradient`); `Node::blur`, a node and its children blurred on the GPU as CSS's `filter: blur()` | works (`tests/gradient_test.php`, `tests/style_test.php`) |
| Choosing by dragging (v1.73): `ListState::$dragSelect` chooses the rows a drag goes over, scrolling near the edges; `$cellSelect` has a table choose blocks of cells (`ColumnRange`, `TableColumn::$rowHeader`, `cellSelected`, `selectedColumns`, `selectColumns`) by clicks, Shift, Cmd, drags and the arrow keys; a right-click chooses its row unless `$menuKeepsSelection`; `$cellMenu` gives cells a context menu, `Context::rowDoubleClicked` lets a cell see its row's double click | works (`tests/drag_select_test.php`) |
| Occlusion culling (v1.74): what a later opaque panel, sheet or popover covers whole is left out of the frame the GPU draws, the pixels the same; the frame statistics count it | works (`tests/cull_test.php`) |
| The terminal and the clipboard (v1.75): programs read the clipboard as `Options::$clipboardRead` allows (deny by default, allow, or ask through `$onClipboardRequest` or the terminal's own prompt); an image alone pastes as an empty bracketed paste, which Claude Code reads screenshots on; Select All copies with `$copyOnSelect`, which `Terminal::setCopyOnSelect` changes live | works (`tests/terminal_test.php`) |
| Distribution (v1.76): `macos.provisioningProfile` embedded before signing, for restricted entitlements such as Communication Notifications; an app's own `InfoPlist.strings` per language kept, so permission prompts show translated; notifications of incoming messages showing their sender's avatar (`NotificationSender`) | works (`tests/build_test.php`, `tests/app_test.php`) |
| File watches (v1.77): FSEvents on macOS, one stream per watched tree, 65536 entries by default; `FileWatchOptions::$hintsOnly` keeps no snapshot and stats no file, reporting `FileHint` events for the directories where something changed ("." after the system lost changes); watches enroll directories only | works (`tests/file_watch_test.php`) |
| Live chart lines (v1.78): `Chart::minMax` keeps the extremes of each pixel column, `Painter::strokePolyline` draws an opaque line as a capsule a segment, `Chart::lineInto` refills a kept path (`examples/chart-streaming`); `TextState::textAnchorCaret`; FSEvents streams start at now again | works (`tests/chart_test.php`, `tests/examples_test.php`) |
| Markdown in linear time (v1.79): the inlines of a paragraph of many lines find their source offsets by halving, and a link closing spends the brackets before it in one step | works (`tests/markdown_parser_test.php`) |
| Shaders (v1.80): mygo's ui/shader, fragment shaders in Go's syntax (Ebitengine's Kage) compiled in PHP to Metal: `Shader::compile`, `draw`, `fill`/`fillPath`/`strokePath`/`text` in place of colors, `Values` and `Uniform`, four images (images or canvases), element effects (`Node::effect`: ColorEffect, DistortionEffect, LayerEffect), the backdrop (`backdropSample`) | works (`examples/shaders`, `tests/shader_test.php`, `tests/shader_compiler_test.php`, `tests/shader_msl_test.php`) |
| Large canvases, styled inputs (v1.81): a canvas up to 8192 pixels a side (one larger than a page of their atlas renders into its own texture), so blackhole renders at the window's pixels; `input()` takes its padding, corners, background, border and shadows, as mygo's TextInput, so wind's composer and chat-glow's field are inputs; a shader draw's uniforms packed in runs (wind's 106 → 7 µs), compiling a quarter faster | works (`tests/canvas_test.php`, `tests/textarea_test.php`, `tests/examples_test.php`, `tests/shader_test.php`) |
| A field's text clipped at its border (v1.82): a field without padding (chat-glow's) shows its first letter whole, as mygo's | works (`tests/textarea_test.php`) |
| A terminal's pointer as Ghostty's, avatars as PNGs (v1.83): the I-beam over a shell, the arrow while a program takes the mouse (the I-beam with Shift), the shape a program asks for with OSC 22; a notification's sender avatar given to the system as a PNG, so an SVG one shows | works (`tests/terminal_test.php`) |
| A terminal's clears, copies, links and drops for apps (v1.84): `Terminal::clearScreen`/`clearScrollback` and `Options::$onClear`, the menu's Clear as ⌘K; `Options::$onCopy` with what copied; the link Command hovers underlined and `Terminal::hoveredLink()`; files dropped paste their paths quoted for the shell; snapshots keep the blank rows at the end; `Terminal::useTheme` answers color questions without a view and tells programs of light and dark (mode 2031) | works (`tests/terminal_test.php`) |
| A terminal's padding and minimum contrast (v1.85): `Options::$padding` and `$paddingColor` (the background, or the edge cells' backgrounds extended, as Ghostty's window-padding-color; a program's own cursor kept in its cell); `Options::$minimumContrast` and `setMinimumContrast`, text darkened or lightened as little as reaches the ratio, shapes keeping their colors | works (`tests/terminal_test.php`) |
| Painting skips what shows nowhere (v1.86): each node's ink (its box, shadows and children, through its transform) found as the frame is recorded, and subtrees whose ink is out of the clip skipped whole: 1000 rows moved out of view paint in 0.7 ms rather than 13.5 | works (`tests/paintcull_test.php`) |
| A terminal tells of commands that finished (v1.87): Ghostty's notify-on-command-finish by the shell's OSC 133 marks: `Options::$notifyOnCommandFinish` (never, unfocused, always), `$notifyOnCommandFinishAction` (the bell, a desktop notification "Command Succeeded/Failed" with how long it took and its exit code) and `$notifyOnCommandFinishAfter` | works (`tests/terminal_test.php`) |
| A shell integration for zsh and bash (v1.88): a zsh or bash started alone reads the user's startup files as it would and marks its prompts and commands with OSC 133, so commands that finish are told of in them too; `Options::$shellIntegration` turns it off | works (`tests/terminal_test.php`) |
| The examples beside mygo's (v1.26): color emoji, tinted images, text areas wrapping a paragraph at a time (a document of 100 000 lines opens at once), padding of text, borders without a background, sliders, disabled controls dimmed, local time | works (`examples/farm`, `examples/text-buffer`, `examples/text-input`) |
| App / Window API: mygo's window methods (showing, focus, minimize, maximize, full screen, bounds and sizes, limits, controls, on top, opacity, shadow, represented file, edited, Dock progress, attention, all Spaces, child windows and sheets) and events | works, with vibrancy, transparency and background colors, panels, flyouts (anchored to a rectangle or a tray icon, flipping and sliding into the screen, closing on a press outside or Escape) or in the system's popovers |
| Router (mygo's): a history of pages with paths and queries, `{name}` and `{name...}` patterns, layouts, pages keeping their state and the focus, sliding and fading in, Back and Forward buttons, Cmd+[ and Cmd+], a mouse's back and forward buttons, links to paths | works (`examples/router`) |
| Draggable nodes (mygo's Draggable): following the pointer in the parent's axes, thrown to snap points at the pointer's speed, bounds rubber-banded or rigid, caught moving, lifting with a pose; values dragged within a window to nodes taking their type, with a copy under the pointer, scrolling at the edges, Escape giving up | works (`examples/drag`) |
| Native drag and drop (mygo's data_drag): data dragged out to other apps in several formats, made lazily; text, URLs, Markdown and other formats dragged in, offered unread and read once dropped; copy and move negotiated; files from the Finder to nodes, then the window's `fileDrop`; a value dropped as it is inside the app; `Window::startDataDrag` | works (`examples/transfer`) |
| Markdown parser (mygo's ui/markdown/parser): CommonMark 0.31.2 and GitHub's extensions (all their examples), footnotes, math; text appended or edited parsed again only where it changed; a streaming reply's half-written end healed | works |
| Markdown view and stream (mygo's ui/markdown): headings, paragraphs with links and footnote references, lists and task lists, quotes, highlighted code blocks with a copy button, tables, images, math and HTML as text or by hooks; selectable, copied as text, HTML and Markdown, or as Markdown with Shift+Cmd+C; a reply being written revealed at an even pace, fading in | works |
| Rich text (mygo's ui/richtext): `Text` of attributed runs in styled paragraphs (headings, lists, task lists, quotes, code blocks, alignment, links, tags, attachments), persistent; `Document` with selection, typing attributes, undo and redo, and the Format commands; Markdown, HTML, RTF and JSON in and out; the editor (`RichText::editor`): word processors' shortcuts, Markdown shortcuts as you type, lists and tasks, undo of formats with the text, input methods, rich copy and paste | works (`examples/richtext`) |
| Terminal (mygo's plugins/terminal): the user's shell, or any program, in a pseudo-terminal, emulated by Ghostty's libghostty-vt (mygo's build, loaded through FFI, downloaded into the cache once); colors, Unicode, the scrollback, full-screen programs, the Kitty keyboard protocol, the mouse, bracketed paste, hyperlinks, titles, OSC 52, pastes a program reads itself; selecting as Ghostty does, keybinds, Ghostty's themes, links opened with Command+click | works (`examples/terminal`) |
| Updates (mygo's Updater): `myphp keygen`, then `myphp build` signs an archive of the app (Ed25519), makes delta updates from the published versions (bsdiff) and writes the manifest, for GitHub releases or a server; `Updater::check()` and `Update::install()` in the app, the bundle replaced, `App::relaunch()`; `App::go()` runs waiting work in a fiber | works |
| updater (mygo's plugins/updater): the update window of Sparkle: background checks once a day, the release notes of a new version with Install, Remind Me Later and Skip This Version, the download's progress, Relaunch; "Check for Updates…" (`Updater::menuItem()`), the user's choices in updater.json, 15 languages; the window as a web page or in native UI (`Native::plugin()`) | works (`examples/updater`) |
| game (mygo's plugins/game): `Game::view()` runs a game in fixed steps (60 a second, or its `rate()`) with its player's keys by position, pointer and wheel, and draws it every frame through a `Painter`; `Camera` (zoom, rotation, whole-pixel panning, `toWorld`, `visible`), `Game::integerFit()` for pixel art; `Gamepad\Gamepad::pads()` reads controllers through GameController.framework in the standard layout | works (`examples/game`) |
| `UI\Canvas` (mygo's): a bitmap a painter draws on the GPU, kept until drawn anew, shown as any image (pixelated for pixel art); `UI\Chart` (mygo's ui/chart): `Linear`, `Band` and `Time` scales with d3's ticks, `Chart::plot` with axes, marks that are nodes (`rect`, `point`, `ruleX`, `ruleY`, `anchor`), lines, areas and pie sectors as paths, stacks, a selection for the pointer and the keys | works (`examples/charts`, `examples/game`) |
| `UI\Direction` (mygo's): right to left for windows in such a language or a subtree of its own (`Node::direction`), logical edges (`paddingStart`, `marginEnd`, `borderStartWidth`…), rows, columns, grids and sideways scrolling mirrored, text from the right, the arrow keys of sliders, ratings, trees, calendars and the like along the line; text fields not yet | works (`MYPHP_DIRECTION=rtl`) |
| `UI\Inspector` (mygo's): developer tools for native UI, docked beside the content: the tree of nodes as markup, the styles, box model and properties of the node chosen or picked in the window, highlighted over it, frame times against the display's, issues such as duplicate keys, a right-to-left toggle; F12, Alt+Cmd+I, the menu role `toggleDevTools`; `PageOptions::$devTools` (`DevTools`), which opens a page's web inspector too; see docs/inspector.md | works |
| `Audio\Audio` (mygo's package audio): sounds decoded into memory that play over themselves, streams decoded a second ahead, `Decoder`s an app writes (a synthesizer); players with volume, pan, speed, loops and loop ranges without a gap, seeking, 5 ms ramps; mixed in PHP into an Audio Queue; WAV and Ogg Vorbis decoded in PHP, MP3, AAC, ALAC, FLAC, AIFF and CAF by the system; see docs/audio.md | works (`examples/piano`) |
| Plugins (mygo's Use), in PHP: `App::use(Plugin)` binds a plugin's service as `plugin:<name>`, its page side a TypeScript client of its own; a `CallContext` parameter tells the calling page and ends as it goes; `App::onQuit` | works |
| File watches (mygo's WatchFiles): `App::watchFiles` on a file or a directory, recursive or not, `Ignore` leaving out what is never opened; batches of create, write, remove and rename once changes stop, a file saved as editors do a write, the end when the root goes away; FSEvents through FFI, or with `hintsOnly` only the directories where something changed | works |
| watch (mygo's plugins/watch): pages watch the directories and files the app names (`src/Plugins/Watch/client/index.ts`, `@myphp-plugins/watch`), recursive only where the app allows, `Resolve` and `Allow`, quotas per page and in all, failures as codes that never say a path | works (`examples/watch`) |
| WebSocket (mygo's plugins/websocket): pages open WebSocket connections from PHP with the browser's API (`src/Plugins/WebSocket/client/index.ts`, `@myphp-plugins/websocket`): any header in the handshake (Authorization, Cookie, Origin), subprotocols, text and binary messages in order, `bufferedAmount`, closing either way, connections ending with their page; libcurl, the system's, through FFI | works (`examples/websocket`) |
| fetch (mygo's plugins/fetch): pages make HTTP requests from PHP with a standard `fetch` (`src/Plugins/Fetch/client/index.ts`): no CORS, any header (Origin, Cookie, Host…), the body streamed as it arrives, aborting stops the request; libcurl, the system's, through FFI | works |
| SQLite (mygo's plugins/sqlite): mygo's build of SQLite 3.53.4 and its shim through FFI; `Sqlite::open`, one statement at a time checked whole before it runs, parameters, `RETURNING`, transactions rolled back on any error, read-only connections, busy timeouts, FTS5, R*Tree and JSON; for pages, `App::use(Sqlite::plugin())` and `src/Plugins/Sqlite/client/index.ts` (`@myphp-plugins/sqlite`), connections owned by their page | works |
| Web pages: `Ipc::bind()` a PHP service, call it from the page, events back, streaming channels, page-side window controls, preload script, generated TypeScript client | works (`examples/webview`) |
| Menu bar with roles and accelerators, context menus, open/save/message dialogs | works (`examples/desktop`) |
| App services (mygo's): deep links (`App::onOpenURL`, `registerURLScheme`, myphp.json's `urlSchemes`) and files opened with the app (`onOpenFile`, `fileAssociations`), a single instance (`requestSingleInstanceLock`, `onSecondInstance`), recent documents, opening at login, `Network` (online, metered), `Clipboard` (data in several formats, files, flush), `Secrets` (the Keychain), `Cookies`; pages: find in page, print, print to PDF, permissions (camera, microphone), downloads | works (`examples/services`) |
| Documents (mygo's): `Documents`, `Document` — a window per file, the File menu's roles (new, open, openRecent, save, saveAs, duplicate, rename, moveTo, revert), autosave in place, recovery copies after a crash, asking before closing and quitting, files changed by other apps, a `DocumentService` for pages; see docs/documents.md | works (`examples/documents`) |
| Custom schemes served by PHP (mygo's `Protocol`): `Protocol::handle`, `ProtocolRequest`/`ProtocolResponse` (streamed bodies), `Protocol::fileServer`; the frontend at myphp://localhost/ (`App::setFrontend`, myphp.json's `devUrl`, `devCommand`, `buildCommand`, `frontendDist`); only trusted pages call PHP (`PageOptions::$trustedOrigins`) | works (`examples/protocol`) |
| Scrolling a node into view (mygo's ScrollIntoView), and the focus Tab brings into view, through nested scrolling containers | works |
| Context menus and menu buttons of the native UI (mygo's ContextMenu, Menu, MenuButton): items, check marks, submenus, shortcuts, the innermost menu, the menu key and Shift+F10, text inputs' and selectable text's editing items, `editItems()` | works |
| Tray icon, global shortcuts, notifications (from a built .app), window events, file drops, remembered window frames | works (`examples/desktop`) |
| Child processes without blocking the UI (`Process`): curl, scripts, tools | works (`examples/http`) |
| SVG: icons as masks in any color (turned by `rotate`), pictures in their own colors — paths, shapes, transforms, gradients, strokes and dashes, opacity, clip paths, masks, style sheets — drawn by a renderer in PHP, which also rasterizes path masks (no CoreGraphics) | works (`examples/widgets`, `files`) |
| Languages and regional formats (mygo's i18n, v1.10): the app's language from the user's (or `App::setLocale`, kept in its defaults); dates, numbers, months, weekdays and day periods in a locale (`I18n`, `DateFormatter`, `NumberFormatter`, ICU through `ext-intl`); CLDR's parents and plural categories; ICU messages (`Message`: plural, selectordinal, select, offsets, number and date styles); the app's catalogs (`Catalog`, `myphp.json` `i18n`), `myphp generate` writing their PHP class and TypeScript module, `myphp i18n check`, `myphp dev --lang`, the pseudo locales en-XA and ar-XB, the bundle's localizations; MyPHP's own strings and menus (en, zh-Hans, zh-Hant) changed by `myphp.<key>` | works (`examples/widgets`) |
| A file manager: sidebar of places, breadcrumbs, the folder as icons or a sortable table sharing one selection, find bar, image preview, Trash through Finder | works (`examples/files`) |
| Code editor (mygo's ui/editor): rope text, undo, several carets and selections, soft wraps, folding, find and replace (PCRE), go to line, completions, hovers, diagnostics, inlay hints and suggestions, the changes against a base in the gutter, Zed's commands and key bindings, input methods at every caret; 28 languages highlighted incrementally; only the lines in view laid out. Diff viewer: split or unified, incremental, words or characters changed, collapsed unchanged lines, Revert and Keep, line selection, annotations, git patches | works (`examples/code-editor`) |
| Accessibility: VoiceOver reads and works every widget, the code editor and the diff viewer (mygo's tree of roles, names, states and actions over `NSAccessibilityElement`s) | new, to try with VoiceOver |
| Transforms (translate, scale, rotate, skew, and in 3D rotateX/rotateY/perspective, backface hidden) and opacity of a node and its children, drawn by the GPU through each quad's map, hit-tested through its inverse (mygo's transform.go) | new |
| Poses (a node moved, scaled, turned and faded after its transform) and content transitions (text rolling as a counter's digits or fading across, icons replaced, pictures crossfading) (mygo's pose.go and content.go) | new |
| Transitions: nodes moving and resizing from where the last frame showed them, entering and leaving (moved, faded, collapsed, scaled, turned), colors fading, matched nodes coming from where their match was (mygo's transition.go) | new |
| Animation: curves, springs, delays, jumps, colors, keyframes and loops by node and key (mygo's motion), timelines of tweens placed along one playhead (paused, sought, reversed, repeated), Reduce Motion; the switch, sliders, collapsibles, accordions and sidebar sections move as mygo's | new |
| Linux and Windows | next |
| Linux (GTK/WebKitGTK), Windows (Win32/WebView2) | planned |

Measured in `examples/inbox 1000` on an M-series Mac: 9 000 nodes rebuilt,
laid out and painted per frame in about 6 ms with real text, 60 fps while
scrolling; frames are only built when something changes.

### The command line

```sh
bin/myphp init my-app              # a native-UI project (-template web for a page calling PHP)
cd my-app && ../myphp/bin/myphp dev   # runs it, restarts on changes, regenerates the TS client
../myphp/bin/myphp build           # build/darwin-arm64/my-app.app and its .dmg, a launcher that execs this machine's php; the plugins' native libraries (the terminal's libghostty-vt) in its Resources
../myphp/bin/myphp build -launcher micro    # one static binary: fetches static-php-cli and builds phpmicro the first time
```

### Requirements

- PHP ≥ 8.3 with `ext-ffi` and `ext-intl` (Homebrew's `php` has both), OPcache JIT recommended
- macOS on Apple Silicon for now

A window with a web page, whose frontend calls PHP:

```php
/** Greeter says hello. */
final class Greeter { public function greet(string $name): string { return "Hello, $name"; } }

Ipc::bind(new Greeter());
App::whenReady(fn() => new Window(new WindowOptions(title: 'Hello', html: '<script>
    myphp.call("Greeter.greet", "Ada").then(s => document.body.textContent = s)</script>')));
App::run();   // MYPHP_GENERATE=src/myphp.ts php app.php writes the typed client instead
```

### Try it

```sh
./run.sh webview          # Notes: a page calling a bound PHP service, with events
./run.sh gen              # writes examples/webview/myphp.ts, the TypeScript client
./run.sh desktop          # menus and dialogs
./run.sh form             # text fields, input methods
./run.sh logview /var/log/system.log   # a log viewer: a table that follows the file, filters, highlights, Cmd+F finds
./run.sh titlebar [hidden|hiddenInset] [x,y]   # a hidden title bar: the view draws its own under the traffic lights (moved to x,y) and drags the window by it
./run.sh files [folder]                # a file manager: places, breadcrumbs, icons or a sortable table, Cmd+F finds, Space previews, Delete… trashes
./run.sh code-editor                   # a code editor: files of several languages, completions of their words, hovers, TODO diagnostics, changes in the gutter, and their diff
./run.sh drag                          # draggable nodes: a card, a puck thrown to stops, a sheet; words dragged into a basket
./run.sh transfer                      # native drag and drop: a note dragged out to other apps; text, links and files dropped in; cards between lists
./run.sh router                        # a router: a sidebar, Back and Forward, links, notes sliding in, a settings layout
./run.sh flyout                        # flyouts: a list of colors under its button, a note editor beside its button, an About box in a system popover
php tests/run.php                      # the headless tests of the UI (no window): layout, keys, focus, the widgets and files examples
php tests/bench.php                    # the cost of a frame, headless: build, layout, record, paint, for the examples' views
./run.sh markdown-notes   # Markdown notes: search, toolbar, resizable panes, live preview, autosave
./run.sh notes            # mygo's notes: rich text notes, a toolbar of their formats, the Format menu, links, Copy Markdown
./run.sh native           # mygo's native: a page calling PHP for dialogs, the clipboard, notifications, a context menu, the tray, a global shortcut, dark mode
./run.sh drag-drop        # mygo's drag-drop: notes, a URL and a file dragged between two windows and other apps
./run.sh richtext         # a rich text editor with a toolbar of its formats
./run.sh terminal         # a terminal: your shell, emulated by Ghostty's libghostty-vt
./run.sh sqlite           # SQLite from a web page through the PHP plugin; Reload shows a connection ends with its page
./run.sh watch [folder]   # a web page watching a folder through the PHP plugin; the buttons change files
./run.sh websocket [url]  # a WebSocket from a web page through the PHP plugin, to an echo server
./run.sh updater --demo   # the update window, with a made-up update to install (--native: in native UI)
./run.sh game             # a little game: WASD, the arrows or a gamepad, the wheel zooms
./run.sh charts           # charts: bars, a live line, stacked areas, a scatter plot, a donut
./run.sh piano            # a piano of synthesized keys: the pointer, the keyboard, a sustain pedal, tunes
./run.sh services         # the app's services from a page: network, clipboard, Keychain, cookies, find, PDF…
./run.sh documents [x.md] # document notes: a window per file, the File menu, autosave, crash recovery
./run.sh protocol         # a frontend at myphp://localhost/, images a scheme draws, a streamed body, a download
./run.sh photos ~/Pictures # a photo browser: thumbnail grid, one full size, arrow keys
./run.sh http             # an HTTP client over curl: requests kept in a list, JSON colored
./run.sh glass            # Liquid Glass over a photo
./run.sh widgets          # the small controls on one page
./run.sh inbox            # native UI, 300-row list, prints fps once a second
./run.sh inbox 1000       # 9 000 nodes per frame
./run.sh hello            # mygo's examples, ported: a page greeting through a bound service
./run.sh counter-native   # a counter in native UI, its buttons and the arrow keys
./run.sh text-buffer 100000 # a text area over a TextBuffer of 100 000 lines
./run.sh text-input       # a text field of the app's own drawing, through TextInputClient
./run.sh frameless        # a frameless, transparent window with vibrancy, its page moving it
./run.sh clipboard        # the clipboard in several formats, one made lazily; data dragged out and dropped in
./run.sh timeline         # a timeline of tweens, placed after each other, at times and labels
./run.sh markdown-chat    # replies streamed token by token as Markdown, revealed word by word
./run.sh embed-webview    # web views inside native UI, and native popovers, selects and modals over them
./run.sh menubar-flyout   # a tray icon's flyout in a system popover
./run.sh menubar-native   # a menu bar app: a window under its tray icon that hides as it loses the focus
./run.sh launcher         # a launcher: a global shortcut, the installed apps ranked as you type, their icons
./run.sh vibrancy         # a window of vibrancy: a sidebar of materials sliding out
./run.sh todo             # a todo list in a Vite page, its typed PHP service and events (needs bun)
./run.sh confetti         # canvas-confetti's demos, every piece moved by poses that only paint
./run.sh effort-slider    # an effort slider stepping into Galaxy, letters blurring as they change
./run.sh farm             # a farming game: till, plant, water, harvest, day and night, music
./run.sh mayoi            # a survivor game: sing at stage fright, gather stones, find your band
./run.sh gallery          # the tour of the toolkit: every widget, lists, charts, motion, languages
```

### Layout

```
bin/myphp     the command line: init, dev, build, micro
src/          App, Window, WindowOptions, Menu, Dialog, Ipc, TypeScript
src/Native/   OS bridges: ObjC runtime, Metal, CoreText, the event view
src/UI/       the toolkit: Context (tree building), Layout (flex), Text, Runtime (input), Painter
examples/     runnable apps
docs/         design notes
AGENTS.md     entry point for coding agents and contributors
```

### License

MIT.

---

## 中文

**用 PHP 写桌面应用，原生 UI 或网页前端二选一。**

MyPHP 把 [mygo](https://github.com/egoist/mygo) 的思路搬到 PHP 8 上：
一个进程、一种语言、不写自己的 C 扩展，系统库全部通过 `ext-ffi` 调用。

每个窗口显示两种界面之一：

- **网页**：用系统自带的 webview（macOS 上是 WKWebView），页面通过类型化的桥接调用 PHP 服务。
- **原生 UI**：纯 PHP 编写，由 MyPHP 自己画到 GPU 上，没有 HTML、JavaScript 和 webview。
  视图是应用状态的函数，每帧重跑（immediate mode），与 mygo 的 `ui` 包同一思路。

一个原生 UI 窗口，视图是应用状态的函数：

```php
use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, Context};

$n = 0;
App::whenReady(function () use (&$n) {
    new Window(new WindowOptions(title: 'Counter', width: 320, height: 200,
        content: Content::view(function (Context $c) use (&$n) {
            $c->column(24, 12, 1);
              $c->text("Count: $n", 20);
              if ($c->clicked($c->button('Increment'))) { $n++; }
            $c->end();
        })));
});
App::run();
```

### 现状

早期。目前在 macOS / Apple Silicon 上能跑的：

| 部分 | 状态 |
|---|---|
| Objective-C 桥（`objc_msgSend`、方法为 PHP 闭包的类） | 可用 |
| WKWebView 窗口，JS → PHP → JS 往返 | 可用（`examples/webview`） |
| 原生 UI：Metal 渲染、CoreText 文字、flex 布局、横竖滚动、点击、hover | 可用（`examples/inbox`、`form`） |
| mygo 的部件和上下文（v1.08）：带操作、ID 和 `closeToast` 的 toast；`openURLThen`；`onClick`、`onChange`、`onSubmit`、`onShortcut`；`Handle` 和 `focusBind`；切换组、带刻度的步进滑块、大纲和大纲表格；列表的 `followAnimation`、表格的 `roundRows` 和 `headerMenu`；路径渐变；`Preferences`（强调色、减少动态效果、增强对比度）；注册字体和 `Font::families`；`Oklch`；`Image::update`；自绘文字控件用的 `TextLayout`；`TextBuffer`；全部无外观的基础部件（`checkboxBase` … `tooltipBase`、`selectBase`、`comboboxBase`、文本输入） | 可用 |
| mygo 的 app、窗口、页面与菜单，v1.09：`Page`（`Window::page()`：loadFile、reload、前进后退、缩放、user agent、`eval`、window.open() 处理、标题/导航/加载/崩溃事件、JavaScript 弹窗以 sheet 显示）；`Event`（`emit`、`emitPage`、`broadcast`）；原生 UI 中的 web view（`Window::newWebView`、`Context::webView`）；页面的偏好设置、语言和标题栏空间；`App` 生命周期事件、激活策略、关于面板、`path`、`exit`；`Dock`、`Shell`、`Screen`、`Theme`；`Power::keepAwake`；可自定义存储的窗口状态；菜单单选项，View/Format/Speech/Services/Window/Help 菜单角色；`Tray::popUpMenu` | 可用 |
| mygo 的元素 API：CSS flexbox（间距、`alignContent`、`wrapReverse`、自动外边距、`justify` 的分布方式、最小最大尺寸、百分比、宽高比），绝对和相对定位的 inset，`attach`/`attachTo` 锚点，网格轨道；每角圆角、任意角度的渐变（sRGB 或 Oklab）、条纹、虚线边框、带扩展的内外阴影、分隔线、`debug`、`invisible`、`passThrough`、可继承的 `disabled`、`focusRing(false)`、滚动条内缩、全部光标；`pointerPosition`、`dragVelocity`、`dragEnded`、`pressedOutside`、`overlayShortcut`、`dismissed`、列表的 `rowWidth` 配合 `scrollWith`、`TextLines`、`textRanges`、`setTextSelection`、`textSelection`、`textAnchorCaret` | 可用 |
| 百万行列表：滚动条、`scrollTo` 平滑滚动、按行 key、选中与键盘导航 | 可用（`examples/inbox`） |
| 原生 UI 里的图片（PNG、JPEG、HEIC、ICNS…），可圆角、可透明、五种适配方式（contain/cover/fill/scale-down/natural）、可灰度 | 可用（`examples/inbox`、`widgets`） |
| 粗体、斜体、等宽文字；富文本 span（颜色、下划线、高亮、换行） | 可用（`examples/inbox`、`logview`） |
| 文字样式沿节点继承（字体族、字号、字重、斜体、行高、对齐、字距、OpenType 特性、语言、下划线、删除线、背景），移植 mygo 的文字系统（CoreText 排版器、光标、选区几何、双向文字、省略号），段落内可嵌链接和带样式的文字 | 可用 |
| 可选中文字（mygo 的 Selectable）：拖选、双击选词、三击选行、Shift 加方向键跨段落扩展选区、复制与全选、`selectedTexts()`、`copyData()` 以多种格式复制 | 可用 |
| 把节点滚动到可见（mygo 的 ScrollIntoView），Tab 移到的焦点也会滚动到可见，嵌套滚动容器逐层处理 | 可用 |
| 原生界面的右键菜单和菜单按钮（mygo 的 ContextMenu、Menu、MenuButton）：菜单项、勾选、子菜单、快捷键提示、最内层菜单优先、菜单键和 Shift+F10、输入框和可选中文字的编辑项、`editItems()` | 可用 |
| 软阴影、描边环；材质（`UI\Material`）；glass 插件（mygo 的 plugins/glass，`Plugins\Glass`）：Liquid Glass（模糊、边缘透镜折射、高光边、着色、按压放大）、沿遮罩渐变的背景模糊、滚动边缘 | 可用（`examples/glass`） |
| 文本框和多行文本区：输入法、选区、自动换行、撤销、Edit 菜单、Tab 焦点；复选框、单选、滑块、下拉选择、链接、进度条、提示 | 可用（`examples/form`） |
| 开关、步进器、分段控件、标签页、折叠区、可拖分栏、表格（按一列或多列排序，列可拖宽、双击适配内容、拖动换位，过宽时横向滚动）、分组侧栏、树、手风琴、星级、范围滑块、工具栏（含开关按钮）、模态对话框、弹出面板、菜单按钮、toast 提示、搜索框、数字输入、水平指示条、加载指示、徽标、分隔线、占位；单选组、原地编辑的文字（表格里的文件名）、分节标题吸顶且可输入首字母选行的列表、截取部分长度的路径、着色位图、逐个排版和绘制的字形 | 可用（`examples/widgets`、`markdown-notes`、`http`、`gallery`） |
| 主题：跟随系统明暗和强调色，实时切换 | 可用 |
| `myphp init / dev / build`：脚手架、改动重启、.app 打包（exec 或静态 micro 启动器） | 可用 |
| 与 mygo 一致的打包（v1.12）：`-platform darwin/arm64,darwin/amd64,darwin/universal`，resources 目录及按平台的子目录（通用二进制自动合并），PNG 或 asset catalog 生成图标，由内而外代码签名，排好窗口布局的 .dmg，公证，`-upload` 到 GitHub Releases 或 S3；`myphp doctor`、`install-skills`、`version`、`help` | 可用（签名、.dmg、公证要在开发者的 Mac 上跑） |
| 与 mygo 一致的开发流程（v1.13）：`myphp dev` 运行 "<name> Dev"，一个带应用图标和资源的 bundle，改动后自动重建，终端里 `r`/`c`/`q`/`h`；SIGINT、SIGTERM 让应用像 Quit 一样退出；`myphp.config.ts`；页面用的包 `myphp-runtime`（`packages/runtime`，生成的 client 和插件都从这里 import）和 `myphp-cli`（`packages/cli`）；Vite + TypeScript 的 web 模板 | 可用（开发版应用要在开发者的 Mac 上跑） |
| 与 mygo 一致的控件和按键（v1.14）：密码框；对话框打开时窗口快捷键不生效；列表按条目的 key 保持位置、选中项和行状态，滚进未测量的行时按步长精确滚动；按键滚动指针下的容器，列表里 Page Up/Down 像 AppKit 一样只滚动；步进器左右键、滑块 Page Up/Down；键盘焦点也显示提示，按下或 Esc 隐藏；文本框 Control-K；JPEG 照片按 EXIF 方向摆正；侧栏输入首字母选择；其他键盘布局下快捷键按键位匹配；`MYPHP_FRAME_STATS` | 可用（照片解码、帧统计和其他布局的按键要在开发者的 Mac 上验证） |
| 按 mygo 测试补齐的列表（v1.15）：任意行高无缝隙，滚轮或按键按步长精确滚动，`scrollTo` 按实测高度对齐到顶部、居中或底部，跟随末尾（新行或行变高时，可配 FollowAnimation 平滑），焦点所在行滚出视野仍保留，Tab 会把下一行滚进来，滚动条拖动与行的位置一致，行停在吸顶分节标题之下，滚动容器里的列表高度等于所有行 | 可用 |
| 按 mygo 测试补齐的文本框和 toast（v1.16）：逐步撤销（移动光标结束一步），撤销连同应用对输入的改写，应用追加文本时视图停在滚轮所在处，文本框失焦时显示开头、按 `textAlign()` 对齐，焦点离开时 text input client 取消标记，toast 在指针悬停和窗口转入后台时暂停计时，焦点在 toast 里时 Esc 关闭它 | 可用 |
| 按 mygo 测试补齐的基础控件、样式和窗口（v1.17）：焦点进入应用自建的模态层，禁用的滑块不响应，`onTextKey` 只接收不产生文字的按键，按住时保持悬停，网格按 align 对齐单元格，文本区占位符自动换行，表单标签对齐文本区第一行 | 可用 |
| 按 mygo 测试补齐的从右到左布局、指示器、日期和组合框（v1.18）：文字在列中按对齐方式铺满，范围滑块、分栏、输入框、弹出层和菜单镜像，从右到左段落里按词移动跟随箭头方向，步进器和范围滑块响应快速点击，颜色色板按应用语言命名，组合框弹出列表的辅助功能和滚动与 AppKit 一致 | 可用 |
| 按 mygo 测试补齐的焦点作用域、变换、SVG 和网页视图（v1.19）：下拉选择改用 mygo 的窗口内弹出列表，弹出层在 Tab 顺序中紧跟锚点，裁剪区域经变换后也限制指针，光标和文本输入客户端的几何经变换换算，自定义节点可接收输入法文字（`textCaret`），图标继承周围的字号和颜色 | 可用 |
| 按 mygo 测试补齐的多选、表格、控件和行内文字（v1.20）：列表报告选择变化，按字母选行与 AppKit 一致，Shift 扩选跟随原条目，按下当帧即显示结果，`clickModifiers()`，表格分组标题横跨各列、连续选中行圆角连成一块，日历和取色器弹层保持自身宽度 | 可用 |
| 按 mygo 测试补齐的基础组件、表单、反馈、开关和主题（v1.21）：焦点在复选框时 Enter 先交给窗口快捷键，Esc 不再丢失焦点，手风琴每个标题都是一个 Tab 停靠点、方向键在标题间移动，工具栏溢出菜单标出已按下的开关，构建后才禁用的 fieldset 也禁用其内容，强调色计算与 mygo 一致，未指定语言的文字按应用语言排版，无头测试绘制工具提示 | 可用 |
| 按 mygo 测试补齐的大纲、重绘、滚动、选区和跟随末尾（v1.22）：after() 按帧时钟计时，转圈指示器只在下一根辐条时重绘，容器在视口外时其绝对定位或变换后的子节点仍会绘制，输入框支持文字背景色、选区色和文字颜色、光标用强调色，富文本也有内容过渡动画，滚动偏移始终限制在内容范围内、`scrollTo` 可指定动画，跟随末尾的列表在改变高度和删行时不再错位 | 可用 |
| 按 mygo 测试补齐的粘贴、文本行、行内对象和文本缓冲（v1.23）：编辑菜单命令统一经由运行时处理（菜单和 Cmd+V 都会触发 `onPaste`，输入框支持撤销和重做），行内对象在排版时就占据自身宽度，文本区排版前也能估算行位置，文本缓冲正确计算非法 UTF-8，未启用选择的列表也能拖动排序 | 可用 |
| 文字按布局分配的宽度自动折行，与 mygo 一致（v1.24）：在列中折行；在行中可收缩到最长的单词再折行；中文可在字间断行；配合 `maxLines()` 限制行数；窗口改变大小时当帧重新折行 | 可用 |
| 广色域颜色，与 mygo 一致（v1.25）：超出 sRGB 的 `Oklch::rgb` 颜色保留 Display P3 色值，在 P3 屏幕上以 float16 绘制填充、阴影、文字和装饰线，其他情况绘制最接近的 sRGB 颜色；`WideColor::srgb` 取 sRGB 近似色 | 可用（`examples/gallery` 的 Wide colors） |
| Go 的随机数（v1.27）：`Random\Rand::pcg($a, $b)` 与 Go 的 `rand.New(rand.NewPCG(a, b))` 产生相同序列（IntN、Float64、Shuffle、Perm、NormFloat64…），带种子的例子与 mygo 一致；移植 mygo 的 `native`、`notes`、`drag-drop` 例子；文字按字体平滑绘制（颜色越浅越粗，与 AppKit 一致）；边框在布局中占位；按钮文字可单独设样式；无论 php.ini 怎么设，日期都用系统时区 | 可用（`examples/farm`、`notes`、`native`、`drag-drop`） |
| 在 Mac 上逐个点按例子（v1.28）：托盘图标留在菜单栏；拖动预览显示元素本来的颜色；弹出框不再打乱其后节点的 id，notes 的链接输入框能接收键入；去掉 v1.27 移植的 Go 随机数，例子改回 PHP 自带的引擎；`-launcher micro` 构建的 app 重新可以签名（phar 放进可执行文件的 `__LINKEDIT`），并带上框架用到的 intl、xmlreader、curl、sockets、filter（`myphp micro` 会重新编译一次 micro.sfx），phar 里的翻译目录也能读到；out 位于 frontendDist 内时拒绝构建，todo 例子与 mygo 一样输出到 `build`；第一次播放声音不再闪退（音频队列地址改用 `ObjC::addr`）；静态 app 的终端和前端页面能从 phar 里运行；14 个例子与 mygo 逐对比对：输入框高度与 mygo 一致且支持 `fontSize()`，打字后 `changed()` 能报告，select 有最小宽度，web view 不再盖住外框边框，禁用按钮的边框不再消失，列表可用 `justify(END)` 把行贴到底部；flyout 例子改为 mygo 的版本 | 可用（`examples/native`、`drag-drop`、`notes`；`myphp build -launcher micro`） |
| 与 mygo 一样的发布流程（v1.29）：每次 push 由 CI 跑 PHP 和 Bun 测试（`.github/workflows/ci.yml`）；推 `v<版本>` tag 时检查 bin/myphp 和各 npm 包的版本一致（`php scripts/version.php` 负责设置），用 npm 的 Trusted Publishing 发布 myphp-runtime、myphp-cli 和插件的 `@myphp-plugins/*`，并创建 GitHub Release（`release.yml`）；从 npm 装的 myphp-cli 会运行项目里 Composer 装的 MyPHP（`vendor/owner888/myphp/bin/myphp`）；npm 包里不再带测试文件 | 可用（插件包的第一次发布和 npm 上的 Trusted Publisher 配置由开发者在 npmjs.com 完成） |
| 布局速度找回文字折行之前的水平（v1.30）：放在太窄的行里的文字，最长的单词不再被拆开（之前最小内容宽度算成了一个字形的宽度，每个字形都排成一行）；每段可能折行的文字，其最小内容宽度直接取自测量它的那一行，不再每帧重新排版：2000 段文字的布局从 14 ms 降到 4 ms，百万行列表从 0.35 ms 降到 0.12 ms；每帧被替换掉的节点树立即释放，不再等 PHP 的垃圾回收（滚动列表时回收会造成掉帧）；改为按显示器刷新出帧（视图的 display link，与 mygo 一致），不再一有变化就立刻出帧 | 可用（`tests/wrap_test.php`、`tests/lists_test.php`） |
| 图片解码挪出主线程（v1.31）：`Image::fromBytes` / `fromFile` 交给 CoreImage 在 NSOperationQueue 的线程上解码，主线程每张图只花约 0.2 ms（之前一张 HEIC 要 200 ms 以上）；在 fiber（`App::go`）里等待时 app 照常绘制，fiber 外照旧阻塞到解完；HEIC 照片按自身方向转正（之前竖拍的会横着显示）；photos、files 例子和 Markdown 视图都在 fiber 里加载图片，Markdown 在图片就绪前显示描述，与 mygo 一致 | 可用（`tests/image_test.php`、`tests/markdown_view_test.php`） |
| 下划线避开字母下伸部分（v1.32）：与 AppKit、mygo 一样，下划线在 g、y、p 等字形穿过它的地方断开，两侧各留一个线宽；汉字、假名、谚文和空格不断开；字形轮廓来自 CoreText（`CTFontCreatePathForGlyph`），按字体、字形缓存 | 可用（`tests/text_test.php`） |
| 视频播放（v1.33）：`Media\Player` 播放 MKV、WebM、MP4、MOV、M4V（H.264、HEVC；AAC、AC-3、E-AC-3、MP3），PHP 只读数据包（MKV 由 PHP 解析），解码和显示都交给系统（`AVSampleBufferDisplayLayer` 与音频渲染器、同步器），画面不经过 PHP；两小时的 1080p 影片打开 15–20 ms，播放时进程占 2–3.5 % CPU；跳转落在精确时间，切换音轨从当前位置接上；`Context::video` 让界面叠在画面上（示例 `video`：控件自动隐藏、进度条、音轨、音量、全屏） | 可用（`tests/media_test.php`、`tests/player_test.php`） |
| 字幕（v1.34）：内嵌文字字幕（MKV 的 SRT、ASS/SSA、WebVTT，MP4 的 tx3g）和外挂字幕文件（.srt、.ass、.ssa、.vtt，UTF-8、UTF-16 或 GB18030）；影片旁边同名的字幕文件自动加载，与 IINA 一致；切换字幕轨、字幕延迟；示例 `video` 在画面上画带描边的字幕，拖入字幕文件即可加载 | 可用（`tests/subtitles_test.php`、`tests/player_test.php`） |
| 仿 IINA 的播放器（v1.35）：节点下的系统材质（`Node::systemMaterial`：窗口的 NSVisualEffectView 各材质或 macOS 26 的玻璃，随节点淡入淡出）、SF Symbols 图像（`Image::symbol`）、隐藏红绿灯（`Window::setWindowButtonVisibility`）、锁定窗口比例（`Window::setAspectRatio`）、`Player::videoTrack`；示例 `video` 按 IINA 窗口布局：标题栏、玻璃控制条、OSD、快速设置侧边栏、IINA 默认快捷键 | 可用（`tests/material_test.php`、`tests/image_test.php`、`tests/examples_test.php`） |
| 播放更省 CPU（v1.36）：播放器定时器降到每秒 4 次，两次之间字幕变化时按时唤醒；示例 `video` 只在显示的时间有变化时才画新帧，控制条隐藏时不画 | 可用（`tests/player_test.php`、`tests/subtitles_test.php`） |
| VP9、AV1 硬件解码与 Opus（v1.37）：Mac 能硬件解码时（VP9 所有 Apple 芯片，AV1 需 M3 起），MKV、WebM、MP4 里的 VP9、AV1 交给系统解码器播放（VP9 的 vpcC 由首个关键帧生成）；支持 WebM 常用的 Opus 音频 | 可用（`tests/media_test.php`、`tests/player_test.php`） |
| 给应用用的视频播放器（v1.38）：`Context::videoPlayer` 在 `Media\Player` 上搭出 IINA 式窗口（标题栏、玻璃控制条、OSD、快速设置侧边栏、IINA 快捷键；`VideoPlayerState`、`VideoPlayerOptions`），界面文字跟随应用语言；`Player::setAspectRatio`、`setCrop`、`setRotation`、`setAudioDelay`；示例 `video` 只剩几十行 | 可用（`tests/videoplayer_test.php`、`tests/media_test.php`、`tests/player_test.php`） |
| 滚轮与续播（v1.39）：播放器上下滚动调音量、左右滚动拖进度；记住每部片子停在哪里，下次打开接着播（`Media\PlaybackPositions`、`Player::path`、`VideoPlayerOptions::$resume`） | 可用（`tests/videoplayer_test.php`） |
| 播放列表与画中画（v1.40）：`VideoPlayerState::open` 打开影片（`Context::videoPlayer($state)`），同文件夹的影片按 Finder 顺序成为播放列表（`Media\Playlist`），左侧侧边栏显示，播完自动下一集，⌘→ / ⌘← 切换；控制条可开画中画 | 可用（`tests/videoplayer_test.php`） |
| 章节与播放列表的循环、随机、排序（v1.41）：读取 MKV、MP4 的章节（`Player::chapters`），显示在播放列表侧边栏的章节页，双击跳转；单集循环、列表循环、随机播放和排序菜单与 IINA 一致；菜单支持小标题（`UI\Menu::header`）；播放时阻止熄屏（`VideoPlayerOptions::$keepAwake` 可关），全屏时鼠标也随控制条隐藏 | 可用（`tests/videoplayer_test.php`、`tests/media_test.php`、`tests/menu_test.php`） |
| FLAC（v1.42）：MKV、MP4 里的 FLAC 音频交给系统解码（magic cookie 按 AVFoundation 的 dfLa 盒子生成） | 可用（`tests/media_test.php`、`tests/player_test.php`） |
| 图形字幕（v1.43）：MKV 里蓝光的 PGS、DVD 的 VobSub 字幕轨，用 PHP 解码（`Media\Pgs`、`Media\VobSub`），按原位置叠在画面上（`Player::subtitlePictures`、`subtitlePlane`） | 可用（`tests/subtitles_test.php`、`tests/player_test.php`、`tests/videoplayer_test.php`） |
| ASS 样式（v1.44）：ASS、SSA 字幕按样式表显示字体、字号、颜色、描边、阴影和底框，按对齐、边距、\pos 定位，支持常用行内标签，以及移动、淡入淡出、\t 动画、卡拉 OK、旋转、缩放和裁切，排版照 libass（`Media\Ass`、`Player::subtitleLines`） | 可用（`tests/subtitles_test.php`、`tests/player_test.php`、`tests/videoplayer_test.php`） |
| 字幕在玻璃下面（v1.45）：`Node::belowMaterials` 把节点画进窗口里一个位于系统材质之下、视频之上的图层；播放器的字幕因此在控制条玻璃下面，和 IINA 一致；文字描边和阴影（`Node::textOutline`、`textShadow`），由字形自身外扩生成 | 可用（`tests/material_test.php`、`tests/style_more_test.php`） |
| 平滑的移动字幕（v1.45.1）：构建帧时调用 `animationFrame()` 现在能请求到下一帧（之前会丢）；移动的 ASS 字幕（弹幕）按屏幕刷新率逐帧绘制，用变换按小数像素平移 | 可用（`tests/repaint_test.php`、`tests/videoplayer_test.php`） |
| 播放器事件、关键帧 seek、正在播放、逐帧、截图（v1.46）：`Player::on` 新增 play、pause、rate、seek、track 事件，变化时各通知一次；`Player::seek($s, exact: false)` 按方向跳到最近的关键帧，方向键、前进后退按钮和滚轮与 IINA 一样用它（`VideoPlayerState::seekBy`）；影片显示在系统的"正在播放"里，并响应媒体键，与 IINA 一致（`VideoPlayerOptions::$nowPlaying`）；逐帧前进后退（`Player::step`，`.` `,`）和当前帧截图（`Player::snapshot`，⌘S 存到 ~/Pictures/Screenshots） | 可用（`tests/player_test.php`、`tests/videoplayer_test.php`） |
| 进度条缩略图（v1.47）：鼠标悬停在进度条上时显示该处的时间和画面缩略图，与 IINA 一致；`Player::thumbnail` 用 VideoToolbox 单独解码指定时间的关键帧，不影响正在播放的画面（1080p 每张约 5 毫秒，在两帧之间生成）；缩略图照 IINA 缓存到磁盘（`Media\ThumbnailCache`） | 可用（`tests/player_test.php`、`tests/videoplayer_test.php`、`tests/thumbnail_cache_test.php`） |
| 时间点回调与 A-B 循环（v1.48）：`Player::at` 在播放经过某个时间点时回调，对应 AVPlayer 的边界时间观察者；IINA 的 A-B 循环（⌘L，`VideoPlayerState::abLoop`），循环点以小滑块显示在进度条上，可拖动 | 可用（`tests/player_test.php`、`tests/videoplayer_test.php`） |
| 网络播放（v1.49）：`new Player('https://…/film.mkv')` 边下边播 Matroska 和 WebM，由 run loop 驱动的 libcurl 预读到与 IINA 同样大小的缓存（`Native\CurlLoop`、`Media\HttpBytes`）；缓存不足时像 mpv 一样暂停等待（buffering 事件、`bufferingPercent`），并显示 IINA 的缓冲提示；示例应用加了“打开网址” | 可用（`tests/http_bytes_test.php`、`tests/player_test.php`、`tests/videoplayer_test.php`） |
| 网络 MP4（v1.50）：`new Player('https://…/film.mp4')` 边下边播 MP4 和 MOV，像 FFmpeg 的 mov 解复用器一样用 PHP 读取（`Media\Mp4`）：moov 在开头或结尾都行，支持 H.264、HEVC、VP9、AV1、AAC、MP3、AC-3、E-AC-3、Opus、FLAC、tx3g 字幕、编辑列表和章节 | 可用（`tests/mp4_test.php`、`tests/player_test.php`） |
| HLS 和 MPEG-TS（v1.51）：`new Player('https://…/master.m3u8')` 像 mpv 一样播放 HLS：选最高码率的变体，它的音轨分组作为音轨列出（只下载正在播的那条），支持 MPEG-TS、fMP4 和纯音频（AAC、AC-3）分段、AES-128、会持续刷新的直播列表和不连续点；分段预读进与 IINA 同样大小的 150 MiB 缓存。本地或网络的 .ts、.m2ts、.mts 也能直接播（`Media\Ts`）：H.264、HEVC、AAC、AC-3、E-AC-3、MP3 | 可用（`tests/hls_test.php`、`tests/ts_test.php`、`tests/player_test.php`） |
| yt-dlp 与 AV1 软解（v1.52）：网页地址（YouTube 及 yt-dlp 支持的站点）像 IINA 一样播放：yt-dlp 选出的最佳视频和音频合并播放（`Media\YtDlp`、`Media\Merged`），带上页面的请求头、标题、章节、字幕和播放列表；读取并按索引定位分片 MP4（DASH）；视频、音频两路合用 IINA 的一个 150 MiB 缓存；系统没有 AV1 解码器时（或 `Player::$hardwareDecoding` 关闭时）用 libdav1d 软解 AV1，`myphp build` 会把它打包进 app | 可用（`tests/yt_dlp_test.php`、`tests/dav1d_test.php`、`tests/mp4_test.php`、`tests/player_test.php`） |
| 副字幕与 YouTube 广告（v1.53）：第二条文字字幕显示在画面顶部，纯文本，有独立的延迟和开关，和 mpv 的 secondary-sid 一致（`Player::setSecondarySubtitleTrack`）；YouTube、Twitch 的网页照 mpv 的 ytdl_hook 先交给 yt-dlp；视频前有广告时，等广告时长过去再打开各路格式（yt-dlp 的 `available_at`），不再被拒（HTTP 403）；节点 id 改用 mygo 的规则（父节点 id 与自身位置混合），内容出现或消失只影响其后的兄弟节点，滚动位置、输入焦点不再错乱 | 可用（`tests/player_test.php`、`tests/yt_dlp_test.php`、`tests/ids_test.php`） |
| 广告倒数（v1.54）：打开前面带广告的网页视频时，加载提示显示广告剩余秒数（「正在跳过广告… 13 秒」），之后显示「正在打开流媒体……」 | 可用（`tests/videoplayer_test.php`） |
| Vorbis 与外挂音轨（v1.55）：系统没有 Vorbis 解码器，Matroska、WebM 里的 Vorbis 音频改用音频模块自带的 PHP 解码器（`Audio\Vorbis\Codec`）解码，以 PCM 播放，支持立体声到 7.1；可在侧栏「Load External Audio」加载外部音频文件，与影片同步播放（`Player::loadAudio`，和 IINA 一致）；IINA 的十段均衡器及其预设（`Player::setEqualizer`），只有某段增益不为 0 时才自行解码并滤波，平直时走原来的播放路径 | 可用（`tests/soft_audio_test.php`、`tests/equalizer_test.php`、`tests/player_test.php`） |
| 均衡器自定义配置（v1.56）：和 IINA 一样，可在均衡器弹出菜单里把当前增益保存为自定义配置，并重命名、删除；命名框与 IINA 相同，名字为空或与预设、已有配置重名时不能确定；修复 `./run.sh video` 的调试日志在弹窗停留超过 3 秒时卡死程序 | 可用（`tests/videoplayer_test.php`） |
| 10-bit AV1 软件解码（v1.57）：10-bit AV1（HDR 片源及 YouTube 1080p 以上常用）和 8-bit 一样由 libdav1d 解码，以 x420 像素缓冲（与 VideoToolbox 解 HEVC Main 10 的格式相同）交给显示层，带上片源标注的色彩信息，缩略图也支持；硬件解码时按容器标注的色彩信息显示（HDR 的 Matroska AV1 不再发灰）；软件解码 60 帧影片不再持续掉帧（显示层能接收时主动要帧），拖动进度时也不再因 libdav1d 从关键帧解码而卡住窗口；修复点过播放器侧栏里的下拉按钮后空格不再播放/暂停 | 可用（`tests/dav1d_test.php`、`tests/player_test.php`、`tests/media_test.php`、`tests/menu_test.php`） |
| 拖动进度（v1.58）：硬解和软解的视频都分片交给渲染器，每次最多占主线程 10 ms，期间照常绘制窗口；上一次精确跳转还没完成时，新的跳转只保留最后一次，完成后再执行，和 mpv 一致；滑块只在值真正变化时才报告 changed，和 mygo 一致 | 可用（`tests/player_test.php`、`tests/primitives_test.php`） |
| HDR 元数据（v1.59）：把片源的母版显示参数和亮度信息（MaxCLL、MaxFALL）交给系统做色调映射，来源是 Matroska 或 MP4 容器，软件解码时优先用每帧 AV1 码流自带的，和 AVFoundation、mpv 的做法一致 | 可用（`tests/media_test.php`、`tests/dav1d_test.php`） |
| 播放中切换硬件解码（v1.60）：视频页的开关和 IINA 的快速设置一样，可在播放中把 AV1 影片在系统硬解和 libdav1d 软解之间切换，从当前位置继续，下一部影片沿用；只支持一种解码方式的影片开关固定不可切换；OpenSubtitles 在线字幕（字幕页的「在线查找」，和 IINA 一样按文件哈希和文件名搜索，语言用偏好语言或系统语言，多个结果时弹出 IINA 的选择列表），默认使用 myphp 内置 key，也可由应用提供（`VideoPlayerOptions::$openSubtitlesKey`）；设置窗口里可选填 OpenSubtitles 账号、设置偏好语言（`Context::videoPlayerSettings`，对应 IINA 的字幕设置页）；先按文件名解析出片名、年份、季集（移植 agc_player 的解析器）去 TMDB 查到 tmdb id，再按 id 搜字幕，查不到时回到 IINA 的哈希加文件名搜索 | 可用（`tests/videoplayer_test.php`、`tests/opensubtitles_test.php`） |
| 播放器设置窗口对齐 IINA（v1.61）：侧栏、搜索、小节索引、卡片式页面；IINA 的「通用」页全部做完并生效（打开媒体时的行为、最小化/失焦/休眠时暂停、播放完保持窗口、旧式全屏和其他屏幕黑屏、屏保、续播、播放历史和最近打开、播放列表、循环、截图的位置/格式/剪贴板/预览），「视频」「字幕」页暂有部分，文案用 IINA 的中英文字符串；设置按 IINA 的键名存取（`Media\PlayerSettings`）；IINA 的欢迎窗口和播放历史窗口（`Context::videoPlayerWelcome`、`Context::videoPlayerHistory`）；examples/video 按 IINA 的方式多窗口打开 | 可用（`tests/opensubtitles_test.php`、`tests/videoplayer_windows_test.php`） |
| 播放器设置全部对齐 IINA（v1.62）：「界面」「视频」「音频」「字幕」「网络」「控制」「按键绑定」「高级」「工具」页全部做完并生效（OSC 布局和工具栏、窗口初始大小位置、OSD、缩略图、画中画、HDR、输出设备、超过 100% 的音量、ReplayGain、无缝播放、首选语言、文本字幕样式和位置、IINA 的外挂字幕匹配、编码、缓存、User-Agent 和代理、yt-dlp、各种点击、捏合、Force Touch 和滚轮、设为默认应用、清除缓存）；音乐模式；IINA 的按键绑定套件和编辑器，执行 mpv 命令；基于 Vision 的 Live Text；应用日志（`MyPHP\Logger`）和日志查看器（`Context::logViewer`） | 可用（`tests/videoplayer_test.php`、`tests/videoplayer_keys_test.php`、`tests/subtitle_matcher_test.php`、`tests/logger_test.php`） |
| 播放中跳转不再卡主线程（v1.63）：音频渲染器的 flush 和时钟的新时间交给系统的串行队列去做，那个线程上不跑 PHP，做法同 mpv 的播放线程；一次跳转占用主线程从 74 ms 降到 12 ms（4K HDR AV1，硬解） | 可用（`tests/player_test.php`、`tests/videoplayer_test.php`） |
| ASS 按 libass 的方式绘制（v1.64）：绘图（`\p`，含 B 样条）、带透视的 `\frx` `\fry`、`\fax` `\fay`、`\blur` `\be`、矩形和绘图的 `\clip` `\iclip`（GPU 遮罩）、`\kf` 扫色，每个片段各用自己的参数；填充半透明时描边挖空；BorderStyle 3 底框按行绘制；字幕设置里的模糊；字形和图片图集按单个位图淘汰，不再整块重建 | 可用（`tests/subtitles_test.php`、`tests/videoplayer_test.php`、`tests/transform_test.php`、`tests/atlas_test.php`） |
| 子树复用（v1.65）：`Context::memo($key, $deps, $build)` 让界面的一部分跨帧保留，只要构建它所依赖的值不变、指针、按键和焦点都没有触及它，就不再重新构建、布局、记录和绘制（设置页每帧约 6 → 1.3–2.2 ms）；每帧的热点也做了优化（1000 行平移列 44 → 37 ms） | 可用（`tests/memo_test.php`） |
| Metal 只重绘变化的部分（v1.66）：与上一帧完全相同的帧不提交；否则只把这个 drawable 上次绘制以来变化的矩形（最多八个，互不相邻）清掉重画，其余保留，与 mygo 相同；`MYPHP_PARTIAL=0` 每帧整帧绘制 | 可用（`tests/damage_test.php`） |
| 对照 mygo 的修复，第一批（v1.67）：双击按元素计数；别的应用保存后立刻改名的文件，文档也能跟上；目录改名按文件标识配对（2 万个文件 0.2 s）；行里百分比的最大宽度生效；按内容撑开的 diff 在绘制前就折叠未改动的行；表格、下拉弹出层、过渡动画、滚动条和托盘与 mygo 一致；更新器每次启动都检查、跟随应用语言、重启进入新版本；flyout 的材质和圆角（`FlyoutOptions::$vibrancy`、`$cornerRadius`）、`WindowOptions::$hideWindowButtons`、`PageOptions::$userAgentSuffix`、`Audio::setLatency`、拖拽结束的 `Result::$escaped` 和 `$point`、`ListState::$keepOffset`；检查器里广色域颜色显示为 oklch() | 可用（`tests/click_count_test.php`、`tests/documents_test.php`、`tests/file_watch_test.php`、`tests/flex_test.php`、`tests/code_editor_parts_test.php`、`tests/updater_test.php`） |
| 布局和文字对齐 CSS 与 mygo（v1.68）：flexbox 按 Chrome 的方式排布（不小于内边距、按内尺寸收缩、基准尺寸不受最小最大值影响、最小值压过最大值、行按子项的 basis 算内容宽度）；行和网格支持 `Node::BASELINE` 基线对齐；`Node::hidden()` 移出布局但保留状态；分栏在小窗口里让出空间，新增 `Context::splitPercent`；中文和日文每个字算一个词；网格子项的百分比按所在区域算；超过 4096 像素的路径分块绘制 | 可用（`tests/flex_test.php`、`tests/widgets_more_test.php`、`tests/cjk_words_test.php`、`tests/svg_test.php`） |
| 输入手感（v1.69）：鼠标滚轮每一格平滑滚动，和浏览器一样，连续多格会累加，应用自己滚动时立即接管；内层容器滚到头后滚轮交给外层；触控板捏合以 `InputEvent::ZOOM` 传给原生 UI；左右轻扫前进后退；滚动带惯性标记，终端的 Command 缩放会忽略惯性；`PageOptions::$swipeNavigation` 和 `$pinchZoom`；快速按下拖动拖拽区也能移动窗口；原生拖拽停在容器边缘时自动滚动 | 可用（`tests/wheel_test.php`、`tests/window_drag_test.php`、`tests/datadrag_test.php`、`tests/terminal_test.php`） |
| 绘图能力（v1.70）：路径描边支持平头和方头线帽、尖角和斜角连接、虚线，路径逐渐画出时虚线位置不变；径向渐变（`Node::radialGradient`、`Painter::fillRadialGradient`）；`Context::setZoom` 清晰缩放窗口内容，指针、菜单、拖拽、输入法和辅助功能都跟着换算；内联对象可以伸到基线以下，由 `Node::drawObjects` 绘制，Markdown 通过 `Options::$inlineMath` 绘制行内公式；两端对齐和首行缩进的段落、软连字符、偏离基线的文字片段和 `TextLayout::lines()`；富文本的首行缩进、段间距、段落 ID 和 `Document::visibleParagraphs` | 可用（`tests/svg_test.php`、`tests/style_test.php`、`tests/zoom_test.php`、`tests/paragraph_test.php`、`tests/text_more_test.php`、`tests/markdown_view_test.php`、`tests/richtext_paragraphs_test.php`） |
| 窗口内绘制的菜单（v1.71）：`Node::popoverMenu` 和 `popoverMenuButton` 用主题样式在窗口里绘制右键菜单和菜单按钮的菜单，键盘操作、首字母跳转、子菜单、焦点和辅助功能角色与系统菜单一致；单选菜单项（`MenuItem::radio`）；菜单项图标，托盘菜单也支持（`MenuIcon::of`、`MenuItem::setIcon`）；日历可以只翻月份而不选中日期（`Node::browse`），翻月箭头居中 | 可用（`tests/popover_menu_test.php`、`tests/menu_icon_test.php`、`tests/datetime_test.php`） |
| 多色标渐变、圆锥渐变、元素模糊（v1.72）：线性和径向渐变最多支持 32 个 `GradientStop` 色标；圆锥渐变（`ConicGradient`、`Node::conicGradient`、`Painter::fillConicGradient`、`fillPathConicGradient`、`strokePathConicGradient`）；用渐变绘制边框（`Node::borderLinearGradient`、`borderConicGradient`）；`Node::blur` 在 GPU 上模糊节点及其子节点，效果同 CSS 的 `filter: blur()` | 可用（`tests/gradient_test.php`、`tests/style_test.php`） |
| 拖选（v1.73）：`ListState::$dragSelect` 拖过哪些行就选中哪些行，靠近边缘时自动滚动；`$cellSelect` 让表格选中一块单元格（`ColumnRange`、`TableColumn::$rowHeader`、`cellSelected`、`selectedColumns`、`selectColumns`），支持单击、Shift、Cmd、拖动和方向键；右键会先选中所在行，`$menuKeepsSelection` 可保留原选择；`$cellMenu` 给单元格加右键菜单，`Context::rowDoubleClicked` 让单元格得知所在行被双击 | 可用（`tests/drag_select_test.php`） |
| 遮挡剔除（v1.74）：被后面不透明的面板、表单或弹出层完全盖住的内容不再交给 GPU 绘制，画面完全一致；帧统计会记录剔除数量 | 可用（`tests/cull_test.php`） |
| 终端与剪贴板（v1.75）：程序读剪贴板按 `Options::$clipboardRead` 处理（默认拒绝，可允许，或通过 `$onClipboardRequest` 或终端自带的提示框询问）；剪贴板里只有图片时发送空的 bracketed paste，Claude Code 借此读取截图；全选也按 `$copyOnSelect` 复制，`Terminal::setCopyOnSelect` 可随时切换 | 可用（`tests/terminal_test.php`） |
| 分发（v1.76）：签名前嵌入 `macos.provisioningProfile`，供 Communication Notifications 等受限权限使用；保留 App 自带的各语言 `InfoPlist.strings`，权限弹窗的说明文字能按系统语言显示；来信通知可以显示发送者头像（`NotificationSender`） | 可用（`tests/build_test.php`、`tests/app_test.php`） |
| 文件监视（v1.77）：macOS 改用 FSEvents，每棵监视的目录树一条流，默认上限 65536 个条目；`FileWatchOptions::$hintsOnly` 不保留快照、不 stat 文件，只给出发生变化的目录的 `FileHint` 事件（系统丢了变化时给 "."）；监视只登记目录 | 可用（`tests/file_watch_test.php`） |
| 实时图表折线（v1.78）：`Chart::minMax` 只保留每个像素列的最高点和最低点，`Painter::strokePolyline` 把不透明折线逐段画成胶囊形，`Chart::lineInto` 复用已有路径（`examples/chart-streaming`）；`TextState::textAnchorCaret`；FSEvents 流重新从当前时刻开始 | 可用（`tests/chart_test.php`、`tests/examples_test.php`） |
| Markdown 线性时间解析（v1.79）：多行段落里的行内元素用二分查找源码偏移；一个链接闭合时，一步就让它之前的方括号全部失效 | 可用（`tests/markdown_parser_test.php`） |
| 着色器（v1.80）：mygo 的 ui/shader，用 Go 语法（Ebitengine 的 Kage）写片段着色器，在 PHP 里编译成 Metal：`Shader::compile`、`draw`，以 `fill`/`fillPath`/`strokePath`/`text` 代替颜色填充，`Values` 和 `Uniform`，四张图片（图片或 canvas），元素特效（`Node::effect`：ColorEffect、DistortionEffect、LayerEffect），读取背后画面（`backdropSample`） | 可用（`examples/shaders`、`tests/shader_test.php`、`tests/shader_compiler_test.php`、`tests/shader_msl_test.php`） |
| 大 canvas、可设置样式的输入框（v1.81）：canvas 每边最大 8192 像素（超过图集一页的 canvas 渲染到独立纹理），blackhole 按窗口实际像素渲染；`input()` 采用设置的内边距、圆角、背景、边框和阴影，与 mygo 的 TextInput 一致，wind 的输入框和 chat-glow 的输入栏改用 input；着色器每次绘制的 uniform 按连续段一次打包（wind 从 106 µs 降到 7 µs），编译快约四分之一 | 可用（`tests/canvas_test.php`、`tests/textarea_test.php`、`tests/examples_test.php`、`tests/shader_test.php`） |
| 输入框文字按边框裁剪（v1.82）：没有左右内边距的输入框（如 chat-glow 的输入栏）第一个字母不再被遮住一部分，与 mygo 一致 | 可用（`tests/textarea_test.php`） |
| 终端指针与 Ghostty 一致、头像转 PNG（v1.83）：shell 上显示 I 形光标，程序接管鼠标时显示箭头（按住 Shift 仍是 I 形），程序用 OSC 22 指定的指针形状照样显示；通知发送者头像先转成 PNG 再交给系统，SVG 头像也能显示 | 可用（`tests/terminal_test.php`） |
| 终端的清屏、复制、链接、拖放与配色接口（v1.84）：`Terminal::clearScreen`/`clearScrollback` 和 `Options::$onClear`，右键菜单的「清除」与 ⌘K 相同；`Options::$onCopy` 告知复制内容和来源；按住 Cmd 悬停的链接加下划线，`Terminal::hoveredLink()` 返回链接和位置；拖入文件会粘贴按 shell 规则转义的路径；快照保留屏幕末尾的空行；`Terminal::useTheme` 让没有视图的终端也能回答颜色查询，并在明暗切换时通知订阅的程序（mode 2031） | 可用（`tests/terminal_test.php`） |
| 终端内边距与最小对比度（v1.85）：`Options::$padding` 和 `$paddingColor`（内边距显示背景色，或像 Ghostty 的 window-padding-color 那样把边缘单元格的背景色延伸进去；程序自绘的光标不外延）；`Options::$minimumContrast` 和 `setMinimumContrast`，文字与背景对比度不足时以最小幅度调深或调浅，制表符、色块等图形保持原色 | 可用（`tests/terminal_test.php`） |
| 跳过不可见的绘制（v1.86）：记录每帧时算出每个节点的绘制范围（自身、阴影和子节点，含变换），范围完全在裁剪区外的子树整棵跳过：移出视野的 1000 行，一次绘制从 13.5 ms 降到 0.7 ms | 可用（`tests/paintcull_test.php`） |
| 终端命令结束通知（v1.87）：按 shell 的 OSC 133 标记实现 Ghostty 的 notify-on-command-finish：`Options::$notifyOnCommandFinish`（从不、未聚焦时、总是）、`$notifyOnCommandFinishAction`（响铃、桌面通知「命令执行成功/失败」，附运行时长和退出码）、`$notifyOnCommandFinishAfter` | 可用（`tests/terminal_test.php`） |
| zsh 与 bash 的 shell 集成（v1.88）：单独启动的 zsh 或 bash 照常读取用户的启动文件，并用 OSC 133 标记提示符和命令，命令结束通知在这两个 shell 里也能用；`Options::$shellIntegration` 可关闭 | 可用（`tests/terminal_test.php`） |
| 对照 mygo 逐个运行例子后修正（v1.26）：彩色 emoji、带着色的图片、文本区按段落折行（10 万行文档即时打开）、文字内边距、无背景的边框、滑块、禁用控件变暗、本地时区 | 可用（`examples/farm`、`examples/text-buffer`、`examples/text-input`） |
| App / Window API：mygo 的窗口方法（显示、焦点、最小化、最大化、全屏、位置与尺寸、尺寸限制、窗口按钮、置顶、透明度、阴影、关联文件、已编辑标记、Dock 进度、请求注意、所有桌面、子窗口与 sheet）和事件 | 可用，含 vibrancy、透明窗口和背景色、面板、flyout（锚定到矩形或托盘图标，空间不够时翻转、滑回屏幕内，点外部或 Esc 关闭），也可以用系统 popover 样式 |
| 路由（mygo 的 Router）：带路径和查询的页面历史、`{name}` 与 `{name...}` 模式、嵌套布局、页面保留状态和焦点、滑入淡入、前进后退按钮、Cmd+[ 与 Cmd+]、鼠标侧键、指向路径的链接 | 可用（`examples/router`） |
| 可拖动节点（mygo 的 Draggable）：按父节点坐标跟随指针、按松手速度甩到吸附点、边界橡皮筋或硬边界、移动中可接住、配合 pose 抬起；窗口内拖拽值到接收该类型的节点，指针下有半透明副本，靠近边缘自动滚动，Esc 取消 | 可用（`examples/drag`） |
| 系统拖放（mygo 的 data_drag）：把数据以多种格式拖到其他应用，格式按需生成；从其他应用拖入文本、链接、Markdown 等格式，悬停时只看格式不读内容，放下才读取；复制/移动协商；Finder 文件先给节点，再给窗口的 `fileDrop`；应用内拖放直接拿到原值 | 可用（`examples/transfer`） |
| Markdown 解析器（mygo 的 ui/markdown/parser）：CommonMark 0.31.2 和 GitHub 扩展（规范示例全部通过）、脚注、公式；追加或编辑的文本只重新解析改动处；流式回复末尾写到一半的语法自动补全 | 可用 |
| Markdown 视图与流式视图（mygo 的 ui/markdown）：标题、带链接和脚注引用的段落、列表和任务列表、引用、带复制按钮的高亮代码块、表格、图片，公式和 HTML 按文本显示或交给钩子；可选中，复制为文本、HTML 和 Markdown，Shift+Cmd+C 复制为 Markdown；正在生成的回复匀速显示、渐入 | 可用 |
| 富文本（mygo 的 ui/richtext）：带属性的文本段、带样式的段落（标题、列表、任务列表、引用、代码块、对齐、链接、标签、附件），持久化数据结构；`Document` 管选区、输入属性、撤销重做和格式命令；Markdown、HTML、RTF、JSON 双向转换；编辑器（`RichText::editor`）：文字处理软件的快捷键、边打边转的 Markdown 快捷写法、列表和任务、格式随文本一起撤销、输入法、富文本复制粘贴 | 可用（`examples/richtext`） |
| 终端（mygo 的 plugins/terminal）：在伪终端里运行用户的 shell 或任意程序，由 Ghostty 的 libghostty-vt 模拟（mygo 编好的库，经 FFI 加载，首次运行下载到缓存）；颜色、Unicode、回滚缓冲、全屏程序、Kitty 键盘协议、鼠标、括号粘贴、超链接、标题、OSC 52、程序自己读取的粘贴；像 Ghostty 一样选择文本、快捷键绑定、Ghostty 的主题、Command+点击打开链接 | 可用（`examples/terminal`） |
| 自动更新（mygo 的 Updater）：`myphp keygen` 生成密钥，`myphp build` 给应用的归档签名（Ed25519），从已发布的版本做增量更新（bsdiff）并写出清单，发布到 GitHub Releases 或自己的服务器；应用里 `Updater::check()`、`Update::install()` 替换整个 bundle，`App::relaunch()` 重启；`App::go()` 在 Fiber 里跑会等待的工作 | 可用 |
| updater（mygo 的 plugins/updater）：Sparkle 式的更新窗口：每天后台检查一次，新版本显示发布说明并提供「安装更新」「以后提醒我」「跳过此版本」，下载进度，「立即重新启动」；菜单项「检查更新…」（`Updater::menuItem()`），用户选择存在 updater.json，15 种语言；窗口可以是网页，也可以用原生 UI 绘制（`Native::plugin()`） | 可用（`examples/updater`） |
| game（mygo 的 plugins/game）：`Game::view()` 以固定步长（每秒 60 步，或游戏自己的 `rate()`）运行游戏，按键位读取按键，另有指针和滚轮，每帧经 `Painter` 绘制；`Camera`（缩放、旋转、按整像素平移、`toWorld`、`visible`），像素画用 `Game::integerFit()`；`Gamepad\Gamepad::pads()` 经 GameController.framework 按标准布局读取手柄 | 可用（`examples/game`） |
| `UI\Canvas`（mygo 的）：画笔在 GPU 上绘制的位图，重画之前一直保留，像图片一样显示（像素画可 pixelated）；`UI\Chart`（mygo 的 ui/chart）：按 d3 取刻度的 `Linear`、`Band`、`Time` 比例尺，带坐标轴的 `Chart::plot`，本身就是节点的标记（`rect`、`point`、`ruleX`、`ruleY`、`anchor`），折线、面积和扇形路径，堆叠，指针和键盘都能移动的选择 | 可用（`examples/charts`、`examples/game`） |
| `UI\Direction`（mygo 的）：从右到左书写语言的窗口或单独一棵子树（`Node::direction`），逻辑边（`paddingStart`、`marginEnd`、`borderStartWidth`…），行、列、网格和横向滚动镜像，文字从右起，滑块、评分、树、日历等的方向键顺着行走；文本框尚未支持 | 可用（`MYPHP_DIRECTION=rtl`） |
| `UI\Inspector`（mygo 的）：原生 UI 的开发者工具，停靠在内容旁边：节点树（标记形式）、所选或在窗口中拾取的节点的样式、盒模型和属性并在内容上高亮、对照显示器刷新率的帧耗时、重复 key 等问题、从右到左开关；F12、Alt+Cmd+I、菜单角色 `toggleDevTools`；`PageOptions::$devTools`（`DevTools`）也打开网页的 Web 检查器；见 docs/inspector.md | 可用 |
| `Audio\Audio`（mygo 的 audio 包）：解码进内存、可叠加播放的音效，提前一秒解码的流，应用自己写的 `Decoder`（合成器）；播放器支持音量、声像、速度、无缝循环和循环区间、跳转、5 ms 渐变；在 PHP 里混音后送进 Audio Queue；WAV 和 Ogg Vorbis 用 PHP 解码，MP3、AAC、ALAC、FLAC、AIFF、CAF 用系统解码器；见 docs/audio.md | 可用（`examples/piano`） |
| 插件（mygo 的 Use），PHP 插件：`App::use(Plugin)` 把插件的服务绑定为 `plugin:<名字>`，网页侧是插件自带的 TypeScript 客户端；`CallContext` 参数告诉方法是哪个页面调用的，页面离开时结束；`App::onQuit` | 可用 |
| 文件监视（mygo 的 WatchFiles）：`App::watchFiles` 监视文件或目录，可递归，`Ignore` 排除的目录从不打开；改动停下后成批给出 create、write、remove、rename，编辑器式保存算一次 write，根目录没了监视即结束；经 FFI 调用 FSEvents；`hintsOnly` 只报告发生变化的目录 | 可用 |
| watch（mygo 的 plugins/watch）：网页按名字监视应用开放的目录和文件（`src/Plugins/Watch/client/index.ts`，`@myphp-plugins/watch`），只在应用允许处递归，`Resolve` 与 `Allow`，每页和总数配额，失败只给代码、从不暴露路径 | 可用（`examples/watch`） |
| WebSocket（mygo 的 plugins/websocket）：网页用浏览器同款 API 从 PHP 建 WebSocket 连接（`src/Plugins/WebSocket/client/index.ts`，`@myphp-plugins/websocket`）：握手可带任意请求头（Authorization、Cookie、Origin），子协议，文本和二进制消息按序发送，`bufferedAmount`，双方都可关闭，连接随页面结束；用系统自带的 libcurl，经 FFI | 可用（`examples/websocket`） |
| fetch（mygo 的 plugins/fetch）：网页用标准的 `fetch`（`src/Plugins/Fetch/client/index.ts`）从 PHP 发 HTTP 请求：没有 CORS 限制，任意请求头（Origin、Cookie、Host…），响应体边收边流给页面，abort 即停止请求；用系统自带的 libcurl，经 FFI | 可用 |
| SQLite（mygo 的 plugins/sqlite）：mygo 编好的 SQLite 3.53.4 和它的 C shim，经 FFI 调用；`Sqlite::open`，每次一条语句、执行前整体校验，参数绑定，`RETURNING`，出错整体回滚的事务，只读连接，忙等超时，FTS5、R*Tree、JSON；网页用 `App::use(Sqlite::plugin())` 和 `src/Plugins/Sqlite/client/index.ts`（`@myphp-plugins/sqlite`），连接归调用它的页面 | 可用 |
| 网页：`Ipc::bind()` 绑定 PHP 服务，页面直接调用，事件回推，流式 channel，页面侧窗口控制，preload 脚本，生成 TypeScript client | 可用（`examples/webview`） |
| 菜单栏（角色、快捷键）、右键菜单、打开/保存/消息对话框 | 可用（`examples/desktop`） |
| App 服务（mygo 的）：深链接（`App::onOpenURL`、`registerURLScheme`、myphp.json 的 `urlSchemes`）与用本应用打开的文件（`onOpenFile`、`fileAssociations`）、单实例（`requestSingleInstanceLock`、`onSecondInstance`）、最近文档、登录时启动、`Network`（是否联网、是否按流量计费）、`Clipboard`（多格式数据、文件、flush）、`Secrets`（钥匙串）、`Cookies`；网页：页内查找、打印、打印为 PDF、权限（摄像头、麦克风）、下载 | 可用（`examples/services`） |
| 文档型应用（mygo 的）：`Documents`、`Document`——每个文件一个窗口、文件菜单的角色（new、open、openRecent、save、saveAs、duplicate、rename、moveTo、revert）、原地自动保存、崩溃后的恢复副本、关闭和退出前询问、被其他应用改动的文件、给网页用的 `DocumentService`；见 docs/documents.md | 可用（`examples/documents`） |
| PHP 服务的自定义 scheme（mygo 的 `Protocol`）：`Protocol::handle`、`ProtocolRequest`/`ProtocolResponse`（可流式输出）、`Protocol::fileServer`；前端在 myphp://localhost/（`App::setFrontend`，myphp.json 的 `devUrl`、`devCommand`、`buildCommand`、`frontendDist`）；只有受信任的页面能调用 PHP（`PageOptions::$trustedOrigins`） | 可用（`examples/protocol`） |
| 托盘图标、全局快捷键、通知（需打包的 .app）、窗口事件、文件拖放、记住窗口位置 | 可用（`examples/desktop`） |
| 不阻塞界面的子进程（`Process`）：curl、脚本、命令行工具 | 可用（`examples/http`） |
| SVG：图标按蒙版任意着色（`rotate` 可旋转），图片按自身颜色绘制——路径、基本图形、变换、渐变、描边与虚线、不透明度、裁剪、遮罩、样式表——由纯 PHP 渲染器绘制，路径蒙版也由它栅格化（不再用 CoreGraphics） | 可用（`examples/widgets`、`files`） |
| 语言与地区格式（mygo 的 i18n，v1.10）：应用语言随用户设置（或 `App::setLocale`，记在应用 defaults 里）；按 locale 写日期、数字、月份、星期和上下午（`I18n`、`DateFormatter`、`NumberFormatter`，通过 `ext-intl` 用 ICU）；CLDR 的父 locale 和复数类别；ICU 消息（`Message`：plural、selectordinal、select、offset、数字和日期样式）；应用自己的目录（`Catalog`，`myphp.json` 的 `i18n`），`myphp generate` 生成 PHP 类和 TypeScript 模块，`myphp i18n check`，`myphp dev --lang`，伪 locale en-XA 和 ar-XB，bundle 的本地化目录；MyPHP 自带文案和菜单（en、zh-Hans、zh-Hant）可用 `myphp.<key>` 改写 | 可用（`examples/widgets`） |
| 文件管理器：常用位置侧栏、路径面包屑、目录以图标或可排序表格显示且共用一个选择、查找条、图片预览、经 Finder 移到废纸篓 | 可用（`examples/files`） |
| 无障碍：VoiceOver 可朗读并操作所有控件、代码编辑器和 diff 查看器（mygo 的角色、名称、状态与动作树，基于 `NSAccessibilityElement`） | 新加，待 VoiceOver 实测 |
| 节点及其子节点的变换（平移、缩放、旋转、斜切，以及 3D 的 rotateX/rotateY/perspective、背面隐藏）和透明度，由 GPU 按每个 quad 的映射绘制，命中测试走逆映射（mygo 的 transform.go） | 新加 |
| 姿态（在变换之后平移、缩放、旋转、淡出节点）和内容过渡（文字像计数器数字那样滚动或淡入淡出、图标替换、图片交叉淡化）（mygo 的 pose.go 和 content.go） | 新加 |
| 过渡：节点从上一帧的位置和尺寸移动、缩放到新布局，进场和离场（平移、淡入淡出、折叠、缩放、旋转），颜色渐变，匹配节点从对应节点原来的位置过来（mygo 的 transition.go） | 新加 |
| 动画：按节点和键的曲线、弹簧、延迟、跳变、颜色、关键帧和循环（mygo 的 motion），沿同一播放头编排补间的时间线（可暂停、拖动定位、倒放、重复），遵循「减弱动态效果」；开关、滑块、折叠区、手风琴和侧边栏分组按 mygo 的方式动起来 | 新加 |
| Linux 和 Windows | 下一步 |
| Linux（GTK/WebKitGTK）、Windows（Win32/WebView2） | 计划中 |

在 M 系列 Mac 上用 `examples/inbox 1000` 实测：每帧重建、布局并绘制 9 000 个节点（含真实文字）约 6 ms，滚动时 60 fps；没有变化时不构建帧。

### 命令行

```sh
bin/myphp init my-app              # 原生 UI 项目（-template web 是调用 PHP 的网页项目）
cd my-app && ../myphp/bin/myphp dev   # 运行，文件改动自动重启，重新生成 TS client
../myphp/bin/myphp build           # build/darwin-arm64/my-app.app 和它的 .dmg，启动器 exec 本机的 php；插件要的原生库（终端的 libghostty-vt）放进 Resources
../myphp/bin/myphp build -launcher micro    # 单个静态二进制：第一次自动下载 static-php-cli 并构建 phpmicro
```

### 环境要求

- PHP ≥ 8.3，带 `ext-ffi` 和 `ext-intl`（Homebrew 的 `php` 都自带），建议开 OPcache JIT
- 目前仅支持 Apple Silicon 的 macOS

一个网页窗口，前端直接调用 PHP：

```php
/** Greeter says hello. */
final class Greeter { public function greet(string $name): string { return "Hello, $name"; } }

Ipc::bind(new Greeter());
App::whenReady(fn() => new Window(new WindowOptions(title: 'Hello', html: '<script>
    myphp.call("Greeter.greet", "Ada").then(s => document.body.textContent = s)</script>')));
App::run();   // MYPHP_GENERATE=src/myphp.ts php app.php 则只生成带类型的 client
```

### 运行

```sh
./run.sh webview          # Notes：页面调用绑定的 PHP 服务，带事件
./run.sh gen              # 生成 examples/webview/myphp.ts（TypeScript client）
./run.sh desktop          # 菜单和对话框
./run.sh form             # 文本框、输入法
./run.sh logview /var/log/system.log   # 日志查看器：跟随文件增长、过滤、高亮、Cmd+F 查找
./run.sh titlebar [hidden|hiddenInset] [x,y]   # 隐藏标题栏：视图在红绿灯下自己画标题栏（x,y 可挪红绿灯），拖它移动窗口
./run.sh files [目录]                   # 文件管理器：常用位置、路径面包屑、图标或可排序表格、Cmd+F 查找、空格预览、Delete… 移到废纸篓
./run.sh drag                          # 可拖动：卡片、甩到吸附点的圆块、底部弹出面板；把词拖进篮子
./run.sh transfer                      # 系统拖放：把便签拖到其他应用；拖入文本、链接和文件；卡片在两列间拖动
./run.sh router                        # 路由：侧栏、前进后退、链接、笔记滑入、设置页布局
./run.sh flyout                        # flyout：按钮下的颜色列表、按钮旁的便签编辑框、系统 popover 里的 About 框
php tests/run.php                      # UI 的无窗口测试：布局、按键、焦点、widgets 和 files 例子
php tests/bench.php                    # 无窗口压测：一帧的 build / layout / record / paint 耗时
./run.sh markdown-notes   # Markdown 笔记：搜索、工具栏、可拖分栏、实时预览、自动保存
./run.sh notes            # mygo 的 notes：富文本笔记、格式工具栏、格式菜单、链接、复制为 Markdown
./run.sh native           # mygo 的 native：网页调用 PHP，演示对话框、剪贴板、通知、右键菜单、托盘、全局快捷键、深色模式
./run.sh drag-drop        # mygo 的 drag-drop：笔记、URL 和文件在两个窗口及其他应用之间拖放
./run.sh richtext         # 富文本编辑器，带格式工具栏
./run.sh terminal         # 终端：你的 shell，由 Ghostty 的 libghostty-vt 模拟
./run.sh sqlite           # 网页经 PHP 插件用 SQLite；点 Reload 可以看到连接随页面结束
./run.sh watch [folder]   # 网页经 PHP 插件监视一个文件夹；按钮会改动其中的文件
./run.sh websocket [url]  # 网页经 PHP 插件连 WebSocket，默认连一个回显服务器
./run.sh updater --demo   # 更新窗口，用一个虚构的更新演示安装流程（--native：原生 UI 版）
./run.sh game             # 一个小游戏：WASD、方向键或手柄移动，滚轮缩放
./run.sh charts           # 图表：柱状图、实时折线、堆叠面积、散点图、环形图
./run.sh piano            # 合成音色的钢琴：鼠标、键盘、延音踏板、自动演奏曲子
./run.sh services         # 从网页调用 App 服务：网络、剪贴板、钥匙串、Cookie、页内查找、PDF…
./run.sh documents [x.md] # 文档型笔记：每个文件一个窗口、文件菜单、自动保存、崩溃恢复
./run.sh protocol         # myphp://localhost/ 上的前端、自定义 scheme 画的图、流式响应、下载
./run.sh photos ~/Pictures # 照片浏览：缩略图网格、单张全尺寸、方向键翻页
./run.sh http             # HTTP 客户端（curl 子进程）：请求列表、JSON 着色
./run.sh glass            # 照片上的 Liquid Glass
./run.sh widgets          # 小控件合集
./run.sh inbox            # 原生 UI，300 行列表，每秒打印一次 fps
./run.sh inbox 1000       # 每帧 9 000 个节点
./run.sh hello            # 移植自 mygo 的示例：页面通过绑定的服务打招呼
./run.sh counter-native   # 原生 UI 计数器：按钮和方向键
./run.sh text-buffer 100000 # 基于 TextBuffer 的十万行文本区
./run.sh text-input       # 应用自己绘制的文本框，经 TextInputClient 接输入法
./run.sh frameless        # 无边框透明窗口加 vibrancy，页面拖动窗口
./run.sh clipboard        # 多种格式的剪贴板（其一延迟生成）；数据拖出与拖入
./run.sh timeline         # tween 时间轴：先后、定时、按标签排布
./run.sh markdown-chat    # 回复按 token 流式到达，以 Markdown 逐词显现
./run.sh embed-webview    # 原生 UI 里的 web view，其上叠原生 popover、下拉和模态框
./run.sh menubar-flyout   # 托盘图标的 flyout，放在系统 popover 里
./run.sh menubar-native   # 菜单栏应用：托盘图标下的窗口，失去焦点即隐藏
./run.sh launcher         # 启动器：全局快捷键，边输边排序的已装应用及其图标
./run.sh vibrancy         # vibrancy 窗口：材质侧栏滑出收起
./run.sh todo             # Vite 页面里的待办：带类型的 PHP 服务与事件（需要 bun）
./run.sh confetti         # canvas-confetti 的演示，每片纸屑由只重绘的 pose 驱动
./run.sh effort-slider    # 力度滑杆，越过 Max 进入 Galaxy，字母变化时模糊
./run.sh farm             # 种田小游戏：翻地、播种、浇水、收获，昼夜与音乐
./run.sh mayoi            # 幸存者小游戏：对舞台恐惧唱歌、收集石子、找齐乐队
./run.sh gallery          # 工具包总览：所有控件、列表、图表、动效、语言
```

### 目录

```
bin/myphp     命令行：init、dev、build、micro
src/          App、Window、WindowOptions、Menu、Dialog、Ipc、TypeScript
src/Native/   操作系统桥接：ObjC 运行时、Metal、CoreText、事件视图
src/UI/       工具包：Context（建树）、Layout（flex）、Text、Runtime（输入）、Painter
examples/     可运行的示例
docs/         设计笔记
AGENTS.md     编码代理与贡献者的入口
```

### 许可

MIT。
