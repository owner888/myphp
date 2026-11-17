# Markdown

`Markdown($text, $options, streaming: $isStreaming)` renders CommonMark and GitHub Flavored Markdown (tables, task lists, strikethrough, autolinks, footnotes, syntax-highlighted code blocks with a Copy button, and inline/block LaTeX math) directly in native GPU UI:

```php
$view = fn() => ScrollView()(
    Markdown("# Notes\n\nSome **bold** text and a list:\n\n- one\n- two")->padding()
);
```

## Streaming LLM replies

`Markdown` is built for language model replies that stream in token by token: pass `streaming: true` while tokens are arriving, and `streaming: false` once complete. Only the outermost open block is re-parsed on each token ($O(1)$ per token regardless of document length), half-written syntax (`**bol`, unclosed code fences, partial links) renders as what it will become without flashing raw markers, and the user can select and copy text across blocks while the stream is still running.

```php
use MyPHP\UI\Markdown\{Options, Reveal, RevealUnit};

$app = new class {
    public Options $md;
    public string $reply = "**Thinking about** your ques";
    public bool $done = false;
    public function __construct() {
        $this->md = new Options(reveal: new Reveal(RevealUnit::ByWord));
    }
};
$view = fn() => Markdown($app->reply, $app->md, streaming: !$app->done)->padding();
```

## Options and reveal pacing

Pass a `MyPHP\UI\Markdown\Options` instance (kept in your state object) to customize rendering:

- **`reveal: new Reveal(RevealUnit::ByWord)`** (or `ByCharacter`, `BySentence`, `ByLine`, `ByParagraph`, `AtOnce`) smoothly paces and fades in incoming tokens so bursty network chunks read at a steady cadence (see [`examples/markdown-chat`](../../examples/markdown-chat)).
- **`wrapCode: true`** soft-wraps long lines inside fenced code blocks instead of scrolling horizontally.
- **`inlineMath: true`** renders `$...$` inline math alongside `$$...$$` display math.
