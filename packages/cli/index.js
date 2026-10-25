// myphp-cli runs the myphp command line tool, bin/myphp of the MyPHP
// checkout it is part of, from the scripts of a project's package.json,
// and types myphp.config.ts (mygo-cli's index.js, whose binaries are Go's).
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));

/**
 * Returns the path of the myphp command to run: $MYPHP_CLI_BINARY when
 * set, else bin/myphp of the checkout this package is in.
 */
export function binaryPath() {
  return process.env.MYPHP_CLI_BINARY || join(here, "..", "..", "bin", "myphp");
}

/**
 * Types the configuration of myphp.config.ts: an object, or a function of
 * the command running that returns one.
 */
export function defineConfig(config) {
  return config;
}
