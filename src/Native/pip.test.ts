// Picture in picture in the runtime (bridge.js; mygo's pipScript): a page that allows it tells whether its document has a video out, as
// the page shows and as one goes in or out; others tell nothing. bun test, here.
import { expect, test } from "bun:test";
import { readFileSync } from "node:fs";

function page(pip: boolean) {
  const posted: Record<string, unknown>[] = [];
  const on: Record<string, () => void> = {};
  const document = {
    head: null, documentElement: {}, pictureInPictureElement: null as unknown,
    addEventListener() {}, createElement: () => ({ isConnected: false }),
  };
  const win: Record<string, unknown> = { webkit: { messageHandlers: { myphp: { postMessage: (raw: string) => posted.push(JSON.parse(raw)) } } } };
  const config = { platform: "darwin", windowId: 1, version: "0", secret: "s", locale: "en", dir: "ltr", ...(pip ? { pip } : {}) };
  const source = readFileSync(new URL("./bridge.js", import.meta.url), "utf8").replaceAll("__CONFIG__", JSON.stringify(config));
  new Function("window", "document", "addEventListener", "MutationObserver", "matchMedia", source)(
    win, document, (e: string, fn: () => void) => { on[e] = fn; }, class { observe() {} }, () => ({ matches: false, addEventListener() {} }));
  const told = () => posted.filter((m) => m.t === "pip").map((m) => m.v);
  return { told, on, document };
}

test("a video going in and out of picture in picture, told", () => {
  const p = page(true);
  p.on.pageshow!();
  p.document.pictureInPictureElement = {};
  p.on.enterpictureinpicture!();
  p.document.pictureInPictureElement = null;
  p.on.leavepictureinpicture!();
  expect(p.told()).toEqual([false, true, false]);
});

test("nothing told by a page that does not allow it", () => {
  const p = page(false);
  expect(p.on.enterpictureinpicture).toBeUndefined();
  expect(p.told()).toEqual([]);
});
