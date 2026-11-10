# Manual checks

What CI cannot check, to be gone through on a real machine once the
platform's work is done. CI's runners have no GPU, no sound device, no
one to press a key or look at a window: a test there proves a path runs,
not that a person sees or hears the right thing. Each check says how to
run it and what should happen; tick it once it does, or note what did
instead.

## Windows

0. [ ] **Running the app**: `myphp devices` lists the Windows desktop and
   `myphp run -d windows <dir>` runs an app there, as `-d macos` and
   `-d linux` do. Not there yet (`Native\Build\DeviceManager` knows
   macOS, Linux and the phones): the checks below need it first.

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
