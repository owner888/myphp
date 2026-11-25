# Building Apps with AI Agents

MyPHP is designed from the ground up for **AI-assisted development** (with
[Pig](https://pigagent.dev), Claude Code, Cursor, Codex, or Windsurf). Because
MyPHP's native UI uses **SwiftUI's exact view names, modifiers, and declarative
patterns** in pure PHP 8.5, and includes built-in headless testing and one-shot
window screenshot tools, an AI coding agent can build, test, visually inspect,
and package a 5-platform desktop & mobile app autonomously in minutes.

---

## 1. Install the Bundled `myphp-app` Skill

Every project created with `myphp init` automatically receives the official
`myphp-app` agent skill inside `.agents/skills/myphp-app/SKILL.md` (and you can
install or update it in any existing project at any time):

```sh
# 1. Create a new project (includes .agents/skills/myphp-app out of the box)
myphp init my-app
# or for a 5-platform 3D app:
myphp init -template 3d my-3d-app

# 2. Or install/update the bundled skill in an existing project
myphp install-skills .

# 3. Optional: install into your global Claude Code / Pig skills directory
cp -R .agents/skills/myphp-app ~/.claude/skills/
```

### What the `myphp-app` skill gives your agent
- **SwiftUI → MyPHP Cheat Sheet**: Exact PHP 8.5 function signatures for
  `VStack`, `HStack`, `TabView`, `NavigationStack`, `ListView`, `Form`, `Chart`,
  `AsyncImage`, `SceneView` (3D), and `SpriteView` (2D/3D games).
- **Clean State & Hot-Reload Architecture**: Enforces keeping state and business
  logic in `src/` classes while keeping `view.php` and `pages/*.php` as pure
  declarative closures bound via `Content::file()`.
- **Self-Verification Loop**: Teaches the agent how to run headless unit tests
  (`tests/ui_test.php`), take automated PNG screenshots (`myphp screenshot`),
  and deploy to iOS Simulator / Android devices (`myphp run -d ios|android`).

---

## 2. How to Prompt Your Agent (Copy-Paste Prompts)

When starting a task with your AI agent inside your project folder, give it a
clear prompt that names the **screens**, **data/state**, and **target
platforms**. Here are three battle-tested prompt templates:

### Prompt A: Cross-Platform Desktop + Mobile App (Adaptive UI)

> **Prompt:**
> "Read `.agents/skills/myphp-app/SKILL.md`. Build a cross-platform expense
> tracker app in this MyPHP project that runs on macOS, Windows, Linux, iOS, and
> Android:
> 1. Put the state and SQLite persistence (`MyPHP\Plugins\Sqlite\Sqlite`) in
>    `src/ExpenseStore.php`.
> 2. Use an adaptive `TabView` (`TabViewStyle::sidebarAdaptable`) in `view.php`
>    with three tabs: **Transactions** (a searchable `ListView` with swipe-to-delete
>    and a `+` sheet to add an expense), **Analytics** (a Swift Charts `Chart`
>    with `BarMark` by category and `SectorMark` donut breakdown), and
>    **Settings** (currency picker and CSV export).
> 3. Write a headless test in `tests/ui_test.php` that adds two expenses,
>    verifies the total, and tests deleting one. Run the test and take a
>    screenshot with `myphp screenshot -out /tmp/app.png` to verify the layout."

### Prompt B: Interactive 3D App or 3D Game (`SceneView` / `SpriteView`)

> **Prompt:**
> "Read `.agents/skills/myphp-app/SKILL.md` and `docs/ui/scene3d.md`. Build an
> interactive 3D solar system explorer:
> 1. In `src/SolarSystem.php`, create a `MyPHP\Plugins\Scene\Scene` with
>    1024×1024 PCF shadows, a central glowing Sun (`Mesh::sphere`, `PointLight`),
>    and orbiting planets with child moons (`Entity::addChild`) and Cook-Torrance
>    PBR materials (`Material::pbr`).
> 2. When the user clicks/taps any planet (`onPick`), trigger a 3D particle
>    burst (`ParticleEmitter3D`), animate its scale with `Tween3D`, and display
>    its orbital stats in a floating 2D HUD badge using `Camera3D::worldToScreen`.
> 3. Add a sidebar on desktop / bottom drawer on mobile (`Device::isDesktop()`)
>    with a time-scale `Slider` and camera focus buttons. Verify with
>    `tests/ui_test.php` and `myphp screenshot`."

### Prompt C: Calling Native iOS / macOS / Android / C APIs (`ffigen` & `jnigen`)

> **Prompt:**
> "We need to read native hardware battery and device thermal state on Android
> and iOS. Do not write C glue by hand:
> 1. Run `myphp jnigen -namespace App\\Native -out src/Native/BatteryManager.php android.os.BatteryManager`
>    for Android.
> 2. Run `myphp ffigen -objc UIDevice -framework UIKit -namespace App\\Native -out src/Native/UIDevice.php ...`
>    for iOS.
> 3. Wrap both in `src/DeviceMonitor.php` and display live battery & charging
>    state in `view.php`."

---

## 3. The Agent's 4-Step Autonomous Verification Loop

What makes MyPHP uniquely productive with AI agents is that **the agent never
has to guess whether its UI works or looks right**. The `myphp` CLI gives the
agent four fast, deterministic feedback tools:

### Step 1: Instant Syntax & Type Check (`< 50 ms`)
```sh
php -l src/ExpenseStore.php && php -l view.php
```

### Step 2: Headless Interaction Test (`< 100 ms`, no window needed)
Using `tests/Headless.php`, the agent builds your `view.php` in memory, clicks
buttons by title or accessibility label, types into text fields, and asserts
state changes at unit-test speed:

```php
$h = new Headless(Content::file(__DIR__ . '/../view.php', $app)->view, 402, 874);
$h->rt->touch = true;
$h->rt->strict = true; // catches duplicate keys immediately
$h->frame();

$h->clickText('Add Expense');
$h->frame();
$h->clickLabel('Title');
$h->type('Coffee');
$h->enter();
$h->frame();

same(1, count($app->items), 'expense added');
```

### Step 3: Visual Screenshot & Accessibility Tree Inspection (`< 1 s`)
With `myphp screenshot`, the agent launches the real GPU window once, waits for
animations and async fibers to settle, and captures both a **pixel-accurate PNG
screenshot** (which multimodal agents like Pig / Claude / Gemini inspect
visually) and the **accessibility element tree**:

```sh
myphp screenshot -size 1080x720 -theme dark -out /tmp/desktop-dark.png .
myphp screenshot -size 402x874 -theme light -out /tmp/mobile-light.png .
```

### Step 4: 4-Platform Live Verification (`macOS`, `Windows`, `iOS`, `Android`)
When iterating or verifying a feature across desktop and mobile platforms:

```sh
myphp dev                  # macOS / Linux desktop: hot reloads view.php on every save
myphp run -d ios .         # iOS Simulator / iPhone / iPad: syncs & hot reloads on save
myphp run -d android .     # Android phone / Enforcing emulator: syncs & hot reloads on save
```

Every time the agent edits and saves `view.php` or `pages/*.php`, your running
desktop or mobile app updates its UI on the spot while keeping your current
state!

For **Windows** verification from a macOS host (with a local Windows 11 VM
reachable via passwordless SSH, e.g. `ssh win`), install native PHP 8.4/8.5
(`ext-ffi` enabled) and MinGW/LLVM-MinGW inside Windows — because `myphp build`
on Windows bundles the host Windows `php.exe` and `ext/*.dll` alongside a
compiled GUI launcher rather than cross-compiling from macOS. The agent syncs
the project over SSH, runs `php -d ffi.enable=1 tests\ui_test.php` and
`bin\myphp.cmd build -skip-installer .`, and captures a real Direct3D 11 + Mica
window screenshot in the logged-in desktop session via `schtasks /IT` (since
OpenSSH runs in Session 0) before `scp`-ing the PNG back for visual inspection.

---

## 4. Feeding Full Documentation to LLMs (`llms.txt`)

If you use an AI IDE or agent that supports documentation indexing (such as
Cursor `@Docs` or custom RAG), point it to:

- **`https://myphp.ai/llms.txt`** — A structured index of every MyPHP guide,
  declarative UI component, 3D engine API, and plugin reference.