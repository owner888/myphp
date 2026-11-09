<?php
// A chat with a pretend language model whose replies stream in token by
// token as Markdown (mygo's examples/markdown-chat): Markdown::stream shows
// a reply while it comes, Markdown::view once it is done, and the list
// follows the end until the user scrolls up.
// Run: myphp run -d macos examples/markdown-chat
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\Content;
use MyPHP\UI\Markdown\{Markdown, Options};

// MyPHP, and its declarative views (VStack, Text…): loaded already in a phone's app, whose launcher loads them
class_exists(\MyPHP\App::class) || require __DIR__ . '/../../src/autoload.php';

const REVEAL_NAMES = ['Word', 'Sentence', 'Line', 'Paragraph', 'At once'];

const REPLIES = [
    "Here is a minimal **LRU cache** in PHP:\n\n"
    . "```php\nfinal class LRU\n{\n    private array \$items = [];\n\n    public function __construct(private int \$cap) {}\n\n"
    . "    public function get(string \$key): mixed\n    {\n        if (!array_key_exists(\$key, \$this->items)) { return null; }\n        \$v = \$this->items[\$key];\n        unset(\$this->items[\$key]);\n        return \$this->items[\$key] = \$v; // now the most recent\n    }\n}\n```\n\n"
    . "A few things to note:\n\n1. A PHP array keeps the order of insertion, so the first key is the least recent.\n2. `get` is a writer too, since it moves the entry.\n3. For heavy traffic, consider **APCu** or a shared cache.\n",
    "## Comparing the options\n\n| Strategy | Read latency | Staleness | Complexity |\n|---|--:|:-:|---|\n| No cache | ~41 ms | none | trivial |\n| Cache-aside | ~0.2 ms | medium | low |\n| Write-through | ~0.2 ms | low | medium |\n\n"
    . "> **Tip:** start with *cache-aside*; move to write-through once staleness bites.\n\nSee the [PHP manual on APCu](https://www.php.net/manual/en/book.apcu.php) for a cache in memory.",
    "Sure. The area of a circle is \$\$A = \\pi r^2\$\$ and its circumference \$\$C = 2\\pi r\$\$.\n\n"
    . "- [x] Measure the radius\n- [x] Square it\n- [ ] Multiply by π\n\nThat's all there is to it~ unless you need ~~approximations~~ the exact value, which `M_PI` gives to 15 digits.",
];

final class Message
{
    public function __construct(public int $id, public bool $mine, public string $text = '', public bool $done = false) {}
}

final class Chat
{
    public ?Window $win = null;
    /** @var list<Message> */
    public array $messages = [];
    /** What is being written. */
    public string $input = '';
    public Options $md;
    public int $next = 0, $replies = 0;
    /** How replies come in: by word, sentence, line or paragraph, or at once. */
    public int $reveal = 0;

    public function __construct()
    {
        $this->md = new Options();
    }

    /** Sends the input, and streams the next reply in. */
    public function send(): void
    {
        $this->messages[] = new Message(++$this->next, true, trim($this->input), true);
        $this->input = '';
        $this->messages[] = $reply = new Message(++$this->next, false);
        $src = REPLIES[$this->replies++ % count(REPLIES)];
        if ($this->win === null) { // without a window, the reply comes whole
            [$reply->text, $reply->done] = [$src, true];
            return;
        }
        $this->token($reply, $src, 0);
    }

    /** The next token of $src: a few bytes, at the uneven pace of a model. */
    private function token(Message $reply, string $src, int $i): void
    {
        App::after((8 + random_int(0, 29)) / 1000, function () use ($reply, $src, $i) {
            if ($reply->done) { return; } // stopped
            $n = min(strlen($src) - $i, 2 + random_int(0, 6));
            while ($n < strlen($src) - $i && (ord($src[$i + $n]) & 0xC0) === 0x80) { $n++; }
            $i += $n;
            $reply->text = substr($src, 0, $i);
            if ($i >= strlen($src)) { $reply->done = true; }
            $this->win?->invalidate();
            if (!$reply->done) { $this->token($reply, $src, $i); }
        });
    }
}

$s = new Chat();
App::setName('Markdown chat');
App::whenReady(function () use ($s) {
    $s->win = new Window(new WindowOptions(title: 'Markdown chat', width: 820, height: 720, content: Content::file(__DIR__ . '/view.php', $s)));
});
App::run();
