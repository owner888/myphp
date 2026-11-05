# Android and iOS

MyPHP runs on a phone as it does on the desktop: the same immediate-mode
UI drawn on the GPU (GLES 3 on Android, Metal on iOS), the same PHP, the
app's `main.php` unchanged. What a phone has that the desktop lacks —
touch, the safe area, the software keyboard, the system's Back, the
pickers — is myphp's own (mygo has no mobile platform;
`docs/mygo-deviations.md`, "The phone").

## Building and running

As with Flutter, the CLI is the one way to a device, on every platform:

```sh
myphp doctor                               # the NDK, Xcode, adb, the phone runtimes
myphp devices                              # phones over adb, simulators
myphp run -d android examples/counter-native   # the APK, signed, installed, started; adb logcat MyPHP
myphp run -d ios examples/counter-native       # the .app in the simulator
myphp run -d macos examples/counter-native
myphp build -platform android/arm64            # the APK alone; ios/arm64 the .ipa
```

The first run for a phone builds its PHP runtime (libffi, OpenSSL,
libcurl, libxml2, libphp: the NDK for Android, `arm64`, minSdk 31; Xcode
for iOS 16; 10-20 minutes), kept under `build/android`, `build/ios` (an
iPhone) and `build/ios-simulator` (the simulator, another slice of the
same libraries) and built again when its script changes. The scripts under `scripts/` are the CLI's
internals, not to be run by hand.

A device runs the `.ipa` signed with your own certificate, or through
Xcode's Devices window.

