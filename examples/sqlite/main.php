<?php
// SQLite in a web page, through the PHP plugin: App::use(Sqlite::plugin())
// binds "plugin:sqlite", and the page calls it as the plugin's TypeScript
// client does (src/Plugins/Sqlite/client/index.ts), here in plain
// JavaScript with the same wire values. Notes go into notes.db in the app's
// data directory. "Reload" shows that a connection belongs to its page:
// the reloaded page cannot use the last page's id, which PHP closed.
// Run: ./run.sh sqlite
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\Plugins\Sqlite\Sqlite;

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

App::use(Sqlite::plugin());

$html = <<<'HTML'
<!doctype html><meta charset="utf-8">
<style>
  body { font: 15px -apple-system, system-ui; margin: 0; padding: 24px; color: #1d1d1f; }
  form { display: flex; gap: 8px; margin-bottom: 16px; }
  input { flex: 1; font: inherit; padding: 6px 10px; border: 1px solid #ccc; border-radius: 6px; }
  button { font: inherit; padding: 6px 14px; border: 0; border-radius: 6px; background: #3377ff; color: #fff; }
  li { padding: 6px 0; border-bottom: 1px solid #eee; }
  #log { color: #888; font-size: 13px; white-space: pre-wrap; }
</style>
<h2>Notes in SQLite</h2>
<form id="f"><input id="t" placeholder="Title" autofocus><button>Add</button></form>
<p><button id="reload" type="button">Reload</button></p>
<ul id="list"></ul>
<div id="log"></div>
<script>
  // The plugin's wire values, as client/index.ts sends and reads them.
  const value = (v) => v === null ? { type: "null" } : typeof v === "number" && Number.isInteger(v) ? { type: "integer", integer: String(v) }
    : typeof v === "number" ? { type: "real", real: v } : { type: "text", text: String(v) };
  const cell = (c) => c.type === "integer" ? Number(c.integer) : c.type === "real" ? c.real : c.type === "text" ? c.text : null;
  const sql = (method, ...args) => myphp.call("plugin:sqlite." + method, ...args);
  const say = (s) => log.textContent += s + "\n";
  (async () => {
    const before = sessionStorage.getItem("db");
    if (before) {
      // The last page's connection closed with it.
      try { await sql("query", before, "SELECT 1", []); say("the last page's connection still works: wrong"); }
      catch (e) { say("the last page's connection: " + e.message); }
    }
    const db = await sql("open", "notes.db", { readOnly: false });
    sessionStorage.setItem("db", db);
    say("opened notes.db as " + db.slice(0, 8) + "…");
    await sql("execute", db, "CREATE TABLE IF NOT EXISTS notes (id INTEGER PRIMARY KEY, title TEXT NOT NULL, at TEXT DEFAULT CURRENT_TIMESTAMP)", []);
    const render = async () => {
      const { rows } = await sql("query", db, "SELECT id, title, at FROM notes ORDER BY id DESC LIMIT 50", []);
      list.innerHTML = rows.map((r) => r.map(cell)).map(([id, title, at]) => `<li>#${id} ${title} <small>${at}</small></li>`).join("");
    };
    f.onsubmit = async (e) => {
      e.preventDefault();
      try {
        const [r] = await sql("transaction", db, [{ sql: "INSERT INTO notes (title) VALUES (?)", args: [value(t.value)] }]);
        say("inserted rowid " + r.lastInsertId);
        t.value = "";
        await render();
      } catch (x) { say(x.message); }
    };
    reload.onclick = () => location.reload();
    await render();
  })().catch((e) => say(e.message));
</script>
HTML;

App::setName('SQLite');
App::whenReady(function () use ($html) {
    new Window(new WindowOptions(title: 'MyPHP — SQLite from a page', width: 560, height: 480, html: $html));
});
App::run();
