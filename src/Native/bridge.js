// The runtime MyPHP installs in every page as window.myphp, before any
// script of the page runs. __CONFIG__ is replaced with the window's
// configuration. Messages follow mygo's bridge: calls go out with the
// window's secret, replies, events and channel values come back through
// __deliver.
(function () {
  if (window.myphp) return;
  const cfg = __CONFIG__;
  let nextId = 1;
  let nextChannel = 1;
  const pending = new Map();
  const listeners = new Map();
  const channels = new Map(); // id => Channel
  const post = (m) => window.webkit.messageHandlers.myphp.postMessage(JSON.stringify(m));
  // The desktop's preferences and the app's language, which PHP tells at document start and as they change.
  let preferences = Object.freeze({ accent: null, reduceMotion: false, reduceTransparency: false, highContrast: false, textScale: 1 });
  let locale = cfg.locale || "en";
  let dir = cfg.dir === "rtl" ? "rtl" : "ltr";
  // CSS variables PHP keeps on :root: the room of the window controls, the accent, the text scale; and the rule that fits the root to its
  // content (fitRule), in MyPHP's style sheet.
  const vars = {};
  let fitCss = "";
  let sheet = null, style = null;
  const setVars = (v) => {
    Object.assign(vars, v);
    render();
  };
  const render = () => {
    const css = Object.entries(vars).filter(([, x]) => x !== null).map(([k, x]) => `${k}:${x}`).join(";");
    const text = (css ? `:root{${css}}` : "") + fitCss;
    if (document.adoptedStyleSheets) { // a constructed style sheet, which a Content Security Policy allows
      if (!sheet) { sheet = new CSSStyleSheet(); document.adoptedStyleSheets = [...document.adoptedStyleSheets, sheet]; }
      sheet.replaceSync(text);
      return;
    }
    style ??= document.createElement("style");
    style.textContent = text;
    const add = () => { if (!style.isConnected) (document.head ?? document.documentElement)?.append(style); };
    if (document.documentElement) add(); else document.addEventListener("DOMContentLoaded", add, { once: true });
  };
  const thrown = (e) => setTimeout(() => { throw e; });

  class CallError extends Error {
    constructor(method, message) {
      super(message);
      this.name = "CallError";
      this.method = method;
    }
  }

  /**
   * A stream of values from a PHP method: pass one as the argument of a
   * Channel parameter, then iterate it (for await) or set onmessage.
   * Values arrive in order, before the call's result; the channel closes
   * when the method returns. close() (or breaking out of the loop) stops
   * the method's sending.
   */
  class Channel {
    #id = 0;
    #closed = false;
    #queue = []; // {value, n, ask}
    #waiting = [];
    #onmessage = null;
    #pushed = 0;
    onclose = null;
    get closed() { return this.#closed; }
    get onmessage() { return this.#onmessage; }
    set onmessage(fn) {
      this.#onmessage = fn;
      while (fn && this.#queue.length > 0) this.#take(this.#queue.shift(), fn);
    }
    /** Called by call(): the id PHP knows the channel by. */
    __attach() {
      if (this.#id || this.#closed) throw new Error("myphp: a channel can be passed to one call, before it is closed");
      this.#id = nextChannel++;
      channels.set(this.#id, this);
      return this.#id;
    }
    /** A value from PHP; ask: PHP waits to hear once it is taken (mygo's flow control). */
    __push(value, ask) {
      if (this.#closed) return;
      const item = { value, n: ++this.#pushed, ask };
      if (this.#onmessage) this.#take(item, this.#onmessage);
      else if (this.#waiting.length > 0) this.#take(item, this.#waiting.shift(), true);
      else this.#queue.push(item);
    }
    #take(item, fn, iterating) {
      if (item.ask) post({ t: "chan-ack", c: this.#id, k: cfg.secret, n: item.n });
      if (iterating) fn({ value: item.value, done: false });
      else fn(item.value);
    }
    __end() {
      if (this.#closed) return;
      this.#closed = true;
      channels.delete(this.#id);
      if (this.#queue.length === 0) this.#waiting.splice(0).forEach((w) => w({ value: undefined, done: true }));
      if (this.onclose) this.onclose();
    }
    close() {
      if (this.#closed) return;
      this.#queue = [];
      if (this.#id) post({ t: "chan-close", c: this.#id, k: cfg.secret });
      this.__end();
    }
    toJSON() { return this.#id; }
    [Symbol.asyncIterator]() {
      return {
        next: () => {
          if (this.#queue.length > 0) return new Promise((resolve) => this.#take(this.#queue.shift(), resolve, true));
          if (this.#closed) return Promise.resolve({ value: undefined, done: true });
          return new Promise((resolve) => this.#waiting.push(resolve));
        },
        return: () => { this.close(); return Promise.resolve({ value: undefined, done: true }); },
      };
    }
  }

  const windowCall = (name) => () => window.myphp.call("myphp:window." + name);

  // mygo's Runtime, which myphp-runtime (packages/runtime) types and wraps.
  window.myphp = {
    platform: cfg.platform,
    windowId: cfg.windowId,
    version: cfg.version,
    /** A channel to pass to a call of a PHP method that streams values through a Channel parameter; a channel serves one call. */
    channel(onmessage) {
      const c = new Channel();
      if (onmessage) c.onmessage = onmessage;
      return c;
    },
    /** Calls a bound PHP method, e.g. call("Notes.add", "title"); a Channel argument streams values back. */
    call(method, ...args) {
      return new Promise((resolve, reject) => {
        const id = nextId++;
        const chans = args.filter((a) => a instanceof Channel);
        try { chans.forEach((c) => c.__attach()); } catch (e) { reject(e); return; }
        pending.set(id, { resolve, reject, method, chans });
        post({ t: "call", id, k: cfg.secret, m: method, a: args });
      });
    },
    /** Listens for an event PHP emits; returns a function that stops listening. */
    on(name, fn) {
      if (typeof fn !== "function") throw new TypeError("listener must be a function");
      let set = listeners.get(name);
      if (!set) listeners.set(name, (set = new Set()));
      const l = (p) => fn(p);
      set.add(l);
      return () => { set.delete(l); if (set.size === 0 && listeners.get(name) === set) listeners.delete(name); };
    },
    /** Listens for the next time PHP emits an event. */
    once(name, fn) {
      const off = window.myphp.on(name, (p) => { off(); fn(p); });
      return off;
    },
    /** The accent color and the accessibility settings of the desktop (MyPHP\ThemePreferences); the "myphp:preferences" event tells their changes. */
    get preferences() { return preferences; },
    /** The language the app shows, as "zh-Hans", and the direction it is written in; the "myphp:locale" event tells a change. */
    get locale() { return locale; },
    get dir() { return dir; },
    /** This window, as the page may drive it. */
    window: Object.freeze({
      minimize: windowCall("Minimize"),
      maximize: windowCall("Maximize"),
      unmaximize: windowCall("Unmaximize"),
      toggleMaximize: windowCall("ToggleMaximize"),
      isMaximized: windowCall("IsMaximized"),
      toggleFullScreen: windowCall("ToggleFullScreen"),
      close: windowCall("Close"),
      setTitle: (title) => window.myphp.call("myphp:window.SetTitle", title),
    }),
    __deliver(msg) {
      if (msg.t === "reply") {
        const p = pending.get(msg.id);
        if (!p) return;
        pending.delete(msg.id);
        p.chans.forEach((c) => c.__end());
        if (msg.ok) p.resolve(msg.v);
        else p.reject(new CallError(p.method, msg.e));
      } else if (msg.t === "event") {
        if (msg.n === "myphp:preferences") preferences = Object.freeze(msg.p);
        if (msg.n === "myphp:locale") {
          if (typeof msg.p?.locale !== "string" || msg.p.locale === locale) return; // told only of a language the page did not have
          locale = msg.p.locale;
          dir = msg.p.dir === "rtl" ? "rtl" : "ltr";
        }
        const set = listeners.get(msg.n);
        if (set) for (const fn of [...set]) { try { fn(msg.p); } catch (e) { thrown(e); } }
      } else if (msg.t === "chan") {
        const c = channels.get(msg.c);
        if (!c) return;
        if (msg.end) c.__end();
        else c.__push(msg.p, msg.a === 1);
      }
    },
  };
  // Find in page (Window::findInPage, mygo's find.ts): matches are marked with the CSS Custom
  // Highlight API, which leaves the page's DOM and selection alone; engines without it select
  // the active match instead.
  let findState = null;
  let findSheet = null;
  const highlights = () => typeof CSS !== "undefined" && "highlights" in CSS && typeof Highlight !== "undefined";
  const collect = (text, matchCase) => {
    const ranges = [];
    if (!text || !document.body) return ranges;
    const needle = matchCase ? text : text.toLowerCase();
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
      acceptNode(node) {
        const el = node.parentElement;
        if (!el || /^(SCRIPT|STYLE|NOSCRIPT|TEMPLATE|TEXTAREA)$/.test(el.tagName)) return NodeFilter.FILTER_REJECT;
        return el.getClientRects().length ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
      },
    });
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
      const hay = matchCase ? node.nodeValue : node.nodeValue.toLowerCase();
      for (let i = hay.indexOf(needle); i >= 0; i = hay.indexOf(needle, i + needle.length)) {
        const r = document.createRange();
        r.setStart(node, i);
        r.setEnd(node, i + needle.length);
        ranges.push(r);
      }
    }
    return ranges;
  };
  const stopFind = () => {
    if (findState && !highlights()) getSelection()?.removeAllRanges();
    findState = null;
    if (highlights()) {
      CSS.highlights.delete("myphp-find");
      CSS.highlights.delete("myphp-find-active");
    }
  };
  const find = (text, opts = {}) => {
    const matchCase = !!opts.matchCase;
    if (!opts.next || !findState || findState.text !== text || findState.matchCase !== matchCase) {
      findState = { text, matchCase, ranges: collect(text, matchCase), active: -1 };
    }
    const n = findState.ranges.length;
    if (n === 0) {
      stopFind();
      return { matches: 0, active: 0 };
    }
    findState.active = findState.active < 0 ? (opts.backward ? n - 1 : 0) : (findState.active + (opts.backward ? n - 1 : 1)) % n;
    const range = findState.ranges[findState.active];
    if (highlights()) {
      if (!findSheet && "adoptedStyleSheets" in document) {
        // A constructed style sheet, which a Content Security Policy allows.
        findSheet = new CSSStyleSheet();
        findSheet.replaceSync("::highlight(myphp-find){background-color:#fdeb62;color:#000}::highlight(myphp-find-active){background-color:#ff9632;color:#000}");
        document.adoptedStyleSheets = [...document.adoptedStyleSheets, findSheet];
      }
      CSS.highlights.set("myphp-find", new Highlight(...findState.ranges));
      CSS.highlights.set("myphp-find-active", new Highlight(range));
    } else {
      const sel = getSelection();
      sel?.removeAllRanges();
      sel?.addRange(range);
    }
    range.startContainer.parentElement?.scrollIntoView({ block: "center", inline: "nearest" });
    return { matches: n, active: findState.active + 1 };
  };
  Object.defineProperty(window, "__myphp", { value: Object.freeze({ find, stopFind }) });
  window.myphp.on("myphp:title-bar", (t) => setVars({ "--myphp-titlebar-height": `${t.height}px`, "--myphp-titlebar-inset-left": `${t.left}px`, "--myphp-titlebar-inset-right": `${t.right}px` }));
  window.myphp.on("myphp:preferences", (p) => setVars({ "--myphp-accent": p.accent, "--myphp-text-scale": String(p.textScale) }));
  // The page's theme color (Page::themeColor, mygo's theme-color.ts): the content of the first <meta name="theme-color"> in the head whose
  // media is absent, empty or matches, and whose content is a color, as browsers pick it; told as #rrggbbaa, or "" for none, as it changes.
  // Before the DOM is ready only a color is told, so that going from a page to another of the same color tells nothing; once it is, none too.
  const themeColorReady = (() => {
    const hex2 = (n) => Math.round(Math.min(Math.max(n, 0), 255)).toString(16).padStart(2, "0");
    // A canvas's fillStyle as #rrggbbaa: engines serialize sRGB colors as #rrggbb, or rgba(r, g, b, a) when translucent; other color spaces give null.
    const fillStyleHex = (v) => {
      if (/^#[0-9a-f]{6}$/i.test(v)) return v.toLowerCase() + "ff";
      const m = /^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)(?:[\s,/]+([\d.]+))?\s*\)$/i.exec(v);
      return m ? "#" + hex2(+m[1]) + hex2(+m[2]) + hex2(+m[3]) + hex2(m[4] === undefined ? 255 : +m[4] * 255) : null;
    };
    let ctx;
    // A CSS color parsed as the engine does, by a canvas, whose fillStyle keeps its value when given what is no color; others drawn and read back.
    const parse = (value) => {
      value = value.trim();
      if (!value) return null;
      if (ctx === undefined) {
        const canvas = document.createElement("canvas");
        canvas.width = canvas.height = 1;
        ctx = canvas.getContext ? canvas.getContext("2d", { willReadFrequently: true }) : null;
      }
      if (!ctx) return null;
      ctx.fillStyle = "#000";
      ctx.fillStyle = value;
      if (ctx.fillStyle === "#000000") {
        ctx.fillStyle = "#fff";
        ctx.fillStyle = value;
        if (ctx.fillStyle === "#ffffff") return null; // no color
      }
      const hex = fillStyleHex(String(ctx.fillStyle));
      if (hex) return hex;
      ctx.clearRect(0, 0, 1, 1);
      ctx.fillRect(0, 0, 1, 1);
      const [r, g, b, al] = ctx.getImageData(0, 0, 1, 1).data;
      return "#" + hex2(r) + hex2(g) + hex2(b) + hex2(al);
    };
    let last, ready = false; // last undefined: nothing told yet
    const report = (c) => {
      if (c === last || (c === null && last === undefined && !ready)) return;
      last = c;
      post({ t: "theme-color", v: c ?? "" });
    };
    const queries = new Map();
    const matches = (media) => {
      let q = queries.get(media);
      if (!q) {
        q = matchMedia(media);
        q.addEventListener ? q.addEventListener("change", update) : q.addListener(update);
        queries.set(media, q);
      }
      return q.matches;
    };
    const current = () => {
      if (!document.head) return null;
      for (const m of document.head.querySelectorAll('meta[name="theme-color" i]')) {
        const media = m.getAttribute("media")?.trim();
        if (media && !matches(media)) continue;
        const c = parse(m.getAttribute("content") ?? "");
        if (c !== null) return c;
      }
      return null;
    };
    function update() { report(current()); }
    // The head's metas alone, so that pages changing their body pay nothing; the document and its root for a head that comes, or is replaced, later.
    const isMeta = (n) => n.localName === "meta";
    const hasMeta = (nodes) => Array.prototype.some.call(nodes, (n) => n.nodeType === 1 && (isMeta(n) || n.querySelector("meta")));
    let head = null, root = null;
    const observer = new MutationObserver((records) => {
      let changed = false;
      for (const r of records) {
        if (r.target === document || r.target === document.documentElement) { observe(); changed = true; }
        else if (r.type === "attributes") changed ||= isMeta(r.target);
        else changed ||= hasMeta(r.addedNodes) || hasMeta(r.removedNodes);
      }
      if (changed) update();
    });
    function observe() {
      if (document.documentElement !== root && (root = document.documentElement)) observer.observe(root, { childList: true });
      if (document.head !== head && (head = document.head)) observer.observe(head, { childList: true, subtree: true, attributes: true, attributeFilter: ["name", "content", "media"] });
    }
    observer.observe(document, { childList: true });
    observe();
    update();
    return () => { ready = true; report(current()); };
  })();
  // A window that takes the size of its page's content (FlyoutOptions::$fitContent, WindowOptions::$fitContent; mygo's fit-content.ts), as
  // browsers size the popups of their extensions: the root is laid out at the width of its content within the window's limits (cfg.fit, in
  // CSS pixels, 0 for none; myphp:fit-content as the zoom changes them), by a rule without specificity so that a page's own size for html
  // wins, the room of a vertical scrollbar left out of the widths while the content is taller than the maximum height, so that text wraps
  // at the maximum. Once the DOM is ready, before dom-ready, then from a ResizeObserver on the root and the window's resize events, it
  // tells PHP the root's size and the scrollbars' room as they change; PHP clamps it, turns it into points and resizes the window.
  const fitStart = (() => {
    if (!cfg.fit) return () => {};
    let limits = cfg.fit, scrollbar = 0, started = false, pending = false, last = null;
    const rule = () => {
      const d = ["width:max-content"];
      if (limits.minWidth > 0) d.push(`min-width:${Math.max(limits.minWidth - scrollbar, 0)}px`);
      if (limits.maxWidth > 0) d.push(`max-width:${Math.max(limits.maxWidth - scrollbar, 0)}px`);
      if (limits.minHeight > 0) d.push(`min-height:${limits.minHeight}px`);
      return `:where(:root){${d.join(";")}}`;
    };
    const apply = () => { fitCss = rule(); render(); };
    const report = (s) => {
      if (last && last.w === s.w && last.h === s.h && last.sw === s.sw && last.sh === s.sh) return;
      last = s;
      post({ t: "content-size", ...s });
    };
    // fromObserver: changing the root's size in a ResizeObserver's callback would end its loop with an error event, which the page would
    // hear; the rule changes in the next frame then, and the observer tells the size.
    const measure = (fromObserver) => {
      const root = document.documentElement;
      if (!root) return;
      const viewport = document.scrollingElement ?? root;
      const sw = Math.max(innerWidth - viewport.clientWidth, 0);
      const sh = Math.max(innerHeight - viewport.clientHeight, 0);
      let r = root.getBoundingClientRect();
      // A vertical scrollbar shows for a moment whenever the content grows before the window does, and WebKit may keep it while it lays
      // out: its room is left out of the width only where the content is taller than the window may be, and PHP decides whether it counts.
      const room = limits.maxHeight > 0 && r.height > limits.maxHeight ? sw : 0;
      if (room !== scrollbar) {
        scrollbar = room;
        if (!fromObserver) {
          apply();
          r = root.getBoundingClientRect();
        } else if (!pending) {
          pending = true;
          requestAnimationFrame(() => { pending = false; apply(); });
        }
      }
      const body = document.body; // the content overflows the root only when the root is at its maximum width
      report({ w: r.width, h: r.height, sw, sh: body && body.scrollWidth > body.clientWidth ? sh : 0 });
    };
    apply();
    window.myphp.on("myphp:fit-content", (l) => { limits = l; apply(); if (started) measure(false); });
    return () => {
      if (started) return;
      started = true;
      measure(false);
      if (typeof ResizeObserver !== "undefined" && document.documentElement) new ResizeObserver(() => measure(true)).observe(document.documentElement);
      addEventListener("resize", () => measure(false)); // the window's own size changed what scrollbars show
    };
  })();
  document.addEventListener("DOMContentLoaded", () => { themeColorReady(); fitStart(); post({ t: "dom-ready" }); });
  // A press on an element whose --app-region (or -webkit-app-region) is drag moves the window; a double click zooms it as macOS is set to (mygo's drag regions). Controls stay controls.
  const controls = 'input,textarea,select,button,a[href],summary,label,[contenteditable]:not([contenteditable="false"]),video[controls],audio[controls]';
  const webkitRegion = typeof CSS !== "undefined" && CSS.supports("-webkit-app-region", "drag");
  const region = (el) => {
    const r = getComputedStyle(el).getPropertyValue("--app-region").trim();
    if (r || !webkitRegion) return r;
    for (let e = el; e; e = e.parentElement) {
      const w = getComputedStyle(e).getPropertyValue("-webkit-app-region").trim();
      if (w === "drag" || w === "no-drag") return w;
    }
    return "";
  };
  addEventListener("mousedown", (e) => {
    if (e.button !== 0 || !(e.target instanceof Element)) return;
    if (region(e.target) !== "drag" || e.target.closest(controls)) return;
    e.preventDefault();
    post({ t: e.detail === 2 ? "dblclick" : "drag" });
  }, true);
  // An Escape no handler of the page called preventDefault on: a flyout or a panel closes on it.
  addEventListener("keydown", (e) => {
    if (e.key !== "Escape" || e.isComposing) return;
    setTimeout(() => {
      if (!e.defaultPrevented) post({ t: "escape" });
    });
  });
})();
