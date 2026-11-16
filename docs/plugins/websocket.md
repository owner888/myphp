# WebSockets & Composer Ecosystem

MyPHP applications are standard PHP 8.4+ / 8.5 projects: you can use both MyPHP's built-in `libcurl` WebSocket client (`MyPHP\Plugins\WebSocket`) and any pure-PHP package from the **Composer / Packagist** ecosystem.

---

## 1. Using Composer Packages Across All 5 Platforms

Every project scaffolded with `myphp init` automatically loads `vendor/autoload.php` in `boot.php`, and `myphp build` / `myphp run` automatically bundles your project's `vendor/` directory into macOS `.app`, Windows `.exe`, Linux `.deb`, iOS `.ipa`, and Android `.apk` packages.

```sh
composer require nesbot/carbon
composer require guzzlehttp/psr7
```

### Composer Compatibility Matrix (Desktop & Mobile)

| Package Category | Compatibility on Desktop & Mobile (iOS / Android) | Notes |
| --- | --- | --- |
| **Pure PHP libraries** (DTOs, Markdown/YAML/TOML/CSV parsers, math, state machines, JWT, validation, Carbon, Brick/Math, Ramsey/Uuid) | ✅ **100% Compatible** | Bundled directly into the app across all 5 platforms |
| **Standard extension libraries** (`ext-json`, `ext-mbstring`, `ext-ctype`, `ext-openssl`, `ext-zlib`, `ext-curl`, `ext-xml`, `ext-SimpleXML`, `ext-dom`, `ext-pdo`) | ✅ **100% Compatible** | All compiled into MyPHP's mobile (`libphp.a` / `libphp.so`) and desktop runtimes |
| **`ext-ffi` / JNI native wrappers** | ✅ **100% Compatible** | Supported via `myphp ffigen` and `myphp jnigen` |
| **CLI / Web-Server frameworks** (Workerman, Swoole, ReactPHP event loops, or libraries calling `pcntl_fork` / `proc_open` on iOS) | ⚠️ **Desktop Only / Use `App::go()` Instead** | GUI apps already run on the OS main event loop (`NSRunLoop` / `ALooper` / Win32 / GTK); use `App::go()` fibers and `URLSession` instead of external event loops. iOS sandboxes forbid `fork`/`proc_open`. |

---

## 2. Built-in `libcurl` WebSocket Client

MyPHP also ships a native `libcurl`-based WebSocket transport (`MyPHP\Plugins\WebSocket\WebSocket`) that supports custom handshake headers (`Authorization`, `Cookie`, `Origin`), subprotocols, text/binary frames, and automatic ping/pong handling on the run loop.