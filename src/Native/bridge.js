// The runtime MyPHP installs in every page as window.myphp, before any
// script of the page runs. __CONFIG__ is replaced with the window's
// configuration. Messages follow mygo's bridge: calls go out with the
// window's secret, replies and events come back through __deliver.
(function () {
  if (window.myphp) return;
  const cfg = __CONFIG__;
  let nextId = 1;
  const pending = new Map();
  const listeners = new Map();
  const post = (m) => window.webkit.messageHandlers.myphp.postMessage(JSON.stringify(m));

  class CallError extends Error {
    constructor(method, message) {
      super(message);
      this.name = "CallError";
      this.method = method;
    }
  }

  window.myphp = {
    platform: cfg.platform,
    windowId: cfg.windowId,
    version: cfg.version,
    /** Calls a bound PHP method, e.g. call("Notes.add", "title"). */
    call(method, ...args) {
      return new Promise((resolve, reject) => {
        const id = nextId++;
        pending.set(id, { resolve, reject, method });
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
    __deliver(msg) {
      if (msg.t === "reply") {
        const p = pending.get(msg.id);
        if (!p) return;
        pending.delete(msg.id);
        if (msg.ok) p.resolve(msg.v);
        else p.reject(new CallError(p.method, msg.e));
      } else if (msg.t === "event") {
        const set = listeners.get(msg.n);
        if (set) for (const fn of set) fn(msg.p);
      }
    },
  };
  document.addEventListener("DOMContentLoaded", () => post({ t: "dom-ready" }));
})();
