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