The app's `main.php` becomes the package's `app.php`, with the framework
under `src/` beside it; the launcher requires `src/autoload.php` before
it, so the app registers no autoloader of its own (its desktop one, if it
has one, is never reached for MyPHP's classes).

Text is the system's: `TextRunShaper` and `Canvas.drawGlyphs` on Android,
CoreText on iOS, so Chinese, Japanese and emoji come with the system's
fonts and fallbacks; nothing is bundled.

## What a phone does not have

One full-screen window: a second `new Window()`, the menu bar, the tray,
global shortcuts, the Dock and window placement throw
`Platform\Unsupported` — never ignored. So do `Dialog::prompt` and
`Dialog::login` (ask in the window, with inputs), `Dialog::open` of a
directory, and on iOS `Dialog::save` (write the file, then share it).

## The safe area and the keyboard

`$c->safeArea` holds how far the status bar, the notch or cutout, and the
home indicator reach in from the top, right, bottom and left;
`$c->keyboard` how high the keyboard stands while a field has the focus
(both zero on the desktop). `safeArea()` keeps content out of them:

```php
$c->safeArea(function () use ($c) {
    $c->scroll($state, vertical: true);   // what the keyboard would cover scrolls into view
      $c->input($name)->placeholder('名字');
    $c->end();
});
```

A `navigationBar` or a `tabView` takes the edge it fills off `$c->safeArea`
for what is built after it, so a `safeArea()` below the bar pads for the
bar's edge no second time; `$c->windowSafeArea` keeps the window's own,
which the sheets keep out of.

A field focused brings the keyboard up, with the system's input method
(Chinese, Japanese: composing text shows in the field); the focus leaving
takes it down. A `tabBar` or `navigationBar` extends into the safe area
itself, so build them outside `safeArea()`, at the window's edges.

## Pages, tabs and Back

A `Router` (docs/ui/navigation.md) is the page stack: `push()` a page,
`back()` to the one before. The bars of a phone go round it:

```php
$tabs = $c->tabView([[$home, '首页'], [$search, '搜索'], [$me, '我的']], $this->tab, function (int $tab, string $where) use ($c) {
    $c->navigationBar($this->router->title(), $this->router);   // "‹ Home  Note", the way back
    $c->safeArea(function () use ($c) {
        $this->router->view($c, fn(Route $r) => $this->page($c, $r));
    });
});
if ($c->changed($tabs)) { $this->router->replace(['/home', '/search', '/me'][$this->tab]); }
```

The same code is a tab bar on a phone, tabs across the top on an iPad held
upright, and a sidebar on an iPad on its side or on the Mac, as SwiftUI's
`TabView` adapts; `tabBar()` alone is the bar for an app that wants it
everywhere.

Each tab keeps a router of its own when its pages must survive a switch.

Android's Back button and gesture go one step at a time: a field with
the keyboard loses the focus; else a dialog, sheet, popover or menu
closes (what Escape closes); else the router shown goes back a page;
and at the first page the app ends, as Android's apps do.

## Files and photos

`Dialog::open()` is the system's picker. Filters naming only pictures and
films open the photo picker (no permission needed on either platform),
anything else the document picker:

```php
$files = Dialog::open(new OpenDialogOptions(filters: [new FileFilter('课程表', ['jpg', 'png', 'pdf'])]));
foreach ($files as $path) { Http::post($url, ['file' => $path]); }   // a copy under its own name
```

What is chosen is copied into the app's cache under its name, so the
path works with every PHP file function and the upload keeps the name;
delete it once sent. `Dialog::save()` on Android opens the system's
"create document" and returns `/proc/self/fd/N`, written through.

## HTTPS

`Http`, the fetch plugin and WebSockets go through libcurl as on the
desktop: on Android the APK's own `libcurl.so` with OpenSSL inside
(trusting the system's certificates, which the activity writes out as
`cacerts.pem` at every start), on iOS the one linked into the app
(trusting `cacert.pem`, curl's copy of Mozilla's, which the package
script fetches). Nothing to configure in the app.

## The widgets of a phone

Besides every widget of the desktop (a `modal`, an `alertDialog`, a
`toast`, a `popover`, a `calendar`, a `menuButton`…), all of which take
touch — a tap is a click, a long press a right click, a drag a scroll
with inertia — a phone has, in `src/UI/ContextMobile.php`:

| | |
|---|---|
| `tabView($items, &$index, $content)` | the tabs where Apple puts them: bottom on a phone, top on a tablet upright, a sidebar on its side and on the desktop |
| `tabBar($items, &$index)` | across the bottom: icons over labels, the chosen one in the accent |
| `navigationBar($title, ?$router, ?$trailing)` | across the top: the title, the way back, buttons at the end |
| `sheet(&$open, $fn)` | a panel rising from the bottom, a grabber over it; a tap outside closes it |
| `actionSheet(&$open, $title, ...$actions)` | choices rising from the bottom with a Cancel; `"!Delete"` is destructive |
| `safeArea($fn)` | content kept out of the bars, the cutout and the keyboard |

Their sizes are UIKit's, the one design on every platform
(`tests/mobile_test.php` checks them headless).

## Coming from SwiftUI or Flutter

The shape of an app is the desktop's, immediate mode: the view builds the
tree every frame (`$c->column(); … $c->end()`), a value's state is passed
as `&$ref` (SwiftUI's `Binding`), a lifetime's as a State object
(Flutter's controllers), and a widget reports through `changed()`,
`clicked()`, `dismissed()` rather than callbacks. Names and sizes follow
UIKit; what Apple has no name for takes Flutter's.

| SwiftUI | Flutter | myphp |
|---|---|---|
| `NavigationStack` + `navigationTitle` | `Navigator` + `AppBar` | `Router` + `navigationBar($title, $router)` |
| `NavigationLink` / `path.append` | `Navigator.push` | `$router->push('/note/42')` |
| `dismiss()` / the back button | `Navigator.pop` | `$router->back()`; the bar's chevron, Android's Back |
| `TabView` (`.sidebarAdaptable`) | `Scaffold.bottomNavigationBar` / `NavigationRail` | `tabView($items, &$index, $content)`: bottom on a phone, top on a tablet upright, a sidebar on its side and the desktop; `tabBar` is the bar alone |
| `.safeAreaInset`, `ignoresSafeArea` | `SafeArea` | `safeArea($fn)`; `$c->safeArea`, `$c->keyboard` |
| `.sheet(isPresented:)` | `showModalBottomSheet` | `sheet(&$open, $fn)` |
| `.confirmationDialog` | a bottom sheet of `ListTile`s | `actionSheet(&$open, $title, ...$actions)` |
| `.alert` | `showDialog` + `AlertDialog` | `alertDialog(&$open, $title, $message, ...$buttons)` |
| — | `SnackBar` | `toast($message)` |
| `TextField` + `@State` | `TextField` + `TextEditingController` | `input($state)` + `TextState` |
| `List` / `ScrollView` | `ListView.builder` + `ScrollController` | `list($state, $n, $row)` + `ListState`; `scroll()` + `ScrollState` |
| `Button("…") { }` | `ElevatedButton(onPressed:)` | `if ($c->clicked($c->button('…'))) { }` |
| `.padding()`, `.cornerRadius()` | `Padding`, `ClipRRect` | `->padding()`, `->radius()` (modifiers on the node) |
| `PhotosPicker`, `fileImporter` | `image_picker`, `file_picker` | `Dialog::open()` with filters |
| `URLSession` | `http` | `Http::get()`, in `App::go()` |

## Checking on a device

`examples/counter-native` on the phone, then in turn: a field (the
keyboard, Chinese input), `Dialog::open` with image filters (the photo
picker) and without (documents), `Http::get` of an https URL, Back at a
pushed page then at the first, the bars at the notch and the home
indicator, dark mode.
