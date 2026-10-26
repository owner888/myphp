<?php
// A chat with a pretend language model whose replies stream in token by
// token as Markdown (mygo's examples/markdown-chat): Markdown::stream shows
// a reply while it comes, Markdown::view once it is done, and the list
// follows the end until the user scrolls up.
// Run: ./run.sh markdown-chat
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Animation, Content, Context, ListState, Node, TextState};
use MyPHP\UI\Markdown\{Markdown, Options, Reveal, RevealUnit};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

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
    public TextState $input;
    public ListState $list;
    public Options $md;
    public int $next = 0, $replies = 0;
    /** How replies come in: by word, sentence, line or paragraph, or at once. */
    public int $reveal = 0;

    public function __construct()
    {
        $this->input = new TextState();
        $this->list = new ListState();
        $this->md = new Options();
    }

    /** Sends the input, and streams the next reply in. */
    public function send(): void
    {
        $this->messages[] = new Message(++$this->next, true, trim($this->input->text), true);
        $this->input->text = '';
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

function view(Context $c, Chat $app): void
{
    $t = $c->theme;
    $app->list->key = fn(int $i) => $app->messages[$i]->id;
    $app->list->followEnd = true;
    $app->list->followAnimation = Animation::smooth(); // glide up as the reply grows
    $app->md->reveal = new Reveal([RevealUnit::ByWord, RevealUnit::BySentence, RevealUnit::ByLine, RevealUnit::ByParagraph, RevealUnit::AtOnce][$app->reveal]);
    $c->column(0, 0, 1);
      $c->row(0, 8)->align(Node::CENTER)->padding(8, 20)->borderWidth(0, 0, 1, 0)->borderColor($t->border);
        $c->muted('Stream by');
        $c->segmented(REVEAL_NAMES, $app->reveal)->label('Stream by');
      $c->end();
      if ($app->messages === []) {
          $c->column(16, 0, 1)->align(Node::CENTER); // at the top, centered, as mygo's list shows it with no message
            $c->muted('Ask anything: the replies are canned, streamed as a model streams them.');
          $c->end();
      } else {
          $c->list($app->list, count($app->messages), fn(int $i) => message($c, $app, $app->messages[$i]))->grow(1)->padding(16, 20);
      }
      $c->row(0, 8)->padding(12, 20)->borderWidth(1, 0, 0, 0)->borderColor($t->border);
        $in = $c->input($app->input, 'Message', 0)->autoFocus();
        $last = $app->messages === [] ? null : $app->messages[count($app->messages) - 1];
        if ($last !== null && !$last->done) {
            if ($c->clicked($c->button('Stop'))) { $last->done = true; }
        } else {
            $send = $c->primaryButton('Send')->disabled(trim($app->input->text) === '');
            if (($c->clicked($send) || $c->submitted($in)) && trim($app->input->text) !== '') { $app->send(); }
        }
      $c->end();
    $c->end();
}

/** A message: the user's in a bubble on the right, the model's as Markdown. */
function message(Context $c, Chat $app, Message $m): void
{
    $t = $c->theme;
    $c->row(0, 0)->padding(8, 0);
      if ($m->mine) {
          $c->spacer();
          $c->text($m->text)->selectable()->padding(8, 12)->round(14)->background($t->surface)->maxWidth(520);
      } else {
          $c->column()->maxWidth(760)->grow(1);
            $m->done ? Markdown::view($c, $m->text, $app->md) : Markdown::stream($c, $m->text, $app->md);
          $c->end();
      }
    $c->end();
}

$s = new Chat();
App::setName('Markdown chat');
App::whenReady(function () use ($s) {
    $s->win = new Window(new WindowOptions(title: 'Markdown chat', width: 820, height: 720, content: Content::view(fn(Context $c) => view($c, $s))));
});
App::run();
