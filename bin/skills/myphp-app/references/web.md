# Web frontends (myphp init -template web)

A window can show a web page instead of native UI: the page is a
TypeScript frontend built with Vite, and PHP serves it services. Read
`docs/getting-started.md`, `docs/bindings.md` and `docs/frontend.md`.
The view rules of SKILL.md are for native UI; the verification steps
still apply, as below.

## The parts

- `myphp.config.ts` is the project's configuration (there is no
  `myphp.json`): `entry`, `devUrl`/`devCommand` for `myphp dev`,
  `frontendDist` (the built page), `bindings` (the generated client).
- `app.php` binds services with `Ipc::bind(new Habits(...))` and opens
  the window with `url: '/'`. Call `App::setName()` first, before any
  service calls `App::path()`: the data folder is named after the app.
- Each public method of a bound service is callable from the page.
  `bun run generate` (`myphp generate`) writes the typed client,
  `src/myphp.ts`, from the PHP signatures and PHPDoc (`list<Habit>`, the
  promoted properties' types). Run it after changing a service.
- Optional parameters: a call that leaves one out, or passes `undefined`
  or `null` to one that does not take null, gets its PHP default.
- User errors: throw `InvalidArgumentException` in PHP; the page gets a
  rejected promise, tested with `isCallError(err)` from `myphp-runtime`,
  whose message it can show.
- Keep the data in PHP (`App::path(PathName::UserData)`), not in
  `localStorage`: `myphp dev` and the built app load the page from
  different origins.
- Push from PHP to the page with an `Event` (`docs/bindings.md`, Events),
  and stream with a `Channel`.

## Checking it

1. Test the services as plain PHP: they are ordinary classes, so a test
   requires them and calls their methods (no FFI needed).
2. `bun run typecheck` for the frontend.
3. `bun run build:web`, then `myphp screenshot -data <tmp dir> -out
   shot.png`: it shows the page from `frontendDist` once it has loaded and
   its pending calls to PHP have answered. `-tree` writes nothing for a
   page; read the page with the automation plugin's `eval`
   (`docs/automation.md`) instead. Take one with `-theme dark` too: the
   page's `prefers-color-scheme` follows it.
4. A headless test as SKILL.md shows needs the framework's path: there is
   no `myphp.json`, so run it with `MYPHP_HOME` set to the checkout.
