# Android 平台规范与 Google Play 上架合规指南

本文档为 MyPHP 维护者及后续 Coding Agent 提供 Android 平台开发、打包与 Google Play 上架合规的准则。

---

## 一、Google Play 核心政策与合规铁律

### 1. 严禁远程下发可执行代码（DDA 4.9 条红线）
- **条款规定**：Google Play 开发者分发协议（DDA）第 4.9 条明确禁止应用从任何非 Google Play 渠道动态下载、安装或更新可执行代码（包括原生二进制、动态加载的 DEX 或篡改核心功能的脚本）。
- **合规做法**：
  - 生产发版（`myphp build`）将应用的 PHP 代码、`vendor/` 与裁剪后的框架编译进安装包自带的原生只读库 **`lib/arm64-v8a/libapp.so`**（包内零 `.php` 明文源码，类似 Flutter 的 `libapp.so`）；开发调试（`myphp run`）作为离线静态 Assets 随包封装以支持秒级热重载；
  - 严禁通过远程服务器下载外部 `.so`、`.dex` 或在线替换 PHP 执行主入口；
  - 遵循与 React Native、Unity、Flutter 同类的内嵌解释运行时合规范式。

### 2. Android 15+ 16KB 内存页面对齐（2025 年强制新规）
- **规则要求**：针对 Google Play 新应用及更新，编译的目标原生共享库（`.so`）必须支持 16KB 物理内存页面（Page-size alignment 16384 bytes）。
- **技术实现**：
  - NDK 编译原生二进制时，必须显式传递链接参数：`-Wl,-z,max-page-size=16384`；
  - 验证命令：
    ```bash
    readelf -l build/android/libs/arm64-v8a/*.so | grep -E "LOAD|align"
    ```
    确认所有 LOAD 段的对齐为 `align 2**14`（16384 字节）。

### 3. 正式发布签名（Release Keystore）
- **禁止项**：严禁将使用默认 `debug.keystore`（密码 `android`，别名 `androiddebugkey`）签名的包提交到 Google Play Console，会被系统直接报错拒绝。
- **签名配置**：
  打包时通过环境变量指定开发者的生产签名证书：
  ```bash
  export ANDROID_KEYSTORE_PATH="/path/to/your/release.jks"
  export ANDROID_KEYSTORE_PASS="your-keystore-password"
  export ANDROID_KEY_ALIAS="your-key-alias"
  export ANDROID_KEY_PASS="your-key-password"
  ```

### 3.5 标准 SELinux 策略下必须能运行
- 不要只在「SELinux 为 permissive」的手机上验证：多数用户的设备是 enforcing。opcache 默认用 `memfd` 做锁，`fcntl()` 在标准策略下被拒绝（`Cannot create lock - Permission denied`，应用一启动就崩溃），permissive 的手机只会在日志里留一条 `avc: denied`，看不出来。
- 做法：`build-android-php.sh` 编译 PHP 时去掉 `memfd_create`，opcache 改为在 `opcache.lockfile_path`（启动器设为应用的缓存目录）里建一个文件锁。
- 验证：用 enforcing 的模拟器（`getenforce` 为 `Enforcing`，系统镜像是 `user` 版本），比如 Android TV 的 arm64 镜像；日志里不应出现该应用的 `avc: denied`。

### 4. 提交格式要求（.aab 格式）
- Google Play 官方强制要求新上架应用必须提交 **Android App Bundle (`.aab`)** 格式，单一 `.apk` 仅用于开发调试与离线分发。
- 出包命令：`myphp build -platform android/arm64 -format aab -channel play`（`-channel play` 去掉 `REQUEST_INSTALL_PACKAGES` 并关闭应用内 APK 自更新；`-format` 默认是 `apk`）。输出在 `dist/<name>.aab`。
- **只出 64 位（`arm64-v8a`）**：MyPHP 的框架依赖 64 位的 PHP 整数（纳秒时钟 `hrtime(true)` 在 32 位 PHP 上返回浮点而不是整数，大量 `int` 字段和 `strict_types` 会直接报错；还有超过 2³¹ 的常量、`pack('P'|'J'|'q'|'Q')` 等 64 位格式在 32 位 PHP 不可用）。所以没有 `armeabi-v7a` 运行库，AAB/APK 里只有 `arm64-v8a`，Google Play 会自动不向仅支持 32 位的设备分发。要支持 32 位设备，得先让整个框架对 32 位整数无依赖，那是单独的大工程。
- 实现与注意：
  - 不依赖 Gradle 和 bundletool：用 `aapt2 --proto-format` 链接资源，把 base 模块（`manifest/`、`dex/`、`lib/`、`assets/`、`res/`、`resources.pb`）和 `BundleConfig.pb` 打成 zip，再用 `jarsigner` 签名（`ANDROID_KEYSTORE_*` 那把上传密钥，Google Play 用它校验，并用自己的应用签名密钥给分发的 APK 签名）。
  - 清单里的 `android:extractNativeLibs="true"` 保留：随包的 `lib*.so` 如果是要当程序运行的（例如子进程），Play 分发时仍会把它解压到应用的原生库目录。
  - 可以用 Google 的 bundletool 自行校验：`java -jar bundletool.jar validate --bundle=dist/<name>.aab`，或 `build-apks --mode=universal` 生成一个通用 APK 装机试。

---

## 二、Android UI 与渲染架构铁律

1. **纯 GPU 即时模式渲染，不退化为 WebView 伪原生**：
   - 界面使用 EGL + GLES 3.2 绘制在 `SurfaceView`，由 `AChoreographer` 120Hz/60Hz VSYNC 脉冲驱动；
   - 仅当明确需要展示 Web 页面时，才通过 `Platform\Android\WebView` 叠放嵌入；
2. **软键盘与窗口策略（`adjustNothing`）**：
   - `AndroidManifest.xml` 中 Activity 统一使用 `android:windowSoftInputMode="adjustNothing"`；
   - 保持底层 GPU 缓冲区尺寸永远恒定，软键盘弹起时由 `WindowInsets.Type.ime()` 同步安全区，不让系统强行向上顶飞或缩放窗口；
3. **自适应图标（Adaptive Icon）规范**：
   - 必须配置 Android 8.0+（API 26+）自适应图标：`res/mipmap-anydpi-v26/ic_launcher.xml`，区分独立的前景矢量图层（`ic_launcher_foreground`）与背景色图层（`ic_launcher_background`），适配圆形、圆角矩形等多种系统遮罩裁切。
