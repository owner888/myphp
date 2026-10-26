// Where myphp-cli finds the myphp command: bun test, here.
import { afterEach, expect, test } from "bun:test";
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { binaryPath, defineConfig } from "./index.js";

const checkout = join(import.meta.dir, "..", "..", "bin", "myphp");
const saved = process.env.MYPHP_CLI_BINARY;
const dirs: string[] = [];
afterEach(() => {
  if (saved === undefined) delete process.env.MYPHP_CLI_BINARY;
  else process.env.MYPHP_CLI_BINARY = saved;
  for (const d of dirs.splice(0)) rmSync(d, { recursive: true, force: true });
});

test("MYPHP_CLI_BINARY comes first", () => {
  process.env.MYPHP_CLI_BINARY = "/opt/myphp/bin/myphp";
  expect(binaryPath()).toBe("/opt/myphp/bin/myphp");
});

test("in a MyPHP checkout, the checkout's bin/myphp", () => {
  delete process.env.MYPHP_CLI_BINARY;
  expect(binaryPath(tmpdir())).toBe(checkout);
});

test("defineConfig returns what it is given", () => {
  const config = { name: "Notes" };
  expect(defineConfig(config)).toBe(config);
});

// The checkout comes first, so the search of vendor/ runs on a copy of this package outside it.
test("installed from npm: Composer's vendor/owner888/myphp, in the directory or above it", async () => {
  delete process.env.MYPHP_CLI_BINARY;
  const root = mkdtempSync(join(tmpdir(), "myphp-cli-"));
  dirs.push(root);
  const pkg = join(root, "node_modules", "myphp-cli");
  mkdirSync(pkg, { recursive: true });
  writeFileSync(join(pkg, "index.js"), await Bun.file(join(import.meta.dir, "index.js")).text());
  const { binaryPath: installed } = await import(join(pkg, "index.js"));
  const web = join(root, "web", "src");
  mkdirSync(web, { recursive: true });
  expect(() => installed(web)).toThrow("composer require owner888/myphp");
  const bin = join(root, "vendor", "owner888", "myphp", "bin");
  mkdirSync(bin, { recursive: true });
  writeFileSync(join(bin, "myphp"), "");
  expect(installed(web)).toBe(join(bin, "myphp"));
});
