/**
 * The fetch plugin of MyPHP (mygo's @mygo-plugins/fetch): a `fetch` that
 * makes HTTP requests from PHP, free of CORS and of the webview's
 * restrictions on headers. It takes the standard options and returns a
 * standard `Response`, whose body streams as PHP reads it. The PHP side
 * must use the plugin:
 *
 * ```php
 * App::use(Fetch::plugin()); // MyPHP\Plugins\Fetch\Fetch
 * ```
 *
 * ```ts
 * import { fetch } from "@myphp-plugins/fetch";
 *
 * const res = await fetch("https://api.example.com/items", {
 *   headers: { authorization: `Bearer ${token}` },
 * });
 * const items = await res.json();
 * ```
 *
 * URLs that are not http or https, such as the app's own pages, `data:` and
 * `blob:` URLs, go to the webview's `fetch`.
 *
 * @module
 */
/** The page runtime MyPHP installs in its pages. */
interface Runtime {
  call<T>(method: string, ...args: unknown[]): Promise<T>;
  isCallError(e: unknown): e is Error;
  Channel: new <T>() => AsyncIterable<T> & { close(): void };
}
const myphp = () => (window as unknown as { myphp: Runtime }).myphp;

/** What PHP streams back: the response's head, then chunks of its body. */
interface Part {
  status?: number;
  statusText?: string;
  url?: string;
  redirected?: boolean;
  headers?: [string, string][];
  /** Base64. */
  chunk?: string;
}

/** Statuses whose responses have no body. */
const NULL_BODY = new Set([101, 103, 204, 205, 304]);

/** Methods whose names are normalized to upper case, as `fetch` does. */
const NORMALIZED = new Set(["DELETE", "GET", "HEAD", "OPTIONS", "POST", "PUT"]);

/**
 * Makes an HTTP request from PHP, with the options and result of the
 * standard `fetch`. `mode`, `credentials`, `cache` and the like have no
 * meaning there and are ignored; no cookies are kept. Response headers are all there but `Set-Cookie`.
 */
export async function fetch(input: RequestInfo | URL, init: RequestInit = {}): Promise<Response> {
  const req = typeof Request !== "undefined" && input instanceof Request ? input : null;
  const base = typeof location !== "undefined" ? location.href : undefined;
  const url = new URL(req ? req.url : input instanceof URL ? input.href : String(input), base);
  if (url.protocol !== "http:" && url.protocol !== "https:") return globalThis.fetch(input, init);

  const signal = init.signal ?? req?.signal ?? null;
  signal?.throwIfAborted();
  let method = init.method ?? req?.method ?? "GET";
  if (NORMALIZED.has(method.toUpperCase())) method = method.toUpperCase();
  // Plain Headers keep the names a Request would drop, such as Origin.
  const headers = new Headers(init.headers ?? req?.headers);
  let body: Uint8Array | null = null;
  if (init.body != null) {
    const r = new Response(init.body);
    const type = r.headers.get("content-type");
    if (type && !headers.has("content-type")) headers.set("content-type", type);
    body = new Uint8Array(await r.arrayBuffer());
  } else if (init.body === undefined && req?.body) {
    body = new Uint8Array(await req.arrayBuffer());
  }
  if (body && (method === "GET" || method === "HEAD")) {
    throw new TypeError(`fetch: a ${method} request cannot have a body`);
  }

  const parts = new (myphp().Channel)<Part>();
  const done = myphp().call<void>("plugin:fetch.fetch", {
    url: url.href,
    method,
    headers: [...headers],
    body: body && toBase64(body),
    redirect: init.redirect ?? req?.redirect ?? "follow",
  }, parts);
  // Rejections are taken where they matter; this one must not go unhandled.
  const failed = done.then(
    () => null,
    (err: unknown) => failure(err),
  );
  const it = parts[Symbol.asyncIterator]();

  let stream: ReadableStreamDefaultController<Uint8Array> | undefined;
  let rejectHead: ((reason: unknown) => void) | undefined;
  const onAbort = () => {
    parts.close();
    try {
      stream?.error(signal!.reason);
    } catch {}
    rejectHead?.(signal!.reason);
  };
  signal?.addEventListener("abort", onAbort, { once: true });
  const cleanup = () => signal?.removeEventListener("abort", onAbort);

  let head: Part;
  try {
    const first = await new Promise<IteratorResult<Part>>((resolve, reject) => {
      rejectHead = reject;
      it.next().then(resolve, reject);
    });
    rejectHead = undefined;
    if (first.done) throw (await failed) ?? new TypeError("fetch failed: no response");
    head = first.value;
  } catch (err) {
    cleanup();
    parts.close();
    throw err;
  }

  const status = head.status ?? 200;
  const nullBody = NULL_BODY.has(status) || method === "HEAD";
  const readable = nullBody
    ? null
    : new ReadableStream<Uint8Array>(
        {
          start(controller) {
            stream = controller;
          },
          async pull(controller) {
            const next = await it.next();
            if (signal?.aborted) return;
            if (!next.done) {
              if (next.value.chunk) controller.enqueue(fromBase64(next.value.chunk));
              return;
            }
            cleanup();
            const err = await failed;
            if (err) controller.error(err);
            else controller.close();
          },
          cancel() {
            cleanup();
            parts.close();
          },
        },
        { highWaterMark: 0 },
      );
  if (nullBody) {
    parts.close();
    cleanup();
  }
  // Response takes statuses from 200 to 599 only.
  const valid = status >= 200 && status <= 599;
  const res = new Response(readable, {
    status: valid ? status : 200,
    statusText: head.statusText ?? "",
    headers: head.headers ?? [],
  });
  const own = (name: string, value: unknown) => Object.defineProperty(res, name, { value, enumerable: true });
  own("url", head.url ?? url.href);
  own("redirected", head.redirected ?? false);
  if (!valid) own("status", status);
  return res;
}

export default fetch;

/** The error a failed request rejects with. */
function failure(err: unknown): Error {
  if (!myphp().isCallError(err)) return err instanceof Error ? err : new TypeError(String(err));
  if (err.message.includes("plugin is not used")) {
    return new Error("@myphp-plugins/fetch: the PHP side does not use the plugin: call App::use(Fetch::plugin()) before App::run");
  }
  return new TypeError(`fetch failed: ${err.message}`, { cause: err });
}

function toBase64(bytes: Uint8Array): string {
  const native = (bytes as { toBase64?: () => string }).toBase64;
  if (native) return native.call(bytes);
  let s = "";
  for (let i = 0; i < bytes.length; i += 0x8000) {
    s += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000) as unknown as number[]);
  }
  return btoa(s);
}

function fromBase64(s: string): Uint8Array {
  const native = (Uint8Array as { fromBase64?: (s: string) => Uint8Array }).fromBase64;
  if (native) return native(s);
  const bin = atob(s);
  const bytes = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
  return bytes;
}
