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

### Status

Early. What works today, on macOS / Apple Silicon:

| piece | state |
|---|---|
| Objective-C bridge (`objc_msgSend`, classes with PHP methods) | works |
| WKWebView window, JS → PHP → JS | works (`examples/webview`) |
| Native window, immediate-mode tree + flex layout + paint at 60 fps | works, paints through CoreGraphics (`examples/native`) |
| Metal renderer, CoreText text, input events | next |
| Linux (GTK/WebKitGTK), Windows (Win32/WebView2) | planned |

Measured in `examples/native` on an M-series Mac: 9 000 nodes rebuilt, laid
out and painted every frame in about 4 ms, 60 fps, memory flat at 6 MB.

### Requirements

- PHP ≥ 8.3 with `ext-ffi` (Homebrew's `php` has it), OPcache JIT recommended
- macOS on Apple Silicon for now

### Try it

```sh
./run.sh webview          # a WKWebView window driven by PHP
./run.sh native           # native UI, 300-row list, prints fps once a second
./run.sh native 1000      # 9 000 nodes per frame
```

### Layout

```
src/Native/   OS bridges (ObjC today; GTK and Win32 later)
src/UI/       the toolkit: Node, Context (tree building), Layout (flex)
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

### 现状

早期。目前在 macOS / Apple Silicon 上能跑的：

| 部分 | 状态 |
|---|---|
| Objective-C 桥（`objc_msgSend`、方法为 PHP 闭包的类） | 可用 |
| WKWebView 窗口，JS → PHP → JS 往返 | 可用（`examples/webview`） |
| 原生窗口：每帧重建树 + flex 布局 + 绘制，60 fps | 可用，暂经 CoreGraphics 绘制（`examples/native`） |
| Metal 渲染器、CoreText 文字、输入事件 | 下一步 |
| Linux（GTK/WebKitGTK）、Windows（Win32/WebView2） | 计划中 |

在 M 系列 Mac 上用 `examples/native` 实测：每帧重建、布局并绘制 9 000 个节点约 4 ms，稳定 60 fps，内存恒定 6 MB。

### 环境要求

- PHP ≥ 8.3，带 `ext-ffi`（Homebrew 的 `php` 自带），建议开 OPcache JIT
- 目前仅支持 Apple Silicon 的 macOS

### 运行

```sh
./run.sh webview          # PHP 驱动的 WKWebView 窗口
./run.sh native           # 原生 UI，300 行列表，每秒打印一次 fps
./run.sh native 1000      # 每帧 9 000 个节点
```

### 目录

```
src/Native/   操作系统桥接（现在是 ObjC，之后加 GTK 和 Win32）
src/UI/       工具包：Node、Context（建树）、Layout（flex 布局）
examples/     可运行的示例
docs/         设计笔记
AGENTS.md     编码代理与贡献者的入口
```

### 许可

MIT。
