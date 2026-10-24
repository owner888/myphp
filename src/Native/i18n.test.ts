// The formatter of messages in pages (i18n.ts, mygo-runtime's i18n.test.ts):
// the fixtures MyPHP\Message checks too (tests/i18n/messages.json), so that
// PHP and pages say the same, catalogs, and the module `myphp generate`
// writes (tests/i18n/app/locales.ts, which tests/i18ngen_test.php checks
// against the generator). bun test, here.
import { afterEach, describe, expect, test } from "bun:test";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import {
  createCatalog,
  currentLocale,
  formatMessage,
  isPseudoLocale,
  MessageSyntaxError,
  parseMessage,
  skeletonOptions,
  type CatalogData,
} from "./i18n";

const fixtures = JSON.parse(readFileSync(join(import.meta.dir, "../../tests/i18n/messages.json"), "utf8")) as {
  format: { message: string; locale?: string; args?: Record<string, unknown>; want: string }[];
  errors: string[];
  pseudo: { message: string; locale: string; args?: Record<string, unknown>; want: string }[];
};
const g = globalThis as { myphp?: unknown };

describe("formatMessage", () => {
  test("has the fixtures", () => {
    expect(fixtures.format.length).toBeGreaterThan(50);
    expect(fixtures.errors.length).toBeGreaterThan(20);
  });
  for (const c of [...fixtures.format, ...fixtures.pseudo]) {
    test(`${c.message} in ${c.locale ?? "en"} with ${JSON.stringify(c.args ?? {})}`, () => {
      expect(formatMessage(c.locale ?? "en", c.message, c.args)).toBe(c.want);
    });
  }
  for (const s of fixtures.errors) {
    test(`${JSON.stringify(s)} does not parse`, () => {
      expect(() => parseMessage(s)).toThrow(MessageSyntaxError);
    });
  }
  test("formats numbers and dates with Intl", () => {
    expect(formatMessage("de", "{n, plural, one {# Datei} other {# Dateien}}", { n: 1000 })).toBe("1.000 Dateien");
    expect(formatMessage("en", "{n, number, ::currency/EUR}", { n: 3 })).toBe("€3.00");
    const day = new Date(Date.UTC(2026, 0, 2, 15, 4));
    expect(formatMessage("en", "{d, date, short}", { d: day })).toBe(new Intl.DateTimeFormat("en", { dateStyle: "short" }).format(day));
    expect(formatMessage("en", "{d}", { d: day })).toBe(new Intl.DateTimeFormat("en", { dateStyle: "short", timeStyle: "short" }).format(day));
    expect(formatMessage("en", "{d, date}", { d: "tomorrow" })).toBe("tomorrow");
  });
  test("writes other values", () => {
    expect(formatMessage("en", "{e}", { e: new Error("boom") })).toBe("boom");
    expect(formatMessage("en", "{v}", { v: null })).toBe("");
    expect(formatMessage("en", "{n, plural, one {#} other {# x}}", { n: 2n })).toBe("2 x");
  });
  test("reports where a message does not parse", () => {
    expect(() => parseMessage("Hello {name")).toThrow(MessageSyntaxError);
    try {
      parseMessage("Hello {name");
    } catch (err) {
      expect((err as MessageSyntaxError).offset).toBe(11);
    }
  });
});

const data: CatalogData = {
  source: "en",
  languages: ["en", "de", "en-001", "pt", "pt-PT", "zh-Hans", "zh-Hant"],
  fallbacks: { en: ["en"], de: ["de", "en"], "en-001": ["en-001", "en"], pt: ["pt", "en"], "pt-PT": ["pt-PT", "pt", "en"], "zh-Hans": ["zh-Hans", "en"], "zh-Hant": ["zh-Hant", "en"] },
  params: { hello: ["name"], color: [], files: ["n"], broken: [] },
  messages: {
    en: { hello: "Hello, {name}!", color: "Color", files: "{n, plural, one {# file} other {# files}}", broken: "Fine in English" },
    "en-001": { color: "Colour" },
    de: { hello: "Hallo, {name}!", files: "{n, plural, one {# Datei} other {# Dateien}}", broken: "{kaputt" },
    "zh-Hans": { hello: "你好，{name}！" },
    "zh-Hant": { hello: "你好，{name}！", color: "顏色" },
    pt: { color: "Cor" },
    "pt-PT": { hello: "Olá, {name}!" },
  },
};

interface Messages {
  hello(name: string): string;
  color(): string;
  files(n: number): string;
  broken(): string;
}

