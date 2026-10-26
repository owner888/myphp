# myphp-runtime

Typed access to the runtime of MyPHP apps,
for their web frontends.

MyPHP injects its runtime into every page of an app as `window.myphp`. This
package gives it types and imports. The client that `myphp generate` writes
for your bound PHP services is built on it, so you mostly use that:

```ts
import { Greeter, events } from "./myphp"; // generated

const greeting = await Greeter.greet("Ada");
events.tick.on((time) => console.log(time));
```

Install it from npm, `npm install myphp-runtime`, or depend on it from the
MyPHP the app runs on, `"myphp-runtime": "file:<MyPHP>/packages/runtime"`,
as `myphp init -template web` writes it. Its modules are TypeScript, which
Vite and Bun take as they are.

Use the package directly for the window and the platform:

```ts
import { currentWindow, isCallError, isMyPHP, runtime } from "myphp-runtime";

if (isMyPHP() && runtime().platform === "darwin") {
  document.body.classList.add("mac");
}
await currentWindow.toggleMaximize();
```

A PHP method that streams values through a `MyPHP\Channel` parameter takes
a `Channel` in its place:

```ts
import { Channel } from "myphp-runtime";

const lines = new Channel<string>();
const done = Shell.tail("ls -R", lines);
for await (const line of lines) console.log(line);
await done;
```

A PHP method that throws rejects with a `CallError` (see
`isCallError`). Outside a MyPHP window, e.g. when the dev server is opened in a
browser, calls reject and `isMyPHP()` is false.

`myphp-runtime/i18n` formats the messages of the app's catalogs in its pages,
as `MyPHP\Message` does in PHP: messages of ICU MessageFormat, with the plural
rules, numbers and dates of `Intl`. `myphp generate` writes a typed module of
the catalogs next to the client (`locales.ts`) on top of it:

```ts
import { messages } from "./locales"; // generated

status.textContent = messages.filesSelected(3); // "3 files selected"
```

`formatMessage(locale, message, args)` formats a message of your own.
