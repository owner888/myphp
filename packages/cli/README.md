# myphp-cli

The `myphp` command of [MyPHP](https://github.com/owner888/myphp) for the
scripts of a project's package.json, and the types of `myphp.config.ts`.

MyPHP's command line tool is PHP, part of the framework, which the project
installs with Composer:

```sh
composer require owner888/myphp
npm install -D myphp-cli
```

```json
{
  "scripts": {
    "dev": "myphp dev",
    "build": "myphp build"
  }
}
```

`myphp` runs, in this order, `$MYPHP_CLI_BINARY` when it is set; bin/myphp
of the MyPHP checkout the package is part of (a `file:` dependency, as
`myphp init -template web` writes it); else
`vendor/owner888/myphp/bin/myphp` in the current directory or the nearest
directory above it that has one. It needs PHP 8.3 or later with FFI.

`defineConfig` types the configuration of `myphp.config.ts`, which takes
the place of myphp.json:

```ts
import { defineConfig } from "myphp-cli";

export default defineConfig({
  name: "My App",
  identifier: "com.example.myapp",
  devUrl: "http://localhost:5173",
});
```
