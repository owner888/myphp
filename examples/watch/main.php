<?php
// Watching files from a web page, through the PHP plugin: the page watches
// the root "folder" by its name, as the plugin's TypeScript client does
// (src/Plugins/Watch/client/index.ts), here in plain JavaScript over
// myphp.call and a Channel. The buttons change files through a bound
// method; changing them in Finder or an editor shows the same. The folder
// is the one given, or a "watched" folder in the app's data directory.
// Run: ./run.sh watch [folder]
declare(strict_types=1);

use MyPHP\{App, Ipc, Window, WindowOptions};
use MyPHP\Plugins\Watch\{Options, Root, Watch};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

App::setName('Watch');
$dir = $argv[1] ?? App::userDataPath() . '/watched';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

App::use(Watch::new(new Options(roots: [
    'folder' => new Root(realpath($dir), recursive: true, ignore: fn(string $p, bool $isDir) => $isDir && in_array(basename($p), ['node_modules', '.git'], true)),
])));

/** Changes files in the folder, as another program would. */
final class Files
{
    private int $n = 0;

    public function __construct(private readonly string $dir) {}

    public function change(string $what): string
    {
        $n = ++$this->n;
        match ($what) {
            'create' => file_put_contents("{$this->dir}/note-$n.txt", "note $n\n"),
            'write' => file_put_contents("{$this->dir}/notes.txt", "line $n\n", FILE_APPEND),
            'save' => (function () use ($n) { // as editors save: a new file renamed over the old one
                file_put_contents("{$this->dir}/notes.txt.tmp", "saved $n\n");
                rename("{$this->dir}/notes.txt.tmp", "{$this->dir}/notes.txt");
            })(),
            'mkdir' => mkdir("{$this->dir}/dir-$n/inner", 0755, true),
            'clear' => array_map(fn($f) => is_dir($f) ? exec('rm -rf ' . escapeshellarg($f)) : unlink($f), glob("{$this->dir}/*")),
            default => throw new RuntimeException("no change $what"),
        };
        return $what;
    }
}
Ipc::bind(new Files(realpath($dir)));

$html = <<<'HTML'
<!doctype html><meta charset="utf-8">
<style>
  body { font: 15px -apple-system, system-ui; margin: 0; padding: 24px; color: #1d1d1f; }
  button { font: inherit; padding: 6px 14px; border: 0; border-radius: 6px; background: #3377ff; color: #fff; margin: 0 6px 6px 0; }
  #log { font: 13px ui-monospace, monospace; white-space: pre-wrap; }
  .op { display: inline-block; width: 64px; color: #3377ff; }
</style>
<h2>Watching <code id="dir"></code></h2>
<p>
  <button data-c="create">New file</button><button data-c="write">Append</button><button data-c="save">Save as editors do</button>
  <button data-c="mkdir">New folder</button><button data-c="clear">Clear</button>
  <button id="reload">Reload</button>
</p>
<div id="log"></div>
<script>
  const say = (html) => log.insertAdjacentHTML("afterbegin", html + "\n");
  for (const b of document.querySelectorAll("[data-c]")) b.onclick = () => myphp.call("Files.change", b.dataset.c).catch((e) => say(e.message));
  reload.onclick = () => location.reload();
  (async () => {
    // What the plugin's client does: a channel for the parts, "ready" first, then a part per batch.
    const parts = new myphp.Channel();
    const outcome = myphp.call("plugin:watch.watch", { root: "folder", path: ".", recursive: true, debounceMs: 0 }, parts);
    for await (const part of parts) {
      if (part.type === "ready") { say("<i>ready</i>"); continue; }
      say(part.events.map((e) => `<span class="op">${e.op}</span>${e.oldPath ? e.oldPath + " → " : ""}${e.path}${e.isDir ? "/" : ""}`).join("\n") + "\n—");
    }
    const o = await outcome;
    say(o.code ? `<b>${o.code}</b>: ${o.message}` : "<i>the watch ended</i>");
  })().catch((e) => say(e.message));
</script>
HTML;

App::whenReady(function () use ($html, $dir) {
    new Window(new WindowOptions(title: 'MyPHP — watching files from a page', width: 640, height: 560, html: str_replace('<code id="dir"></code>', '<code>' . htmlspecialchars(realpath($dir)) . '</code>', $html)));
});
App::run();
