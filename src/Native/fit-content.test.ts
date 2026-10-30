// A window that takes its page's content's size, in the runtime (bridge.js; mygo's fit-content.test.ts): the rule that lays the root out
// at its content's width within the limits, and the sizes told as they change, once the DOM is ready. bun test, here.
import { expect, test } from "bun:test";
import { readFileSync } from "node:fs";

type Size = { w: number; h: number; sw: number; sh: number };
/** The runtime in a page of a window with fit (the limits) or none, whose root measures as box says: what it posts, its style, its hooks. */
function page(fit: Record<string, number> | null) {
  const posted: Record<string, unknown>[] = [];
  let ready = () => {};
  let observed: () => void = () => {};
  let resized: () => void = () => {};
  const frames: (() => void)[] = [];
  const box = { w: 0, h: 0, clientWidth: 400, clientHeight: 300, bodyScroll: 0, bodyClient: 0 };
  const style = { textContent: "", isConnected: false };
  const root = { getBoundingClientRect: () => ({ width: box.w, height: box.h }), append() {} };
  const document = {
    head: { append: () => (style.isConnected = true), querySelectorAll: () => [] },
    documentElement: root,
    get scrollingElement() { return { clientWidth: box.clientWidth, clientHeight: box.clientHeight }; },
    get body() { return { scrollWidth: box.bodyScroll, clientWidth: box.bodyClient }; },
    addEventListener: (e: string, fn: () => void) => { if (e === "DOMContentLoaded") ready = fn; },
    createElement: () => style,
  };
  const win: Record<string, unknown> = { webkit: { messageHandlers: { myphp: { postMessage: (raw: string) => posted.push(JSON.parse(raw)) } } } };
  const config = { platform: "darwin", windowId: 1, version: "0", secret: "s", locale: "en", dir: "ltr", ...(fit ? { fit } : {}) };
  const source = readFileSync(new URL("./bridge.js", import.meta.url), "utf8").replaceAll("__CONFIG__", JSON.stringify(config));
  new Function("window", "document", "addEventListener", "MutationObserver", "matchMedia", "ResizeObserver", "requestAnimationFrame", "innerWidth", "innerHeight", source)(
    win, document, (e: string, fn: () => void) => { if (e === "resize") resized = fn; },
    class { observe() {} }, () => ({ matches: false, addEventListener() {} }),
    class { constructor(fn: () => void) { observed = fn; } observe() {} }, (fn: () => void) => frames.push(fn), 400, 300);
  const sizes = () => posted.filter((m) => m.t === "content-size").map(({ t, ...s }) => s as Size);
  const deliver = (n: string, p: unknown) => (win.myphp as { __deliver(m: unknown): void }).__deliver({ t: "event", n, p });
  return { posted, sizes, box, style, ready: () => ready(), observed: () => observed(), resized: () => resized(), frames, deliver };
}

test("the rule lays the root out at its content's width, within the limits, without specificity", () => {
  expect(page({ minWidth: 0, minHeight: 0, maxWidth: 0, maxHeight: 0 }).style.textContent).toBe(":where(:root){width:max-content}");
  expect(page({ minWidth: 100, minHeight: 40, maxWidth: 300, maxHeight: 200 }).style.textContent).toBe(
    ":where(:root){width:max-content;min-width:100px;max-width:300px;min-height:40px}");
  expect(page({ minWidth: 0, minHeight: 0, maxWidth: 133.5, maxHeight: 0 }).style.textContent).toBe(":where(:root){width:max-content;max-width:133.5px}");
  expect(page(null).style.textContent).toBe("");
});

test("the size told once the DOM is ready, before dom-ready, then as it changes", () => {
  const p = page({ minWidth: 0, minHeight: 0, maxWidth: 300, maxHeight: 200 });
  p.box.w = 120; p.box.h = 80;
  expect(p.sizes()).toEqual([]);
  p.ready();
  expect(p.posted.map((m) => m.t).filter((t) => t !== "theme-color")).toEqual(["content-size", "dom-ready"]);
  p.observed();
  p.box.w = 120.5;
  p.observed();
  p.resized();
  expect(p.sizes()).toEqual([{ w: 120, h: 80, sw: 0, sh: 0 }, { w: 120.5, h: 80, sw: 0, sh: 0 }]);
});

test("a vertical scrollbar's room left out of the widths only past the maximum height", () => {
  const p = page({ minWidth: 100, minHeight: 0, maxWidth: 300, maxHeight: 200 });
  p.box.w = 120; p.box.h = 150; p.box.clientWidth = 385; // a scrollbar for a moment
  p.ready();
  expect(p.style.textContent).toBe(":where(:root){width:max-content;min-width:100px;max-width:300px}");
  p.box.h = 500;
  p.observed(); // in the observer: the rule changes in the next frame
  expect(p.style.textContent).toBe(":where(:root){width:max-content;min-width:100px;max-width:300px}");
  p.frames.shift()!();
  expect(p.style.textContent).toBe(":where(:root){width:max-content;min-width:85px;max-width:285px}");
  expect(p.sizes().at(-1)).toEqual({ w: 120, h: 500, sw: 15, sh: 0 });
});

test("a horizontal scrollbar's room told only while the content is wider than the root", () => {
  const p = page({ minWidth: 0, minHeight: 0, maxWidth: 300, maxHeight: 0 });
  p.box.w = 300; p.box.h = 60; p.box.clientHeight = 285; // a scrollbar of 15 along the bottom
  p.ready();
  p.box.bodyScroll = 500; p.box.bodyClient = 300; // the content wider than the root
  p.resized();
  expect(p.sizes().map((s) => s.sh)).toEqual([0, 15]);
});

test("other limits as the zoom changes", () => {
  const p = page({ minWidth: 100, minHeight: 40, maxWidth: 300, maxHeight: 200 });
  p.box.w = 120; p.box.h = 80;
  p.ready();
  p.deliver("myphp:fit-content", { minWidth: 50, minHeight: 20, maxWidth: 150, maxHeight: 100 });
  expect(p.style.textContent).toBe(":where(:root){width:max-content;min-width:50px;max-width:150px;min-height:20px}");
});
