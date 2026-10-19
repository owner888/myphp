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
    #queue = [];
    #waiting = [];
    #onmessage = null;
    onclose = null;
    get closed() { return this.#closed; }
    get onmessage() { return this.#onmessage; }
    set onmessage(fn) {
      this.#onmessage = fn;
      while (fn && this.#queue.length > 0) fn(this.#queue.shift());
    }
    /** Called by call(): the id PHP knows the channel by. */
    __attach() {
      if (this.#id || this.#closed) throw new Error("myphp: a channel can be passed to one call, before it is closed");
      this.#id = nextChannel++;
      channels.set(this.#id, this);
      return this.#id;
    }
    __push(value) {
      if (this.#closed) return;
      if (this.#onmessage) this.#onmessage(value);
      else if (this.#waiting.length > 0) this.#waiting.shift()({ value, done: false });
      else this.#queue.push(value);
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
          if (this.#queue.length > 0) return Promise.resolve({ value: this.#queue.shift(), done: false });
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
        else c.__push(msg.p);
      }
    },
  };
  document.addEventListener("DOMContentLoaded", () => post({ t: "dom-ready" }));
  // An Escape no handler of the page called preventDefault on: a flyout or a panel closes on it.
  addEventListener("keydown", (e) => {
    if (e.key !== "Escape" || e.isComposing) return;
    setTimeout(() => {
      if (!e.defaultPrevented) post({ t: "escape" });
    });
  });
})();
