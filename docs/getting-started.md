# Getting started

MyPHP builds native desktop (macOS, Windows, Linux) and mobile (iOS, iPadOS,
Android) applications and 2D/3D games in pure PHP 8.4+ / 8.5, rendered directly
on the GPU with SwiftUI-style declarative views ([native UI](ui/README.md)).

## Install

Install PHP 8.4 or later (8.5 recommended) with `ext-ffi` (`ffi.enable=1`):

```sh
curl -fsSL https://myphp.ai/install.sh | bash
```

Native UI needs nothing more on macOS or Windows. On Linux it uses GTK 3 and
libepoxy (`sudo apt install libgtk-3-0 libepoxy0` on Debian and Ubuntu).
`myphp doctor` checks your desktop and mobile toolchains (`myphp doctor --fix`
auto-configures PHP extensions), and says when a newer MyPHP is out:
`myphp upgrade` takes it, as `flutter upgrade`.

## Create a project

```sh
myphp init my-app                  # 2D SwiftUI declarative app
myphp init -template 3d my-3d-app  # 5-platform 3D SceneView app
```

This creates a clean, pure-PHP project with zero frontend build tools required:

```
my-app/
├── app.php                    the app: creates the state and opens its window
├── src/Counter.php            the state and its actions, with no view
├── view.php                   the window's declarative view ($this is the state)
├── tests/ui_test.php          builds the view headless and tests interactions
├── boot.php                   loads MyPHP and vendor/autoload.php (Composer)
├── myphp.json                 the app's name, identifier, version and options
└── resources/
    └── icon.png               the app icon, a 1024×1024 PNG
```

Run the headless UI test (needs no window or display, `< 50 ms`):

```sh
myphp test
```

MyPHP is installed once on the machine, as the Flutter SDK is: the project does not carry it nor say where it is. `myphp.json`'s `"myphp"` is the least version the project needs, and `myphp upgrade` moves every project to the new release.

## Develop with Live Hot-Reload

```sh
cd my-app
myphp dev                # Desktop development bundle with instant hot reload
myphp run -d macos       # Run directly on macOS
myphp run -d ios         # Run on iOS Simulator or iPhone (hot reloads on save)
myphp run -d android     # Run on Android device or emulator (hot reloads on save)
```

Saving `view.php` or `pages/*.php` hot reloads the window in place in `< 10 ms`
while preserving your current state ([view files](ui/views.md#view-files-and-hot-reload)).
Press `r` in the terminal to trigger a reload manually, `R` to restart from
scratch, or `q` to quit.

## Reactive State & Actions

Views read `$this` (your state object) and mutate properties directly in action
closures or `App::go()` fibers:

```php
$app = new class {
    public string $name = '';
    public string $greeting = '';
    public function greet(): void {
        $this->greeting = "Hello, {$this->name}!";
    }
};

$view = fn() => VStack(spacing: 16)(
    TextField('Your name', text: $app->name)->frame(width: 240)->onSubmit(fn() => $app->greet()),
    Button('Greet', action: fn() => $app->greet())->buttonStyle(ButtonStyle::borderedProminent),
    Text(verbatim: $app->greeting),
)->padding(24);
```

## Package for Distribution

```sh
myphp build                                # Build for the current desktop OS
myphp build -platform darwin/universal     # macOS Universal .app + .dmg
myphp build -platform windows/amd64        # Windows .exe + installer
myphp build -platform linux/amd64          # Linux .deb + .tar.gz + PKGBUILD
myphp build -platform ios/arm64            # iOS .app + .ipa
myphp build -platform android/arm64        # Android signed .apk
```

See [building and distributing](distribution.md) for code signing, notarization,
and auto-updates.