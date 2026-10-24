<?php
// The app's services from a page: one instance (start it twice: the second
// hands its command line to this one), deep links and files opened with
// the app (from a bundle that declares urlSchemes and fileAssociations),
// the network's state as it changes, the clipboard, a secret in the
// Keychain, recent documents, cookies, opening at login (from a bundle),
// and the page's own: find in page, print, print to PDF, a download.
// Run: ./run.sh services
declare(strict_types=1);

use MyPHP\{App, Clipboard, Cookie, Cookies, Event, FindOptions, Ipc, Network, NetworkStatus, PDFOptions, PageSize, SecretNotFound, Secrets, Window, WindowOptions};
use MyPHP\Transfer\Data;

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

App::setName('Services');
if (!App::requestSingleInstanceLock()) {
    exit; // the first instance has our command line
}

final class Services
{
    public ?Window $win = null;

    public function __construct(private readonly Event $logged) {}

    /** Tells the page a line for its log, once there is one. */
    public function log(string $line): void
    {
        if ($this->win !== null) { $this->logged->emit($this->win, $line); }
    }

    public function network(): NetworkStatus { return Network::status(); }

    public function copy(string $text): string
    {
        Clipboard::write(Data::text($text));
        return 'copied; the clipboard has ' . implode(', ', Clipboard::formats());
    }

    public function paste(): string
    {
        return Clipboard::read('text/plain')->formats() === [] ? '(no text)' : Clipboard::readFormat('text/plain');
    }

    public function saveSecret(string $value): string
    {
        Secrets::set('demo-token', $value);
        return 'stored';
    }

    public function readSecret(): string
    {
        try {
            return Secrets::get('demo-token');
        } catch (SecretNotFound) {
            return '(none stored)';
        }
    }

    /** @return list<string> */
    public function recent(): array
    {
        App::addRecentDocument(__FILE__);
        return App::recentDocuments();
    }

    /** @return list<array<string, mixed>> */
    public function cookies(): array
    {
        Cookies::set(new Cookie('demo', 'hello', 'example.com', expires: new DateTimeImmutable('+1 day')));
        return array_map(fn(Cookie $c) => ['name' => $c->name, 'domain' => $c->domain, 'path' => $c->path, 'sameSite' => $c->sameSite], Cookies::list());
    }

    public function find(string $text, bool $next): string
    {
        $r = $this->win->page()->findInPage($text, new FindOptions(findNext: $next));
        return "{$r->active} of {$r->matches}";
    }

    public function pdf(): string
    {
        $path = getenv('HOME') . '/Desktop/services.pdf';
        file_put_contents($path, $this->win->page()->printToPDF(new PDFOptions(PageSize::a4(), background: true)));
        return "written to $path";
    }

    public function print(): void { $this->win->page()->print(); }

    public function login(bool $on): string
    {
        App::setOpenAtLogin($on);
        return App::openAtLogin() ? 'opens at login' : 'does not open at login';
    }
}

$svc = new Services(new Event('log', 'string'));
Ipc::bind($svc);
App::onOpenURL(fn(string $url) => $svc->log("open URL: $url"));
App::onOpenFile(fn(string $path) => $svc->log("open file: $path"));
App::onSecondInstance(function (array $args, string $wd) use ($svc) {
    $svc->log('second instance: ' . json_encode($args) . " in $wd");
    $svc->win?->focus();
});
Network::onChanged(fn(NetworkStatus $s) => $svc->log('network: ' . json_encode($s)));

App::whenReady(function () use ($svc) {
    $svc->win = new Window(new WindowOptions(title: 'Services', width: 720, height: 640, html: <<<'HTML'
        <!doctype html><meta charset="utf-8">
        <style>body{font:14px -apple-system,system-ui;padding:16px} button{margin:2px} #log{white-space:pre-wrap;font:12px ui-monospace,monospace;background:#8881;padding:8px;min-height:120px}</style>
        <h3>App services</h3>
        <p><button id=net>Network</button> <button id=copy>Copy “hello”</button> <button id=paste>Paste</button>
           <button id=secret>Store a secret</button> <button id=read>Read it</button> <button id=recent>Recent documents</button>
           <button id=cookies>Cookies</button> <button id=login>Open at login</button> <button id=nologin>Not at login</button></p>
        <p>Find: <input id=q value="the"> <button id=find>Find</button> <button id=next>Next</button>
           <button id=print>Print…</button> <button id=pdf>Print to PDF</button>
           <a href="data:text/plain,downloaded%20by%20MyPHP" download="myphp.txt">Download a file</a></p>
        <div id=log></div>
        <p>The quick brown fox jumps over the lazy dog: the find bar finds the words of the page, as the browsers do.</p>
        <script>
        const log = (s) => (document.getElementById("log").textContent += s + "\n");
        const run = (id, m, ...a) => document.getElementById(id).onclick = () => myphp.call(m, ...a.map((f) => typeof f === "function" ? f() : f)).then((v) => log(m + ": " + JSON.stringify(v)), (e) => log(m + " failed: " + e.message));
        run("net", "Services.network"); run("copy", "Services.copy", "hello"); run("paste", "Services.paste");
        run("secret", "Services.saveSecret", "s3cret"); run("read", "Services.readSecret"); run("recent", "Services.recent");
        run("cookies", "Services.cookies"); run("login", "Services.login", true); run("nologin", "Services.login", false);
        run("find", "Services.find", () => document.getElementById("q").value, false); run("next", "Services.find", () => document.getElementById("q").value, true);
        run("print", "Services.print"); run("pdf", "Services.pdf");
        myphp.on("log", log);
        </script>
        HTML));
    $svc->win->page()->on('willDownload', fn(MyPHP\DownloadEvent $e) => $svc->log("download {$e->suggestedName} to {$e->path}"));
    $svc->win->page()->on('downloadDone', fn(MyPHP\Download $d) => $svc->log('downloaded ' . $d->path . ($d->error !== null ? " ({$d->error})" : '')));
});
App::run();
