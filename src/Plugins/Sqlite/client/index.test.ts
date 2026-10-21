// The page side of the plugin (mygo's src/index.test.ts), over a fake
// window.myphp whose calls the tests answer: bun test, here.
import { afterEach, expect, test } from "bun:test";
import { open } from "./index";

type Handler = (...args: unknown[]) => unknown;

/** A window.myphp whose call() answers from handlers, as PHP would, and keeps the calls (mygo's fakeGo). */
function fakeMyPHP(handlers: Record<string, Handler>) {
  const calls: [string, unknown[]][] = [];
  const g = globalThis as unknown as { window?: unknown };
  const before = g.window;
  g.window = {
    myphp: {
      async call(method: string, ...args: unknown[]) {
        calls.push([method, JSON.parse(JSON.stringify(args))]);
        const h = handlers[method];
        if (!h) throw new Error(`no bound method ${method}`);
        return h(...args);
      },
    },
  };
  return { calls, uninstall: () => { g.window = before; } };
}

let php: ReturnType<typeof fakeMyPHP> | undefined;
afterEach(() => php?.uninstall());

test("encodes parameters and decodes every SQLite storage class", async () => {
  php = fakeMyPHP({
    "plugin:sqlite.open": () => "db-1",
    "plugin:sqlite.query": () => ({
      columns: ["n", "n", "big", "real", "text", "blob", "emptyBlob", "emptyText", "zero", "negative"],
      rows: [[{ type: "null" }, { type: "integer", integer: "42" }, { type: "integer", integer: "9223372036854775807" },
        { type: "real", real: 0.25 }, { type: "text", text: "中文\0🙂" }, { type: "blob", blob: "AP8=" },
        { type: "blob" }, { type: "text" }, { type: "real" }, { type: "integer", integer: "-9223372036854775808" }]],
    }),
  });
  const db = await open("app.db", { readOnly: true });
  const blob = new Uint8Array([9, 0, 255, 9]).subarray(1, 3);
  const rows = await db.query("SELECT ?, ?, ?, ?, ?, ?, ?, ?", [null, true, 42, -9223372036854775808n, 0.25, "中文\0🙂", blob, new Uint8Array()]);
  expect(rows.columns.slice(0, 2)).toEqual(["n", "n"]);
  expect(rows.rows[0]).toEqual([null, 42, 9223372036854775807n, 0.25, "中文\0🙂", new Uint8Array([0, 255]), new Uint8Array(), "", 0, -9223372036854775808n]);
  expect(php.calls[0]).toEqual(["plugin:sqlite.open", ["app.db", { readOnly: true }]]);
  expect(php.calls[1]![1][2]).toEqual([
    { type: "null" }, { type: "integer", integer: "1" }, { type: "integer", integer: "42" },
    { type: "integer", integer: "-9223372036854775808" }, { type: "real", real: 0.25 },
    { type: "text", text: "中文\0🙂" }, { type: "blob", blob: "AP8=" }, { type: "blob", blob: "" },
  ]);
});

test("execute and transaction preserve rowids, and send a single atomic batch", async () => {
  const reply = { changes: 1, lastInsertId: "9007199254740993" };
  php = fakeMyPHP({ "plugin:sqlite.open": () => "db", "plugin:sqlite.execute": () => reply, "plugin:sqlite.transaction": () => [reply, reply] });
  const db = await open(":memory:");
  expect(await db.execute("INSERT", [false])).toEqual({ changes: 1, lastInsertId: 9007199254740993n });
  expect(await db.transaction([{ sql: "INSERT", params: [1] }, { sql: "UPDATE" }])).toEqual([
    { changes: 1, lastInsertId: 9007199254740993n }, { changes: 1, lastInsertId: 9007199254740993n },
  ]);
  expect(php.calls[2]).toEqual(["plugin:sqlite.transaction", ["db", [{ sql: "INSERT", args: [{ type: "integer", integer: "1" }] }, { sql: "UPDATE", args: [] }]]]);
});

test("rejects unsafe parameters before making an IPC call", async () => {
  php = fakeMyPHP({ "plugin:sqlite.open": () => "db" });
  const db = await open(":memory:");
  for (const param of [NaN, Infinity, -Infinity, Number.MAX_SAFE_INTEGER + 1, 1n << 63n, -(1n << 63n) - 1n]) {
    await expect(db.execute("SELECT ?", [param])).rejects.toThrow("sqlite:");
  }
  await expect(db.transaction([{ sql: "SELECT 1" }, { sql: "SELECT ?", params: [NaN] }])).rejects.toThrow("finite");
  expect(php.calls.length).toBe(1);
});

test("large BLOBs do not overflow the browser's argument stack", async () => {
  php = fakeMyPHP({ "plugin:sqlite.open": () => "db", "plugin:sqlite.execute": () => ({ changes: 0, lastInsertId: "0" }) });
  const db = await open(":memory:");
  const bytes = new Uint8Array(256 * 1024).fill(255);
  await db.execute("SELECT ?", [bytes]);
  const [arg] = php.calls[1]![1][2] as [{ blob: string }];
  expect(atob(arg.blob).length).toBe(bytes.length);
});

test("close is idempotent and rejects further use", async () => {
  php = fakeMyPHP({ "plugin:sqlite.open": () => "db", "plugin:sqlite.close": () => undefined });
  const db = await open(":memory:");
  const a = db.close();
  const b = db.close();
  expect(a).toBe(b);
  await expect(db.query("SELECT 1")).rejects.toThrow("closed");
  await Promise.all([a, b]);
  await db.close();
  await expect(db.execute("SELECT 1")).rejects.toThrow("closed");
  expect(php.calls.length).toBe(2);
});

test("propagates PHP errors and permits retrying a failed close", async () => {
  let attempts = 0;
  php = fakeMyPHP({
    "plugin:sqlite.open": () => "db",
    "plugin:sqlite.query": () => { throw new Error("sqlite: no such table"); },
    "plugin:sqlite.close": () => { if (++attempts === 1) throw new Error("close failed"); },
  });
  const db = await open(":memory:");
  await expect(db.query("SELECT * FROM missing")).rejects.toThrow("no such table");
  await expect(db.close()).rejects.toThrow("close failed");
  await db.close();
  expect(attempts).toBe(2);
});

test("decodes infinite REAL results without losing their SQLite type", async () => {
  php = fakeMyPHP({
    "plugin:sqlite.open": () => "db",
    "plugin:sqlite.query": () => ({ columns: ["positive", "negative"], rows: [[{ type: "real", special: "Infinity" }, { type: "real", special: "-Infinity" }]] }),
  });
  const db = await open(":memory:");
  expect((await db.query("SELECT 1e999, -1e999")).rows).toEqual([[Infinity, -Infinity]]);
});
