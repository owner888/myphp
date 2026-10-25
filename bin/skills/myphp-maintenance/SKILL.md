---
name: myphp-maintenance
description: Maintain PHP desktop applications built with MyPHP, including native UI changes, crash fixes, and framework upgrades. Apply MyPHP-specific view, identity, threading, and testing practices.
---

# MyPHP app maintenance

Check which MyPHP checkout the app uses (`framework` in `myphp.json`, or `$MYPHP_HOME`) and the app's existing conventions before changing APIs. Read the framework's `AGENTS.md`, `docs/architecture.md` and the doc comments of the classes involved; `docs/mygo-deviations.md` lists where MyPHP differs from mygo, whose names its API takes. Confirm that an API exists in that checkout before using it.

The native UI rules below apply where the app uses `MyPHP\UI`; preserve a web app's frontend and its typed TypeScript client (`bindings`, which `myphp generate` writes).

## Views, nodes and identity

- A view is a closure taking a `Context`, built again for every frame it is asked for, possibly several times for one event. Keep the Context out of app state and callbacks that outlive the build.
- The nodes builders return (`$c->button(...)`, `$c->column(...)`) belong to one build. Store app data, widget state (`ListState`, `TextState`, `ScrollState`…) and `Handle`s, never a node.
- Bind persistent identity with `->bind($handle)`. `$handle->focused($c)` and `focusWithin($c)` read the committed focus; `$handle->focus($c)` asks for it and waits while the node is hidden; `cancelFocus($c)` cancels that. `$handle->bounds($c)` gives committed geometry in input callbacks.
- Use `->focusBind($field, $value)` when app data should move the focus, and `$c->focusedValue($field)` for where it actually is.
- Give stateful widgets stable, unique keys with `$c->key($key)` before building them; a container's key comes before its children and their local state.

## Input and actions

- Bound values (`&$value`) update after the build that shows them; `$c->changed($node)` and `$c->submitted($node)` report in the following build. Bind to persistent model fields, not to values computed again each build.
- `->onClick`, `->onChange`, `->onSubmit` and `->onShortcut` run after the build; prefer them when changing a collection being built. `$c->clicked($node)` remains fine for local interactions.
- Declare scoped commands with `$handle->onShortcut($c, $key, $mods, $fn)`; only an enabled node of the current build takes its command.

## State, work and rendering

- Trigger side effects from handled actions or explicit guarded jobs, never unconditionally from a view.
- PHP runs the UI on one thread: keep slow I/O off it with the framework's non-blocking APIs (fetch, file watches, timers) and publish results with `$window->update($fn)`; `invalidate()` asks for a frame without changing state. Drop stale results after the source changed or the window closed.
- Keep `->draw()` callbacks to painting; they can run repeatedly. Prefer MyPHP's widgets to keep keyboard, focus, disabled and accessibility behavior, and the app's theme and sizes.

## Verification

- Reproduce UI failures headless, as the framework's `tests/` do with `Headless`: build frames, drive the pointer and keys, and check what the nodes and the painted list show, including frames where nodes disappear and return.
- Run `php -d ffi.enable=1 tests/run.php` for the framework and the app's own checks, `php -l` on changed files, and look at the running app (`myphp dev`) when layout or platform behavior changes, respecting the user's verification preferences.
- For upgrades, check the APIs the app uses against the new checkout (its doc comments and `docs/`), and rerun the relevant tests. Report what could not be verified on this machine.
