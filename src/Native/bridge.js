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

  window.myphp = {
    platform: cfg.platform,
    windowId: cfg.windowId,
    version: cfg.version,
    Channel,
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
      let set = listeners.get(name);
      if (!set) listeners.set(name, (set = new Set()));
      set.add(fn);
      return () => set.delete(fn);
    },
    isCallError: (e) => e instanceof CallError,
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
        const set = listeners.get(msg.n);
        if (set) for (const fn of set) fn(msg.p);
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
  post({ t: "page" }); // a document starts: the calls of the last one end (MyPHP\CallContext)
  document.addEventListener("DOMContentLoaded", () => post({ t: "dom-ready" }));
  // An Escape no handler of the page called preventDefault on: a flyout or a panel closes on it.
  addEventListener("keydown", (e) => {
    if (e.key !== "Escape" || e.isComposing) return;
    setTimeout(() => {
      if (!e.defaultPrevented) post({ t: "escape" });
    });
  });
})();
