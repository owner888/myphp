// The page's theme color in the runtime (bridge.js; mygo's theme-color.test.ts): the first meta whose media matches and whose content is a
// color, as #rrggbbaa, told as it changes, and none only once the DOM is ready. bun test, here.
import { expect, test } from "bun:test";
import { readFileSync } from "node:fs";

type Attrs = Record<string, string>;
/** The runtime in a page whose head has metas of attrs, whose dark appearance matches as dark says: what it posts, and its hooks. */
function page(metas: Attrs[], dark = false) {
  const posted: { t: string; v?: string }[] = [];
  let ready = () => {};
  let mutated: (records: unknown[]) => void = () => {};
  const changes: (() => void)[] = [];
  const head = { querySelectorAll: () => metas.map((a) => ({ getAttribute: (n: string) => a[n] ?? null })) };
  // A canvas whose fillStyle takes #rrggbb, rgb() and two names, as an engine serializes them, and keeps its value for anything else.
  const named: Record<string, string> = { red: "#ff0000", navy: "#000080", "rgb(0 0 255 / 50%)": "rgba(0, 0, 255, 0.5)" };
  const ctx = {
    _v: "#000000",
    get fillStyle() { return this._v; },
    set fillStyle(v: string) { const s = v.trim().toLowerCase(); if (/^#[0-9a-f]{6}$/.test(s)) this._v = s; else if (named[s]) this._v = named[s]!; else if (s === "#000") this._v = "#000000"; else if (s === "#fff") this._v = "#ffffff"; },
  };
  const document = {
    head, documentElement: {},
    addEventListener: (e: string, fn: () => void) => { if (e === "DOMContentLoaded") ready = fn; },
    createElement: () => ({ getContext: () => ctx, isConnected: false }),
  };
  const win: Record<string, unknown> = { webkit: { messageHandlers: { myphp: { postMessage: (raw: string) => posted.push(JSON.parse(raw)) } } } };
  const source = readFileSync(new URL("./bridge.js", import.meta.url), "utf8").replaceAll("__CONFIG__", JSON.stringify({ platform: "darwin", windowId: 1, version: "0", secret: "s", locale: "en", dir: "ltr" }));
  new Function("window", "document", "addEventListener", "MutationObserver", "matchMedia", source)(win, document, () => {},
    class { constructor(fn: (r: unknown[]) => void) { mutated = fn; } observe() {} },
    (media: string) => ({ matches: dark === (media === "(prefers-color-scheme: dark)"), addEventListener: (_: string, fn: () => void) => changes.push(fn) }));
  const colors = () => posted.filter((m) => m.t === "theme-color").map((m) => m.v);
  return { colors, ready: () => ready(), mutate: () => mutated([{ type: "attributes", target: { localName: "meta" } }]), metas, posted };
}

test("the first meta whose media matches and whose content is a color", () => {
  const light = [{ name: "theme-color", content: "red", media: "(prefers-color-scheme: light)" }, { name: "theme-color", content: "navy", media: " (prefers-color-scheme: dark) " }];
  expect(page(light).colors()).toEqual(["#ff0000ff"]);
  expect(page(light, true).colors()).toEqual(["#000080ff"]);
  expect(page([{ content: "nope" }, {}, { content: " #1E90FF " }]).colors()).toEqual(["#1e90ffff"]);
  expect(page([{ content: "rgb(0 0 255 / 50%)" }]).colors()).toEqual(["#0000ff80"]);
});

test("none told once the DOM is ready, not before", () => {
  const p = page([]);
  expect(p.colors()).toEqual([]); // at document start the page before's may be the same
  p.ready();
  expect(p.colors()).toEqual([""]);
  expect(p.posted.at(-1)?.t).toBe("dom-ready");
});

test("changes alone, as metas change", () => {
  const p = page([{ content: "red" }]);
  p.mutate();
  p.metas[0]!.content = "navy";
  p.mutate();
  p.metas.length = 0;
  p.mutate();
  p.ready();
  expect(p.colors()).toEqual(["#ff0000ff", "#000080ff", ""]);
});
