// A PHP side for the plugins' client tests (mygo's fake-go.ts): MyPHP's
// real page runtime, src/Native/bridge.js, installed as window.myphp (the
// global object's myphp, as myphp-runtime finds it),
// whose calls go to handlers written in TypeScript that stream through
// channels as the PHP methods do. bun test, in a plugin's client/.
import { readFileSync } from "node:fs";

/** A call being handled. */
export interface PhpCall {
  args: unknown[];
  /** Sends a value on the channel passed as the i-th argument; ask asks the page to acknowledge it once taken (flow control). */
  send(i: number, value: unknown, ask?: boolean): void;
  /** Aborted when the page closes a channel of the call. */
  signal: AbortSignal;
}

export type Handler = (call: PhpCall) => unknown;

interface Message { t: string; id: number; k: string; m: string; a: unknown[]; c: number }

/** Installs window.myphp with handlers for methods; returns what was called. */
export function fakeMyPHP(handlers: Record<string, Handler>): { calls: [string, unknown[]][]; posted: Record<string, unknown>[]; uninstall(): void } {
  const calls: [string, unknown[]][] = [];
  const posted: Record<string, unknown>[] = [];
  const aborts = new Map<number, AbortController>();
  const g = globalThis as unknown as { window?: unknown; myphp?: unknown };
  const before = g.window;
  const win: Record<string, unknown> = {};
  const deliver = (msg: unknown) => (win.myphp as { __deliver(m: unknown): void }).__deliver(msg);
  win.webkit = {
    messageHandlers: {
      myphp: {
        postMessage(raw: string) {
          const m = JSON.parse(raw) as Message;
          posted.push(m as unknown as Record<string, unknown>);
          if (m.t === "chan-close") aborts.get(m.c)?.abort();
          if (m.t !== "call") return;
          calls.push([m.m, m.a]);
          const abort = new AbortController();
          // The channels a call passed are numbers, the ids the page knows them by.
          const chans = m.a.map((a) => (typeof a === "number" ? a : 0));
          // Like PHP: on a later turn of the run loop, the channels ending with the reply.
          setTimeout(async () => {
            const handler = handlers[m.m];
            let reply: { ok: boolean; v?: unknown; e?: string };
            try {
              if (!handler) throw new Error(`no bound method ${m.m}`);
              chans.forEach((c) => c && aborts.set(c, abort));
              const v = await handler({
                args: m.a,
                send(i, value, ask) {
                  if (abort.signal.aborted) throw new Error(`channel ${chans[i]} is closed`);
                  deliver({ t: "chan", c: chans[i], k: m.k, p: value, ...(ask ? { a: 1 } : {}) });
                },
                signal: abort.signal,
              });
              reply = { ok: true, v };
            } catch (err) {
              reply = { ok: false, e: err instanceof Error ? err.message : String(err) };
            }
            deliver({ t: "reply", id: m.id, k: m.k, ...reply });
          });
        },
      },
    },
  };
  const source = readFileSync(new URL("../Native/bridge.js", import.meta.url), "utf8").replaceAll(
    "__CONFIG__",
    JSON.stringify({ platform: "darwin", windowId: 1, version: "0.0.0-test", secret: "s", locale: "en", dir: "ltr" }),
  );
  new Function("window", "document", "addEventListener", "MutationObserver", "matchMedia", source)(win, { addEventListener() {}, createElement: () => ({ isConnected: false }) }, () => {},
    class { observe() {} }, () => ({ matches: false, addEventListener() {} }));
  g.window = win;
  g.myphp = win.myphp; // in a page, window is the global object, where myphp-runtime finds it
  return { calls, posted, uninstall: () => { g.window = before; delete g.myphp; } };
}

export const tick = () => new Promise((r) => setTimeout(r, 1));

export const b64 = (s: string) => Buffer.from(s).toString("base64");
