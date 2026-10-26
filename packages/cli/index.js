// myphp-cli runs the myphp command line tool, bin/myphp of MyPHP, from the
// scripts of a project's package.json, and types myphp.config.ts
// (mygo-cli's index.js, whose binaries are Go's). MyPHP's CLI is PHP, part
// of the framework: the project's Composer installs it, as
// vendor/owner888/myphp, or the package is part of a MyPHP checkout
// (a file: dependency, as `myphp init -template web` writes it).
import { existsSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));

/** Whether $dir is a MyPHP checkout: its CLI and its sources. */
function isCheckout(dir) {
  return existsSync(join(dir, "bin", "myphp")) && existsSync(join(dir, "src", "App.php"));
}

/**
 * Returns the path of the myphp command to run: $MYPHP_CLI_BINARY when
 * set; bin/myphp of the MyPHP checkout this package is part of; else
 * vendor/owner888/myphp/bin/myphp, which Composer installs, in $cwd or the
 * nearest directory above it that has one.
 */
export function binaryPath(cwd = process.cwd()) {
  if (process.env.MYPHP_CLI_BINARY) return process.env.MYPHP_CLI_BINARY;
  const checkout = join(here, "..", "..");
  if (isCheckout(checkout)) return join(checkout, "bin", "myphp");
  for (let dir = cwd; ; dir = dirname(dir)) {
    const bin = join(dir, "vendor", "owner888", "myphp", "bin", "myphp");
    if (existsSync(bin)) return bin;
    if (dirname(dir) === dir) break;
  }
  throw new Error(
    `myphp-cli: MyPHP is not installed in ${cwd} or above it: install it with Composer, ` +
      "composer require owner888/myphp, or set MYPHP_CLI_BINARY to its bin/myphp",
  );
}

/**
 * Types the configuration of myphp.config.ts: an object, or a function of
 * the command running that returns one.
 */
export function defineConfig(config) {
  return config;
}
