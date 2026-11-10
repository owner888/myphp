# Manual checks

What CI cannot check, to be gone through on a real machine once the
platform's work is done. CI's runners have no GPU, no sound device, no
one to press a key or look at a window: a test there proves a path runs,
not that a person sees or hears the right thing. Each check says how to
run it and what should happen; tick it once it does, or note what did
instead.

## Windows

0. [ ] **Running and building the app** (`bin\myphp.cmd` is the command
   on Windows): `myphp doctor` shows the Windows section (build, WebView2,
   FFmpeg, a C compiler) right; `myphp devices` lists the Windows desktop;
   `myphp run -d windows <dir>` runs an app with its log in the terminal;
   `myphp build <dir>` makes build\windows-amd64, whose <Name>.exe starts
   the app with no console window, shows the app's icon in Explorer, on
   its windows and on the taskbar (one button, not PHP's), and runs from
   another folder and another machine once the zip is unpacked there.

### The player

1. [ ] **Sound (WASAPI)**: a film plays with its sound on the default
   output, in time with the picture; pause, seek and a rate of 1.5 keep
   it in time. The Audio menu lists the outputs ("wasapi/…", by their
   names), and choosing another moves the sound there. Unplugging the
   headphones that play goes on (on the system's default output) rather
   than stopping.
2. [ ] **Hardware decoding (D3D11VA)**: on a machine with a GPU, an H.264
   or HEVC film with hardware decoding on logs "decodes with D3D11VA" at
   debug, and the Inspector names it ("FFmpeg h264 (D3D11VA)"); the
   picture is right (no green or torn frames), and turning hardware
   decoding off and on while it plays goes on from where it was.
3. [ ] **Media controls (SystemMediaTransportControls)**: while a film
   plays, the volume flyout (and the lock screen) show its name, its
   position moving, and Play/Pause. Their Play/Pause, the keyboard's
   media keys and dragging the flyout's position drive the player, and
   the app does not crash: the controls' events must come on the main
   thread (`Windows\NowPlaying`'s handlers are not agile). Closing the
   film takes it out of the flyout.
4. [ ] **Thumbnails (WIC's JPEGs)**: hovering the timeline shows the
   frames there, and they come back from the cache on the next run.

### Web pages (WebView2)

5. [ ] **A web view in native UI** (examples/embed-webview): it sits where
   the content puts it, follows a resize and a scroll, and is clipped by
   what scrolls it; native UI drawn over it does not show there (a known
   deviation: a child window, not composition).
6. [ ] **DevTools, downloads, new windows**: F12 (with devTools on) opens
   the inspector; a download asks where to save, lands there, and is told
   at its end; a link with target=_blank or window.open() is the app's to
   open.
7. [ ] **Permissions**: a page asking for the camera, the microphone or
   the location gets the app's answer (allowed or denied), and the
   system's indicator shows while the camera is in use.

### Files

8. [ ] **A document renamed elsewhere** (examples/documents): renamed or
   moved in Explorer, the window follows it; edited in another editor, an
   unedited document reloads, an edited one asks at its next save.

### What CI checked by its API, to look at

9. [ ] **Menus**: the menu bar and the context menus follow the system's
   dark mode and look as Windows 11's (rounded, spaced), their icons and
   shortcuts aligned; the tray icon shows, its menu opens by left and
   right click.
10. [ ] **Text input**: Chinese and Japanese through the system's IME:
    the candidate window by the caret, composing shown in the field,
    the committed text right.
11. [ ] **Notifications and global shortcuts**: a notification shows (its
    click told to the app); a global shortcut works while another app
    has the keyboard.
12. [ ] **The clipboard with other apps**: text, HTML and an image copied
    from the app paste into Word or Paint, and theirs into the app.
13. [ ] **A prompt and a login** (a page's prompt(), the player's open
    URL and its login): the dialog sits over its window, sized to its
    texts (a long Chinese prompt wraps, nothing clipped) at 100% and 150%
    scaling; Tab, Enter and Escape work, IME types into the field; a
    login's fields show their placeholders, the password hidden.
14. [ ] **The taskbar**: a window's progress shows on its button (normal,
    paused, error, indeterminate); Dock::setIcon changes the title bar's
    and the button's icon (and Alt+Tab's), '' brings the executable's
    back; a bounce flashes the button till the app comes forward; the
    about panel shows the name, versions and copyright.
15. [ ] **Icons and apps**: Shell::fileIcon of a file, a folder, an .exe
    and a shell:AppsFolder item look as Explorer shows them, edges
    smooth (straight alpha, not dark fringes); the Start menu's apps list
    with their names in the user's language.
16. [ ] **A page's capture, PDF and sound**: the PNG looks as the page
    shows; the PDF opens, laid out for print, at the size asked; muting a
    page playing sound silences it, isAudible true while it plays; picture
    in picture floats the page's video and is told as on.
17. [ ] **Dragging out** (the player's playlist, examples with draggable
    items): a file dragged to Explorer copies it (Shift moves it), text
    and a link dragged into Notepad, Word or Edge land there; the drag
    shows its preview under the pointer, sharp at 150%; Escape cancels it;
    dragging between two windows of the app moves the item as it is.
18. [ ] **Dropping in**: files from Explorer, text from Word and a link
    from Edge dropped on native UI are taken where the content takes
    them (the pointer's badge shows copy, or none where it does not);
    over a web page, the page takes them as Edge would.
19. [ ] **The system's symbols** (the player's toolbar and menus, the log
    viewer): each icon means what macOS's does, centered in its square
    and as heavy as the text beside it; on Windows 10 (Segoe MDL2 Assets)
    too. The ones drawn as Material Symbols do not clash.
20. [ ] **Materials** (a window with `vibrancy: 'mica'`, a popover's
    `'popover'`): on Windows 11 22H2 or later, Mica (or Acrylic) shows
    behind the content and the page, follows the desktop's wallpaper and
    dims when the window is inactive; the content's own backgrounds draw
    over it; resizing does not flash black. On Windows 10 the window is as
    without one. `blur()` hands the keyboard to the window below.
21. [ ] **The installer** (`myphp build`, then the setup .exe): it installs
    for the user without asking for administrator rights, in Chinese on a
    Chinese Windows; the Start menu shortcut and, when ticked, the desktop
    one open the app with its icon; Settings > Apps lists it with its
    version and publisher and uninstalls it cleanly; installing over a
    running app asks to close it first; a file of the app's type opens in
    it from Explorer, a link of its URL scheme from the browser.
22. [ ] **Signing** (windows.certificate with a real .pfx, or
    signCommand): the launcher, the installer and Uninstall.exe show a
    valid signature in their properties; SmartScreen names the publisher.
23. [ ] **Updates** (updates in myphp.json, two versions published):
    the older one finds the newer, downloads it (a delta when published),
    installs it once it quits, relaunches after App::relaunch(), the
    uninstaller still there; `irm <url>/install.ps1 | iex` installs the
    latest on another PC, and again over a running one.

## iOS

0. [ ] **Running, hot reload and building** (`myphp doctor`, `myphp devices`,
   `myphp run -d ios <dir>`, `myphp build -platform ios/arm64 <dir>`):
   `myphp doctor` shows the Apple & iOS Toolchain and both iOS runtimes
   (device and simulator) built; `myphp devices` lists booted/available
   iPhone and iPad simulators and any connected physical iPhone/iPad;
   `myphp run -d ios examples/mobile` boots the simulator if needed,
   installs and launches the app, streams its colored `os_log` lines, and
   hot reloads (`r` or saving `view.php`) with the state kept and hot
   restarts (`R`) in under a second; `myphp build -platform ios/arm64` produces
   `<out>/<name>.app` and `<out>/<name>.ipa`.
1. [ ] **The safe area, orientation and iPad adaptive layout**
   (`examples/mobile`, `examples/airpods`): on an iPhone the navigation bar
   reaches into the status bar / Dynamic Island, the tab bar floats over
   the home indicator, and what scrolls goes under both to the screen's
   edges; rotating the device or simulator updates the window bounds and
   safe-area insets on the next frame; on an iPad simulator held upright
   `TabView(.sidebarAdaptable)` shows the tabs as a top capsule, and on its
   side as a sidebar, while `.sheet` centers as a page sheet over the
   dimmed window.
2. [ ] **Liquid Glass and system materials** (`examples/mobile`,
   `examples/airpods`): on iOS 26+ the tab bar and navigation bar buttons
   use `UIGlassEffect` (and `UIBlurEffect` on iOS 16–18) over the Metal
   view, blurring the scrolling content beneath while their icons and
   labels paint crisp in the overlay Metal view (`g_over_view`) and all
   touches pass through to PHP's hit-testing.
3. [ ] **Touch, gestures and scrolling physics** (`examples/mobile`,
   `examples/airpods`): the 200 000-row list scrolls at 60–120 Hz with
   inertial fling; dragging a row sideways locks horizontally (8 pt
   directional lock) to reveal `swipeActions` (half swipe stays open, full
   swipe slides out and runs the first action, a tap on an open row closes
   it); pulling down at the top of the ”我的“ tab rubber-bands and triggers
   `refreshable`; long-pressing a packet row in `examples/airpods` plays a
   medium haptic, blurs the background, lifts the row, and tracks the held
   finger across the `contextMenu` items to choose on lift; swiping from
   the leading edge pops a pushed `NavigationStack` page interactively.
4. [ ] **Text input and Chinese/Japanese IME** (`examples/mobile`,
   `examples/text-input`): tapping a `TextField` raises the software
   keyboard and `safeArea` keeps the field above it; switching to the
   Chinese or Japanese keyboard inside iOS composes underlined marked text
   in the field, backspace edits the composition, and choosing a candidate
   commits it; with a hardware keyboard attached, the candidate bar sits
   by the field's caret (`placeCaret`); tapping outside dismisses the
   keyboard.
5. [ ] **Pickers, alerts and sheets** (`examples/mobile`): ”选图片“ opens
   `PHPickerViewController` (without asking for library permission),
   ”选文件“ opens `UIDocumentPickerViewController`, and the chosen photo
   or file is copied into `tmp/open/<id>/` under its own name and decoded
   off the main thread (`CoreImage`) to show in the form; `sheet`,
   `confirmationDialog` and `alert` open, animate and dismiss.
6. [ ] **System settings, appearance and localization** (`examples/airpods`,
   `examples/mobile`): toggling Dark Mode, changing Settings > Display >
   Text Size (Dynamic Type) or toggling Reduce Motion updates the running
   app; choosing Light/Dark, a text size (up to the accessibility sizes,
   where the three battery cards stack vertically) or English / 简体中文 in
   `examples/airpods`'s Settings tab updates the window and UIKit's glass
   at once; `App::locale()` and `App::region()` follow iOS's language and
   regional formats.
7. [ ] **VoiceOver** (`examples/airpods`, Settings > Accessibility >
   VoiceOver): swiping moves VoiceOver's cursor through the elements in
   reading order and scrolls offscreen list rows into view; double-tapping
   activates buttons, tabs and switches (announcing "1" / "0"); swiping up
   or down on a slider adjusts it, and on a row with `swipeActions` or
   `contextMenu` offers the actions and "Show Menu"; a two-finger scrub
   dismisses an open sheet, menu or alert.
8. [ ] **HTTPS, Keychain, Clipboard, Haptics, Notifications and WebView**
   (`examples/mobile`, `examples/webview`): ”HTTPS GET example.com“ in
   `examples/mobile` succeeds over bundled `libcurl` + OpenSSL with
   `cacert.pem`; each button in ”触感 Haptics“ plays its feedback on a
   physical iPhone's Taptic Engine; `Secrets` stores, reads and deletes in
   the iOS Keychain; `Clipboard` copies and pastes text and PNG images
   with other iOS apps; local notifications show a banner and report their
   click to `App::onNotificationClick`; an embedded `WebView`
   (`Context::webView`) loads HTML/URLs, exchanges IPC messages with PHP
   via `window.myphp`, and answers `Page::eval()`.
