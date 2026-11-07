<?php
// Gallery tours MyPHP's own user interface toolkit (mygo's
// examples/gallery): a window drawn on the GPU from PHP, without a web page.
// It shows layout, the widgets, text editing, a list of ten thousand rows
// with context menus, a chat of messages of every height, charts, styling
// (grids, borders, gradients, text decorations, motion), custom drawing,
// overlays, file drops, updates from timers, and languages: a menu of
// them, numbers and dates formatted in each, and right-to-left layout. Its
// pages are paths of a router, with a history.
// Run: ./run.sh gallery
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\Plugins\Glass\Glass;
use MyPHP\UI\{Content, Context, Font, GridState, Key, ListState, Motion, Node, OutlineState, Router, Selection, Span, SVG, TextState};
use MyPHP\UI\Markdown\{Markdown, Options as MarkdownOptions, RevealUnit};
use MyPHP\UI\RichText\{Attributes, Document, ListKind, Paragraph, ParagraphStyle, Run, Text as RichTextText};

require __DIR__ . '/../../src/autoload.php'; // MyPHP, and its declarative views (VStack, Text…)

const PAGES = ['Overview', 'Controls', 'Text', 'Rich Text', 'List', 'Charts', 'Markdown', 'Styling', 'Drawing', 'Glass', 'Overlays', 'Motion', 'Languages'];
const SIZES = ['Small', 'Medium', 'Large', 'Extra large'];

/** A task of the Drag and drop card. */
final class GalleryTask
{
    public function __construct(public string $name, public bool $done = false) {}
}

/** A file of the table on the List page. */
final class TableFile
{
    public function __construct(public int $id, public string $name, public string $kind, public int $size) {}
}

/** A message of the chat on the List page; ids grow with time, below zero for those loaded later from before. */
final class Message
{
    public function __construct(public readonly int $id, public readonly string $text, public readonly bool $mine) {}

    /** The day of the message, 12 a day, 0 for today's. */
    public function day(): int
    {
        return $this->id < 0 ? intdiv($this->id - 11, 12) - 16 : intdiv($this->id, 12) - 16;
    }
}

/** What the messages of the chat say. */
const CHAT_LINES = [
    'Did the list keep its place?',
    'Yes.',
    'It measures each message as it shows, and estimates the others from those it measured.',
    'So a message of ten lines and one of a single word scroll the same.',
    'Load the older ones: the messages in view stay where they are.',
    'Nice!',
    'Scroll up a little and the date of the day you are in stays at the top, until the next day pushes it away.',
    'And at the end, new messages keep coming into view, unless you scrolled up to read.',
    'Then a button takes you back to the latest.',
    'What about a million messages?',
    'Its offsets are floats, so they scroll by fractions of a point all the same.',
    '👍',
    'The rows it builds are only those in view, and a few beyond for Tab.',
    'Lunch?',
    'Sure, in ten minutes.',
];

/** Makes up message $id, of one to three lines of the chat. */
function makeMessage(int $id): Message
{
    $r = ($id * 2654435761) & 0xffffffff;
    $lines = [];
    for ($k = 0; $k < 1 + intdiv(($r >> 3) % 5, 2); $k++) { $lines[] = CHAT_LINES[(($r >> 9) + $k * 4) % count(CHAT_LINES)]; }
    return new Message($id, implode(' ', $lines), $r % 3 === 0);
}

/** An item of the Motion page's list. */
final class MotionItem
{
    public function __construct(public readonly int $id, public readonly string $name) {}
}

/** The colors that tint the items of the Motion page. */
const MOTION_COLORS = [0x2563eb, 0x16a34a, 0xd97706, 0xdb2777, 0x7c3aed];

