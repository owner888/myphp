# myphp Android 后端：交接说明

## 目标

给 myphp 加 Android 平台，**高性能原生 UI**：和桌面端同一套即时模式 UI，在 GPU 上自己画，不走 WebView 渲染界面。原则和 Linux 移植一样：核心只通过 `App::backend()`（`src/Platform/` 的接口）调用系统，先把 Android 需要的接口抽出来，再在 `src/Platform/Android/` 里实现。macOS、Linux 现有行为不能变。

先读：`AGENTS.md`（规则，必须遵守）、`docs/architecture.md`（尤其是 Linux 那几节：GL 渲染、GTK 主循环、输入法、FFI 纪律）、偏差文档（AGENTS.md 规则 10 指定的那个），以及 `src/Platform/Linux/` 整个目录。Linux 后端就是「同一套接口的第二个实现」的范例。

上游没有 Android，也没有触屏支持：Android 专属的一切（触屏手势、安全区、软键盘、生命周期）都是 myphp 自己的设计，全部记进偏差文档。

## 已定的决定（开发者已确认）

1. **PHP 运行时**：用 NDK 交叉编译 libphp（embed SAPI，含 ffi、opcache 和 JIT、mbstring、intl 视情况、curl、sqlite3、zlib、openssl 等现在用到的扩展）。仓库里只放构建脚本（`scripts/` 下），产物不进 git。static-php-cli 不支持 Android；Termux 的 php 写死了 `/data/data/com.termux`，不能直接用。
2. **允许一个薄 Java 外壳（几百行）加一个 C 启动器**：C 启动器和 macOS 的 C launcher 同类；Java 只做 Java 才能做的事：Activity、SurfaceView、InputConnection（中文输入法）、WebView、系统对话框、SAF 文件选择，以及 JNI 回调入口。业务逻辑不进 Java。
3. **文字走系统 API，minSdk 31（Android 12）**：JNI 调 `TextRunShaper` 做整形、`Canvas.drawGlyphs` 光栅化字形进图集。不打包 HarfBuzz/FreeType。字形图集只在缓存未命中时走 JNI，每帧不穿 JNI。
4. **打包不用 Gradle**：`myphp build -target android` 直接调 aapt2、javac、d8、zipalign、apksigner（开发机装好 SDK build-tools 和 NDK），自己写，和 .deb 一样。
5. **窗口模型**：第一版只有一个全屏主窗口，窗口内可以有模态层（alert、sheet、popover）。第二个 `new Window()` 抛 `Platform\Unsupported`，明确报错。菜单栏、托盘、全局快捷键、Dock、窗口位置大小等：Unsupported，明确报错，不悄悄忽略（AGENTS.md：no silent fallbacks）。
6. **测试设备**：开发者自己的 Android 手机（arm64），通过 adb。

## APK 结构

```
APK
├─ classes.dex   薄 Java 外壳：MyphpActivity（SurfaceView）、输入法 InputConnection、
│                WebView、AlertDialog、SAF、JNI 入口；模板源码放 bin/template/android/
├─ lib/arm64-v8a/
│   ├─ libmyphp.so   C 启动器：JNI_OnLoad / 原生入口，起 PHP 线程，embed SAPI 运行入口脚本
│   └─ libphp.so     NDK 编译的 PHP
└─ assets/        框架 src/ 和应用 PHP 源码；首次启动解压到 files 目录，opcache 文件缓存
```

线程模型：
- PHP 只在自己的线程上运行，这个线程拥有一个 ALooper，作为 run loop（对应 Linux 的 GTK 主循环 `step()`）。
- 帧节奏用 `AChoreographer`（对应 macOS 的 display link），渲染循环不经过 Java。
- Java UI 线程和 PHP 线程之间：输入事件、输入法、生命周期、系统服务结果，经一个 fd（pipe/eventfd）加消息队列传给 PHP 线程，在 ALooper 上唤醒；PHP 调 Java 用 JNI（需要 UI 线程的操作由 Java 侧 post 到主线程）。
- PHP 从 FFI 直接调 NDK C 接口：EGL、GLES3、ANativeWindow、ALooper、AChoreographer、AAudio、AMediaCodec、`__android_log_write`（Logger 输出到 logcat）。
- `src/Native/` 里新写一个 JNI 桥（地位同 ObjC 桥）：JNIEnv 函数表、类和方法 ID 缓存、局部引用管理、字符串转换、回调 holder。

## 各接口对照

