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
| Images (PNG, JPEG, HEIC, ICNS…) in native UI, rounded and faded, fitted (contain, cover, fill, scale-down, natural size), in grayscale | works (`examples/native`, `widgets`) |
| Bold, italic and monospace text; rich text spans with color, underline, highlight, wrapping | works (`examples/native`, `logview`) |
| Soft shadows, rings, Liquid Glass (blur, lens bend at the edge, lit rim, tint, interactive), backdrop blur, scroll edge | works (`examples/glass`) |
| Text fields and text areas: input methods, selection, wrapping, undo, Edit menu, Tab focus; checkbox, radio, slider, select, link, progress, tooltips | works (`examples/form`) |
| Switch, stepper, segmented control, tabs, collapsible section, split panes, table (sortable, resizable columns), sidebar with sections, tree, accordion, rating, range slider, combobox, autocomplete, token field, date input, calendar, time input, color picker and well, find bar, form with fields and fieldsets, checkbox group, breadcrumbs, alert dialog, grid view (virtualized), multiple selection (Cmd/Shift-click, Shift+arrows, Cmd+A), rows and grid items dragged to reorder, grid layout (fixed/fr/fit tracks), SVG icons, avatars, hidden title bar (titleBar + dragWindow), toolbar with toggles, modal dialogs, popovers, menu buttons, toasts, search field, number input, meter, spinner, badge, divider, spacer | works (`examples/widgets`, `notes`, `http`) |
| Theme: light/dark after the system, its accent color, live switching | works |
| `myphp init / dev / build`: scaffold, run with restart, .app bundle (exec or static micro launcher) | works |
| App / Window API (whenReady, run, WindowOptions, show/hide/close/setTitle) | works, a subset of mygo's |
| Web pages: `Ipc::bind()` a PHP service, call it from the page, events back, streaming channels, page-side window controls, preload script, generated TypeScript client | works (`examples/webview`) |
| Menu bar with roles and accelerators, context menus, open/save/message dialogs | works (`examples/desktop`) |
| Tray icon, global shortcuts, notifications (from a built .app), window events, file drops, remembered window frames | works (`examples/desktop`) |
| Child processes without blocking the UI (`Process`): curl, scripts, tools | works (`examples/http`) |
| SVG: icons as masks in any color (turned by `rotate`), pictures in their own colors — paths, shapes, transforms, gradients, strokes and dashes, opacity, clip paths, masks, style sheets — drawn by a renderer in PHP, which also rasterizes path masks (no CoreGraphics) | works (`examples/widgets`, `files`) |
| Languages and regional formats: the app's language from the user's (or `App::setLocale`, kept in its defaults), MyPHP's own strings and menus in it (en, zh-Hans, zh-Hant), dates, calendars and times in the region's formats and the language's names (ICU through `ext-intl`) | works (`examples/widgets`) |
| A file manager: sidebar of places, breadcrumbs, the folder as icons or a sortable table sharing one selection, find bar, image preview, Trash through Finder | works (`examples/files`) |
| Code editor (mygo's ui/editor): rope text, undo, several carets and selections, soft wraps, folding, find and replace (PCRE), go to line, completions, hovers, diagnostics, inlay hints and suggestions, the changes against a base in the gutter, Zed's commands and key bindings, input methods at every caret; 28 languages highlighted incrementally; only the lines in view laid out. Diff viewer: split or unified, incremental, words or characters changed, collapsed unchanged lines, Revert and Keep, line selection, annotations, git patches | works (`examples/code-editor`) |
| Accessibility: VoiceOver reads and works every widget, the code editor and the diff viewer (mygo's tree of roles, names, states and actions over `NSAccessibilityElement`s) | new, to try with VoiceOver |
| Animation: curves, springs, delays, jumps, colors, keyframes and loops by node and key (mygo's motion), timelines of tweens placed along one playhead (paused, sought, reversed, repeated), Reduce Motion; the switch, sliders, collapsibles, accordions and sidebar sections move as mygo's | new |
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
php tests/run.php                      # the headless tests of the UI (no window): layout, keys, focus, the widgets and files examples
php tests/bench.php                    # the cost of a frame, headless: build, layout, record, paint, for the examples' views
./run.sh notes            # Markdown notes: search, toolbar, resizable panes, live preview, autosave
./run.sh photos ~/Pictures # a photo browser: thumbnail grid, one full size, arrow keys
./run.sh http             # an HTTP client over curl: requests kept in a list, JSON colored
./run.sh glass            # Liquid Glass over a photo
./run.sh widgets          # the small controls on one page
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
| 原生 UI 里的图片（PNG、JPEG、HEIC、ICNS…），可圆角、可透明、五种适配方式（contain/cover/fill/scale-down/natural）、可灰度 | 可用（`examples/native`、`widgets`） |
| 粗体、斜体、等宽文字；富文本 span（颜色、下划线、高亮、换行） | 可用（`examples/native`、`logview`） |
| 软阴影、描边环、Liquid Glass（模糊、边缘透镜折射、高光边、着色、按压放大）、背景模糊、滚动边缘 | 可用（`examples/glass`） |
| 文本框和多行文本区：输入法、选区、自动换行、撤销、Edit 菜单、Tab 焦点；复选框、单选、滑块、下拉选择、链接、进度条、提示 | 可用（`examples/form`） |
| 开关、步进器、分段控件、标签页、折叠区、可拖分栏、表格（可排序、可拖列宽）、分组侧栏、树、手风琴、星级、范围滑块、工具栏（含开关按钮）、模态对话框、弹出面板、菜单按钮、toast 提示、搜索框、数字输入、水平指示条、加载指示、徽标、分隔线、占位 | 可用（`examples/widgets`、`notes`、`http`） |
| 主题：跟随系统明暗和强调色，实时切换 | 可用 |
| `myphp init / dev / build`：脚手架、改动重启、.app 打包（exec 或静态 micro 启动器） | 可用 |
| App / Window API（whenReady、run、WindowOptions、show/hide/close/setTitle） | 可用，mygo 的子集 |
| 网页：`Ipc::bind()` 绑定 PHP 服务，页面直接调用，事件回推，流式 channel，页面侧窗口控制，preload 脚本，生成 TypeScript client | 可用（`examples/webview`） |
| 菜单栏（角色、快捷键）、右键菜单、打开/保存/消息对话框 | 可用（`examples/desktop`） |
| 托盘图标、全局快捷键、通知（需打包的 .app）、窗口事件、文件拖放、记住窗口位置 | 可用（`examples/desktop`） |
| 不阻塞界面的子进程（`Process`）：curl、脚本、命令行工具 | 可用（`examples/http`） |
| SVG：图标按蒙版任意着色（`rotate` 可旋转），图片按自身颜色绘制——路径、基本图形、变换、渐变、描边与虚线、不透明度、裁剪、遮罩、样式表——由纯 PHP 渲染器绘制，路径蒙版也由它栅格化（不再用 CoreGraphics） | 可用（`examples/widgets`、`files`） |
| 语言与地区格式：应用语言随用户设置（或 `App::setLocale`，记在应用 defaults 里），MyPHP 自带文案和菜单跟着切换（en、zh-Hans、zh-Hant），日期、日历、时间按地区格式与语言名称显示（通过 `ext-intl` 用 ICU） | 可用（`examples/widgets`） |
| 文件管理器：常用位置侧栏、路径面包屑、目录以图标或可排序表格显示且共用一个选择、查找条、图片预览、经 Finder 移到废纸篓 | 可用（`examples/files`） |
| 无障碍：VoiceOver 可朗读并操作所有控件、代码编辑器和 diff 查看器（mygo 的角色、名称、状态与动作树，基于 `NSAccessibilityElement`） | 新加，待 VoiceOver 实测 |
| 动画：按节点和键的曲线、弹簧、延迟、跳变、颜色、关键帧和循环（mygo 的 motion），沿同一播放头编排补间的时间线（可暂停、拖动定位、倒放、重复），遵循「减弱动态效果」；开关、滑块、折叠区、手风琴和侧边栏分组按 mygo 的方式动起来 | 新加 |
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
php tests/run.php                      # UI 的无窗口测试：布局、按键、焦点、widgets 和 files 例子
php tests/bench.php                    # 无窗口压测：一帧的 build / layout / record / paint 耗时
./run.sh notes            # Markdown 笔记：搜索、工具栏、可拖分栏、实时预览、自动保存
./run.sh photos ~/Pictures # 照片浏览：缩略图网格、单张全尺寸、方向键翻页
./run.sh http             # HTTP 客户端（curl 子进程）：请求列表、JSON 着色
./run.sh glass            # 照片上的 Liquid Glass
./run.sh widgets          # 小控件合集
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
