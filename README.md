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
| Native UI: Metal renderer, CoreText text, flex layout, scrolling on either axis, clicks, hover | works (`examples/native`, `form`) |
| Lists over a million rows: scroll bars, `scrollTo` glides, keyed rows, selection with keyboard navigation | works (`examples/native`) |
| Images (PNG, JPEG, HEIC, ICNS…) in native UI, rounded and faded | works (`examples/native`) |
| Bold, italic and monospace text; rich text spans with color, underline, highlight, wrapping | works (`examples/native`, `logview`) |
| Text fields and text areas: input methods, selection, wrapping, undo, Edit menu, Tab focus | works (`examples/form`) |
| Theme: light/dark after the system, its accent color, live switching | works |
| `myphp init / dev / build`: scaffold, run with restart, .app bundle (exec or static micro launcher) | works |
| App / Window API (whenReady, run, WindowOptions, show/hide/close/setTitle) | works, a subset of mygo's |
| Web pages: `Ipc::bind()` a PHP service, call it from the page, events back, streaming channels, page-side window controls, preload script, generated TypeScript client | works (`examples/webview`) |
| Menu bar with roles and accelerators, context menus, open/save/message dialogs | works (`examples/desktop`) |
| Tray icon, global shortcuts, notifications (from a built .app), window events, file drops, remembered window frames | works (`examples/desktop`) |
| Linux and Windows | next |
| Linux (GTK/WebKitGTK), Windows (Win32/WebView2) | planned |

Measured in `examples/native 1000` on an M-series Mac: 9 000 nodes rebuilt,
laid out and painted per frame in about 6 ms with real text, 60 fps while
scrolling; frames are only built when something changes.

### The command line

```sh
bin/myphp init my-app              # a native-UI project (--template web for a page calling PHP)
cd my-app && ../myphp/bin/myphp dev   # runs it, restarts on changes, regenerates the TS client
../myphp/bin/myphp build           # dist/MyApp.app, a launcher that execs this machine's php
../myphp/bin/myphp build --launcher micro   # one static binary: fetches static-php-cli and builds phpmicro the first time
```

### Requirements

- PHP ≥ 8.3 with `ext-ffi` (Homebrew's `php` has it), OPcache JIT recommended
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
./run.sh logview /var/log/system.log   # a log viewer: follows the file, filters, highlights
./run.sh native           # native UI, 300-row list, prints fps once a second
./run.sh native 1000      # 9 000 nodes per frame
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
| 原生 UI：Metal 渲染、CoreText 文字、flex 布局、横竖滚动、点击、hover | 可用（`examples/native`、`form`） |
| 百万行列表：滚动条、`scrollTo` 平滑滚动、按行 key、选中与键盘导航 | 可用（`examples/native`） |
| 原生 UI 里的图片（PNG、JPEG、HEIC、ICNS…），可圆角、可透明 | 可用（`examples/native`） |
| 粗体、斜体、等宽文字；富文本 span（颜色、下划线、高亮、换行） | 可用（`examples/native`、`logview`） |
| 文本框和多行文本区：输入法、选区、自动换行、撤销、Edit 菜单、Tab 焦点 | 可用（`examples/form`） |
| 主题：跟随系统明暗和强调色，实时切换 | 可用 |
| `myphp init / dev / build`：脚手架、改动重启、.app 打包（exec 或静态 micro 启动器） | 可用 |
| App / Window API（whenReady、run、WindowOptions、show/hide/close/setTitle） | 可用，mygo 的子集 |
| 网页：`Ipc::bind()` 绑定 PHP 服务，页面直接调用，事件回推，流式 channel，页面侧窗口控制，preload 脚本，生成 TypeScript client | 可用（`examples/webview`） |
| 菜单栏（角色、快捷键）、右键菜单、打开/保存/消息对话框 | 可用（`examples/desktop`） |
| 托盘图标、全局快捷键、通知（需打包的 .app）、窗口事件、文件拖放、记住窗口位置 | 可用（`examples/desktop`） |
| Linux 和 Windows | 下一步 |
| Linux（GTK/WebKitGTK）、Windows（Win32/WebView2） | 计划中 |

在 M 系列 Mac 上用 `examples/native 1000` 实测：每帧重建、布局并绘制 9 000 个节点（含真实文字）约 6 ms，滚动时 60 fps；没有变化时不构建帧。

### 命令行

```sh
bin/myphp init my-app              # 原生 UI 项目（--template web 是调用 PHP 的网页项目）
cd my-app && ../myphp/bin/myphp dev   # 运行，文件改动自动重启，重新生成 TS client
../myphp/bin/myphp build           # dist/MyApp.app，启动器 exec 本机的 php
../myphp/bin/myphp build --launcher micro   # 单个静态二进制：第一次自动下载 static-php-cli 并构建 phpmicro
```

### 环境要求

- PHP ≥ 8.3，带 `ext-ffi`（Homebrew 的 `php` 自带），建议开 OPcache JIT
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
./run.sh logview /var/log/system.log   # 日志查看器：跟随文件增长、过滤、高亮
./run.sh native           # 原生 UI，300 行列表，每秒打印一次 fps
./run.sh native 1000      # 每帧 9 000 个节点
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
