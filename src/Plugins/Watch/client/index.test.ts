// The page side of the watch plugin (mygo's src/index.test.ts), over
// MyPHP's real page runtime: bun test, here.
import { afterEach, expect, test } from "bun:test";
import { fakeMyPHP } from "../../fake-myphp";
import { type FileEvent, watch, WatchError } from "./index";

let cleanup = () => {};
afterEach(() => cleanup());

test("streams the batches, and close waits for PHP", async () => {
  let cleaned = false;
  const php = fakeMyPHP({
    "plugin:watch.watch": async ({ send, signal, args }) => {
      expect(args[0]).toEqual({ root: "project", path: ".", recursive: false, debounceMs: 0 });
      send(1, { type: "ready" });
      send(1, {
        type: "events",
        events: [
          { op: "create", path: "a", isDir: false },
          { op: "write", path: "b", isDir: false },
        ],
      });
      send(1, { type: "events", events: [{ op: "remove", path: "a", isDir: false }] });
      await new Promise<void>((resolve) => signal.addEventListener("abort", () => resolve(), { once: true }));
      cleaned = true;
    },
  });
  cleanup = php.uninstall;
  const w = await watch("project");
  const iterator = w[Symbol.asyncIterator]();
  expect((await iterator.next()).value?.map((e: FileEvent) => e.op)).toEqual(["create", "write"]);
  expect((await iterator.next()).value?.map((e: FileEvent) => e.op)).toEqual(["remove"]);
  await w.close();
  await w.close();
  expect(cleaned).toBe(true);
});

test("a watch that ends ends the iteration", async () => {
  const php = fakeMyPHP({
    "plugin:watch.watch": ({ send, args }) => {
      expect(args[0]).toEqual({ root: "project", path: "config.json", recursive: false, debounceMs: -1 });
      send(1, { type: "ready" });
      send(1, { type: "events", events: [{ op: "remove", path: ".", isDir: false }] });
    },
  });
  cleanup = php.uninstall;
  const w = await watch("project", { path: "config.json", debounceMs: -1 });
  const batches = [];
  for await (const batch of w) batches.push(batch);
  expect(batches).toEqual([[{ op: "remove", path: ".", isDir: false }]]);
  await expect(w.closed).resolves.toBeUndefined();
});

test("errors are WatchErrors with their code", async () => {
  const php = fakeMyPHP({
    "plugin:watch.watch": ({ send, args }) => {
      if ((args[0] as { root: string }).root === "denied") {
        return { code: "denied", message: "filesystem watch denied" };
      }
      send(1, { type: "ready" });
      send(1, { type: "events", events: [{ op: "create", path: "a", isDir: false }] });
      return { code: "limit", message: "filesystem watch limit" };
    },
  });
  cleanup = php.uninstall;
  const denied = await watch("denied").catch((err) => err);
  expect(denied).toBeInstanceOf(WatchError);
  expect(denied.code).toBe("denied");
  const w = await watch("project");
  const seen: unknown[] = [];
  const iterate = (async () => {
    for await (const batch of w) seen.push(batch);
  })();
  await expect(iterate).rejects.toBeInstanceOf(WatchError);
  await expect(w.closed).rejects.toMatchObject({ code: "limit" });
  expect(seen).toHaveLength(1);
  await expect(w.close()).resolves.toBeUndefined();
});

test("invalid options and an aborted signal never call PHP", async () => {
  const php = fakeMyPHP({});
  cleanup = php.uninstall;
  await expect(watch("project", { debounceMs: NaN })).rejects.toBeInstanceOf(TypeError);
  await expect(watch("project", { debounceMs: 60001 })).rejects.toBeInstanceOf(TypeError);
  const signal = AbortSignal.abort("stop");
  await expect(watch("project", { signal })).rejects.toBe("stop");
  expect(php.calls).toHaveLength(0);
});
