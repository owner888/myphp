# Android 平台规范与 Google Play 上架合规指南

本文档为 MyPHP 维护者及后续 Coding Agent 提供 Android 平台开发、打包与 Google Play 上架合规的准则。

---

## 一、Google Play 核心政策与合规铁律

### 1. 严禁远程下发可执行代码（DDA 4.9 条红线）
- **条款规定**：Google Play 开发者分发协议（DDA）第 4.9 条明确禁止应用从任何非 Google Play 渠道动态下载、安装或更新可执行代码（包括原生二进制、动态加载的 DEX 或篡改核心功能的脚本）。
- **合规做法**：
  - 应用的 PHP 源码、前端资源及扩展二进制必须**作为离线静态 Assets 一同封装在安装包内**；
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

### 4. 提交格式要求（.aab 格式）
- Google Play 官方强制要求新上架应用必须提交 **Android App Bundle (`.aab`)** 格式，单一 `.apk` 仅用于开发调试与离线分发。

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
