# Android and iOS

MyPHP runs on a phone as it does on the desktop: the same immediate-mode
UI drawn on the GPU (GLES 3 on Android, Metal on iOS), the same PHP, the
app's `main.php` unchanged. What a phone has that the desktop lacks —
touch, the safe area, the software keyboard, the system's Back, the
pickers — is myphp's own (mygo has no mobile platform;
`docs/upstream-deviations.md`, "The phone").

## Building and running

As with Flutter, the CLI is the one way to a device, on every platform:

```sh
myphp doctor                               # the NDK, Xcode, adb, the phone runtimes
myphp devices                              # phones over adb, simulators
myphp emulators --launch pixel_11_pro      # an Android emulator, booted (--create makes one)
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

The views keep out of the status bar, the notch or cutout and the home
indicator by themselves, as SwiftUI's: a `NavigationStack`'s bar reaches
into the status bar, a `TabView`'s bar floats over the home indicator, and
what scrolls under them goes on to the screen's edges. While a field has
the focus, the keyboard takes the bottom and what it would cover of the
field scrolls into view. On the desktop there is none of it.

## Pages, tabs and Back

An app's top-level pages are one `TabView(.sidebarAdaptable)`, each tab a
`NavigationStack` of its pages, its `navigationTitle()` in the bar:

```php
$app = new class {
    public string $tab = 'home'; public array $notes = [1 => 'Groceries', 2 => 'Ideas'];
};
$view = fn() => TabView(selection: $app->tab)(
    Tab('首页', systemImage: 'house', value: 'home')(
        NavigationStack()(
            ListView()(Each($app->notes)(fn(string $t, int $id) => NavigationLink($t, value: "/note/$id")))->navigationTitle('首页'),
        )->navigationDestination(for: '/note/{id}', destination: fn(int $id) => Text($app->notes[$id])->navigationTitle('Note')),
    ),
    Tab('我的', systemImage: 'person', value: 'me')(Form()(LabeledContent('Version', '1.0'))->navigationTitle('我的')),
)->tabViewStyle(TabViewStyle::sidebarAdaptable);
```

The same code is a tab bar on a phone, a capsule of the tabs' titles
centered at the top on an iPad held upright (iPadOS 26's), and a sidebar
on an iPad on its side or on the Mac, as SwiftUI's `TabView` adapts;
A sheet
is a phone's from the bottom (with its detents), and on an iPad a page
sheet centered over the dimmed window.

Each tab keeps its pages' history as another shows.

Android's Back button and gesture go one step at a time: a field with
the keyboard loses the focus; else a dialog, sheet, popover or menu
closes (what Escape closes); else the navigation stack shown goes back a page;
and at the first page the app ends, as Android's apps do.

## The phone's theme

On iOS and Android the window starts from `Theme::phone()`: UIKit's
system colors (systemBlue, label and secondaryLabel, systemBackground,
the separator, systemRed, the grouped backgrounds, pure black in the dark),
17-point text, corners rounded 12, and UIKit's control sizes — buttons,
fields and selects 44 points high (buttons as capsules), switches 63 × 28 (iOS 26's),
segmented controls 32 — the one design on both. The desktop keeps mygo's
theme and sizes. `Color::systemGroupedBackground` and
`secondarySystemGroupedBackground` are a `Form`'s page and sections.

On iOS the glass of the tab bar and the navigation bar, and any
`glassEffect()`, is UIKit's: Liquid Glass (UIGlassEffect) on iOS 26,
the system's blurs on iOS 16 to 18, over the page so the page shows
through it as it scrolls under. Android has no system glass for what
myphp draws, and draws its own (`Plugins\Glass`), as Linux does.

## The system's settings

The theme follows the phone's as it follows the desktop's
(`Theme::follow`): dark mode; the text size (iOS's Settings > Display >
Text Size, Android's Font size), which scales the theme's text and every
`Font` style as Dynamic Type does; and Reduce Motion (iOS) or
Remove animations (Android), with which what animates settles at once.
Changing a setting while the app runs changes the window.

VoiceOver and TalkBack read the views and act on them
([accessibility](ui/accessibility.md)): rows of lists with their swipe
actions and Show Menu, tabs, switches, sliders, fields; a menu, a sheet
or an alert on top alone until it closes.

## Files and photos

A list's rows take swipe actions, as Mail's: `->swipeActions()` on a row,
its buttons uncovered at its end as the finger drags the row toward the
start, the first nearest the content, a destructive one red; let go past
half their width the row stays open; dragged past half the row, the row
slides out and the first action runs (UIKit's full swipe). A flick only
opens or closes. A long press on a row with a `contextMenu()` lifts it
over the blurred window with its menu. The row still scrolls: a finger
moving up or down scrolls the list, moving sideways swipes the row.

```php
$app = new class {
    public array $mails = ['Lunch?', 'Invoice', 'Weekend'];
    public bool $refreshed = false;
    public function reload(): void { $this->refreshed = true; }
};
$view = fn() => NavigationStack()(
    ListView($app->mails)(fn(string $m, int $i) => Text(verbatim: $m)
        ->swipeActions(Button('Delete', role: ButtonRole::destructive, action: fn() => array_splice($app->mails, $i, 1)))
        ->contextMenu(Button('Copy', action: fn() => null, systemImage: 'doc.on.doc')))
        ->listStyle(ListStyle::plain)
        ->refreshable(fn() => $app->reload())
        ->navigationTitle('Mail')->navigationBarTitleDisplayMode(TitleDisplayMode::large),
);
```

A page pulled down at its top refreshes, as UIRefreshControl: a spinner in
the room it leaves, the action run once it is let go past 60 points. A
large title, as Mail's, goes under the bar as the page scrolls, the bar's
own title showing then.

A settings page is a `Form` of `Section`s, an inset grouped list:

```php
$app = new class { public bool $haptics = true; public string $lang = 'en'; };
$view = fn() => NavigationStack()(
    Form()(
        Section('General', footer: 'The language of the app.')(
            Picker('Language', selection: $app->lang)(Text('English')->tag('en'), Text('中文')->tag('zh')),
            NavigationLink('About', value: '/about'),
        ),
        Section('Sounds')(Toggle('Haptics', isOn: $app->haptics)),
    )->navigationTitle('Settings'),
)->navigationDestination(for: '/about', destination: fn() => Text('myphp')->navigationTitle('About'));
```

`Dialog::open()` is the system's picker. Filters naming only pictures and
films open the photo picker (no permission needed on either platform),
anything else the document picker:

<!-- no-test -->
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

Every view takes touch — a tap is a click, a long press opens a context
menu, a drag scrolls with inertia — and shows as iOS 26 and 27 do, on
Android too (only the font differs, Roboto there):

| | |
|---|---|
| `TabView` | the tab bar floating over the bottom, a capsule of glass, icons over labels, the chosen one in the accent; `tabBarMinimizeBehavior(.onScrollDown)` shrinks it; `Tab(role: .search)`, `Tab(role: .prominent)` |
| `NavigationStack` | the bar across the top, clear, the way back a chevron in a circle of glass, a swipe from the start edge; `toolbar()` a capsule of glass at its end; large titles |
| `.sheet` | a panel rising from the bottom, floating 8 in, a grabber over it; `presentationDetents` |
| `.confirmationDialog` | choices rising from the bottom with a Cancel |
| `.alert` | UIKit's alert |
| `Form`, `ListView` of `Section`s | an inset grouped list, as Settings: rows 52 high in rounded groups |
| `.swipeActions`, `.refreshable`, `.contextMenu` | Mail's swipe, pull to refresh, the long-press menu over the blurred window |
| `.searchable`, `.searchScopes` | iOS 26's search field across the top, its scopes under it |

Their sizes are UIKit's, the one design on every platform
(`tests/mobile_test.php` and `tests/declarative_test.php` check them
headless). `Haptics::impact()`, `selection()`, `success()`, `warning()` and
`error()` play the phone's haptics from an action (docs/native.md, "The
shell"); the controls play their own.

## Coming from SwiftUI or Flutter

The views are SwiftUI's ([Coming from SwiftUI](swiftui.md)); Flutter's
widgets map to them so:

| Flutter | myphp |
|---|---|
| `Scaffold.bottomNavigationBar`, `NavigationRail` | `TabView(selection:)(Tab(…)(…))->tabViewStyle(TabViewStyle::sidebarAdaptable)` |
| `Navigator` + `AppBar` | `NavigationStack(path:)` + `navigationTitle()`, `toolbar()` |
| `SliverAppBar.large` | `navigationBarTitleDisplayMode(TitleDisplayMode::large)` |
| `RefreshIndicator` | `->refreshable(fn() => …)` |
| `Navigator.push`, `Navigator.pop` | `NavigationLink(title, value:)`, `$path->push()`, `$path->back()` |
| `SafeArea` | built in |
| `showModalBottomSheet` | `->sheet(isPresented:, content:)` |
| `ListView` of `Card`s, `ListTile` | `Form()(Section(…)(…))`, `LabeledContent`, `NavigationLink` |
| `Dismissible`, `flutter_slidable` | `->swipeActions(Button(…))` |
| `showDialog` + `AlertDialog` | `->alert(title, isPresented:, actions:)` |
| `SnackBar` | `->toast($message)` |
| `TextField` + `TextEditingController` | `TextField(title, text: $this->text)` |
| `ListView.builder` + `ScrollController` | `ListView($items, id:)(fn($item) => …)`, `ScrollViewReader` |
| `ElevatedButton(onPressed:)` | `Button(title, action:)` |
| `Padding`, `ClipRRect` | `->padding()`, `->cornerRadius()` |
| `Theme.of(context).textTheme.titleLarge` | `->font(Font::title2)` |
| `image_picker`, `file_picker` | `Dialog::open()` with filters |
| `http` | `Http::get()`, in `App::go()` or `->task()` |
| `HapticFeedback.lightImpact()` | `Haptics::impact('light')` |

## Checking on a device

`examples/counter-native` on the phone, then in turn: a field (the
keyboard, Chinese input), `Dialog::open` with image filters (the photo
picker) and without (documents), `Http::get` of an https URL, Back at a
pushed page then at the first, the bars at the notch and the home
indicator, dark mode. Then with VoiceOver (Settings > Accessibility) or
TalkBack on, in `examples/airpods`: swipe through a page, double-tap a
tab and a switch, a row's actions (VoiceOver: swipe up or down; TalkBack:
three-finger tap) and Show Menu, then dismiss the menu (two-finger scrub,
Back).
