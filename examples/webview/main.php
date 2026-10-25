<?php
// A web page that calls PHP: Notes is a bound service, its methods are
// `Notes.add` and `Notes.list` in the page, and PHP pushes an "added" event.
// Clock.ticks streams through a Channel (a generator: the window stays live
// between ticks), the page drives its window (myphp.window.setTitle), and a
// preload script runs before the page's own.
// Run: ./run.sh webview
// TypeScript client: MYPHP_GENERATE=examples/webview/myphp.ts ./run.sh webview
declare(strict_types=1);

use MyPHP\{App, Channel, ChannelClosed, Event, Ipc, PageOptions, Window, WindowOptions};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

/** Note is a note. */
final class Note
{
    public function __construct(public int $id, public string $title, public ?string $body = null) {}
}

/** Notes stores the user's notes. */
final class Notes
{
    /** @var Note[] */
    private array $items = [];
    public static ?Event $added = null;

    /** Add creates a note and returns it. */
    public function add(string $title, ?string $body = null): Note
    {
        if ($title === '') {
            throw new InvalidArgumentException('a note needs a title');
        }
        $note = new Note(count($this->items) + 1, $title, $body);
        $this->items[] = $note;
        self::$added?->broadcast($note);
        return $note;
    }

    /**
     * List returns all notes.
     * @return Note[]
     */
    public function list(): array
    {
        return $this->items;
    }

    /** Count returns how many notes there are. */
    public function count(): int
    {
        return count($this->items);
    }
}

/** Clock streams. */
final class Clock
{
    /**
     * Ticks sends $n timestamps, one every 300 ms, and returns how many it sent.
     * @param Channel<string> $out
     * @return int
     */
    public function ticks(int $n, Channel $out): Generator
    {
        for ($i = 1; $i <= $n; $i++) {
            usleep(300_000);
            try {
                yield date('H:i:s') . " tick $i";
            } catch (ChannelClosed) {
                return $i - 1; // the page stopped listening
            }
        }
        return $n;
    }
}

Ipc::bind(new Notes());
Ipc::bind(new Clock());
Notes::$added = new Event('added', Note::class);

$html = <<<'HTML'
<!doctype html><meta charset="utf-8">
<style>
  body { font: 15px -apple-system, system-ui; margin: 0; padding: 24px; color: #1d1d1f; }
  form { display: flex; gap: 8px; margin-bottom: 16px; }
  input { flex: 1; font: inherit; padding: 6px 10px; border: 1px solid #ccc; border-radius: 6px; }
  button { font: inherit; padding: 6px 14px; border: 0; border-radius: 6px; background: #3377ff; color: #fff; }
  li { padding: 6px 0; border-bottom: 1px solid #eee; }
  #err { color: #c33; min-height: 1.2em; }
  #count { color: #888; }
</style>
<h2>Notes <span id="count"></span></h2>
<form id="f"><input id="t" placeholder="Title" autofocus><button>Add</button></form>
<p><button id="stream" type="button">Stream 5 ticks</button> <button id="stop" type="button">Stop</button> <span id="ticks"></span></p>
<div id="err"></div>
<ul id="list"></ul>
<script>
  const { call, on } = { call: (m, ...a) => myphp.call(m, ...a), on: (n, fn) => myphp.on(n, fn) };
  const render = (notes) => {
    list.innerHTML = notes.map(n => `<li>#${n.id} ${n.title}</li>`).join('');
    call('Notes.count').then(c => count.textContent = `(${c})`);
  };
  f.onsubmit = async (e) => {
    e.preventDefault(); err.textContent = '';
    try { await call('Notes.add', t.value); t.value = ''; }
    catch (x) { err.textContent = x.name === 'CallError' ? x.message + ' — from ' + x.method : x; }
  };
  on('added', (note) => console.log('event added', note) || call('Notes.list').then(render));
  call('Notes.list').then(render);
  let ch = null;
  stream.onclick = async () => {
    ch?.close(); ch = myphp.channel(); ticks.textContent = '';
    const done = call('Clock.ticks', 5, ch);            // the channel rides along as an argument
    for await (const line of ch) ticks.textContent += line + ' · ';
    ticks.textContent += 'sent ' + await done;
    myphp.window.setTitle('Notes — ' + PRELOADED + ' streamed');   // PRELOADED comes from the preload script
  };
  stop.onclick = () => ch?.close();
</script>
HTML;

App::setName('Notes');
App::whenReady(function () use ($html) {
    $w = new Window(new WindowOptions(title: 'MyPHP + WKWebView — Notes', width: 640, height: 480, html: $html,
        page: new PageOptions(preloadScript: 'window.PRELOADED = "v' . App::VERSION . '";')));
    $w->on('fileDrop', fn($e) => printf("[PHP] dropped: %s\n", implode(', ', $e->paths))); // files dropped on the page reach PHP, not the page
    // PHP -> page -> PHP: run a script once the DOM is ready and read its value back.
    $w->page()->on('domReady', function () use ($w): void {
        printf("[PHP] page says: %s\n", $w->page()->eval('document.querySelector("h2").firstChild.textContent.trim() + " / " + navigator.userAgent.split(" ").pop()'));
    });
});
App::run();