| 接口 | Linux | Android |
|---|---|---|
| 渲染 | GTK GLArea + GL | EGL + GLES 3：把 Linux 的 GL 渲染器抽成共用，着色器加 `#version 300 es` 版本；一帧一个缓冲区、一次实例化绘制不变 |
| 文字 | Pango | JNI：TextRunShaper + Canvas.drawGlyphs；系统字体 + 回退 |
| 输入 | GTK 事件 + 输入法上下文 | 触屏翻译成现有指针事件；InputConnection 做软键盘和组字 |
| 窗口 | GtkWindow | 一个 Activity 的 Surface；见决定 5 |
| 对话框、剪贴板、打开链接、通知 | GTK / D-Bus | AlertDialog、ClipboardManager、Intent、NotificationManager（POST_NOTIFICATIONS 权限） |
| 打开/保存文件 | GtkFileChooser | SAF：content:// URI，用 `openFileDescriptor` 拿 fd，给 PHP 用 `/proc/self/fd/N` |
| 密钥 | libsecret | Android Keystore |
| 网络状态 | NetworkManager | ConnectivityManager |
| 屏幕常亮 | portal inhibit | FLAG_KEEP_SCREEN_ON |
| 外观 | GTK 设置 / portal | Configuration.uiMode，跟随变化 |
| Web 页面 | WebKitGTK | android.webkit.WebView：`shouldInterceptRequest` 做自定义 scheme，`addJavascriptInterface` 做 IPC（bridge.js 协议不变） |
| 播放器 | FFmpeg + PulseAudio | AMediaCodec + AAudio（都是 C 接口，纯 FFI） |
| 玻璃、材质 | 自己画 | 自己画（`hasSystemMaterials()` 返回 false，已有） |
| 日志 | stderr | logcat |

## 需要新抽的接口

1. **触屏语义**：点按 = 单击；长按 = 右键（弹上下文菜单）；拖动 = 滚动，松手惯性（复用现有触控板惯性路径）；双指捏合 = 缩放（现有 InputZoom）。没有悬停。第一版只在平台层翻译，UI 核心不改。
2. **文本输入**：把 macOS 的 NSTextInputClient 和 Linux 的 GTK 输入法上下文抽成一个平台文本输入接口，Android 用 InputConnection 实现（组字、提交、删除、选区、显示/隐藏软键盘、光标位置给输入法）。
3. **生命周期**：前台/后台、Surface 销毁和重建（GL 上下文丢失后重建纹理和图集）、内存紧张、返回键（先关模态层，再交给应用，最后退出）。
4. **安全区**：状态栏、导航栏、刘海、软键盘弹出，作为窗口 insets 交给 UI，内容默认避开。
5. **密度和外观**：屏幕密度转缩放比例；深色模式跟随 Configuration。

## 分阶段

| 阶段 | 内容 | 完成标准 |
|---|---|---|
| P0 工具链 | NDK 编 libphp 的脚本；C 启动器；最小 Java 外壳；不用 Gradle 打出签名 APK | 手机上运行，logcat 里有 PHP 打的 "hello" |
| P1 渲染 | 抽出共用 GL 渲染器；EGL + Surface；AChoreographer 驱动帧 | counter-native 能显示，点按能计数，60 fps 无多余重绘 |
| P2 文字和输入 | 文字引擎；触屏手势；文本输入接口 + InputConnection | form 例子能输入中文，列表能滑动带惯性 |
| P3 生命周期 | 切后台再回来、Surface 重建、旋转、安全区、深色、密度 | 旋转和切后台都不崩不黑屏 |
| P4 系统服务 | 剪贴板、对话框、打开链接、通知、SAF、Keystore、网络状态、屏幕常亮 | 对应例子能跑 |
| P5 页面和媒体 | WebView + IPC；AMediaCodec + AAudio 播放器 | webview、video 例子能跑 |
| P6 开发体验 | `myphp build -target android`；`myphp dev` 经 adb 推送并热重载；`myphp screenshot` 支持 Android（adb + 自动化插件） | 改一行 PHP，手机上马上看到 |

每个阶段结束：容器里全套无头测试 0 失败（`php -d ffi.enable=1 tests/run.php`），开发者 Mac 上的测试也 0 失败，手机上按完成标准验证。

## 测试环境

- 开发容器里没有 KVM，跑不了 Android 模拟器；能否下载 SDK/NDK（dl.google.com）要先试。下得了就在容器里打 APK，下不了就在开发者 Mac 上打。
- 真机测试在开发者的手机上：adb 安装、logcat、`adb exec-out screencap -p` 截图。给开发者的验证步骤要写成可直接复制运行的命令。
- 现有无头测试与平台无关，UI 层改动仍由它们兜底；Android 后端另写冒烟脚本。

## 规矩（摘自 AGENTS.md 和开发者的要求）

- 新第三方包先问开发者；小东西自己写。
- git 全由开发者操作：不 commit、不 push、不建分支；只改文件、跑测试、报告改了哪些文件。
- 进 git 的文件里不出现开发者的名字（写 "the developer"），不出现会话链接或账号信息。
- 不悄悄兜底；Unsupported 要明确报错。日志走 `MyPHP\Logger`。
- 每解决一个问题，在 `docs/architecture.md` 记一段；和上游不同的地方记进偏差文档。
- `bin/myphp` 改完要 `chmod 755`。
- 回复开发者用中文。