describe("createCatalog", () => {
  const catalog = createCatalog<Messages>(data);
  afterEach(() => {
    delete g.myphp;
  });
  test("falls back along the languages", () => {
    const cases: [string, string, Record<string, unknown>, string][] = [
      ["en", "hello", { name: "Ada" }, "Hello, Ada!"], ["de", "hello", { name: "Ada" }, "Hallo, Ada!"], ["de-CH", "hello", { name: "Ada" }, "Hallo, Ada!"],
      ["de", "color", {}, "Color"], ["de", "broken", {}, "broken"], ["en-001", "color", {}, "Colour"], ["zh-TW", "color", {}, "顏色"], ["zh-Hant-HK", "color", {}, "顏色"],
      ["zh-CN", "color", {}, "Color"], ["pt-PT", "color", {}, "Cor"], ["fr", "hello", { name: "Ada" }, "Hello, Ada!"], ["de", "missing", {}, "missing"],
      ["de", "toString", {}, "toString"], ["de", "constructor", {}, "constructor"], ["de", "files", { n: 1 }, "1 Datei"], ["fr", "files", { n: 0 }, "0 files"],
      ["en-XA", "color", {}, "[Çöļöŕ one]"], ["ar-XB", "color", {}, "‏‮Color‬‏"], ["", "color", {}, "Color"],
    ];
    for (const [locale, key, args, want] of cases) expect(catalog.format(locale, key, args), `${locale} ${key}`).toBe(want);
    expect([catalog.resolve("zh-TW"), catalog.resolve("en-AU"), catalog.resolve("xx")]).toEqual(["zh-Hant", "en", "en"]);
  });
  test("binds the messages to the language the page shows", () => {
    const messages = catalog.messages();
    g.myphp = { locale: "de" };
    expect(currentLocale()).toBe("de");
    expect(messages.files(3)).toBe("3 Dateien");
    g.myphp = { locale: "zh-Hant" };
    expect(messages.hello("Ada")).toBe("你好，Ada！");
    expect(catalog.messagesIn("en").files(1)).toBe("1 file");
  });
});

test("isPseudoLocale", () => {
  expect([isPseudoLocale("en-XA"), isPseudoLocale("ar-xb"), isPseudoLocale("en"), isPseudoLocale("not a tag")]).toEqual([true, true, false, false]);
});

test("skeletons as Intl's options", () => {
  expect(skeletonOptions("yMMMd")).toEqual({ year: "numeric", month: "short", day: "numeric" });
  expect(skeletonOptions("Hmm")).toEqual({ hour: "numeric", hourCycle: "h23", minute: "2-digit" });
  expect(skeletonOptions("Kms")).toEqual({ hour: "numeric", hourCycle: "h11", minute: "numeric", second: "numeric" });
});

describe("the module myphp generate writes", () => {
  const file = join(import.meta.dir, "../../tests/i18n/app/locales.ts");
  test("type-checks", () => {
    const tsc = Bun.spawnSync(["tsc", "--noEmit", "--strict", "--noUncheckedIndexedAccess", "--target", "ES2022", "--module", "ESNext", "--moduleResolution", "bundler", "--lib", "ES2022,DOM", "--skipLibCheck", file]);
    expect(tsc.stdout.toString() + tsc.stderr.toString()).toBe("");
    expect(tsc.exitCode).toBe(0);
  });
  test("says the messages", async () => {
    const locales = await import(file);
    expect(locales.languages).toEqual(["en", "de", "en-GB", "zh-Hant"]);
    const de = locales.messagesIn("de");
    expect([de.title(), de.filesSelected(0), de.filesSelected(1), de.progress(0.5)]).toEqual(["Notizen", "Keine Dateien ausgewählt", "1 Datei ausgewählt", "50 % done"]);
    expect(locales.messagesIn("en-GB").progress(0.5)).toBe("50% complete");
    expect(locales.messagesIn("zh-TW").filesSelected(2)).toBe("已選取 2 個檔案");
    expect([locales.messagesIn("en").guests("Ada", 3), locales.messagesIn("en").place(22), locales.messagesIn("en").positional("a", "b"), locales.messagesIn("en").keyword("x", "y", "z", "w"), locales.messagesIn("en-XA").title()]).toEqual(["Ada and 2 others", "You came 22nd", "a of b", "x y z w", "[Ñöţéš one]"]);
    g.myphp = { locale: "de" };
    try {
      expect(locales.messages.greeting("Ada")).toBe("Hallo, Ada!");
    } finally {
      delete g.myphp;
    }
  });
});