/** The shapes of a 24×24 stroked icon, drawn in currentColor as icon sets draw them. */
function icon(string $shapes): SVG
{
    return SVG::parse('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $shapes . '</svg>');
}

/** The icon of a page, parsed once. */
function pageIcon(string $page): SVG
{
    static $icons = [];
    return $icons[$page] ??= icon(match ($page) {
        'Overview' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'Controls' => '<path d="M4 7h10M18 7h2M4 17h2M10 17h10"/><circle cx="16" cy="7" r="2"/><circle cx="8" cy="17" r="2"/>',
        'Text' => '<path d="M5 6V5h14v1M12 5v14M9 19h6"/>',
        'Rich Text' => '<path d="M6 4h6a4 4 0 0 1 0 8H6zM6 12h7a4 4 0 0 1 0 8H6z"/>',
        'List' => '<path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/>',
        'Charts' => '<path d="M3 3v18h18"/><path d="M8 17v-5M13 17V8M18 17v-8"/>',
        'Markdown' => '<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M6 15V9l2.5 3L11 9v6M15.5 9v6M13.5 13l2 2 2-2"/>',
        'Styling' => '<path d="M12 21a9 9 0 1 1 9-9c0 2.5-2 3.5-3.5 3.5H16a2 2 0 0 0-1.5 3.3c.4.5.4 2.2-2.5 2.2z"/><circle cx="7.5" cy="11" r="1"/><circle cx="11" cy="7" r="1"/><circle cx="16" cy="8.5" r="1"/>',
        'Drawing' => '<path d="M15 5l4 4M4 20l1-4.5L16.5 4a2.1 2.1 0 0 1 3 3L8 18.5z"/>',
        'Glass' => '<rect x="3" y="6" width="18" height="12" rx="6"/><path d="M7 10.5a3 3 0 0 1 2.5-1.5"/>',
        'Overlays' => '<path d="M12 3 3 8l9 5 9-5z"/><path d="m3 13 9 5 9-5"/>',
        'Motion' => '<path d="M3 12h4M5 7h6M5 17h6"/><circle cx="16" cy="12" r="5"/>',
        'Languages' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
    });
}

/** The icons of buttons: a star, a check mark, a chevron and a loader; and a picture in its own colors, gradients, a clip path and a dot in currentColor. */
function glyph(string $name): SVG
{
    static $icons = [];
    return $icons[$name] ??= match ($name) {
        'star' => icon('<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>'),
        'check' => icon('<path d="M20 6 9 17l-5-5"/>'),
        'chevron' => icon('<path d="m6 9 6 6 6-6"/>'),
        'loader' => icon('<path d="M21 12a9 9 0 1 1-6.2-8.6"/>'),
        'dots' => icon('<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>'),
        'badge' => SVG::parse('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120">
    <defs>
        <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#6366f1"/><stop offset="1" stop-color="#ec4899"/></linearGradient>
        <radialGradient id="glow" cx=".35" cy=".3" r=".6"><stop offset="0" stop-color="#fff" stop-opacity=".55"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></radialGradient>
        <clipPath id="round"><rect width="120" height="120" rx="28"/></clipPath>
    </defs>
    <g clip-path="url(#round)"><rect width="120" height="120" fill="url(#bg)"/><circle cx="42" cy="36" r="60" fill="url(#glow)"/></g>
    <path d="M34 78 60 34l26 44z" fill="none" stroke="#fff" stroke-width="9" stroke-linejoin="round"/>
    <circle cx="60" cy="64" r="7" fill="currentColor"/>
</svg>'),
    };
}

/** The path of a page, and the page of a path. */
function pagePath(string $page): string { return '/' . str_replace(' ', '-', strtolower($page)); }

function pageOf(string $path): string
{
    foreach (PAGES as $p) {
        if ($path === pagePath($p) || str_starts_with($path, pagePath($p) . '/')) { return $p; }
    }
    return '';
}

/** $order with the items at $items moved before $to, as GridState::$reorder asks. @param list<int> $order @param list<int> $items @return list<int> */
function reorder(array $order, array $items, int $to): array
{
    $moved = $rest = []; $at = -1;
    foreach ($order as $i => $v) {
        if ($i === $to) { $at = count($rest); }
        if (in_array($i, $items, true)) { $moved[] = $v; } else { $rest[] = $v; }
    }
    if ($at < 0) { $at = count($rest); }
    array_splice($rest, $at, 0, $moved);
    return $rest;
}

/** The color of hue $h in degrees, saturation $s and lightness $l. */
function hsl(float $h, float $s, float $l): int
{
    $c = (1 - abs(2 * $l - 1)) * $s;
    $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
    $m = $l - $c / 2;
    [$r, $g, $b] = match (true) {
        $h < 60 => [$c, $x, 0], $h < 120 => [$x, $c, 0], $h < 180 => [0, $c, $x],
        $h < 240 => [0, $x, $c], $h < 300 => [$x, 0, $c], default => [$c, 0, $x],
    };
    return (int)(($r + $m) * 255) << 16 | (int)(($g + $m) * 255) << 8 | (int)(($b + $m) * 255);
}

/** A grid of cards: two to a row where the window leaves room for two of 300 points beside the sidebar, else one, as at the narrowest window. */
function cards(Context $c): Node
{
    return $c->grid($c->width >= 880 ? 2 : 1, 16);
}

/** A card: a titled panel, built in until end(). */
function card(Context $c, string $title): Node
{
    $t = $c->theme;
    $n = $c->column(18, 12, 0, $t->background)->radius(10)->border(1, $t->border)->shadow(0, 1, 3, 0, 0x000000, 0.06);
    if ($title !== '') { $c->text($title, 15, style: Font::BOLD); }
    return $n;
}

// The charts of the Charts page.
const CHART_MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
/** The series' colors, in light and dark windows alike. */
const CHART_COLORS = [0x3b82f6, 0x10b981, 0xf59e0b, 0xec4899];
/** The visits of the stacked areas, by week, from three sources. */
const CHART_SOURCES = ['Search', 'Social', 'Direct'];
const CHART_VISITS = [
    [30, 34, 38, 36, 42, 47, 45, 52, 58, 55, 61, 66],
    [12, 15, 13, 19, 24, 22, 28, 31, 27, 35, 38, 36],
    [20, 19, 22, 21, 23, 22, 25, 24, 27, 26, 28, 30],
];
const CHART_PLATFORMS = ['macOS', 'Windows', 'Linux', 'Browsers'];
const CHART_SHARES = [46, 31, 14, 9];

/** The state of the Charts page: the sales of the bar chart, how the areas stack, and the points of the scatter plot. */
final class GalleryCharts
{
    /** @var list<float> */
    public array $sales = [];
    public int $stacking = 0;
    /** The month and the second the pointer is on, and the share of the donut, null for none. */
    public ?string $hoverMonth = null;
    public ?DateTimeImmutable $at = null;
    public ?float $angle = null;
    /** @var list<list<array{0: float, 1: float}>> a car's weight and fuel use, by class */
    public array $scatter = [[], [], []];

    /** The page's data, the same at every start. */
    public function __construct()
    {
        $r = new \Random\Randomizer(new \Random\Engine\Xoshiro256StarStar(12));
        $normal = fn(): float => sqrt(-2 * log(max(1e-12, $r->nextFloat()))) * cos(2 * M_PI * $r->nextFloat());
        foreach (CHART_MONTHS as $i => $_) { $this->sales[] = 40 + 50 * sin($i / 3.5) + 20 * $r->nextFloat(); }
        foreach ($this->scatter as $k => $_) {
            for ($i = 0; $i < 24; $i++) {
                $w = 1100 + 450 * $k + 170 * $normal();
                $this->scatter[$k][] = [$w, 3 + $w / 420 + 0.9 * $normal()];
            }
        }
    }
}

// The Markdown page.
const MARKDOWN_SAMPLE = "## Streaming Markdown\n\n"
    . "MyPHP parses **CommonMark** and *GitHub Flavored Markdown* as it streams in: only the block being written is parsed again, and the end shows what its half-written syntax will become, so `**bol` is already **bold**.\n\n"
    . "### What it shows\n\n"
    . "1. Headings, paragraphs, *emphasis*, **strong**, ~~strikethrough~~ and `code`\n"
    . "2. Lists, ordered and not:\n"
    . "   - nested items\n"
    . "   - and [links](https://github.com/owner888/myphp) that open in the browser\n"
    . "3. Task lists:\n"
    . "   - [x] parse incrementally\n"
    . "   - [ ] take over the world\n\n"
    . "> Text can be selected across blocks, and copied as text or, from the context menu, as Markdown.\n\n"
    . "```php\n"
    . "// stream() shows a reply as it comes, and view() once it is done.\n"
    . "function message(Context \$c, Message \$m): void\n"
    . "{\n"
    . "    \$m->done ? Markdown::view(\$c, \$m->text, \$app->md) : Markdown::stream(\$c, \$m->text, \$app->md);\n"
    . "}\n"
    . "```\n\n"
    . "| Renderer | Streaming | Selection |\n"
    . "|---|:-:|---|\n"
    . "| Re-parse everything | O(n²) | per block |\n"
    . "| **MyPHP** | O(the open block) | across blocks |\n\n"
    . "Math such as \$\$e^{i\\pi} + 1 = 0\$\$ shows as its TeX unless the app renders it, and footnotes[^1] are listed at the end.\n\n"
    . "---\n\n"
    . "[^1]: Like this one.\n";
const REVEAL_NAMES = ['Word', 'Character', 'Sentence', 'Line', 'Paragraph', 'At once'];
const REVEAL_UNITS = [RevealUnit::ByWord, RevealUnit::ByCharacter, RevealUnit::BySentence, RevealUnit::ByLine, RevealUnit::ByParagraph, RevealUnit::AtOnce];

/** The state of the Markdown page. */
final class MarkdownDemo
{
    public MarkdownOptions $opts;
    public string $text = MARKDOWN_SAMPLE;
    public bool $done = true, $wrap = false;
    public int $unit = 0;
    /** The stream going on, so that a new one stops it. */
    public int $stream = 0;

    public function __construct() { $this->opts = new MarkdownOptions(); }
}

// The languages the gallery declares, its own first: those MyPHP's controls are translated to, and others whose formats and layout show. Its own text is English in every one.
const GALLERY_LANGUAGES = ['en', 'zh-Hans', 'zh-Hant', 'de', 'fr', 'ja', 'ar', 'he', 'hi'];

/** The text the Rich Text page starts with. */
function sampleNote(): RichTextText
{
    $bold = new Attributes(weight: 700);
    $code = new Attributes(code: true);
    return RichTextText::of(
        new Paragraph(new ParagraphStyle(heading: 1), [new Run('Trip to Lisbon')]),
        new Paragraph(runs: [new Run('Flights land at '), new Run('10:40', $bold), new Run(' on Friday. The hotel is in '),
            new Run('Alfama', new Attributes(italic: true)), new Run(', near the '),
            new Run('castle', new Attributes(link: 'https://en.wikipedia.org/wiki/S%C3%A3o_Jorge_Castle')), new Run('.')]),
        new Paragraph(new ParagraphStyle(heading: 2), [new Run('Pack')]),
        new Paragraph(new ParagraphStyle(list: ListKind::Tasks, checked: true), [new Run('Passport')]),
        new Paragraph(new ParagraphStyle(list: ListKind::Tasks), [new Run('Walking shoes')]),
        new Paragraph(new ParagraphStyle(list: ListKind::Tasks), [new Run('Adapter for '), new Run('type F', $code), new Run(' sockets')]),
        new Paragraph(new ParagraphStyle(heading: 2), [new Run('Eat')]),
        new Paragraph(new ParagraphStyle(list: ListKind::Numbers), [new Run('Pastéis de nata', $bold), new Run(' at Manteigaria')]),
        new Paragraph(new ParagraphStyle(list: ListKind::Numbers, indent: 1), [new Run('Warm, with cinnamon')]),
        new Paragraph(new ParagraphStyle(list: ListKind::Numbers), [new Run('Grilled sardines')]),
        new Paragraph(new ParagraphStyle(quote: true), [new Run('Lisbon is a city to walk, slowly, uphill.')]),
        new Paragraph(runs: [new Run('Type '), new Run('# ', $code), new Run(', '), new Run('- ', $code), new Run(' or '), new Run('**bold**', $code), new Run(' to format as you write.')]),
    );
}

final class Gallery
{
    public ?Window $win = null;
    public MarkdownDemo $md;
    /** The pages: "/overview", "/list", and a row of the list, "/list/42". */
    public Router $router;
    /** Lays the gallery out right to left, as an app in Arabic or Hebrew is. */
    public bool $rtl = false;
    /** The text of the fields the declarative views bind: the search field, the city, the list's filter, the chat's draft. */
    public string $searchText = '', $cityText = '', $filterText = '';
    /** The toast showing, the crumb of the path clicked (-1 for none), and whether files are dragged over the drop well. */
    public ?string $toast = null;
    public int $crumb = -1;
    public bool $filesTargeted = false;
    /** The language chosen on the Languages page, "" for the system's. */
    public string $language = '';
    public int $count = 0;
    public bool $agree = false, $notify = false;
    public int $size = 1;
    public string $plan = 'Pro';
    public float $volume = 35;
    public TextState $name, $email, $bio, $findQuery, $draft, $motionText;
    public int $picked = -1;
    /** @var array<int, bool> */
    public array $starred = [];
    public int $tab = 0;
    public float $copies = 1;
    public Selection $chosen;
    /** @var array<string, bool> the tree's folders open */
    public array $tree = ['src' => true];
    public string $leaf = '';
    public DateTimeImmutable $birthday, $now, $meeting, $due;
    public bool $dueOpen = false;
    public bool $dialog = false;
    /** @var list<string> */
    public array $files = [];
    /** @var list<float> */
    public array $samples = [];
    public int $period = 0;
    public bool $pinned = false;
    public string $fruit = 'Apple';
    /** The toolbar's toggles and view. */
    public bool $bold = false, $italic = false, $underline = false, $inspector = false;
    public int $layout = 0;
    /** The combobox's choice and the tags. */
    public string $font = 'Helvetica';
    /** @var list<string> */
    public array $tags = ['php', 'native'];
    public bool $eased = false;
    /** @var list<MotionItem> the items of the Motion page's list, the next one's number, and whether its panel is open */
    public array $items = [];
    public int $nextItem = 3;
    public bool $panelOpen = false, $motionTarget = false, $transformPopover = false;
    public int $motionRun = 0;
    /** The card thrown on the Motion page: where it rests, and the spring it lands with; and the tile open, 1 + its index, 0 for none. */
    public ?array $fling = null;
    public int $flingStyle = 0, $tileOpen = 0;
    /** The card turned over, the counter, and the task done on the Motion page. */
    public bool $cardFlipped = false, $countDown = false, $taskDone = false;
    public int $counter = 0;
    /** The disclosure, the sections of the accordion, and their choices. */
    public bool $advanced = false, $verbose = false, $share = false, $news = false;
    /** @var list<bool> */
    public array $sections = [true, false, false];
    /** @var list<TableFile> */
    public array $tableFiles = [];
    /** The alert, the notes deleted with an undo, the find bar, the notifications' check boxes, and the path. */
    public bool $alert = false, $finding = false;
    public int $notes = 12, $findAt = 0;
    /** What the Overlays card's menu did last, whether the card is pinned, and its zoom. */
    public string $cardDid = '', $cardZoom = 'Medium';
    public bool $cardPinned = false;
    public bool $notifyMail = true, $notifyCalendar = false, $notifyMessages = false;
    public int $pathAt = 4, $pathDepth = 4;
    /** The Glass page's style, what is under its toolbar (a bar of glass, a soft or hard scroll edge, or a progressive blur), and where its lens is. */
    public int $glassStyle = 0, $glassEdge = 1;
    /** @var array{0: float, 1: float} */
    public array $lens = [110, 190];
    /** The tint of the meeting's text, and its opacity. */
    public int $tint = 0x2563eb;
    public float $tintAlpha = 1.0;
    /** The indicators' values: the rating, the battery, the quality and the range of prices. */
    public int $stars = 4;
    public float $battery = 35, $quality = 75, $priceLow = 100, $priceHigh = 350;
    public ?Document $note = null;
    /** The grid of swatches, the one last chosen, those chosen, and their order; the tasks dragged between two columns. */
    public GridState $swatches;
    public Selection $swatchesChosen;
    /** @var list<int> */
    public array $swatchOrder = [];
    /** @var list<GalleryTask> */
    public array $tasks = [];
    /** The outline and its order. */
    public OutlineState $outline;
    public ListState $chat, $table;
    /** @var list<Message> */
    public array $messages = [];
    public GalleryCharts $chart;

    public function __construct(string $path = '/overview')
    {
        $this->router = new Router($path);
        $this->md = new MarkdownDemo();
        foreach (['name', 'email', 'bio', 'findQuery', 'draft', 'motionText'] as $f) { $this->$f = new TextState(''); }
        $this->chosen = new Selection();
        $this->birthday = new DateTimeImmutable('1815-12-10 00:00', new \DateTimeZone('UTC'));
        $this->now = new DateTimeImmutable();
        $this->meeting = new DateTimeImmutable('2026-10-15 09:30');
        $this->due = new DateTimeImmutable('2026-10-29 00:00');
        $this->items = [new MotionItem(1, 'Item 1'), new MotionItem(2, 'Item 2'), new MotionItem(3, 'Item 3')];
        $this->swatches = new GridState();
        $this->swatchesChosen = new Selection();
        $this->outline = new OutlineState();
        $this->chat = new ListState(); $this->table = new ListState();
        $this->chart = new GalleryCharts();
    }

    public function text(Context $c): void
    {
        $t = $c->theme;
        card($c, 'Form');
          $c->form(function () use ($c): void {
              $c->field('Name', fn() => $c->input($this->name, 'Ada Lovelace', 0));
              $invalid = $this->email->text !== '' && !str_contains($this->email->text, '@') ? 'Enter an email address, such as ada@example.com.' : '';
              $c->field('Email', function () use ($c): void {
                  if ($c->submitted($c->input($this->email, 'ada@example.com', 0))) { $this->dialog = true; }
              }, 'Enter opens a dialog.', $invalid);
              $c->field('About you', fn() => $c->textarea($this->bio, 'Multiple lines, with undo, selection and input methods.', 0, 110), sprintf('%d characters', mb_strlen($this->bio->text)));
              $c->fieldset('Optional', function () use ($c): void {
                  $c->field('Birthday', fn() => $c->dateInput($this->birthday));
                  $c->field('Updates', fn() => $c->checkbox('Send me the newsletter', $this->news));
              });
          });
        $c->end();
        card($c, 'Find');
          $text = 'The quick brown fox jumps over the lazy dog. The dog sleeps; the fox runs on.';
          $q = $this->findQuery->text;
          $matches = $q === '' ? 0 : substr_count(strtolower($text), strtolower($q));
          if ($c->shortcut('f', Key::COMMAND)) { $this->finding = true; }
          if (!$this->finding && $c->clicked($c->button('Find…'))) { $this->finding = true; }
          $c->findBar($this->finding, $this->findQuery, $matches, $this->findAt)?->radius(8);
          $c->text($text);
        $c->end();
        card($c, 'Typography');
          $c->text('Display 28', 28, style: Font::BOLD);
          $c->text('Italic, underlined and struck through')->italic()->underline()->strikethrough();
          $c->richText([
              new Span('Rich text mixes '), new Span('bold', weight: 700), new Span(', '),
              new Span('colored', color: $t->accent), new Span(', '), new Span('large', 20),
              new Span(' and '), new Span('underlined', underline: true), new Span(' runs in one paragraph.'),
          ]);
          $c->text('Monospace: function main() {}')->font('monospace');
          $c->text('SPACED CAPITALS', 12, $t->textMuted, Font::BOLD)->letterSpacing(2);
          $c->text('Tabular digits: 1,111.11 / 8,888.88')->fontFeatures('tnum');
          $c->text('Mixed scripts: English, Ελληνικά, Русский, 日本語, 한국어, العربية, עברית, हिन्दी 🎉')->selectable();
          $c->text(str_repeat('Long text wraps to the width it gets. ', 6), color: $t->textMuted);
          $c->text(str_repeat('A single line that ends with an ellipsis when it does not fit. ', 4))->singleLine();
        $c->end();
        card($c, 'Text selection');
          $c->column(0, 12)->selectable();
            $c->text('Drag from this paragraph into the next, then copy the selected text.');
            $c->richText([new Span('Each paragraph keeps its own layout. '), new Span('They share one selection.', weight: 700)]);
            $c->text('This hint is excluded from selection.', color: $t->textMuted)->unselectable();
          $c->end();
        $c->end();
    }

}

$s = new Gallery();
App::setName('Gallery');
// The languages of the Languages page's menu.
App::setLanguages(...GALLERY_LANGUAGES);
App::whenReady(function () use ($s) {
    $s->win = new Window(new WindowOptions(title: 'MyPHP UI Gallery', width: 980, height: 720, minWidth: 640, minHeight: 480, stateKey: 'gallery',
        content: Content::file(__DIR__ . '/view.php', $s)));
    // A minute of samples to start with, then one a second, when the clock changes: the window draws nothing in between.
    $x = 0.0;
    $sample = function () use (&$x): float { $x += 0.35; return 0.5 + 0.35 * sin($x) + 0.1 * sin($x * 3.1); };
    for ($i = 0; $i < 60; $i++) { $s->samples[] = $sample(); }
    $tick = function () use (&$tick, $s, $sample): void {
        $s->win?->update(function () use ($s, $sample): void {
            $s->now = new DateTimeImmutable();
            $s->samples = [...array_slice($s->samples, 1), $sample()];
        });
        App::after(1 - fmod(microtime(true), 1), $tick);
    };
    App::after(1 - fmod(microtime(true), 1), $tick);
});
App::run();
