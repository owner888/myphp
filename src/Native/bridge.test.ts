// The page runtime (bridge.js): channels acknowledge the values PHP asks
// about once the page takes them, as mygo's flow control. bun test, here.
import { afterEach, expect, test } from "bun:test";
import { fakeMyPHP, tick } from "../Plugins/fake-myphp";

let php: ReturnType<typeof fakeMyPHP> | undefined;
afterEach(() => php?.uninstall());

interface Runtime {
  call(method: string, ...args: unknown[]): Promise<unknown>;
  Channel: new () => AsyncIterable<unknown> & { close(): void; onmessage: ((v: unknown) => void) | null };
}
const myphp = () => (globalThis as unknown as { window: { myphp: Runtime } }).window.myphp;
const acks = () => php!.posted.filter((m) => m.t === "chan-ack").map((m) => m.n);

test("a value asking for it is acknowledged once taken, not before", async () => {
  let release = () => {};
  php = fakeMyPHP({
    "S.stream": (c) => {
      c.send(0, "a");
      c.send(0, "b", true);
      c.send(0, "c");
      return new Promise<void>((r) => (release = r));
    },
  });
  const ch = new (myphp().Channel)();
  const done = myphp().call("S.stream", ch);
  await tick();
  await tick();
  expect(acks()).toEqual([]); // queued, not taken
  const it = ch[Symbol.asyncIterator]();
  expect((await it.next()).value).toBe("a");
  expect(acks()).toEqual([]);
  expect((await it.next()).value).toBe("b");
  expect(acks()).toEqual([2]);
  release();
  await done;
});

test("onmessage takes values as they come", async () => {
  php = fakeMyPHP({ "S.stream": (c) => { c.send(0, "x", true); c.send(0, "y", true); } });
  const ch = new (myphp().Channel)();
  const got: unknown[] = [];
  ch.onmessage = (v) => got.push(v);
  await myphp().call("S.stream", ch);
  expect(got).toEqual(["x", "y"]);
  expect(acks()).toEqual([1, 2]);
});

interface Settings {
  on(name: string, fn: (p: unknown) => void): () => void;
  once(name: string, fn: (p: unknown) => void): () => void;
  readonly preferences: { accent: string | null; textScale: number };
  readonly locale: string;
  readonly dir: string;
  __deliver(m: unknown): void;
}
const page = () => (globalThis as unknown as { window: { myphp: Settings } }).window.myphp;

test("the preferences and the language PHP tells, kept for the page", () => {
  php = fakeMyPHP({});
  expect([page().preferences.accent, page().locale, page().dir]).toEqual([null, "en", "ltr"]);
  const heard: unknown[] = [];
  page().on("myphp:locale", (p) => heard.push(p));
  page().__deliver({ t: "event", n: "myphp:preferences", p: { accent: "#007aff", reduceMotion: false, reduceTransparency: false, highContrast: true, textScale: 1 } });
  page().__deliver({ t: "event", n: "myphp:locale", p: { locale: "en", dir: "ltr" } }); // the one it has: no news
  page().__deliver({ t: "event", n: "myphp:locale", p: { locale: "ar", dir: "rtl" } });
  expect([page().preferences.accent, Object.isFrozen(page().preferences), page().locale, page().dir]).toEqual(["#007aff", true, "ar", "rtl"]);
  expect(heard).toEqual([{ locale: "ar", dir: "rtl" }]);
});

test("once hears an event a single time", () => {
  php = fakeMyPHP({});
  const got: unknown[] = [];
  page().once("ping", (p) => got.push(p));
  page().__deliver({ t: "event", n: "ping", p: 1 });
  page().__deliver({ t: "event", n: "ping", p: 2 });
  expect(got).toEqual([1]);
});
