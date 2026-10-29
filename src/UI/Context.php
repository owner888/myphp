<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;
use MyPHP\{App, DateFormatter, DateOptions, I18n, L10n, Menu, MenuItem, NumberOptions, Region, Width};
use MyPHP\Window;

/** Builds a frame's tree. The view function calls these in order, as code. */
final class Context
{
    use ContextMotion;
    use ContextTransfer;
    use ContextBase;

    public Node $root;
    /** @var Node[] */
    private array $stack;
    /** Identity for the next node, from key(). */
    private string|int|null $nextKey = null;
    /** @var array<int, true> the ids keys gave nodes in this pass, which another node given one tells of (Runtime::duplicateKey) */
    private array $keyed = [];
    /**
     * Where the nodes built at the root are numbered: the view's, and the
     * overlays' apart (mygo's overlay layer), while one is built; the row
     * of a list being built, whose nodes are numbered under its key, not
     * among the list's (the rows in view change as it scrolls).
     */
    private int $rootCount = 0, $overlayCount = 0;
    private bool $inOverlay = false;
    /** @var ?array{0: Node, 1: int, 2: int} the list's node, the row's base id, the row's nodes so far */
    private ?array $row = null;
    /** The sidebar being built, innermost last: its node, the chosen id (by reference), the items built so far. */
    private array $sidebars = [];
    /** How many toolbars enclose what is being built: their buttons take no face until hovered. */
    private int $toolbars = 0;
    /** The form being built (Context::form): its labels' boxes and the widest label, for them all to be as wide. */
    private ?object $form = null;
    /** The list, the row and the list's node of the row being built (Context::list), for an editable text in it: [ListState, int, Node], or null. */
    private ?array $listRow = null;
    /** The checkbox group being built (Context::checkboxGroup): references to its check boxes' values, or null outside one. */
    private ?array $checks = null;

    /** The accordions being built, innermost last: the headers built so far (the second on gets a line above), and last frame's. @var list<object{heads: list<int>, last: list<int>}> */
    private array $accordions = [];
    /** The tree being built, innermost last: its local state (last frame's item ids and their parents), this frame's, the open items enclosing the one being built. */
    private array $trees = [];
    /** The router whose page is being built, which a link to a path goes through, and whether what is built is inert (a page going away). */
    private ?Router $router = null;
    private bool $inert = false;
    /** Whether the overlay built now was opened from inside the dialog on top. */
    private bool $overlayInModal = false;

    /**
     * Whether the window shows its material (WindowOptions::$vibrancy)
     * wherever the view draws no background (mygo's Vibrancy). The root
     * draws the theme's background unless told otherwise; a sidebar over the
     * material, beside opaque content:
     *
     *     if ($c->vibrancy()) { $c->root->color = null; }
     */
    public bool $vibrancy = false;

    public function vibrancy(): bool
    {
        return $this->vibrancy;
    }

    /** The room the window controls take in a window with a hidden title bar (Window::titleBar): height, left, right; zero elsewhere. */
    public array $titleBar = ['height' => 0.0, 'left' => 0.0, 'right' => 0.0];

    /**
     * The room the window controls take in a window with a hidden title
     * bar (WindowOptions::$titleBarStyle), as mygo's Context.TitleBar:
     * height, left and right in points, all zero in other windows and in
     * full screen. Keep clear of the controls and let the bar drag the
     * window: `$c->row(...)->size(0, max($bar['height'], 32))->dragWindow()`.
     * @return array{height: float, left: float, right: float}
     */
    public function titleBar(): array
    {
        return $this->titleBar;
    }

    /** $width and $height are the room the tree will be laid out in. */
    public function __construct(private TextMetrics $metrics, public ?Runtime $runtime = null, public float $width = 0, public float $height = 0, public ?Theme $theme = null, public float $scale = 1.0)
    {
        $this->theme ??= Theme::light();
        $this->root = new Node(Node::CONTAINER, Node::COLUMN, 0, 0, 0, 0, 0, null, $this->theme->background);
        $this->root->rtl = $runtime?->direction() ?? false;
        $this->stack = [$this->root];
    }

    /** The arrow keys that go back and forth along a line of $n: Left and Right, or Right and Left right to left, as the arrows move among items laid out from the right (mygo's arrows). @return array{0: int, 1: int} */
    private function arrows(Node $n): array
    {
        return $n->rtl ? [Key::RIGHT, Key::LEFT] : [Key::LEFT, Key::RIGHT];
    }

    /**
     * The direction of the nodes built now (mygo's Direction): that of the
     * node they go in. Widgets of an app's own read it to draw what points
     * along the line, as an arrow forward.
     */
    public function direction(): Direction
    {
        return $this->stack[count($this->stack) - 1]->rtl ? Direction::RightToLeft : Direction::LeftToRight;
    }

    private function add(Node $n): Node
    {
        $top = $this->stack[count($this->stack) - 1];
        $placed = $this->place($top); // before it is one of the kids
        $top->kids[] = $n;
        $n->context = $this;
        $n->disabledIn = $top->isDisabled(); // inside a disabled node (mygo's IsDisabled)
        if (!$n->dirSet && $n->rtl !== $top->rtl) {
            $n->setDir($top->rtl, false); // the direction of the node it is built in
        }
        if ($top->kind === Node::RICH) {
            if ($n->kind !== Node::TEXT && $n->kind !== Node::RICH) {
                throw new \LogicException('only text nodes (text, link, richText) go inside a text'); // they continue its paragraph (mygo's inline elements)
            }
            $n->inline = true;
        } elseif ($n->kind === Node::TEXT || $n->kind === Node::RICH) {
            $n->scope = $this->selectScope();
        }
        $in = $this->runtime?->inspector;
        [$base, $index] = $placed;
        $this->took($top);
        if ($this->nextKey !== null) {
            $n->id = self::mix($base, self::keyHash($this->nextKey));
            if (isset($this->keyed[$n->id])) {
                $this->runtime?->duplicateKey($n->id, $this->nextKey, Inspector::callSite());
            }
            $this->keyed[$n->id] = true;
            if ($in !== null && $in->open) { $in->keys[$n->id] = $this->nextKey; } // the tree shows it
            $this->nextKey = null; // the key is this node's alone: a leaf must not pass it on to the next node built
        } else {
            $n->id = self::mix($base, $index | $n->kind << 40); // mygo's: its parent's, mixed with its place among its children and its kind
        }
        if ($in !== null && $in->open && $n->id === $in->selected) {
            $in->source = Inspector::callSite(); // where the app builds the node the inspector shows
        }
        return $n;
    }

    /**
     * The id nodes built in $top are numbered from and the place the next
     * takes there: its id and how many it holds, the root's view and
     * overlays apart, a list's row by its key.
     * @return array{0: int, 1: int}
     */
    private function place(Node $top): array
    {
        if ($this->row !== null && $top === $this->row[0]) { return [$this->row[1], $this->row[2]]; }
        if ($top === $this->root) { return $this->inOverlay ? [self::OVERLAY, $this->overlayCount] : [$top->id, $this->rootCount]; }
        return [$top->id, count($top->kids)];
    }

    /** Counts the node just built in $top where place() does not count its kids. */
    private function took(Node $top): void
    {
        if ($this->row !== null && $top === $this->row[0]) { $this->row[2]++; }
        elseif ($top === $this->root) { $this->inOverlay ? $this->overlayCount++ : $this->rootCount++; }
    }

    /** The id of the overlays' layer, which the nodes of overlays are numbered under (mygo's overlayID). */
    private const OVERLAY = 0x6f7665726c6179;

    /**
     * $a mixed with $b, as mygo's mix (a splitmix64 finalizer) mixes a
     * parent's id with a child's place or key, by shifts and exclusive ors
     * alone (xorshift64 twice): PHP's integers multiplied past 64 bits turn
     * to floats, and a hash call a node cost some 4 ms a frame of 9 000.
     */
    private static function mix(int $a, int $b): int
    {
        $h = $a ^ $b ^ ($b << 23) ^ 0x632BE59BD9B4E019;
        $h ^= $h << 13; $h ^= ($h >> 7) & 0x01FFFFFFFFFFFFFF; $h ^= $h << 17;
        $h ^= $h << 13; $h ^= ($h >> 7) & 0x01FFFFFFFFFFFFFF; $h ^= $h << 17;
        return $h;
    }

    /** Key $key hashed (a string and an int apart, as mygo's keys of different types are). */
    private static function keyHash(string|int $key): int
    {
        return self::hash((is_int($key) ? 'i' : 's') . $key) ^ 0x25A5A5A5A5A5A5A5;
    }

    /** The id add() will give the next node, for what is computed from its last-frame rect before it is built (a grid's columns). */
    private function nextId(int $kind = Node::CONTAINER): int
    {
        $top = $this->stack[count($this->stack) - 1];
        [$base, $index] = $this->place($top);
        return self::mix($base, $this->nextKey !== null ? self::keyHash($this->nextKey) : $index | $kind << 40);
    }

    /** The language the app shows (App::locale), a BCP 47 tag such as "en" or "zh-Hans", for what the view says in it. */
    public function locale(): string { return App::locale(); }

    /** The user's regional formats (App::region): the locale of the formats, the first day of the week and the hour cycle, which dates and times follow. */
    public function region(): Region { return App::region(); }

    /** MyPHP's own strings in the language the app shows (L10n), which an app changes with keys "myphp.<key>" of its catalogs. */
    public function texts(): L10n { return L10n::in($this->locale()); }

    /**
     * The regional formats the controls write dates and times in, and the
     * locale that puts them together with the app's language
     * (Region::formatLocale), as mygo's formats: where the system says
     * nothing of them, as without a running app (the tests), they are
     * those CLDR gives the language.
     * @return array{0: Region, 1: string}
     */
    public function formats(): array
    {
        $lang = $this->locale() ?: 'en'; $sys = $this->region();
        $key = "$lang/{$sys->locale}/{$sys->firstWeekday}/{$sys->hourCycle}";
        if (self::$formatsKey !== $key) {
            $r = $sys->hourCycle === 12 || $sys->hourCycle === 24 ? $sys : new Region('', I18n::firstWeekday($lang), I18n::hourCycle($lang));
            self::$formats = [$r, $r->formatLocale($lang)]; self::$formatsKey = $key;
        }
        return self::$formats;
    }

    /** The regional formats last worked out, by language and system formats, since they hold frame after frame. */
    private static ?string $formatsKey = null;
    private static array $formats = [];

    /**
     * Gives the next node an identity that holds across frames whatever
     * is built before it, so conditional UI keeps its clicks, hover and
     * focus straight; the nodes inside a keyed container are keyed by
     * their order within it. A list keys each row by its index.
     */
    public function key(string|int $key): static
    {
        $this->nextKey = $key;
        return $this;
    }

    /** The id key($key) gives the next node built here, for the state of a node not built (a router keeps its pages'). */
    public function keyedId(string|int $key): int
    {
        return self::mix($this->place($this->stack[count($this->stack) - 1])[0], self::keyHash($key));
    }

    /** The router whose page is being built, which links to paths go through, or null outside one (mygo's Router). */
    public function router(): ?Router
    {
        return $this->router;
    }

    /** @internal Runtime::drawMenu: whether what is built now is inert, a page going away. */
    public function isInert(): bool
    {
        return $this->inert;
    }

    /** Builds $fn as a page of $router, inert while $inert (a page going away): shortcuts reach nothing in it. Router's. */
    public function inRoute(?Router $router, bool $inert, Closure $fn): void
    {
        [$savedRouter, $savedInert] = [$this->router, $this->inert];
        $this->router = $router;
        $this->inert = $this->inert || $inert;
        $fn();
        [$this->router, $this->inert] = [$savedRouter, $savedInert];
    }

    /** The color behind what is being built: the background of the nearest container around it that has one, else the theme's (mygo's backdrop). */
    public function background(): int
    {
        for ($i = count($this->stack) - 1; $i >= 0; $i--) {
            if ($this->stack[$i]->color !== null) {
                return $this->stack[$i]->color;
            }
        }
        return $this->theme->background;
    }

    /** A button going back in $router's history, disabled at its start, as in the toolbar of Finder or a browser; a right click lists the pages before, to go back several (mygo's BackButton). */
    public function backButton(Router $router): Node
    {
        return $this->historyButton($router, -1);
    }

    /** A button going forward in $router's history, as backButton goes back (mygo's ForwardButton). */
    public function forwardButton(Router $router): Node
    {
        return $this->historyButton($router, 1);
    }

    private function historyButton(Router $r, int $d): Node
    {
        $t = $this->theme;
        [$label, $can] = $d < 0 ? ['Back', $r->canGoBack()] : ['Forward', $r->canGoForward()];
        $b = $this->makeButton('', null, false);
        $b->fw = $t->space(4) + 2 * $t->space(2); $b->fh = $t->space(4) + 2 * $t->space(1.5); // the arrow, padded Space(1.5) by Space(2)
        $b->label($label)->tooltip($label);
        $b->disabled = !$can;
        $color = $can ? $t->text : $t->textMuted;
        $b->drawOver(function (DisplayList $l, Node $n) use ($d, $color, $t) {
            $cx = $n->x + $n->w / 2; $cy = $n->y + $n->h / 2; $s = $t->space(4) * 0.22; $dx = $s * $d; // back points the way pages came from
            $l->path((new Path)->moveTo($cx - $dx / 2, $cy - $s * 1.6)->lineTo($cx + $dx, $cy)->lineTo($cx - $dx / 2, $cy + $s * 1.6), $color, 1.0, 1.6);
        });
        if ($can && $this->clicked($b)) {
            $r->go($d);
        }
        if ($this->rightClicked($b) && ($w = Window::focused()) !== null && ($pages = $r->pagesToward($d)) !== []) {
            $items = [];
            foreach ($pages as $i => $title) {
                $steps = $d * ($i + 1);
                $items[] = new MenuItem($title, function (MenuItem $it, ?Window $win) use ($r, $steps) { $r->go($steps); $win?->invalidate(); });
            }
            (new Menu($items))->popup($w);
        }
        return $b;
    }

    private static function hash(string $path): int
    {
        return unpack('q', hash('xxh64', $path, true))[1];
    }

    private function open(Node $n): Node
    {
        $this->add($n);
        $this->stack[] = $n;
        return $n;
    }

    /** The text engine the view is measured with. */
    public function metrics(): TextMetrics
    {
        return $this->metrics;
    }

    /** Builds what $fn builds over everything else, at the window's root, wherever the build is (mygo's Overlay): popups placed with Node::at in the window's coordinates. */
    public function overlay(Closure $fn): void
    {
        $saved = $this->toRoot();
        $fn();
        $this->back($saved);
    }

    /** Builds at the root from now, over the view, as overlays are, keeping whether what opens it is inside the dialog on top (a popover is where its anchor is); back() returns to where it was. @return array{list<Node>, bool, bool} */
    private function toRoot(): array
    {
        $saved = [$this->stack, $this->overlayInModal, $this->inOverlay];
        $this->overlayInModal = $this->insideModal();
        $this->stack = [$this->root];
        $this->inOverlay = true;
        return $saved;
    }

    /** @param array{list<Node>, bool, bool} $saved what toRoot() returned */
    private function back(array $saved): void
    {
        [$this->stack, $this->overlayInModal, $this->inOverlay] = $saved;
    }

    /** Whether what is built now is inside the dialog on top, or no dialog shows (mygo's insideModal): the window's shortcuts built behind one wait. */
    private function insideModal(): bool
    {
        $m = $this->runtime?->modal;
        if ($m === null || $this->overlayInModal) {
            return true;
        }
        foreach ($this->stack as $n) {
            if ($n->id === $m) {
                return true;
            }
        }
        return false;
    }

    /** Runs $fn when $key with exactly $mods is pressed in the scope built now: the container open, else the window wherever the focus is (mygo's Context.OnShortcut). */
    public function onShortcut(int|string $key, int $mods, Closure $fn): void
    {
        $top = $this->stack[count($this->stack) - 1];
        if ($top !== $this->root) {
            $top->onShortcut($key, $mods, $fn);
        } elseif ($this->insideModal()) { // the window's, which wait behind a dialog
            $this->runtime?->addAction(null, 'shortcut', $fn, $key, $mods);
        }
    }

    /** $s shaped in the font of the text built now, or $font, wrapped at $width points (0 breaks at newlines only), kept for a text control of one's own (mygo's ShapeText). */
    public function shapeText(string $s, float $width = 0, ?TextStyle $font = null): TextLayout
    {
        return $this->shapeRichText([new Span($s)], $width, $font);
    }

    /** $spans shaped as shapeText does, aligned by $align (Node::START, CENTER, END) in the direction of what is built now, justified, indented or broken between words alone as TextLayout::shape says (mygo's ShapeRichText, ShapeRichTextWith). @param list<Span> $spans */
    public function shapeRichText(array $spans, float $width = 0, ?TextStyle $font = null, int $align = Node::START, bool $justify = false, float $indent = 0, bool $noBreakWords = false): TextLayout
    {
        $style = ($font ?? new TextStyle())->over($this->inheritedText());
        return TextLayout::shape($this->metrics->textSystem(), $spans, $style, $this->theme->fontSize, $width, $align, $this->stack[count($this->stack) - 1]->rtl, $justify, $indent, $noBreakWords);
    }

    /**
     * $s shaped on one line in $font over the text style here, without
     * wrapping: its glyphs with the pen at 0 on the baseline at 0, for
     * Painter::glyphs (mygo's ui.Shape). A line break starts a line below.
     * @return list<\MyPHP\Text\Glyph>
     */
    public function shape(string $s, ?TextStyle $font = null): array
    {
        $l = $this->shapeText($s, 0, $font)->layout;
        if ($l->lines === []) {
            return [];
        }
        $base = $l->lines[0]->baseline;
        $out = [];
        foreach ($l->lines as $line) {
            foreach ($line->glyphs as $g) {
                $g = clone $g; // the layout is shared
                $g->y -= $base;
                $out[] = $g;
            }
        }
        return $out;
    }

    /** The ascent, descent and line gap of $font over the text style here, in points (mygo's Font.Metrics). @return array{ascent: float, descent: float, lineGap: float} */
    public function fontMetrics(?TextStyle $font = null): array
    {
        $style = ($font ?? new TextStyle())->over($this->inheritedText())->style($this->theme->fontSize);
        [$a, $d, $lh] = $this->metrics->textSystem()->metrics($style);
        return ['ascent' => $a, 'descent' => $d, 'lineGap' => max(0.0, $lh - $a - $d)];
    }

    /**
     * The coverage of $glyphs (shape) drawn with the pen at ($x, $y) on the
     * baseline, in points, onto a $w × $h point image at $scale pixels a
     * point: a byte a pixel, ceil($w * $scale) wide and ceil($h * $scale)
     * high, top row first; for effects the painter has not, as a blur of
     * letters (mygo's ui.Render of a view drawing glyphs).
     * @param list<\MyPHP\Text\Glyph> $glyphs
     */
    public function renderGlyphs(array $glyphs, float $x, float $y, float $w, float $h, float $scale): string
    {
        return $this->metrics->glyphCoverage($glyphs, $x, $y, (int)ceil($w * $scale), (int)ceil($h * $scale), $scale);
    }

    /** How far the pointer moved since the last frame while the left button is down from a press on $n: [dx, dy] in points, or null when $n is not being dragged (mygo's Element.Dragged). @return array{0: float, 1: float}|null */
    public function dragged(Node $n): ?array
    {
        return $this->runtime?->dragged($n->id);
    }

    /** The value of the member of $field (Node::focusBind) that has the focus, as the last frame committed it, whatever the field asks for meanwhile; null for none (mygo's FocusedValue). */
    public function focusedValue(mixed &$field): mixed
    {
        return $this->runtime?->focusedValue($field);
    }

    /** The settings of the desktop that controls follow: a frame follows their changes (mygo's Preferences). */
    public function preferences(): Preferences
    {
        return $this->runtime?->preferences ?? new Preferences();
    }

    /** Opens $url with the system's handler (mygo's OpenURL): an error goes unheard. */
    public function openURL(string $url): void
    {
        $this->runtime?->openURLThen($url);
    }

    /** Opens $url as openURL does, and $done(?\Throwable $err) gets what came of it before a frame builds anew with what it changed: an error when no app could open it (mygo's OpenURLThen). */
    public function openURLThen(string $url, Closure $done): void
    {
        $this->runtime?->openURLThen($url, $done);
    }

    /** Whether the node just built was clicked (a mouse-up on where it was last frame). */
    public function clicked(Node $n): bool
    {
        return $this->runtime?->clicked($n->id) ?? false;
    }

    /** The pointer's position from $n's top left in its axes, and whether it is over $n (mygo's PointerPosition). @return array{0: float, 1: float, 2: bool} */
    public function pointerPosition(Node $n): array
    {
        $rt = $this->runtime;
        $r = $rt?->rectOf($n->id);
        $p = $rt?->toLocal($n->id, $rt->input->mouseX, $rt->input->mouseY);
        if ($r === null || $p === null) {
            return [0.0, 0.0, false];
        }
        return [$p[0], $p[1], $rt->input->mouseInside && $p[0] >= 0 && $p[1] >= 0 && $p[0] < $r[2] && $p[1] < $r[3]];
    }

    /** How fast the pointer pressing $n moves now, in points per second along $n's axes: zero once it stops for a moment, or while nothing presses $n (mygo's DragVelocity). @return array{0: float, 1: float} */
    public function dragVelocity(Node $n): array
    {
        $n->draggable = true; // a press on it is its own
        $p = $this->runtime?->pressOf($n->id);
        if ($p === null || !$p['down'] || $n->isDisabled()) {
            return [0.0, 0.0];
        }
        return $this->runtime->alongAxes($n->id, $p['track']->velocity($this->runtime->now));
    }

    /** Whether the pointer pressing $n, and moving, let go since the last frame: how fast it moved then, as dragVelocity, which a spring taking over keeps going; null for none. A click, which does not move, ends no drag (mygo's DragEnded). @return ?array{0: float, 1: float} */
    public function dragEnded(Node $n): ?array
    {
        $n->draggable = true;
        $rt = $this->runtime;
        $p = $rt?->pressOf($n->id);
        if ($p === null || $p['down'] || !$p['released'] || $n->isDisabled()) {
            return null;
        }
        $rt->changed();
        return $rt->alongAxes($n->id, $p['v']);
    }

    /** The time of the frame being built, in nanoseconds (mygo's Now): what moves computes from it; a test's clock (Runtime::$clock) when it sets one. */
    public function now(): int
    {
        return $this->runtime?->now ?? hrtime(true);
    }

    /** The size of the view being built, [width, height] in points (mygo's Size). @return array{0: float, 1: float} */
    public function size(): array
    {
        return [$this->width, $this->height];
    }

    /**
     * Zooms the window's content by $z, as a browser zooms a page (mygo's
     * SetZoom): the view is laid out in a window 1/z its size and shown z
     * times larger, drawn at the screen's resolution times z, so that text,
     * icons, vectors and images stay as sharp as at that size natively,
     * not a picture of the window scaled up (as Node::scale's transform
     * draws). 1 shows it at its size; the zoom stays until set again, and
     * is clamped to between 0.25 and 5. A frame that sets another builds
     * again at the new size before it shows. What the view sees is zoomed:
     * size(), titleBar() and every length are in the content's points, z
     * of them to the window's; the pointer, input methods, accessibility,
     * context menus, drags, web views, videos and materials follow it.
     *
     *     $c->setZoom($app->zoom); // 1.25 for 125%
     */
    public function setZoom(float $z): void
    {
        $this->runtime?->setZoom($z);
    }

    /** The zoom setZoom set, 1 for none (mygo's Zoom). */
    public function zoom(): float
    {
        return $this->runtime?->zoom ?? 1.0;
    }

    /** Asks for a frame $seconds from now, for what changes then (mygo's After). */
    public function after(float $seconds): void
    {
        $this->runtime?->after($seconds);
    }

    /** Asks for the next frame as soon as the display can show it, for what moves with now(): call it in every frame while it moves (mygo's AnimationFrame). */
    public function animationFrame(): void
    {
        $this->runtime?->animationFrame();
    }

    /** Whether the pointer holds $n down: pressed on it last frame and not let go (mygo's Pressed). */
    public function pressed(Node $n): bool
    {
        return $this->runtime?->holds($n->id) ?? false;
    }

    /** Whether the pointer went down since the last frame outside $n: on neither it, nor what is in it, nor the node it is attached to (Node::attachTo), nor what is attached to a node inside it. A popover closes then, the press going on to what is under it; the last press decides (mygo's PressedOutside). */
    public function pressedOutside(Node $n): bool
    {
        return $this->runtime?->pressedOutside($n->id) ?? false;
    }

    /** Whether $key with exactly $mods was pressed for $n, an overlay, which gets the keys left, wherever the focus is: the overlay built last first, so that Escape closes the one on top (mygo's OverlayShortcut). */
    public function overlayShortcut(Node $n, int|string $key, int $mods = 0): bool
    {
        return $this->runtime?->overlayShortcut($n->id, $key, $mods) ?? false;
    }

    /** Whether a double click's second press landed on $n this frame (its last-frame rect); the click reports too. */
    public function doubleClicked(Node $n): bool
    {
        return $this->runtime?->doubleClicked($n->id) ?? false;
    }

    /**
     * @internal UI\VideoPlayer: the keys pressed this frame that no shortcut
     * took, offered to $take (their characters, code, modifiers and the
     * characters without the modifiers); those it takes (true) taken.
     * @param Closure(string, int, int, string): bool $take
     */
    public function takeKeys(Closure $take): void
    {
        if ($this->inert || !$this->insideModal()) { return; }
        $this->runtime?->takeKeys($take);
    }

    /** @internal UI\VideoPlayer: whether the middle button clicked $n this frame. */
    public function middleClicked(Node $n): bool
    {
        return $this->runtime?->middleClicked($n->id) ?? false;
    }

    /** @internal UI\VideoPlayer: whether a Force Touch press went deep on $n this frame (its second stage, NSEvent's pressure). */
    public function forceClicked(Node $n): bool
    {
        return $this->runtime?->forceClicked($n->id) ?? false;
    }

    /** @internal UI\VideoPlayer: a trackpad's pinch over $n this frame: [the magnification added, whether it began, whether it ended]; null for none. @return ?array{0: float, 1: bool, 2: bool} */
    public function magnified(Node $n): ?array
    {
        return $this->runtime?->magnified($n->id);
    }

    public function rightClicked(Node $n): bool
    {
        return $this->runtime?->rightClicked($n->id) ?? false;
    }

    public function hovered(Node $n): bool
    {
        return $this->runtime?->hovered($n->id) ?? false;
    }

    /** The box of $n in the last frame, before its transform, in the window's coordinates of the layout, which draw closures use (mygo's LayoutBounds): x, y, w, h. @return array{0: float, 1: float, 2: float, 3: float} */
    public function layoutBounds(Node $n): array
    {
        return $this->runtime?->rectOf($n->id) ?? [0.0, 0.0, 0.0, 0.0];
    }

    /** The bounds of $n in the window in the last frame, through its transform (mygo's Bounds): x, y, w, h. @return array{0: float, 1: float, 2: float, 3: float} */
    public function bounds(Node $n): array
    {
        $r = $this->runtime?->rectOf($n->id);
        return $r === null ? [0.0, 0.0, 0.0, 0.0] : $this->runtime->worldOf($n->id)->bounds(...$r);
    }

    /** The point ($x, $y) of the last frame's box of $n, from its top left, in the window (mygo's LocalToWindow). @return array{0: float, 1: float} */
    public function localToWindow(Node $n, float $x, float $y): array
    {
        return $this->runtime?->toWindow($n->id, $x, $y) ?? [0.0, 0.0];
    }

    /** The window's point ($x, $y) in the last frame's box of $n, from its top left (mygo's WindowToLocal); null through a singular transform, which takes no pointer. @return ?array{0: float, 1: float} */
    public function windowToLocal(Node $n, float $x, float $y): ?array
    {
        return $this->runtime?->toLocal($n->id, $x, $y);
    }

    /**
     * A grid, as mygo's Grid: its children fill its cells row by row, in
     * columns sized by $cols (Track::fixed, fr, fit) and rows that fit
     * their content, or by $rows; $gap between tracks. An int $cols gives
     * that many columns of equal width (mygo's Columns(n)).
     * @param int|list<Track> $cols @param list<Track> $rows
     */
    public function grid(int|array $cols, float $gap = 0, array $rows = [], float $pad = 0, int $grow = 0, ?int $color = null): Node
    {
        if (is_int($cols)) { $cols = array_fill(0, max(1, $cols), Track::fr()); }
        return $this->open(new Node(Node::CONTAINER, Node::ROW, $pad, $gap, 0, 0, $grow, null, $color))->grid($cols, $rows);
    }

    public function column(float $pad = 0, float $gap = 0, int $grow = 0, ?int $color = null): Node
    {
        return $this->open(new Node(Node::CONTAINER, Node::COLUMN, $pad, $gap, 0, 0, $grow, null, $color));
    }

    public function row(float $pad = 0, float $gap = 0, int $grow = 0, ?int $color = null): Node
    {
        return $this->open(new Node(Node::CONTAINER, Node::ROW, $pad, $gap, 0, 0, $grow, null, $color));
    }

    /** A column that scrolls what is built in it up and down, keeping its offset while it shows, as a page of a router keeps it in the history: end() it (mygo's Scroll). */
    public function scroll(float $pad = 0, float $gap = 0, int $grow = 0, ?int $color = null): Node
    {
        $n = $this->column($pad, $gap, $grow, $color);
        return $n->scroll($this->runtime?->local($n->id, fn() => new ScrollState) ?? new ScrollState);
    }

    /** A column that scrolls what is built in it both ways, keeping its offsets while it shows: end() it (mygo's ScrollBoth). */
    public function scrollBoth(float $pad = 0, float $gap = 0, int $grow = 0, ?int $color = null): Node
    {
        $n = $this->column($pad, $gap, $grow, $color);
        return $n->scroll($this->runtime?->local($n->id, fn() => new ScrollState) ?? new ScrollState, true, true);
    }

    public function end(): void
    {
        array_pop($this->stack);
    }

    /**
     * Builds what $build builds, one node, keyed by $key (key()), and in the
     * frames after places that node again as it was laid out, not building
     * it, while $deps is the same (===), it is built in the same context,
     * and nothing reaches it: the pointer over it, pressing or scrolling
     * there, keys when it holds the focus or reads them (shortcut()), a
     * scrolling state or a field's text inside it moving, the focus, a tip
     * or a menu changing anywhere. $deps holds all $build shows that may
     * change: what it reads of the app's state.
     *
     *     foreach ($settings as $i => $s) {
     *         $c->memo("setting-$i", [$s->name, $s->on], function () use ($c, $s): void {
     *             $c->row(0, 8)->fillWidth();
     *               $c->text($s->name)->grow(1);
     *               $c->toggle($s->on, '');
     *             $c->end();
     *         });
     *     }
     *
     * A page of many parts, few changing at a time, then builds, measures
     * and arranges only those; the rest are moved where they go. A build
     * that asks for frames (an animation, after()) or changes the runtime's
     * state other than adding to it is built every frame. Style the node in
     * $build: what is done to the node returned is done again to the node
     * kept. (Not mygo's, which compares its nodes one by one with last
     * frame's: in PHP that costs about as much as laying them out.)
     */
    public function memo(int|string $key, array $deps, Closure $build): Node
    {
        $top = $this->stack[count($this->stack) - 1];
        [$base] = $this->place($top);
        $id = self::mix($base, self::keyHash($key));
        $rt = $this->runtime;
        $ctx = [$top->rtl, $top->isDisabled(), $this->selectScope(), $this->theme, $this->scale, $this->width, $this->height, $this->toolbars, $this->inert, $this->inOverlay,
            $this->overlayInModal, $this->router, $this->form, $this->checks !== null, $this->listRow[0] ?? null, count($this->sidebars), count($this->accordions), count($this->trees),
            $this->vibrancy, $this->titleBar];
        $style = $this->inheritedText();
        if ($rt !== null && ($n = $rt->memoHit($id, $deps, $ctx, $style)) !== null) {
            if (isset($this->keyed[$id])) { $rt->duplicateKey($id, $key, Inspector::callSite()); }
            $this->keyed[$id] = true;
            foreach ($rt->memoContextAdds($id) as $prop => [$list, $added]) { // what its build gave the context, again
                if ($list) { array_push($this->$prop, ...$added); } else { foreach ($added as $k => $v) { $this->$prop[$k] = $v; } } // not +=, which copies a typed property's array whole
            }
            $this->took($top);
            $top->kids[] = $n;
            $n->placed = $rt->frameNumber;
            return $n;
        }
        $count = count($top->kids); $depth = count($this->stack);
        $before = get_object_vars($this);
        $rt?->memoBegin();
        $this->key($key);
        $build();
        if (count($top->kids) !== $count + 1 || count($this->stack) !== $depth || $top->kids[$count]->id !== $id) {
            throw new \LogicException('memo() builds one node, which takes its key: the build built ' . (count($top->kids) - $count) . ($depth !== count($this->stack) ? ', and left a container open' : ''));
        }
        $n = $top->kids[$count];
        $n->placed = $rt?->frameNumber ?? 0;
        $adds = Runtime::memoAdds($before, get_object_vars($this), ['root', 'stack', 'nextKey', 'keyed', 'rootCount', 'overlayCount', 'row', 'base']);
        if ($rt !== null && $rt->memoEnd($id, $deps, $ctx, $style, $n, $adds !== null)) {
            $rt->memoKeepContext($id, $adds);
            $keeper = $rt->memoKeeper(fn(): self => new self($this->metrics, $rt, 0, 0, $this->theme, $this->scale));
            $stack = [$n];
            while (($k = array_pop($stack)) !== null) { // kept from frame to frame: in a context of no tree, which holds the runtime, not this frame's, which would hold the frame's tree
                $k->context = $keeper;
                foreach ($k->kids as $kid) { $stack[] = $kid; }
            }
        }
        return $n;
    }

    /**
     * A virtualized list of $count rows: $row($i) builds row $i, and only
     * the rows in view are built, from the anchor $state keeps. Row heights
     * come from what layout measured last frame, so the list builds rows
     * for 1.5 views' worth of estimated height, and asks for another frame
     * if that fell short. Without rows, $empty builds what the list shows
     * instead, laid out as a column's content: a message that it is empty,
     * say (mygo's List with children). A state shows one list at a time.
     */
    public function list(ListState $state, int $count, Closure $row, ?Closure $empty = null): Node
    {
        $state->went = $count < $state->count;
        $state->sync($count); // rows that came or went above the place and the choice, with keys
        $state->count = $count;
        if ($state->followEnd && ($state->atEnd || $state->viewport == 0) && !$state->moving()) {
            $state->scrollToEnd(); // at the end, or never laid out: the end shows as rows come or grow, until the user scrolls away
        }
        $state->row = min($state->row, max(0, $count - 1));
        // The place may have been set before the view's size was known (end() at
        // startup, say) or the rows changed under it: keep it within the rows.
        $state->scrollBy(0);
        $node = $this->column(0, 0, 1)->scroll($state)->role(Role::LIST);
        $state->own($this, $node); // for focused(), focusWithin(), focus() and shortcut() on the state
        $node->sizedByRows = $this->stack[count($this->stack) - 2]->scroll !== null;
        $state->glides = $state->followAnimation !== null && $state->viewport > 0 && $this->runtime !== null && !$this->runtime->reduceMotion && !$this->runtime->instant();
        if ($state->followAnimation !== null && $this->runtime !== null) { // what came rises from below as the list glides to its end (mygo's FollowAnimation)
            if ($state->grew) { // the end moved down last frame, as Layout measured it: the glide goes on from where that left it
                $this->jump($node, 'follow', $state->lag);
                $state->grew = false;
            }
            $state->lag = $state->went ? $this->jump($node, 'follow', 0.0) : $this->animateTo($node, 'follow', 0.0, $state->followAnimation); // rows gone: in place at once
            if ($state->lag != 0.0 && $this->runtime->wheeled) { $state->lag = $this->jump($node, 'follow', 0.0); } // the user scrolling stops it
        }
        $node->focused = $state->selected !== null && ($this->runtime?->focused($node->id) ?? false);
        $start = $state->row; $y = -$state->offset;
        while ($y > 0 && $start > 0) { // the view's top above the place's row (ListState::scrollBy): the rows there, as far as their estimates go
            $start--;
            $y -= $state->step($start);
        }
        $state->first = $start;
        $state->editables = false; // until an editable text is built in a row
        $limit = max($state->viewport, $this->height) * 1.5;
        $outer = $this->row; // a list in a row of another
        $savedRow = $this->listRow;
        $headers = []; // the kids that are headers, by row
        if ($count === 0 && $empty !== null) {
            $empty();
        }
        for ($i = $start; $i < $count && $y < $limit; $i++) {
            $this->row = [$node, self::rowBase($node, $state, $i), 0]; // the row's nodes are numbered under its item's key, else its index
            $k = count($node->kids);
            $this->listRow = [$state, $i, $node];
            $row($i);
            $this->listRow = $savedRow;
            if (isset($node->kids[$k])) { $node->rowStarts[$k] = $i; } // assistive technology sees row $i around its nodes
            $y += $state->step($i);
            if (!isset($node->kids[$k])) {
                continue;
            }
            if ($this->runtime?->focusWithin($node->kids[$k]->id)) { $state->focusRow = $i; }
            if ($state->label !== null) { $node->kids[$k]->label(($state->label)($i)); }
            if ($state->isHeader($i)) {
                $headers[$i] = $node->kids[$k];
                continue; // headers are not chosen
            }
            $first = $node->kids[$k];
            if ($state->selected !== null) { $first->selected = $state->chosen($i); }
            $rt = $this->runtime;
            if ($rt !== null && $state->reorder !== null) { // a press on the row, moved 4 points, drags it (and the rows chosen with it)
                $d = $rt->dragged($first->id);
                if ($d !== null) {
                    if ($state->drag === null) {
                        $r = $rt->rectOf($first->id) ?? [0, 0, 0, 0];
                        $state->drag = ['rows' => $state->draggedRows($i), 'row' => $i, 'ox' => $rt->input->mouseX - $r[0], 'oy' => $rt->input->mouseY - $r[1], 'dist' => 0.0, 'far' => false];
                    }
                    $state->drag['dist'] += hypot($d[0], $d[1]);
                    $state->drag['far'] = $state->drag['far'] || $state->drag['dist'] >= 4; // mygo's dragStart
                }
                if ($state->drag !== null && $state->drag['far'] && in_array($i, $state->drag['rows'], true)) {
                    self::fade($first, 0.4); // where the row was, as it moves with the pointer
                }
            }
            if ($rt !== null && $state->drag !== null && $state->drag['far']) {
                $rt->clicked($first->id); // the release that ends a drag is no click
            } elseif ($rt !== null && $state->selected !== null) { // rows not chosen leave a click to the row's own (mygo's TestDragAroundControls)
                if ($rt->doubleClicked($first->id)) {
                    $state->choose($i);
                    $state->submit();
                } elseif ($rt->clicked($first->id)) {
                    $state->click($i, $rt->clickMods); // Cmd and Shift choose several, with a selection
                }
            }
        }
        if ($state->focusRow !== null && ($state->focusRow < $start || $state->focusRow >= $i) && $state->focusRow < $count && $this->runtime !== null) {
            // The row holding the focus, scrolled out of view, is built still, out of it: the focus stays and takes the keys (mygo's focusedRow).
            $this->row = [$node, self::rowBase($node, $state, $state->focusRow), 0];
            $k = count($node->kids);
            $this->listRow = [$state, $state->focusRow, $node];
            $row($state->focusRow);
            $this->listRow = $savedRow;
            if (isset($node->kids[$k]) && $this->runtime->focusWithin($node->kids[$k]->id)) {
                $node->kids[$k]->at(0, -1e6);
            } else {
                array_splice($node->kids, $k);
                $state->focusRow = null;
            }
        }
        if ($state->header !== null && $count > 0 && ($h = $state->headerAbove($state->row)) >= 0) {
            // The header of the section at the top, built where the others are, or out of the flow when it is above them: Layout pins it.
            if (isset($headers[$h])) {
                $node->pinned = $headers[$h];
            } else {
                $this->row = [$node, self::rowBase($node, $state, $h), 0];
                $k = count($node->kids);
                $this->listRow = [$state, $h, $node];
                $row($h);
                $this->listRow = $savedRow;
                if (isset($node->kids[$k])) {
                    $node->pinned = $node->kids[$k]->absolute();
                    if ($state->label !== null) { $node->pinned->label(($state->label)($h)); }
                }
            }
            foreach ($headers as $i => $hn) {
                if ($i > $h) { $node->pinStop = $hn; break; }
            }
        }
        $this->row = $outer;
        $rt = $this->runtime;
        if ($rt !== null && $state->label !== null && $state->selected !== null && $count > 0 && ($typed = $rt->typed($node->id)) !== '') {
            // Typing the first letters of a row's label goes to it (mygo's typed).
            $now = $rt->now / 1e9;
            if ($now - $state->typedAt >= 1.0) { $state->typed = ''; }
            $state->typed .= $typed; $state->typedAt = $now;
            $j = self::typedRow($state->typed, $state->selected, $count, $state->label, $state->isHeader(...));
            if ($j >= 0) { $state->choose($j); }
        }
        $this->end();
        $state->remember();
        $node->changed = $state->changedSinceBuilt(); // the choice, by the keys before the build or a click in it (mygo's List Changed)
        if ($state->drag !== null && $this->runtime !== null) {
            $this->reorderRows($state, $node, $count);
        }
        return $node;
    }

    /**
     * The row whose label starts with $text, ignoring case, from row $cur
     * (the one chosen, -1 for none) on and round, or -1 (mygo's typed): the
     * same letter again goes to the next row starting with it. $skip
     * passes over rows, as a list's headers.
     */
    private static function typedRow(string $text, int $cur, int $n, Closure $label, ?Closure $skip = null): int
    {
        if ($text === '' || $n <= 0) { return -1; }
        $from = max($cur, 0);
        $first = mb_substr($text, 0, 1);
        if (str_repeat($first, mb_strlen($text)) === $text) { // one letter, again and again
            $text = $first;
            if ($cur >= 0) { $from = $cur + 1; }
        }
        for ($d = 0; $d < $n; $d++) {
            $i = ($from + $d) % $n;
            if (($skip === null || !$skip($i)) && mb_strtolower(mb_substr($label($i), 0, mb_strlen($text))) === mb_strtolower($text)) { return $i; }
        }
        return -1;
    }

    /** What a row's nodes are keyed by in its list: its item's key (ListState::$key), so that its state goes with the item as rows come and go above it, else its index. */
    /** The id the nodes of row $i of list $node are numbered under: of its item's key, else its index. */
    private static function rowBase(Node $node, ListState $state, int $i): int
    {
        return self::mix($node->id, self::keyHash($state->key === null ? "row $i" : 'k' . $state->keyOf($i)));
    }

    /**
     * The rows of $list being dragged (ListState::$drag): shows where they
     * would go, a copy of the row under the pointer with how many go with
     * it, scrolls the list near its edges, and as they drop calls
     * ListState::$reorder with them and the row they go before, as mygo's
     * listFrame.reorder.
     */
    private function reorderRows(ListState $state, Node $list, int $count): void
    {
        $rt = $this->runtime; $t = $this->theme;
        $drag = &$state->drag;
        $x = $rt->input->mouseX; $y = $rt->input->mouseY;
        // The row before which the rows dropped at $y go: that of the row of the last frame whose middle is below it, the count past the last (dropAt).
        $to = -1; $below = -1;
        foreach ($list->kids as $k => $kid) {
            if ($kid === $list->pinned && $kid->absolute) { continue; } // a header built out of the flow
            $r = $rt->rectOf($kid->id);
            if ($r === null) { continue; }
            $row = $state->first + $k;
            if ($y < $r[1] + $r[3] / 2) { if ($to < 0 || $row < $to) { $to = $row; } } elseif ($row > $below) { $below = $row; }
        }
        if ($to < 0) { $to = min($below + 1, $count); }
        if (!$rt->held()) { // dropped: rows already there stay
            if ($drag['far'] && !(count($drag['rows']) === 1 && ($to === $drag['rows'][0] || $to === $drag['rows'][0] + 1))) {
                ($state->reorder)($drag['rows'], $to);
            }
            $drag = null;
            return;
        }
        if (!$drag['far']) { return; }
        $first = $state->first;
        $list->drawOver(function (DisplayList $dl, Node $l, Theme $t) use ($to, $first) { // the line where they would go: at the top of row $to, or under the last
            $k = $to - $first;
            if (isset($l->kids[$k])) { $y = $l->kids[$k]->y - max($l->gap, 0) / 2; }
            elseif (isset($l->kids[$k - 1])) { $y = $l->kids[$k - 1]->y + $l->kids[$k - 1]->h + max($l->gap, 0) / 2; }
            else { return; }
            $x = $l->x + $l->padX();
            $dl->rect($x + 4, $y - 1, $l->w - 2 * $l->padX() - 4, 2, $t->accent, 1.0, 1.0);
            $dl->rect($x, $y - 4, 8, 8, $t->accent, 1.0, 4.0);
        });
        $this->dragScroll($list, $state, $y);
        $k = $drag['row'] - $first;
        if (isset($list->kids[$k])) { $this->dragGhost($list->kids[$k], count($drag['rows']), $x - $drag['ox'], $y - $drag['oy']); }
    }

    /** A copy of $src under the pointer at three quarters, at ($gx, $gy), with an accent pill of how many go with it when more than one; at the root, over everything (mygo's paintDrag). */
    private function dragGhost(Node $src, int $count, float $gx, float $gy): void
    {
        $t = $this->theme; $x = $this->runtime->input->mouseX; $y = $this->runtime->input->mouseY;
        $ghost = self::copy($src);
        self::fade($ghost, 0.75);
        $ghost->at($gx - $this->root->padX(), $gy - $this->root->padY());
        $this->root->kids[] = $ghost;
        if ($count > 1) {
            $saved = $this->toRoot();
            [$tw, $th] = $this->metrics->measure((string)$count, $t->fontSize - 2);
            $this->row(0, 0, 0, $t->accent)->size(max($tw + 10, $th + 4), $th + 4)->round(($th + 4) / 2)->center()->at($x + 10 - $this->root->padX(), $y - $th - 14 - $this->root->padY());
              $this->text((string)$count, $t->fontSize - 2, $t->accentText, Font::BOLD);
            $this->end();
            $this->back($saved);
        }
    }

    /** The scrolling container $node scrolls while a drag is within 32 points of its top or bottom, faster nearer to it (mygo's dragScroll). */
    private function dragScroll(Node $node, Scrollable $state, float $y): void
    {
        $lr = $this->runtime->rectOf($node->id);
        if ($lr === null) { return; }
        $edge = 32; $dy = 0.0;
        if ($y - $lr[1] < $edge) { $dy = -($edge - ($y - $lr[1])) / 2; } elseif ($lr[1] + $lr[3] - $y < $edge) { $dy = ($edge - ($lr[1] + $lr[3] - $y)) / 2; }
        if ($dy != 0) { $state->scrollBy($dy); $this->runtime->animate(); }
    }

    /** A copy of $n and its children, with ids of their own (not those of the nodes copied, which keep their clicks and hover). */
    private static function copy(Node $n): Node
    {
        $c = clone $n;
        $c->id = self::hash('copy/' . $n->id);
        $c->draw = null; $c->drawOver = null; $c->tooltip = null; $c->focusable = false;
        $c->kids = array_map(self::copy(...), $n->kids);
        return $c;
    }

    /** Sets $n's and its children's alpha. */
    private static function fade(Node $n, float $alpha): void
    {
        $n->alpha = $alpha;
        foreach ($n->kids as $k) { self::fade($k, $alpha); }
    }

    /** @var list<int> the nodes the view asked to scroll into view in this frame (Node::scrollIntoView) */
    public array $reveal = [];

    /** The theme's text style, under what the nodes being built set. */
    private ?TextStyle $base = null;

    /** The Selectable container text built now is in: the innermost, unless a control, an Unselectable or a disabled node is nearer (mygo's prepareSelectable); 0 for none. */
    private function selectScope(): int
    {
        for ($i = count($this->stack) - 1; $i > 0; $i--) {
            $p = $this->stack[$i];
            if ($p->kind === Node::CONTAINER && $p->selectable && !$p->disabled) {
                return $p->id;
            }
            if ($p->unselectable || $p->focusable || $p->disabled || $p->kind !== Node::CONTAINER) {
                return 0; // a control owns its text
            }
        }
        return 0;
    }

    /** The text style the nodes being built set over the theme's, which text built now inherits (mygo's resolvedText). */
    public function inheritedText(): TextStyle
    {
        if ($this->base === null) {
            $this->base = new TextStyle();
            $this->base->size = $this->theme->fontSize;
            $this->base->color = $this->theme->text;
        }
        $in = $this->base;
        foreach ($this->stack as $n) {
            if ($n->ts !== null) {
                $in = $n->ts->over($in);
            }
        }
        return $in;
    }

    /**
     * Text in the size and color the nodes around it set (Node::fontSize,
     * Node::textColor), the theme's by default, or $size and $color; $style
     * is Font bits (BOLD, ITALIC, MONO), over a weight, italics or a
     * monospaced family around it. Inside a paragraph it continues it.
     */
    public function text(string $s, ?float $size = null, ?int $color = null, int $style = 0): Node
    {
        $in = $this->inheritedText();
        $size ??= $in->size;
        $style |= Node::fontBits($in);
        [$w, $h] = $this->metrics->measure($s, $size, $style);
        $n = $this->add(new Node(Node::TEXT, 0, 0, 0, $w, $h, 0, $s, null, $size, $color ?? $in->color));
        $n->style = $style;
        $n->inherit = $in;
        $n->metrics = $this->metrics;
        if ($in !== $this->base && $in->needsLayout()) {
            $n->toRich();
            $n->fw = $n->fh = 0.0; // laid out, not measured
        }
        return $n;
    }

    /**
     * Spans that differ in style over the text style of the node, which it
     * and the nodes around it set as for text (Node::fontSize, font(),
     * textColor()…), or $size and $color, as mygo's RichText: the spans
     * wrap together as one paragraph, or several at newlines, at $width;
     * with $wrap at the width its container had last frame, inside its
     * padding (the window's width the first time, and a frame follows when
     * it differs); otherwise at the width layout gives it, as text does
     * (Layout::wrapTexts). The node is as wide as its widest line.
     *
     * Text built in its children() (text(), link(), richText()) continues
     * the paragraph after its spans, as HTML's inline elements do: each
     * styles its own text over the paragraph's, and keeps its interaction
     * (clicked, hovered, the focus, a tooltip, assistive technology) with
     * the boxes of its words as its area. A link in a sentence:
     *
     *     $c->richText([])->children(function () use ($c, $url) {
     *         $c->text('Read ');
     *         $c->link('the guide', $url);
     *         $c->text(' to get started.');
     *     });
     *
     * Only text goes inside a text, and the sizes, padding, borders and
     * corners of its children do not apply; a color() highlights their text.
     * @param Span[] $spans
     */
    public function richText(array $spans, ?float $size = null, ?int $color = null, ?float $width = null, bool $wrap = false): Node
    {
        $in = $this->inheritedText();
        $n = $this->add(new Node(Node::RICH, 0, 0, 0, 0, 0, 0, null, null, $size ?? $in->size, $color ?? $in->color));
        if ($size !== null) { $n->fontSize($size); }
        if ($color !== null) { $n->textColor($color); }
        if ($wrap && $width === null) { // the room its container had last frame, inside the padding
            $n->wrapLast = true;
            $parent = $this->stack[count($this->stack) - 1];
            $pw = $this->runtime?->widthOf($parent->id);
            $width = $pw === null ? $this->width : max(1.0, $pw - 2 * $parent->padX());
        }
        $n->wrapWidth = $width;
        $n->spans = $spans;
        $n->inherit = $in;
        $n->metrics = $this->metrics;
        $n->context = $this;
        return $n;
    }

    /** Builds $fn's nodes inside $n, as children() does. @internal */
    public function within(Node $n, \Closure $fn): void
    {
        $this->stack[] = $n;
        try {
            $fn();
        } finally {
            $this->end();
        }
    }

    /** Whether node $n, or one inside it, has the keyboard focus (mygo's FocusWithin). */
    public function focusWithin(Node $n): bool
    {
        return $this->runtime?->focusWithin($n->id) ?? false;
    }

    /**
     * A button without a look (mygo's ButtonBase): a row centering what is
     * built in it until end(), focusable, keeping its width, that clicked()
     * reports and assistive technology presses; give it its look and its
     * label.
     */
    public function buttonBase(): Node
    {
        $b = $this->open(new Node(Node::CONTAINER, Node::ROW, 0, 0, 0, 0, 0, null, null));
        $b->center()->focusable()->shrink(0)->role(Role::BUTTON);
        return $b;
    }

    /**
     * The size spans of text take as rich text shows them, wrapping lines
     * at $width points (none for 0), to lay out what depends on it while
     * building (mygo's MeasureText).
     * @return array{0: float, 1: float}
     */
    public function measureText(float $width, Span ...$spans): array
    {
        $in = $this->inheritedText();
        $text = ''; $runes = 0; $styles = [];
        foreach ($spans as $sp) {
            $text .= $sp->text;
            $runes += mb_strlen($sp->text, 'UTF-8');
            $styles[] = $sp->textSpan($runes);
        }
        $l = $this->metrics->textSystem()->layout(new \MyPHP\Text\Params($text, (new TextStyle())->over($in)->style($this->theme->fontSize), $width, spans: $styles));
        return [$l->width, $l->height];
    }

    /**
     * Whether a link to $url can open in the system when others wrote it,
     * as a message, a language model's reply or a pasted document: an http,
     * https or mailto address; other schemes and paths do not (mygo's
     * SafeToOpen).
     */
    public static function safeToOpen(string $url): bool
    {
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $url) === 1 || !str_contains($url, ':')) {
            return false;
        }
        return in_array(strtolower(explode(':', $url, 2)[0]), ['http', 'https', 'mailto'], true);
    }

    /** Whether the node just built has the keyboard focus (buttons, fields, lists and nodes made focusable()). */
    public function focused(Node $n): bool
    {
        return $this->runtime?->focused($n->id) ?? false;
    }

    /**
     * $value as a text the user edits in place, as a file's name in Finder
     * (mygo's EditableText): a double click on it, or Enter while it has
     * the focus, shows a text input of it with the name before its
     * extension selected; Enter keeps what was typed, as the focus moving
     * away does, and Escape goes back; changed() reports a new value. In a
     * row of a list or a table the list keeps the focus and the text takes
     * none: Enter and F2 edit the text of the row chosen, while a double
     * click on the row submits it; a click on the text of a row already
     * chosen edits it once a second click would no longer make a double
     * click.
     */
    public function editableText(string &$value): Node
    {
        $t = $this->theme; $rt = $this->runtime;
        $box = $this->row()->align(Node::CENTER)->min(0, 0);
        $make = fn() => (object)['editing' => false, 'fresh' => false, 'seen' => false, 'draft' => new TextState(''), 'after' => 0, 'input' => new Handle, 'back' => new Handle];
        $st = $rt?->local($box->id, $make) ?? $make();
        $start = false;
        if ($this->listRow !== null) {
            [$ls, $i, $list] = $this->listRow;
            $ls->editables = true;
            $list->bind($st->back);
            $chosen = $ls->chosen($i);
            $rowNode = end($list->kids);
            if ($ls->editAsked && $ls->selected === $i) {
                $ls->editAsked = false; $start = true;
            } elseif ($chosen && $this->focused($list) && $this->shortcut(Key::F2)) {
                $start = true;
            } elseif ($rt !== null && $rowNode !== false) {
                $now = $this->now();
                if (!$chosen || $rt->doubleClickOver($rowNode->id)) {
                    $st->after = 0;
                } elseif ($rt->clickOver($rowNode->id) && $this->hovered($box)) {
                    $st->after = $now + 500_000_000; // unless a second click makes it a double click
                    $this->after(0.5);
                } elseif ($st->after !== 0 && $now >= $st->after) {
                    $st->after = 0; $start = true;
                }
            }
        } else {
            $box->focusable()->bind($st->back);
            if (!$st->editing && ($this->doubleClicked($box) || ($rt?->activated($box->id) ?? false))) { $start = true; } // a double click, or Enter while it has the focus (which presses what has it)
        }
        if ($start && !$st->editing) {
            $st->editing = true; $st->fresh = true; $st->seen = false;
            $st->draft = new TextState($value);
        }
        if (!$st->editing) {
            $this->text($value)->singleLine();
            $this->end();
            return $box;
        }
        // Where the text was, within its padding and border.
        $pad = $t->space(0.5);
        $in = $this->input($st->draft, '', 0)->bind($st->input)->grow(1)->min($t->space(10), 0)->margin(-$pad - 1, -$pad * 2 - 1);
        $in->borderColor = $t->accent;
        $this->end();
        $done = function (bool $keep) use (&$value, $st, $box, $rt): void {
            $st->editing = false;
            if ($keep && $st->draft->text !== $value) {
                $value = $st->draft->text;
                $box->changed = true;
            }
            $st->back->focus($this); // back to the list, or to the text
            $rt?->changed();
        };
        $focused = $rt?->focused($in->id) ?? false;
        if ($st->fresh) {
            $st->fresh = false;
            $st->input->focus($this);
            $dot = strrpos($st->draft->text, '.'); // the name before its extension, as Finder selects
            $st->draft->setTextSelection(0, $dot !== false && $dot > 0 ? $dot : strlen($st->draft->text));
        } elseif ($st->draft->submitted) {
            $st->draft->submitted = false;
            $done(true);
        } elseif ($focused && $this->shortcut(Key::ESCAPE)) {
            $done(false);
        } elseif ($focused) {
            $st->seen = true;
        } elseif ($st->seen) {
            // The focus went elsewhere: what was typed stays, and the focus with it.
            $st->editing = false;
            if ($st->draft->text !== $value) { $value = $st->draft->text; $box->changed = true; }
            $rt?->changed();
        }
        return $box;
    }

    /** A link: accent text, underlined, a hand pointer, focusable; clicking, or Enter while it has the focus, goes to $url: a path through the router whose page it is in (Router::push), else a URL the system opens (Shell::openExternal). */
    public function link(string $s, string $url, ?float $size = null): Node
    {
        $n = $this->text($s, $size, $this->theme->accent)->focusable();
        $n->underline = $this->hovered($n); // underlined under the pointer, as mygo's
        $n->url = $url;
        $n->role = Role::LINK;
        $router = $this->router;
        if ($url !== '' && $this->runtime?->clicked($n->id)) { // a link going nowhere leaves its click to clicked()
            if ($router !== null && Router::isPath($url)) {
                $router->push($url);
            } else {
                $this->runtime->openURLThen($url);
            }
        }
        return $n;
    }

    /** A progress bar: $fraction 0..1 of its width filled; below 0 it is indeterminate and animates. */
    public function progress(float $fraction, float $width = 160): Node
    {
        $n = $this->add(new Node(Node::PROGRESS, 0, 0, 0, $width, $this->theme->space(1.5), 0, null, null));
        $n->fraction = $fraction;
        $n->role(Role::PROGRESS)->range(0, 1, $fraction); // below 0: of unknown length
        return $n;
    }

    /**
     * A bar showing $value between $lo and $hi, as mygo's Meter (AppKit's
     * level indicator): the success color, or the warning and danger
     * colors from $levels (MeterLevels), none for neither.
     */
    public function meter(float $value, float $lo, float $hi, ?MeterLevels $levels = null, float $width = 160): Node
    {
        $t = $this->theme;
        $n = $this->add(new Node(Node::PROGRESS, 0, 0, 0, $width, $t->space(2), 0, null, $t->success));
        $n->fraction = $hi > $lo ? max(0.0, min(1.0, ($value - $lo) / ($hi - $lo))) : 0.0;
        $n->role(Role::METER)->range($lo, $hi, $value);
        if ($levels !== null && $levels->warning != $levels->critical) {
            $low = $levels->critical < $levels->warning;
            if ($low ? $value <= $levels->critical : $value >= $levels->critical) { $n->color = $t->danger; }
            elseif ($low ? $value <= $levels->warning : $value >= $levels->warning) { $n->color = $t->warning; }
        }
        return $n;
    }

    /**
     * A field holding a number between $lo and $hi, as mygo's NumberInput:
     * the arrows Up and Down, and the − and + beside it, step it by $step;
     * what is typed applies as soon as it is a number in range, and shows
     * rounded to the decimals of $step once the field loses the focus.
     * changed() reports a new value.
     */
    public function numberInput(int|float &$value, float $lo, float $hi, float $step = 1): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $decimals = ($s = rtrim(rtrim(sprintf('%.10F', $step), '0'), '.')) !== '' && str_contains($s, '.') ? strlen($s) - strpos($s, '.') - 1 : 0;
        $format = fn(float $v) => number_format($v, $decimals, '.', '');
        $n = $this->row(0, $t->space(1))->align(Node::CENTER);
        $text = $rt?->local($n->id, fn() => new TextState($format($value))) ?? new TextState($format($value));
        $set = function (float $v) use (&$value, $lo, $hi, $decimals, $n) {
            $v = round(max($lo, min($hi, $v)), $decimals);
            if ($v != $value) { $value = is_int($value) ? (int)$v : $v; $n->changed = true; }
        };
          $in = $this->input($text, '', $t->space(20));
          if ($rt !== null && $rt->focus === $in->id) {
              if (is_numeric(trim($text->text)) && (float)$text->text >= $lo && (float)$text->text <= $hi) { $set((float)$text->text); }
              if ($this->shortcut(Key::UP)) { $set($value + $step); $text->text = $format($value); $text->setCursor(strlen($text->text)); }
              if ($this->shortcut(Key::DOWN)) { $set($value - $step); $text->text = $format($value); $text->setCursor(strlen($text->text)); }
          } elseif ($text->text !== $format($value)) {
              $text->text = $format($value); $text->setCursor(strlen($text->text)); // what the app set, or what was typed, shown in full
          }
          $minus = $this->button('−')->label('Decrease'); $plus = $this->button('+')->label('Increase');
        $this->end();
        if ($this->clicked($minus) && $value > $lo) { $set($value - $step); }
        if ($this->clicked($plus) && $value < $hi) { $set($value + $step); }
        return $n;
    }

    /** Secondary text, in the theme's muted color. */
    public function muted(string $s, ?float $size = null): Node
    {
        return $this->text($s, $size, $this->theme->textMuted);
    }

    /** A primary button: accent face, lighter hovered, darker pressed. */
    public function button(string $s, ?float $size = null): Node
    {
        return $this->makeButton($s, $size, false);
    }

    /** A button in the accent color, for the main action (mygo's PrimaryButton). */
    public function primaryButton(string $s, ?float $size = null): Node
    {
        return $this->makeButton($s, $size, true);
    }

    /**
     * As mygo's styleButton: padding Space(1.5) by Space(3.5), a bordered
     * surface face, or the accent without a border when primary, or in a
     * toolbar no face until hovered (padding Space(1.5) by Space(2.5));
     * hover and press shade it, and $on (a toggle) keeps it pressed.
     */
    private function makeButton(string $s, ?float $size, bool $primary, bool $on = false): Node
    {
        $t = $this->theme;
        $size ??= $t->fontSize;
        [$w, $h] = $this->metrics->measure($s, $size);
        $segment = $this->segments > 0 && !$primary; // in a toggle group: a segment, padded Space(1) by Space(3), no face but pressed or hovered
        $toolbar = $this->toolbars > 0 && !$primary && !$segment;
        [$base, $hover, $pressed, $fg] = $primary ? [$t->accent, $t->accentHover, $t->accentPressed, $t->accentText] : [$toolbar || $segment ? null : $t->surface, $t->surfaceHover, $t->surfacePressed, $t->text];
        $bordered = !$primary && !$toolbar && !$segment;
        $edge = $bordered ? 2.0 : 0.0; // its border, a point each side, around the padding, as mygo's
        $n = $this->add(new Node(Node::BUTTON, 0, 0, 0, $w + $t->space($segment ? 6 : ($toolbar ? 5 : 7)) + $edge, $h + $t->space($segment ? 2 : 3) + $edge, 0, $s, $on ? $pressed : $base, $size, $fg));
        $n->bordered = $bordered;
        $n->metrics = $this->metrics; // a style of its own (bold(), italic()) measures its label again in layout
        if ($segment) {
            $n->segment = true;
            $n->radius(max($t->radius - $t->space(0.5), 0));
            if ($on) { $n->shadow(0, 1, 2, 0, 0x000000, 0.12); }
        }
        $n->focused = $this->runtime?->focused($n->id) ?? false;
        if (!$on && $this->runtime?->hovered($n->id)) {
            $n->color = $this->runtime->input->mouseDown ? $pressed : $hover;
        }
        return $n;
    }

    /** Whether toggleGroup() builds now: its buttons are segments. */
    private int $segments = 0;

    /** A row of the toggles and buttons $fn builds, drawn joined as the segments of one control, as Bold, Italic and Underline: one stop of Tab, among which the arrows move the focus (mygo's ToggleGroup); label() names it. */
    public function toggleGroup(Closure $fn): Node
    {
        $t = $this->theme;
        $g = $this->row($t->space(0.5), $t->space(0.5), 0, $t->surface)->shrink(0)->align(Node::STRETCH)->round($t->radius)->borderWidth(1)->borderColor($t->border)->focusGroup(Node::HORIZONTAL | Node::VERTICAL)->role(Role::GROUP);
        $this->segments++;
        try {
            $fn();
        } finally {
            $this->segments--;
        }
        $this->end();
        return $g;
    }

    /** A button that stays pressed while $on, as Bold in an editor's toolbar (mygo's Toggle): a click presses it or lets it go, as does Space while it has the focus; changed() reports it. */
    public function toggle(bool &$on, string $label, ?float $size = null): Node
    {
        $n = $this->makeButton($label, $size, false, $on);
        $n->role = Role::TOGGLE_BUTTON;
        if ($this->clicked($n)) {
            $on = !$on;
            $n->changed = true;
        }
        $n->checked($on);
        return $n;
    }

    /**
     * A row of controls across the top of a window, as mygo's Toolbar:
     * buttons, toggles, segmented controls and menu buttons built by $fn,
     * which take no face of their own until hovered.
     */
    public function toolbar(Closure $fn): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $n = $this->row($t->space(1), $t->space(1))->align(Node::CENTER)->focusGroup(Node::HORIZONTAL)->role(Role::TOOLBAR); // one stop of Tab; Left and Right move inside
        $this->toolbars++;
          $fn();
          // Controls that do not fit go, from the last, into a menu at the end (mygo's layoutToolbar): the room is the toolbar's last-frame width.
          $cw = ($rt?->rectOf($n->id)[2] ?? INF) - 2 * $t->space(1);
          $items = $n->kids; $gap = $t->space(1); $moreW = $t->space(3) + 2 * $t->space(2.5);
          $total = 0.0;
          foreach ($items as $i => $k) { Layout::measure($k); $total += $k->mw + ($i > 0 ? $gap : 0); }
          $fits = count($items);
          if ($total > $cw + 0.5) {
              $room = $cw - $moreW - $gap; $used = 0.0;
              for ($fits = 0; $fits < count($items); $fits++) {
                  $w = $items[$fits]->mw + ($fits > 0 ? $gap : 0);
                  if ($used + $w > $room + 0.5) { break; }
                  $used += $w;
              }
          }
          if ($fits < count($items)) {
              $n->kids = array_slice($items, 0, $fits);
              $overflow = array_slice($items, $fits);
              $more = $this->key('more')->column(0, 0)->padding($t->space(1.5), $t->space(2.5))->round($t->radius)->focusable()->center()->role(Role::MENU_BUTTON)->label($this->texts()->get('more'))
                  ->draw(function (DisplayList $list, Node $b, Theme $t) { // mygo's chevrons: »
                      $d = $t->space(3); $x = $b->x + ($b->w - $d) / 2; $y = $b->y + ($b->h - $d) / 2;
                      foreach ([0.2, 0.5] as $k) {
                          $list->line($x + $d * $k, $y + $d * 0.25, $x + $d * ($k + 0.25), $y + $d * 0.5, $t->text, 1.0, 1.5);
                          $list->line($x + $d * ($k + 0.25), $y + $d * 0.5, $x + $d * $k, $y + $d * 0.75, $t->text, 1.0, 1.5);
                      }
                  });
              $more->size($moreW, $t->space(3) + 2 * $t->space(1.5));
              if ($this->hovered($more)) { $more->color = $t->surfaceHover; }
              $this->end();
              $more->menu(function (\MyPHP\UI\Menu $m) use ($overflow, $rt): void { // choosing an item does what clicking the control does, a toggle checked as it is; a divider is a separator (mygo's toolbar menu)
                  foreach ($overflow as $k) {
                      $label = $k->text ?? self::labelOf($k);
                      if ($label === null) { $m->separator(); continue; }
                      $it = $m->item($label);
                      if (in_array($k->role, [Role::TOGGLE_BUTTON, Role::CHECK_BOX, Role::SWITCH], true)) { $it->checked($k->checked === 2); }
                      if ($it->chosen()) { $rt?->press($k->id); }
                  }
              });
          }
        $this->toolbars--;
        $this->end();
        return $n;
    }

    /** The first text inside a composite control, for naming it in a menu. */
    private static function labelOf(Node $n): ?string
    {
        foreach ($n->kids as $k) {
            if ($k->text !== null && $k->text !== '') { return $k->text; }
            if (($l = self::labelOf($k)) !== null) { return $l; }
        }
        return null;
    }

    /** The modifier keys held (Key::SHIFT, CONTROL, OPTION, COMMAND), as of the last key, change of modifiers or pointer event (mygo's Modifiers). */
    public function modifiers(): int
    {
        return $this->runtime?->input->mods ?? 0;
    }

    /** Whether $key (a Key code or a character) was pressed this frame with exactly $mods, unless a focused control took it (mygo's Shortcut). */
    public function shortcut(int|string $key, int $mods = 0): bool
    {
        if ($this->inert || !$this->insideModal()) {
            return false; // a page going away takes no keys, nor a window behind a dialog
        }
        return $this->runtime?->shortcut($key, $mods) ?? false;
    }

    /** Whether Escape or a press outside closed the dialog or popover $n (modal, popover) this frame, setting its $open false: one that must stay open sets it back to true (mygo's Dismissed). */
    public function dismissed(Node $n): bool
    {
        return $n->dismissed;
    }

    /** The modifiers (Key::SHIFT, CONTROL, OPTION, COMMAND) held at the last click of $n, none for a click by Enter or Space (mygo's ClickModifiers). */
    public function clickModifiers(Node $n): int
    {
        return $this->runtime?->clickModifiers($n->id) ?? 0;
    }

    /** Whether the control just built changed its value this frame (checkbox, radio, slider, select), as mygo's Changed. */
    public function changed(Node $n): bool
    {
        return $n->changed;
    }

    /** A checkbox with a label, toggling $checked; changed() reports it. */
    public function checkbox(string $label, bool &$checked, ?float $size = null): Node
    {
        $t = $this->theme;
        $size ??= $t->fontSize;
        [$w, $h] = $this->metrics->measure($label, $size);
        $n = $this->add(new Node(Node::CHECKBOX, 0, 0, 0, $label === '' ? $t->space(4) : $w + $t->space(6), $label === '' ? $t->space(4) : max($h, $t->space(4)), 0, $label, null, $size, $t->text)); // without a label, its box alone
        $n->focusable = true;
        if ($this->runtime?->clicked($n->id)) {
            $checked = !$checked;
            $n->changed = true;
        }
        $n->on = $checked;
        $n->role(Role::CHECK_BOX)->checked($checked);
        if ($this->checks !== null) { $this->checks[] = &$checked; } // in a group: the head checks or clears it
        return $n;
    }

    /** A switch with a label, toggling $on, as mygo's Switch; changed() reports it. */
    public function switch(string $label, bool &$on, ?float $size = null): Node
    {
        $t = $this->theme;
        $size ??= $t->fontSize;
        [$w, $h] = $this->metrics->measure($label, $size);
        $n = $this->add(new Node(Node::SWITCH, 0, 0, 0, $w + $t->space(11), max($h, $t->space(5)), 0, $label, null, $size, $t->text)); // the track Space(9), the gap Space(2)
        $n->focusable = true;
        if ($this->runtime?->clicked($n->id)) {
            $on = !$on;
            $n->changed = true;
        }
        $n->on = $on;
        $n->fraction = $this->animate($n, 'knob', $on ? 1 : 0, 0.14); // how far the knob is toward on
        $n->role(Role::SWITCH)->checked($on);
        return $n;
    }

    /**
     * Sections one above the other in a bordered box, that $fn builds with
     * accordionItem, as mygo's Accordion: each opens and closes on its own;
     * Up and Down move the focus between their headers, Home and End too.
     */
    public function accordion(Closure $fn): Node
    {
        $t = $this->theme;
        $n = $this->column(0, 0, 0, $t->background)->round($t->radius)->border();
        $local = $this->runtime?->local($n->id, fn() => (object)['heads' => []]) ?? (object)['heads' => []]; // the headers of last frame, which the arrows move between
        $this->accordions[] = (object)['heads' => [], 'last' => $local->heads];
          $fn();
        $local->heads = array_pop($this->accordions)->heads;
        $this->end();
        return $n;
    }

    /**
     * A section of an accordion titled $title, whose content $fn builds
     * while $open, as mygo's AccordionItem: a click on the header, or Enter
     * or Space while it has the focus, opens and closes it; changed()
     * reports it.
     */
    public function accordionItem(string $title, bool &$open, Closure $fn): Node
    {
        $t = $this->theme;
        $acc = $this->accordions[count($this->accordions) - 1];
        $n = $this->column(0, 0);
          if ($acc->heads !== []) { $this->divider(); } // a line above all but the first
          $head = $this->row(0, $t->space(2))->padding($t->space(2.5), $t->space(3))->align(Node::CENTER)->focusable()->role(Role::DISCLOSURE);
          $acc->heads[] = $head->id;
          if ($this->focused($head) && ($at = array_search($head->id, $acc->last, true)) !== false) { // the arrows, Home and End between the headers, each a stop of Tab (mygo's accordion keys)
              $to = null; $last = count($acc->last) - 1;
              if ($this->shortcut(Key::DOWN)) { $to = min($at + 1, $last); }
              elseif ($this->shortcut(Key::UP)) { $to = max($at - 1, 0); }
              elseif ($this->shortcut(Key::HOME)) { $to = 0; }
              elseif ($this->shortcut(Key::END)) { $to = $last; }
              if ($to !== null) { $this->runtime->focusOn($acc->last[$to], true); }
          }
          $p = $this->collapsibleToggle($head, $open);
          if ($head->changed) { $n->changed = true; }
          if ($this->hovered($head)) { $head->color = $t->surfaceHover; }
            $this->text($title, $t->fontSize, $t->text, Font::BOLD)->singleLine();
            $this->spacer();
            $a = $this->box($t->space(4), $t->space(4), 0); $a->alpha = 0.0;
            $a->draw(fn(DisplayList $list, Node $a, Theme $t) => self::disclosure($list, $a, 90 + 180 * $p, $t->textMuted)); // down, turning up as it opens
          $this->end();
          $this->collapsiblePanel($open, $p, $fn, 0, $t->space(3), $t->space(3), $t->space(3), $t->space(2)); // Padding(0, Space(3), Space(3))
        $this->end();
        return $n;
    }

    /**
     * A row of $max stars of which $value are filled, as mygo's Rating
     * (AppKit's rating level indicator): a click on a star sets it, a click
     * on the star set clears it, and the arrows, Home and End change it
     * while the rating has the focus; the stars the pointer would set show
     * as it rests on them. changed() reports a new value.
     */
    public function rating(int &$value, int $max = 5): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $n = $this->row(0, $t->space(0.5))->focusable()->role(Role::SLIDER);
        $set = function (int $v) use (&$value, $max, $n) { $v = max(0, min($max, $v)); if ($v !== $value) { $value = $v; $n->changed = true; } };
        if ($rt !== null && $rt->focus === $n->id) {
            [$back, $forth] = $this->arrows($n);
            if ($this->shortcut($forth) || $this->shortcut(Key::UP)) { $set($value + 1); }
            if ($this->shortcut($back) || $this->shortcut(Key::DOWN)) { $set($value - 1); }
            if ($this->shortcut(Key::HOME)) { $set(0); }
            if ($this->shortcut(Key::END)) { $set($max); }
        }
        $size = $t->fontSize * 1.25; $stars = []; $hover = -1;
          for ($i = 0; $i < $max; $i++) {
              $stars[$i] = $this->box($size, $size, 0)->role(Role::NONE); $stars[$i]->color = null; // a glyph alone
              if ($this->hovered($stars[$i])) { $hover = $i; }
          }
        $this->end();
        foreach ($stars as $i => $star) {
            if ($this->clicked($star)) { $set($value === $i + 1 ? 0 : $i + 1); if ($rt !== null) { $rt->focus = $n->id; } }
            $filled = $i < $value; $color = $t->warning; $alpha = 1.0;
            if ($hover >= 0) { $filled = $i <= $hover; $alpha = 0.6; }
            // A star glyph in place of mygo's star path: filled in the warning color, or outlined in the muted color.
            $star->text = $filled ? '★' : '☆'; $star->fontSize = $size; $star->fg = $filled ? $color : $t->textMuted; $star->alpha = $alpha;
        }
        $n->range(0, $max, $value, 1);
        return $n;
    }

    /**
     * Two knobs on one track choosing the range $low to $high between $lo
     * and $hi, as mygo's RangeSlider: a press drags the knob nearest, the
     * arrows, Home and End move the knob with the focus by $step (a
     * hundredth of the range when 0, which also leaves values unsnapped),
     * and changed() reports a move. Space(5) high, at least Space(25) wide.
     */
    public function rangeSlider(float &$low, float &$high, float $lo, float $hi, float $step = 0, float $width = 0): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $kw = $t->space(5);
        $low = max($lo, min($low, $high)); $high = min($hi, max($low, $high));
        $n = $this->row(0, 0, $this->growsAlong($width, $kw))->padding(0, $kw / 2)->min($t->space(25), $t->space(5))->role(Role::GROUP);
        if ($width > 0) { $n->size($width, $t->space(5)); } else { $n->size(0, $t->space(5)); }
        $keyStep = $step > 0 ? $step : ($hi - $lo) / 100;
        $snap = fn(float $v) => $step > 0 ? round(($v - $lo) / $step) * $step + $lo : $v;
        $set = function (string $which, float $to) use (&$low, &$high, $lo, $hi, $snap, $n) {
            [$from, $until] = $which === 'low' ? [$lo, $high] : [$low, $hi];
            $to = $snap(max($from, min($until, $to)));
            if ($to != ($which === 'low' ? $low : $high)) { if ($which === 'low') { $low = $to; } else { $high = $to; } $n->changed = true; }
        };
        $local = $rt?->local($n->id, fn() => (object)['dragging' => null]);
        $r = $rt?->rectOf($n->id);
        $knobs = [];
          foreach (['low', 'high'] as $k => $which) { // the knobs: focusable, placed over the track; the arrows move the one focused
              $v = $which === 'low' ? $low : $high;
              $frac = $hi > $lo ? ($v - $lo) / ($hi - $lo) : 0.0;
              $knob = $this->key($which)->box(0, 0, 0)->size($kw, $t->space(5))->at(($r === null ? 0 : ($r[2] - $kw)) * ($n->rtl ? 1 - $frac : $frac) - $kw / 2, 0)->focusable(); // centered where the knob is drawn: at() is from the padding box, kw/2 in; right to left, lo is at the right
              $knob->color = null; $knob->ownRing = true; // the knob is painted, ring and all, by the slider's draw
              $knob->role(Role::SLIDER)->range($lo, $hi, $v, $keyStep)->label(['minimum', 'maximum'][$k]);
              $knob->nameFrom = $n; $knob->nameJoin = true; // "Price minimum", of a slider labeled Price
              $knobs[$which] = $knob;
              if ($rt !== null && $rt->focus === $knob->id) {
                  [$back, $forth] = $this->arrows($n);
                  if ($this->shortcut($back) || $this->shortcut(Key::DOWN)) { $set($which, $v - $keyStep); }
                  if ($this->shortcut($forth) || $this->shortcut(Key::UP)) { $set($which, $v + $keyStep); }
                  if ($this->shortcut(Key::HOME)) { $set($which, $which === 'low' ? $lo : $low); }
                  if ($this->shortcut(Key::END)) { $set($which, $which === 'low' ? $high : $hi); }
              }
          }
        $this->end();
        // A press on the track or a knob drags the knob nearest to it, and gives it the focus.
        if ($rt !== null && $r !== null && $local !== null) {
            $d = $rt->dragged($n->id) ?? $rt->dragged($knobs['low']->id) ?? $rt->dragged($knobs['high']->id);
            $press = $d === null ? $rt->pressPoint($n->id) : null; // a click let go before the frame was built
            if ($d !== null || $press !== null) {
                $at = max(0.0, min(1.0, (($press[0] ?? $rt->input->mouseX) - $r[0] - $kw / 2) / max(1.0, $r[2] - $kw)));
                $x = $lo + ($n->rtl ? 1 - $at : $at) * ($hi - $lo);
                if ($local->dragging === null) { $local->dragging = abs($x - $high) < abs($x - $low) || $x > $high ? 'high' : 'low'; }
                $set($local->dragging, $x);
                $rt->focus = $knobs[$local->dragging]->id;
            } else {
                $local->dragging = null;
            }
        }
        $dragging = $local->dragging ?? null;
        $held = $this->animate($n, 'held', $dragging !== null ? 1 : 0, 0.15);
        $focusLow = $rt !== null && $rt->focus === $knobs['low']->id && $rt->focusVisible($knobs['low']->id);
        $focusHigh = $rt !== null && $rt->focus === $knobs['high']->id && $rt->focusVisible($knobs['high']->id);
        $n->draw(function (DisplayList $list, Node $e, Theme $t) use ($low, $high, $lo, $hi, $kw, $dragging, $held, $focusLow, $focusHigh) {
            $h = $t->space(1.5); $cy = $e->y + $t->space(2.5);
            $f = fn(float $v) => ($hi > $lo ? ($v - $lo) / ($hi - $lo) : 0.0) * ($e->rtl ? -1 : 1) + ($e->rtl ? 1 : 0); // right to left, from the right
            $x0 = $e->x + $kw / 2 + ($e->w - $kw) * $f($low); $x1 = $e->x + $kw / 2 + ($e->w - $kw) * $f($high);
            $list->rect($e->x, $cy - $h / 2, $e->w, $h, $t->border, 1.0, $h / 2);
            $list->rect(min($x0, $x1), $cy - $h / 2, abs($x1 - $x0), $h, $t->accent, 1.0, $h / 2);
            Painter::knob($list, $t, $x0, $cy, $dragging === 'low' ? $held : 0.0, $focusLow);
            Painter::knob($list, $t, $x1, $cy, $dragging === 'high' ? $held : 0.0, $focusHigh);
        });
        return $n;
    }

    /**
     * A tree of items $fn builds with treeItem, as mygo's Tree: while an
     * item has the keyboard focus, Up and Down move it to the items
     * around, Right opens the item or moves to its first child, and Left
     * closes it or moves to its parent.
     */
    public function tree(Closure $fn): Node
    {
        $n = $this->column(0, 0)->role(Role::TREE);
        $local = $this->runtime?->local($n->id, fn() => (object)['items' => [], 'parents' => []]);
        $this->trees[] = ['local' => $local, 'items' => [], 'parents' => [], 'open' => []];
          $fn();
        $built = array_pop($this->trees);
        $this->end();
        if ($local !== null) { $local->items = $built['items']; $local->parents = $built['parents']; }
        return $n;
    }

    /**
     * An item of a tree showing $label, inside the item it is built in, as
     * mygo's TreeItem: $children builds the items inside it, shown while
     * $open; null children or a null $open makes a leaf. A click on its
     * arrow opens or closes it; clicked() reports a click elsewhere on it,
     * or Enter, for choosing it, which $selected shows.
     */
    public function treeItem(string $label, ?bool &$open, ?Closure $children, bool $selected = false): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $tb = &$this->trees[count($this->trees) - 1];
        $depth = count($tb['open']);
        $branch = $children !== null && $open !== null;
        $n = $this->row(0, $t->space(0.5), 0, $selected ? $t->accent : null)->round($t->radius)->align(Node::CENTER)->focusable()->size(0, $t->space(7))->role(Role::TREE_ITEM);
        $id = $n->id;
        $tb['items'][] = $id;
        $tb['parents'][$id] = $tb['open'][$depth - 1] ?? null;
        if (!$selected && $this->hovered($n)) { $n->color = $t->surfaceHover; }
        if ($rt !== null && $rt->focus === $id) { // the keys, among last frame's items
            $last = $tb['local']->items; $at = array_search($id, $last, true); $to = null;
            if ($this->shortcut(Key::DOWN) && $at !== false && $at + 1 < count($last)) { $to = $last[$at + 1]; }
            if ($this->shortcut(Key::UP) && $at !== false && $at > 0) { $to = $last[$at - 1]; }
            [$back, $forth] = $this->arrows($n);
            if ($this->shortcut($forth)) {
                if ($branch && !$open) { $open = true; } elseif ($branch && $at !== false && $at + 1 < count($last)) { $to = $last[$at + 1]; }
            }
            if ($this->shortcut($back)) {
                if ($branch && $open) { $open = false; } else { $to = $tb['local']->parents[$id] ?? null; }
            }
            if ($to !== null) { $rt->focus = $to; }
        }
        if ($branch && $rt !== null && ($e = $rt->expanded($id)) !== 0) { $open = $e > 0; } // assistive technology opening or closing it
        $n->expanded = $branch && $open; $n->expandable = $branch; $n->level = $depth + 1;
        if ($selected) { $n->checked(true); }
        $fg = $selected ? $t->accentText : $t->text;
          $this->column(0, 0)->size($t->space(1 + 4 * $depth), 0); $this->end(); // the indent
          $arrow = $this->column(0, 0)->size($t->space(4), $t->space(4))->center();
            if ($branch) { $this->text($open ? '▾' : '▸', $t->fontSize - 1, $selected ? $t->accentText : $t->textMuted)->role(Role::NONE); }
          $this->end();
          $this->text($label, $t->fontSize, $fg)->singleLine();
          $this->column(0, 0)->size($t->space(2), 0); $this->end();
        $this->end();
        if ($branch && $this->clicked($arrow)) { $open = !$open; }
        if ($branch && $open) {
            $tb['open'][] = $id;
            $children();
            array_pop($tb['open']);
        }
        return $n;
    }

    /**
     * A list of items in a tree, as AppKit's outline view (mygo's
     * Outline): $roots at the top, and below each item open its children,
     * indented, which $children(item) returns, null for an item without any.
     * A click on an item's arrow opens or closes it, with Option all inside
     * it too; $row(item) builds the content of its row, after its arrow.
     * Only the rows in view are built, as a list's. With
     * $s->list->selected set, rows are chosen as a list's, and while the
     * outline has the focus Right opens the item chosen or goes to its first
     * child, Left closes it or goes to its parent, with Option opening and
     * closing all inside.
     * @param list<int|string> $roots
     */
    public function outline(OutlineState $s, array $roots, Closure $children, Closure $row): Node
    {
        $t = $this->theme;
        $s->flatten($roots, $children);
        $n = $this->list($s->list, $s->rows(), function (int $i) use ($s, $children, $row, $t): void {
            $r = $this->row($t->space(1), $t->space(1))->padding($t->space(1), $t->space(2))->align(Node::CENTER)->role(Role::NONE);
              $this->outlinePrefix($s, $i, $r, $children);
              $row($s->item($i));
            $this->end();
        });
        $n->role = Role::TREE; $n->rowRole = Role::TREE_ITEM;
        $this->outlineKeys($s, $n, $children);
        return $n;
    }

    /**
     * An outline whose rows are a table's, as AppKit's outline view with
     * columns (mygo's OutlineTable): the first of $columns shows the items'
     * arrows, indented as deep as they are, before what $cell(item, col)
     * builds; the columns, the sort and the keys of a table, and those of
     * an outline.
     * @param list<TableColumn> $columns
     * @param list<int|string> $roots
     */
    public function outlineTable(OutlineState $s, array $columns, array $roots, Closure $children, Closure $cell): Node
    {
        $s->flatten($roots, $children);
        $n = $this->table($s->list, $columns, $s->rows(), function (int $i, int $col) use ($s, $children, $cell): void {
            if ($col === 0) {
                $this->outlinePrefix($s, $i, $this->stack[count($this->stack) - 2], $children); // the row around the cell is the tree's item
            }
            $cell($s->item($i), $col);
        });
        $list = $n->kids[count($n->kids) - 1];
        $list->rowRole = Role::TREE_ITEM;
        $this->outlineKeys($s, $list, $children);
        return $n;
    }

    /** The indentation and the arrow of row $i of an outline, $item the row assistive technology sees (mygo's OutlineState.prefix). */
    private function outlinePrefix(OutlineState $s, int $i, Node $item, Closure $children): void
    {
        $t = $this->theme;
        $rt = $this->runtime;
        [$branch, $open] = $s->branch($i);
        $item->level = $s->depth($i) + 1; $item->expandable = $branch; $item->expanded = $open;
        if ($s->depth($i) > 0) {
            $this->column()->size($s->depth($i) * ($s->indent > 0 ? $s->indent : $t->space(4)), 0)->shrink(0); $this->end();
        }
        $arrow = $this->column()->size($t->space(4), $t->space(4))->shrink(0)->role(Role::NONE); $this->end();
        if (!$branch) {
            return;
        }
        $arrow->keepFocus();
        $toggle = $this->clicked($arrow);
        if ($rt !== null && ($e = $rt->expanded($item->id)) !== 0) { $toggle = ($e > 0) !== $open; } // assistive technology opening or closing it
        if ($toggle) {
            $s->setOpen($s->item($i), !$open, $toggle && ($rt?->clickMods ?? 0) & Key::OPTION, $children);
            if ($open) { $s->closed($i); }
            $open = !$open;
            $item->expanded = $open;
            $rt?->changed();
        }
        $turn = $this->animate($arrow, 'open', $open ? 90.0 : 0.0, 0.15);
        $color = $s->list->selected !== null && $s->list->chosen($i) ? $t->accentText : $t->textMuted;
        $rtl = $arrow->rtl;
        $arrow->draw(static function (DisplayList $list, Node $a) use ($turn, $rtl, $color): void { // a chevron pointing along the line, turned down as it opens (mygo's disclosurePath)
            $d = min($a->w, $a->h); $cx = $a->x + $a->w / 2; $cy = $a->y + $a->h / 2;
            $deg = $rtl ? -$turn : $turn;
            [$sin, $cos] = [sin(deg2rad($deg)), cos(deg2rad($deg))];
            $pts = array_map(static function (array $p) use ($d, $cx, $cy, $sin, $cos, $rtl): array {
                [$x, $y] = [($rtl ? -$p[0] : $p[0]) * $d, $p[1] * $d];
                return [$cx + $x * $cos - $y * $sin, $cy + $x * $sin + $y * $cos];
            }, [[-0.1, -0.25], [0.15, 0.0], [-0.1, 0.25]]);
            $list->line($pts[0][0], $pts[0][1], $pts[1][0], $pts[1][1], $color, 1.0, 1.5);
            $list->line($pts[1][0], $pts[1][1], $pts[2][0], $pts[2][1], $color, 1.0, 1.5);
        });
    }

    /** Right and Left on the item chosen while the outline's list $n has the focus. */
    private function outlineKeys(OutlineState $s, Node $n, Closure $children): void
    {
        if ($s->list->selected === null || !$this->focused($n)) {
            return;
        }
        [$back, $forth] = $this->arrows($n);
        $f = $this->shortcut($forth); $b = $this->shortcut($back);
        $fa = $this->shortcut($forth, Key::OPTION); $ba = $this->shortcut($back, Key::OPTION);
        if ($f || $b || $fa || $ba) {
            $s->keys($f || $fa, $b || $ba, $fa || $ba, $children);
            $this->runtime?->changed();
        }
    }

    /**
     * A sidebar of items in sections, as mygo's Sidebar (Finder's,
     * Mail's), $width wide, scrolling when they do not fit: a click
     * chooses an item, whose id $selected holds, and while the sidebar has
     * the focus Up, Down, Home, End and typing the first letters of an
     * item choose among its items; it is one stop of Tab, and changed()
     * reports a new choice.
     */
    public function sidebar(string &$selected, Closure $fn, float $width = 200): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $n = $this->column($t->space(2.5), $t->space(0.5), 0, $t->surface)->size($width)->focusable()->role(Role::TREE);
        $n->choosesItems = true; // whose sections hold their items
        if ($rt !== null) {
            $local = $rt->local($n->id, fn() => (object)['scroll' => new ScrollState, 'items' => [], 'labels' => [], 'typed' => '', 'typedAt' => 0.0]);
            $n->scroll($local->scroll);
            // The keys choose among last frame's items, in their order.
            $items = $local->items; $at = array_search($selected, $items, true); $to = null;
            if ($rt->focus === $n->id && $items !== []) {
                if ($this->shortcut(Key::DOWN)) { $to = $at === false ? 0 : min($at + 1, count($items) - 1); }
                if ($this->shortcut(Key::UP)) { $to = $at === false ? 0 : max($at - 1, 0); }
                if ($this->shortcut(Key::HOME)) { $to = 0; }
                if ($this->shortcut(Key::END)) { $to = count($items) - 1; }
                if (($typed = $rt->typed($n->id)) !== '') { // the first letters of an item (mygo's sidebar keys)
                    $now = $rt->now / 1e9;
                    if ($now - $local->typedAt >= 1.0) { $local->typed = ''; }
                    $local->typed .= $typed; $local->typedAt = $now;
                    $labels = $local->labels;
                    $j = self::typedRow($local->typed, $at === false ? -1 : $at, count($items), fn(int $i) => $labels[$i]);
                    if ($j >= 0) { $to = $j; }
                }
            }
            if ($to !== null && $items[$to] !== $selected) { $selected = $items[$to]; $n->changed = true; }
        }
        $this->sidebars[] = ['node' => $n, 'selected' => &$selected, 'items' => [], 'labels' => [], 'depth' => 0, 'chosen' => null];
          $fn();
        $built = array_pop($this->sidebars);
        $this->end();
        if ($built['chosen'] !== null) { $n->activeDescendant($built['chosen']); } // the item chosen has the focus for assistive technology, as a list's row chosen does
        if (isset($local)) { $local->items = $built['items']; $local->labels = $built['labels']; }
        return $n;
    }

    /**
     * A section of a sidebar titled $title, as mygo's SidebarSection: the
     * items $fn builds while $open (null keeps it open); a click on the
     * title shows and hides them, its arrow showing as the pointer rests
     * on it.
     */
    public function sidebarSection(string $title, ?bool &$open, Closure $fn): Node
    {
        $t = $this->theme;
        $parent = $this->stack[count($this->stack) - 1];
        $alone = ['depth' => 0]; // a section out of a sidebar
        if ($this->sidebars === []) { $sb = &$alone; } else { $sb = &$this->sidebars[count($this->sidebars) - 1]; }
        $n = $this->column(0, $t->space(0.5));
          if (count($parent->kids) > 1) { $this->column(0, 0)->size(0, $t->space(2.5)); $this->end(); } // apart from the section above
          $head = $this->row(0, 0)->padding($t->space(1), $t->space(2))->round($t->radius)->align(Node::CENTER)->role(Role::TREE_ITEM)->level(1);
          if ($open !== null && $this->clicked($head)) { $open = !$open; }
          if ($open !== null && ($e = $this->runtime?->expanded($head->id) ?? 0) !== 0) { $open = $e > 0; } // assistive technology opening or closing it
            $this->text($title, $t->fontSize - 1, $t->textMuted, Font::BOLD);
            $this->spacer();
            if ($open !== null) { // the disclosure arrow, shown as the pointer rests on the title
                $hovered = $this->hovered($head);
                $a = $this->box($t->space(4), $t->space(4), 0); $a->alpha = 0.0;
                $turn = $this->animate($a, 'open', $open ? 90 : 0, 0.15);
                if ($hovered) { $a->draw(fn(DisplayList $list, Node $a, Theme $t) => self::disclosure($list, $a, $turn, $t->textMuted)); }
            }
          $this->end();
          if ($open !== null) { $head->expandable = true; $head->expanded = $open; }
          if ($open === null || $open) { $sb['depth']++; $fn(); $sb['depth']--; }
        $this->end();
        return $n;
    }

    /**
     * An item of a sidebar choosing $id, showing $label after $icon (an
     * SVG in the accent, a glyph, or null), as mygo's SidebarItem; $trailing builds what sits at
     * its end, a badge say. Chosen, it is the accent while the sidebar has
     * the focus and gray otherwise, as AppKit's.
     */
    public function sidebarItem(string $id, string $label, string|SVG|null $icon = null, ?Closure $trailing = null): Node
    {
        $t = $this->theme;
        $sb = &$this->sidebars[count($this->sidebars) - 1];
        $chosen = $sb['selected'] === $id;
        $focused = $this->focused($sb['node']);
        $sb['items'][] = $id; $sb['labels'][] = $label;
        $n = $this->key("item:$id")->row(0, $t->space(2), 0, $chosen ? ($focused ? $t->accent : $t->surfacePressed) : null)->padding($t->space(1.5), $t->space(2))->round($t->radius)->align(Node::CENTER)
            ->role(Role::TREE_ITEM)->level($sb['depth'] + 1)->checked($chosen);
        $n->choosable = true;
        if ($chosen) { $sb['chosen'] = $n; }
        if (!$chosen && $this->hovered($n)) { $n->color = $t->surfaceHover; }
        $fg = $chosen && $focused ? $t->accentText : $t->text;
          if ($icon instanceof SVG) { $this->icon($icon, 16, $chosen && $focused ? $t->accentText : $t->accent); }
          elseif ($icon !== null) { $this->text($icon, 16, $chosen && $focused ? $t->accentText : $t->accent)->role(Role::NONE); }
          $this->text($label, $t->fontSize, $fg)->singleLine();
          if ($trailing !== null) { $this->spacer(); $trailing(); }
        $this->end();
        if ($this->clicked($n) && $sb['selected'] !== $id) {
            $sb['selected'] = $id;
            $sb['node']->changed = true;
            if ($this->runtime !== null) { $this->runtime->focus = $sb['node']->id; } // a click chooses and focuses the sidebar, as a list's row does
        }
        return $n;
    }

    /**
     * The popup of a combobox or autocomplete, mygo's comboboxBase and
     * styleOptions: $options below $anchor while open, at most Space(70)
     * high and scrolling, each item padded Space(1.5) by Space(2.5), the
     * one highlighted in the accent; Up and Down move the highlight, Enter
     * or a click chooses, Escape closes, as does the input losing the
     * focus. Returns the option chosen this frame, or null. $local keeps
     * open, typed, highlight and values across frames.
     * @param list<string> $options
     */
    private function comboPopup(Node $in, Node $anchor, TextState $state, object $local, array $options, bool $firstTyped, bool $typedOnly, bool $clicked = false): ?string
    {
        $t = $this->theme;
        $rt = $this->runtime;
        if ($rt === null) { return null; }
        $chosen = null;
        $focused = $rt->focus === $in->id;
        $typed = $local->seen !== null && $local->seen !== $state->text;
        $local->seen = $state->text;
        $open = function (bool $o, bool $typed, int $highlight) use ($local, $rt) { $local->open = $o; $local->typed = $typed; $local->highlight = $highlight; $local->pointer = [$rt->input->mouseX, $rt->input->mouseY]; };
        $n = count($local->values);
        if (!$focused) { if ($local->open) { $open(false, false, -1); } }
        elseif ($typed) { $open(true, true, $firstTyped ? 0 : -1); }
        elseif ($clicked && !$local->open) { $open(true, false, -1); }
        if ($focused) {
            if ($this->shortcut(Key::DOWN)) { if (!$local->open) { $open(true, $local->typed, 0); } elseif ($n > 0) { $local->highlight = min($local->highlight + 1, $n - 1); } }
            if ($this->shortcut(Key::UP)) { if (!$local->open) { $open(true, $local->typed, -2); } elseif ($n > 0) { $local->highlight = max($local->highlight - 1, 0); } } // -2: the last, once known
            if ($local->open && $this->shortcut(Key::ESCAPE)) { $open(false, false, -1); }
            if ($state->submitted && $local->open && $local->highlight >= 0 && $local->highlight < $n) { // Enter chooses the highlight; the caller sees submitted otherwise
                $chosen = $local->values[$local->highlight]; $state->submitted = false;
            }
        }
        $shown = $local->open && (!$typedOnly || $local->typed) ? $options : [];
        $local->values = $shown;
        if ($local->highlight === -2 || $local->highlight >= count($shown)) { $local->highlight = count($shown) - 1; }
        if ($shown !== [] && $local->open) {
            $r = $rt->rectOf($anchor->id) ?? [0, 0, 0, 0];
            $saved = $this->toRoot();
            $panel = $this->key('combo:' . $in->id)->column($t->space(1), 0, 0, $t->background)->round($t->radius + 2)->border()->shadow(0, 6, 20, 0, 0x000000, 0.18)
                ->at($r[0], $r[1] + $r[3] + $t->space(1))->size($r[2], 0)->scroll($local->scroll)->role(Role::LIST);
            $panel->choosesItems = true;
            $panel->size($r[2], min($t->space(70), count($shown) * ($t->space(3) + $this->metrics->measure('X', $t->fontSize)[1]) + 2 * $t->space(1)));
              foreach ($shown as $i => $opt) {
                  $hl = $i === $local->highlight;
                  $item = $this->key("o$i")->row(0, 0, 0, $hl ? $t->accent : null)->padding($t->space(1.5), $t->space(2.5))->round($t->radius)->role(Role::LIST_ITEM)->checked($hl); // the option the arrows are on is the one assistive technology reads as chosen, as in a list box
                  $item->setPos = $i + 1; $item->setSize = count($shown); $item->choosable = true;
                  if ($hl) { $in->activeDescendant($item); } // the input has the focus for the option its arrows are on
                    $this->text($opt, $t->fontSize, $hl ? $t->accentText : $t->text)->singleLine();
                  $this->end();
                  $p = [$rt->input->mouseX, $rt->input->mouseY];
                  if ($this->hovered($item) && $p !== $local->pointer) { $local->pointer = $p; $local->highlight = $i; } // the pointer moved onto it: not the wheel scrolling it under a pointer at rest
                  if ($hl && $local->shown !== $opt) { $local->shown = $opt; $item->scrollIntoView(); } // into view as the highlight moves to it, and not again, which would pull the popup back as the wheel scrolls away
                  if ($this->clicked($item)) { $chosen = $opt; }
              }
            $this->end();
            $this->back($saved);
        }
        if ($chosen !== null) { $open(false, false, -1); }
        if (!$local->open) { $local->shown = null; }
        $in->expanded($local->open); // what assistive technology reads of the popup
        return $chosen;
    }

    /**
     * A combobox choosing $selected among $options, as mygo's Combobox: a
     * field showing the choice, whose text filters the options below it
     * as the user types, those starting with it first, while a click or
     * Down shows them all; Up and Down move among them and Enter chooses,
     * as does a click; Escape closes them, and the text goes back to the
     * choice when the field loses the focus. changed() reports a new
     * choice. $placeholder shows while it is empty; autoFocus() on the field
     * gives the focus to its input.
     * @param list<string> $options
     */
    public function combobox(string &$selected, array $options, float $width = 0, string $placeholder = ''): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $f = $this->row(0, $t->space(1.5), 0, $t->surface)->padding($t->space(1.5), $t->space(2.5))->round($t->radius)->border()->align(Node::CENTER)->cursor(Cursor::Text)->role(Role::NONE); // mygo's field: as wide as its content in a row, stretched in a column; its label names the input
        if ($width > 0) { $f->size($width, 0); }
        $make = fn() => (object)['text' => new TextState($selected), 'open' => false, 'typed' => false, 'highlight' => -1, 'values' => [], 'seen' => null, 'scroll' => new ScrollState, 'shown' => null, 'pointer' => [0.0, 0.0]];
        $local = $rt?->local($f->id, $make) ?? $make();
        $state = $local->text;
          [, $h] = $this->metrics->measure('X', $t->fontSize);
          $in = $this->add(new Node(Node::INPUT, 0, 0, 0, 0, $h, 1, $placeholder, null, $t->fontSize, $t->text)); // the input, unframed, in the field's box
          $f->focusTo = $in; // autoFocus() and focusBind() on the box give the focus to the input (mygo's focusTo)
          $in->edit = $state; $in->changed = $state->editedSinceBuilt(); $in->inset(-Runtime::FIELD_INSET, -Runtime::FIELD_INSET); $in->min($t->space(25), 0);
          $in->focused = $rt?->focused($in->id) ?? false;
          $in->role = Role::COMBO_BOX; $in->nameFrom = $f;
          $arrow = $this->chevron();
        $this->end();
        $f->borderColor = $in->focused ? $t->accent : ($this->hovered($f) ? Theme::mix($t->border, $t->text, 0.25) : null);
        $click = false; // the input's own click, which opens the options while they are closed
        $focus = false;
        if ($this->clicked($arrow)) { // the chevron toggles them, the choice selected as a click in the text opening them does
            $local->open = !$local->open; $local->typed = false; $local->highlight = -1; $focus = true;
            if ($local->open) { $state->selectAll(); }
        } elseif ($this->clicked($in)) {
            $click = true; $focus = true;
            if (!$local->open) { $state->selectAll(); } // a click opening them selects the choice, for the next key to replace and filter; clicks while they show place the caret
        } elseif ($this->clicked($f)) { $focus = true; } // the box's padding: the input takes the focus
        if ($focus && $rt !== null) { $rt->focus = $in->id; $in->focused = true; }
        $f->focused = $in->focused; // the box reports its input's focus
        $shown = $local->typed ? self::matching($options, $state->text) : $options;
        $chosen = $this->comboPopup($in, $f, $state, $local, $shown, true, false, $click);
        $state->submitted = false; // Enter on no highlight is nothing to a combobox
        if ($chosen !== null) {
            if ($selected !== $chosen) { $selected = $chosen; $f->changed = true; }
            $state->text = $chosen; $state->selectAll(); $local->seen = $chosen;
        } elseif (!$local->open && $rt?->focus !== $in->id && $state->text !== $selected) {
            $state->text = $selected; $state->setCursor(strlen($selected)); $local->seen = $selected; // what was typed matched no choice
        }
        return $f;
    }

    /**
     * A text field editing $value which suggests below it those of
     * $suggestions containing what was typed, those starting with it
     * first, as mygo's Autocomplete: Up and Down move among them and Enter
     * takes one, as does a click, while Enter on none is $state->submitted.
     * changed() reports a change, typed or taken.
     * @param list<string> $suggestions
     */
    public function autocomplete(TextState $state, array $suggestions, string $placeholder = '', float $width = 200): Node
    {
        $rt = $this->runtime;
        $in = $this->input($state, $placeholder, $width)->role(Role::COMBO_BOX);
        $make = fn() => (object)['open' => false, 'typed' => false, 'highlight' => -1, 'values' => [], 'seen' => null, 'scroll' => new ScrollState, 'shown' => null, 'pointer' => [0.0, 0.0], 'was' => $state->text];
        $local = $rt?->local($in->id, $make) ?? $make();
        $shown = [];
        if (($q = trim($state->text)) !== '') {
            foreach (self::matching($suggestions, $q) as $sug) { if (strcasecmp($sug, $state->text) !== 0) { $shown[] = $sug; } }
        }
        $chosen = $this->comboPopup($in, $in, $state, $local, $shown, false, true);
        if ($chosen !== null) { $state->text = $chosen; $state->setCursor(strlen($chosen)); $local->seen = $chosen; }
        $in->changed = $state->text !== $local->was;
        $local->was = $state->text;
        return $in;
    }

    /**
     * A field of tokens, as of tags or a mail's recipients, as mygo's
     * TokenField: $tokens show as chips, each with a button taking it out,
     * before an input where Enter or a comma adds what was typed and
     * Backspace while empty takes out the last; the $suggestions containing
     * what was typed show below, those not tokens yet, Up and Down moving
     * among them and Enter or a click adding one. The field wraps its
     * chips onto more lines. changed() reports a change. $placeholder
     * shows while its input is empty; autoFocus() on the field gives the
     * focus to its input.
     * @param list<string> $tokens
     * @param list<string> $suggestions
     */
    public function tokenField(array &$tokens, array $suggestions = [], float $width = 0, string $placeholder = ''): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $f = $this->row(0, $t->space(1.5), 0, $t->surface)->padding($t->space(1.5), $t->space(2.5))->round($t->radius)->border()->align(Node::CENTER)->cursor(Cursor::Text)->role(Role::NONE); // mygo's field: as wide as its content in a row, stretched in a column; its label names the input
        if ($width > 0) { $f->size($width, 0); }
        $f->wrap($rt?->rectOf($f->id)[2] ?? 0.0);
        $make = fn() => (object)['text' => new TextState(''), 'open' => false, 'typed' => false, 'highlight' => -1, 'values' => [], 'seen' => null, 'scroll' => new ScrollState, 'shown' => null, 'pointer' => [0.0, 0.0]];
        $local = $rt?->local($f->id, $make) ?? $make();
        $state = $local->text; $state->leaveEmptyBackspace = true;
        $changed = false;
        $add = function (string $s) use (&$tokens, &$changed) { $s = trim($s); if ($s !== '' && !in_array($s, $tokens, true)) { $tokens[] = $s; $changed = true; } };
          foreach ($tokens as $i => $tok) {
              $chip = $this->key($tok)->row(0, $t->space(1), 0, $t->surfacePressed)->padding($t->space(0.25), 0)->round($t->radius)->align(Node::CENTER)->cursor(Cursor::Default);
                $this->column(0, 0)->size($t->space(1), 0); $this->end(); // with the gap: PaddingStart(Space(2))
                $this->text($tok, $t->fontSize)->singleLine();
                $x = $this->box($t->space(3.5), $t->space(3.5), 0)->round($t->space(1))->role(Role::BUTTON)->label($this->texts()->get('removeToken', ['token' => $tok])); $x->color = $this->hovered($x) ? $t->surfaceHover : null;
                $x->draw(function (DisplayList $list, Node $b, Theme $t) { $k = $b->w * 0.3; $list->line($b->x + $k, $b->y + $k, $b->x + $b->w - $k, $b->y + $b->h - $k, $t->textMuted, 1.0, 1.25); $list->line($b->x + $b->w - $k, $b->y + $k, $b->x + $k, $b->y + $b->h - $k, $t->textMuted, 1.0, 1.25); });
                $this->column(0, 0); $this->end(); // with the gap: PaddingEnd(Space(1))
              $this->end();
              if ($this->clicked($x)) { array_splice($tokens, $i, 1); $changed = true; }
          }
          [, $h] = $this->metrics->measure('X', $t->fontSize);
          $this->key('input'); // mygo's: the input keeps its identity, whatever tokens come before it
          $in = $this->add(new Node(Node::INPUT, 0, 0, 0, 0, $h, 1, $placeholder, null, $t->fontSize, $t->text)); // the input, unframed, after the chips
          $f->focusTo = $in;
          $in->edit = $state; $in->changed = $state->editedSinceBuilt(); $in->inset(-Runtime::FIELD_INSET, -Runtime::FIELD_INSET); $in->min($t->space(20), 0);
          $in->focused = $rt?->focused($in->id) ?? false;
          $in->nameFrom = $f;
          if ($suggestions !== []) { $in->role = Role::COMBO_BOX; } // else a text field
        $this->end();
        $f->borderColor = $in->focused ? $t->accent : ($this->hovered($f) ? Theme::mix($t->border, $t->text, 0.25) : null);
        if ($this->clicked($f) && $rt !== null) { $rt->focus = $in->id; $in->focused = true; }
        $f->focused = $in->focused;
        if ($in->focused && $tokens !== [] && $state->text === '' && $this->shortcut(Key::DELETE)) { array_pop($tokens); $changed = true; }
        $shown = [];
        if (($q = trim($state->text)) !== '') {
            foreach (self::matching($suggestions, $q) as $sug) { if (!in_array($sug, $tokens, true)) { $shown[] = $sug; } }
        }
        $chosen = $this->comboPopup($in, $f, $state, $local, $shown, false, true);
        if (str_contains($state->text, ',')) {
            $parts = explode(',', $state->text);
            foreach (array_slice($parts, 0, -1) as $part) { $add($part); }
            $state->text = end($parts); $state->setCursor(strlen($state->text)); $local->seen = $state->text;
        }
        if ($chosen !== null) { $add($chosen); $state->text = ''; $state->setCursor(0); $local->seen = ''; }
        elseif ($state->submitted) { $state->submitted = false; $add($state->text); $state->text = ''; $state->setCursor(0); $local->seen = ''; }
        if ($changed) { $f->changed = true; }
        return $f;
    }

    /**
     * A field showing the day of $date, as mygo's DateInput: a plain
     * button of the date (en-US, 1/5/2026) and a calendar page, which a
     * click, Enter or Space opens a calendar below; there a click chooses
     * a day, as the arrows and Enter do, Page Up and Page Down or its
     * buttons move by months, and Escape closes it. changed() reports a
     * new date, which keeps the time of day and the zone of $date.
     */
    public function dateInput(\DateTimeImmutable &$date): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $b = $this->buttonFace($t->space(1.5), $t->space(3.5))->min($t->space(32.5), 0); // its text and a page icon at its ends (Justify(SpaceBetween))
          [, $locale] = $this->formats();
          $this->text(I18n::formatDate($locale, $date), $t->fontSize, $t->text)->singleLine();
          $this->row(0, 0, 1); $this->end();
          $page = $this->box($t->space(3.5), $t->space(3.5), 0); $page->color = null;
          $page->draw(function (DisplayList $list, Node $a, Theme $t) use ($b) { // a calendar page: a box stroked 1.2 at radius 2, a 1.2 line under its top
              $list->rect($a->x + 1, $a->y + 2, $a->w - 2, $a->h - 3, $t->textMuted, 1.0, 2.0); $list->rect($a->x + 2.2, $a->y + 3.2, $a->w - 4.4, $a->h - 5.4, $b->color, 1.0, 1.0);
              $list->rect($a->x + 1, $a->y + 5, $a->w - 2, 1.2, $t->textMuted, 1.0, 0.0);
          });
        $this->end();
        $make = fn() => (object)['open' => false, 'cursor' => $date];
        $local = $rt?->local($b->id, $make) ?? $make();
        if ($this->clicked($b)) { $local->open = !$local->open; $local->cursor = $date; }
        $this->popoverBase($b, $local->open, function (Node $panel) use (&$date, $local, $b, $rt) { // not popover(), whose panel is as wide as the field at least: the calendar keeps its own width
            $this->stylePanel($panel);
            $grid = $this->calendarGrid($date, $local->cursor, false, function (\DateTimeImmutable $d) use (&$date, $local, $b, $rt) {
                if (self::setDay($date, $d)) { $b->changed = true; }
                $local->open = false;
                $rt?->focusOn($b->id);
            });
            if ($rt !== null && $rt->focus === $b->id && $local->open) { $rt->focusOn($grid->id); } // AutoFocus: the calendar takes the keys as it opens
        });
        return $b;
    }

    /** A plain button's face as a row holding what follows, mygo's styleButton on coreButton: a bordered surface, the theme's radius, shaded under the pointer and while pressed, focusable (ringed by the painter). */
    private function buttonFace(float $padY, float $padX): Node
    {
        $t = $this->theme;
        $b = $this->row(0, 0, 0, $t->surface)->padding($padY, $padX)->round($t->radius)->border()->focusable()->align(Node::CENTER);
        if ($this->hovered($b)) { $b->color = $this->runtime->input->mouseDown ? $t->surfacePressed : $t->surfaceHover; }
        return $b;
    }

    /**
     * A month's calendar choosing the day of $date, as mygo's Calendar: a
     * click chooses a day, as the arrows do while the calendar has the
     * focus, Page Up and Page Down or its buttons move by months, Home
     * and End go to the month's first and last day. changed() reports a
     * new date, which keeps the time of day and the zone of $date. The
     * names and the first weekday are en-US. Node::browse() lets the user
     * move about it without choosing.
     */
    public function calendar(\DateTimeImmutable &$date): Node
    {
        $changed = false;
        $g = $this->calendarBox();
        // Input reaches the calendar as it is built, before this build's browse(): it follows the build before, which the user saw (mygo's b7e6bdd).
        $set = $this->runtime?->local($g->id, fn() => (object)['now' => false, 'shown' => false, 'cursor' => $date, 'date' => $date]) ?? (object)['now' => false, 'shown' => false, 'cursor' => $date, 'date' => $date];
        [$set->shown, $set->now] = [$set->now, false];
        if (!$set->shown || $set->date->format('Y-m-d') !== $date->format('Y-m-d')) { $set->cursor = $date; } // the cursor follows the date the app sets
        $set->date = $date;
        $g->calendar = $set;
        $choose = function (\DateTimeImmutable $d) use (&$date, &$changed) {
            if (self::setDay($date, $d)) { $changed = true; }
        };
        if ($set->shown) {
            $this->calendarGrid($date, $set->cursor, false, $choose, $g);
        } else {
            $this->calendarGrid($date, $date, true, $choose, $g);
        }
        $set->date = $date;
        $g->changed = $changed;
        return $g;
    }

    /** The node of a month's grid, which calendarGrid fills (mygo's calendarBox). */
    private function calendarBox(): Node
    {
        $grid = $this->column(0, $this->theme->space(0.5))->focusable()->role(Role::TABLE);
        $grid->ownRing = true;
        return $grid;
    }

    /** Sets the day of $date to $d's, keeping its time of day and zone; whether it changed. */
    private static function setDay(\DateTimeImmutable &$date, \DateTimeImmutable $d): bool
    {
        $next = $date->setDate((int)$d->format('Y'), (int)$d->format('n'), (int)$d->format('j'));
        if ($next == $date) { return false; }
        $date = $next;
        return true;
    }

    /**
     * mygo's calendarGrid: the month around $cursor, the day the keys move,
     * with $date chosen; $choose chooses a day, as a click and Enter do,
     * and every move does with $moveChooses. Cells Space(8) by Space(7),
     * the chosen in the accent, the cursor's surfaceHover, today ringed
     * in the accent, the month's neighbors' days muted.
     */
    private function calendarGrid(\DateTimeImmutable $date, \DateTimeImmutable &$cursor, bool $moveChooses, Closure $choose, ?Node $grid = null): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        [$region, $locale] = $this->formats();
        $cur = $cursor;
        $month = $cur->setDate((int)$cur->format('Y'), (int)$cur->format('n'), 1)->setTime(0, 0);
        $title = I18n::formatDate($locale, $month, new DateOptions(skeleton: 'yMMMM'));
        $first = $region->firstWeekday;
        $grid = ($grid ?? $this->calendarBox())->label($title);
        $visible = $rt?->focusVisible($grid->id) ?? false;
        $grid->draw(function (DisplayList $list, Node $g, Theme $t) use ($visible) { if ($visible) { Painter::paintFocusRing($list, $g->x, $g->y, $g->w, $g->h, $t->radius, $t); } });
        $move = function (\DateTimeImmutable $d) use ($moveChooses, $choose, &$cursor) { if ($moveChooses) { $choose($d); } else { $cursor = $d; } }; // choosing moves the cursor where it is the date chosen
        if ($rt?->focus === $grid->id) {
            [$back, $forth] = $this->arrows($grid);
            if ($this->shortcut($back)) { $move($cur->modify('-1 day')); }
            elseif ($this->shortcut($forth)) { $move($cur->modify('+1 day')); }
            elseif ($this->shortcut(Key::UP)) { $move($cur->modify('-7 days')); }
            elseif ($this->shortcut(Key::DOWN)) { $move($cur->modify('+7 days')); }
            elseif ($this->shortcut(Key::PAGE_UP)) { $move(self::addMonths($cur, -1)); }
            elseif ($this->shortcut(Key::PAGE_DOWN)) { $move(self::addMonths($cur, 1)); }
            elseif ($this->shortcut(Key::HOME)) { $move($cur->modify('first day of this month')); }
            elseif ($this->shortcut(Key::END)) { $move($cur->modify('last day of this month')); }
            elseif ($rt->activated($grid->id)) { $choose($cur); }
        }
          $this->row(0, $t->space(1))->align(Node::CENTER);
            $prev = $this->monthButton(true);
            if ($this->clicked($prev)) { $move(self::addMonths($cur, -1)); }
            $this->row(0, 0, 1)->justify(Node::CENTER); $this->text($title, $t->fontSize, $t->text, Font::BOLD); $this->end();
            $next = $this->monthButton(false);
            if ($this->clicked($next)) { $move(self::addMonths($cur, 1)); }
          $this->end();
          $this->row(0, 0);
            $names = I18n::weekdays($locale, Width::Short, true);
            for ($i = 0; $i < 7; $i++) {
                $this->row(0, 0)->size($t->space(8), 0)->justify(Node::CENTER); $this->text($names[($first + $i) % 7], $t->fontSize - 2, $t->textMuted)->role(Role::COLUMN_HEADER); $this->end();
            }
          $this->end();
          $start = $month->modify('-' . (((int)$month->format('w') - $first + 7) % 7) . ' days'); // six weeks from the first day of the week on or before the first
          $today = (new \DateTimeImmutable('today', $date->getTimezone()))->format('Y-m-d');
          for ($w = 0; $w < 6; $w++) {
              $this->row(0, 0)->role(Role::ROW);
              for ($d = 0; $d < 7; $d++) {
                  $day = $start->modify('+' . ($w * 7 + $d) . ' days');
                  $ymd = $day->format('Y-m-d');
                  $chosen = $ymd === $date->format('Y-m-d'); $atCursor = $ymd === $cur->format('Y-m-d');
                  $cell = $this->key($ymd)->row(0, 0)->size($t->space(8), $t->space(7))->center()->round($t->radius)->role(Role::BUTTON)->label(I18n::formatDate($locale, $day, new DateOptions(skeleton: 'yMMMMd')))->checked($chosen);
                  if ($atCursor) { $grid->activeDescendant($cell); } // assistive technology reads the day the keys are on
                  $fg = $day->format('n') !== $month->format('n') ? $t->textMuted : $t->text;
                  if ($chosen) { $cell->color = $t->accent; $fg = $t->accentText; }
                  elseif ($atCursor || $this->hovered($cell)) { $cell->color = $t->surfaceHover; }
                  if ($ymd === $today && !$chosen) { $cell->border($t->accent); }
                    $this->text(I18n::formatNumber($locale, (int)$day->format('j')), $t->fontSize, $fg);
                  $this->end();
                  if ($this->clicked($cell)) { $choose($day); if (!$moveChooses) { $cursor = $day; } } // with moveChooses the cursor is the date, which choose set
              }
              $this->end();
          }
        $this->end();
        return $grid;
    }

    /** A calendar's button to the month before, $back, or after: a chevron drawn in its middle, where the text's ‹ and › sat low, mirrored right to left (mygo's monthChevron, e554723). */
    private function monthButton(bool $back): Node
    {
        $t = $this->theme;
        $b = $this->buttonFace($t->space(1), $t->space(2.5))->justify(Node::CENTER)->role(Role::BUTTON)->label($this->texts()->get($back ? 'previousMonth' : 'nextMonth'));
          $e = $this->box($t->space(1.5), $t->space(2.5), 0)->shrink(0);
          $e->color = null;
          [$tip, $ends] = $back === $e->rtl ? [0.85, 0.15] : [0.15, 0.85]; // ‹, or ›
          $e->draw(function (DisplayList $list, Node $n, Theme $th, TextPainter $text) use ($tip, $ends): void {
              $p = (new Path)->moveTo($n->x + $n->w * $ends, $n->y + $n->h * 0.1)->lineTo($n->x + $n->w * $tip, $n->y + $n->h * 0.5)->lineTo($n->x + $n->w * $ends, $n->y + $n->h * 0.9);
              (new Painter($list, $text, $th))->strokePath($p, 1.5, $th->text);
          });
        $this->end();
        return $b;
    }

    /** $d moved $n months, as Go's AddDate normalizes: the 31st of a 30-day month lands in the month after. */
    private static function addMonths(\DateTimeImmutable $d, int $n): \DateTimeImmutable
    {
        return $d->setDate((int)$d->format('Y'), (int)$d->format('n') + $n, (int)$d->format('j'));
    }

    /**
     * A field of the time of day of $time, as mygo's TimeInput in the
     * 12-hour cycle of en-US: 3:04 PM. The hours, the minutes and the half
     * of the day each take the focus: Up and Down step the one focused
     * round the clock, digits typed set it, going on to the minutes once
     * the hours are typed, A or P chooses the half, Left and Right move
     * between them. changed() reports a new time, which keeps the date and
     * the zone of $time.
     */
    public function timeInput(\DateTimeImmutable &$time): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        [$region, $locale] = $this->formats();
        $twelve = $region->hourCycle === 12;
        // The order of the segments and what separates the hours from the minutes, as the locale's pattern of hours and minutes writes them.
        [$sep, $periodFirst] = self::timeLayout((new DateFormatter($locale, new DateOptions(skeleton: 'jmm', hourCycle: $twelve ? 12 : 24)))->pattern());
        [$am, $pm] = I18n::dayPeriods($locale);
        $order = $twelve ? ($periodFirst ? [2, 0, 1] : [0, 1, 2]) : [0, 1];
        $f = $this->row(0, 0, 0, $t->surface)->padding($t->space(1.5), $t->space(2))->round($t->radius)->border()->align(Node::CENTER)->role(Role::GROUP);
        $h = (int)$time->format('G'); $m = (int)$time->format('i');
        $set = function (int $nh, int $nm) use (&$time, $f) {
            $next = $time->setTime($nh, $nm, (int)$time->format('s'), (int)$time->format('u'));
            if ($next != $time) { $time = $next; $f->changed = true; }
        };
        $segs = []; $focused = [];
        $texts = [0 => $twelve ? I18n::formatNumber($locale, ($h + 11) % 12 + 1) : I18n::formatNumber($locale, $h, new NumberOptions(minIntegerDigits: 2)), 1 => I18n::formatNumber($locale, $m, new NumberOptions(minIntegerDigits: 2)), 2 => $h >= 12 ? $pm : $am];
          foreach ($order as $i => $k) {
              if ($k === 1) { $this->row(0, 0)->padding(0, 1); $this->text($sep, $t->fontSize, $t->textMuted)->role(Role::NONE); $this->end(); }
              elseif ($i > 0) { $this->box($t->space(1), 0, 0)->color = null; }
              $seg = $this->key("seg$k")->row(0, 0)->padding(0, $t->space(0.5))->round($t->space(1))->focusable()->role(Role::STEPPER); $seg->ownRing = true;
              $seg->nameFrom = $f; $seg->nameJoin = true; // "Alarm hours", of a time input labeled Alarm
              match ($k) {
                  0 => $seg->label($this->texts()->get('hours'))->range($twelve ? 1 : 0, $twelve ? 12 : 23, $twelve ? ($h + 11) % 12 + 1 : $h, 1),
                  1 => $seg->label($this->texts()->get('minutes'))->range(0, 59, $m, 1),
                  2 => $seg->label($this->texts()->get('dayPeriod'))->value($texts[2]),
              };
              $focused[$k] = $rt?->focused($seg->id) ?? false;
              if ($focused[$k]) { $seg->color = $t->accent; }
                $this->text($texts[$k], $t->fontSize, $focused[$k] ? $t->accentText : $t->text);
              $this->end();
              $segs[$k] = $seg;
          }
        $this->end();
        if (in_array(true, $focused, true)) { $f->borderColor = $t->accent; }
        $make = fn() => (object)['typed' => '']; // the digits typed at the segment focused, until they set it or the focus leaves
        $local = $rt?->local($f->id, $make) ?? $make();
        if (!in_array(true, $focused, true)) { $local->typed = ''; }
        foreach ($order as $i => $k) {
            $seg = $segs[$k];
            if (!$focused[$k] || $rt === null) { continue; }
            $step = 0;
            if ($this->shortcut(Key::UP)) { $step = 1; }
            elseif ($this->shortcut(Key::DOWN)) { $step = -1; }
            elseif ($i + 1 < count($order) && $this->shortcut($this->arrows($segs[$order[$i]])[1])) { $rt->focusOn($segs[$order[$i + 1]]->id, true); $local->typed = ''; }
            elseif ($i > 0 && $this->shortcut($this->arrows($segs[$order[$i]])[0])) { $rt->focusOn($segs[$order[$i - 1]]->id, true); $local->typed = ''; }
            $local->typed .= $rt->typed($seg->id);
            if ($k === 2) { // the half of the day: Up and Down switch it, as does the first letter of the other's name
                $pmNow = $h >= 12; $want = $step !== 0 ? !$pmNow : $pmNow;
                if ($local->typed !== '') {
                    $r = mb_strtolower(mb_substr($local->typed, -1));
                    if ($r === mb_strtolower(mb_substr($pm, 0, 1))) { $want = true; } elseif ($r === mb_strtolower(mb_substr($am, 0, 1))) { $want = false; }
                    $local->typed = '';
                }
                if ($want !== $pmNow) { $set($want ? $h + 12 : $h - 12, $m); }
                continue;
            }
            $top = $k === 0 ? 24 : 60;
            if ($step !== 0) {
                if ($k === 0 && $twelve) { $h = intdiv($h, 12) * 12 + ($h % 12 + $step + 12) % 12; } // round the twelve hours of the same half of the day
                elseif ($k === 0) { $h = ($h + $step + 24) % 24; }
                else { $m = ($m + $step + $top) % $top; }
                $set($h, $m);
            }
            if ($local->typed !== '') { // digits: the first sets the segment, a second makes two digits of them, or starts anew past the top; both typed, or one that can't begin two, go on to the minutes
                if ($k === 0 && $twelve) { $top = 13; }
                $typed = $local->typed;
                $n = ctype_digit($typed) ? (int)$typed : null;
                if ($n === null || strlen($typed) > 2 || $n >= $top) { $typed = substr($typed, -1); $n = ctype_digit($typed) ? (int)$typed : null; $local->typed = $typed; }
                if ($n !== null) {
                    if ($k === 0 && $n === 0 && $twelve) { /* the first digit of 01 to 09 */ }
                    elseif ($k === 0 && $twelve) { $h = intdiv($h, 12) * 12 + $n % 12; $set($h, $m); }
                    elseif ($k === 0) { $h = $n; $set($h, $m); }
                    else { $m = $n; $set($h, $m); }
                    if ((strlen($typed) === 2 || $n * 10 >= $top) && $k === 0) { $local->typed = ''; $rt->focusOn($segs[1]->id, true); }
                } else { $local->typed = ''; }
            }
        }
        return $f;
    }

    /**
     * What separates the hours from the minutes in a time pattern, and
     * whether the day period comes before the hours (mygo's timeLayout):
     * the fields (a letter repeated, outside quotes) and the literal text
     * between them.
     * @return array{0: string, 1: bool}
     */
    private static function timeLayout(string $pattern): array
    {
        $items = []; $lit = ''; $quoted = false; $n = strlen($pattern);
        for ($i = 0; $i < $n; $i++) {
            $c = $pattern[$i];
            if ($c === "'") {
                if ($i + 1 < $n && $pattern[$i + 1] === "'") { $lit .= "'"; $i++; } else { $quoted = !$quoted; }
            } elseif (!$quoted && ctype_alpha($c)) {
                $items[] = ['', $lit]; $lit = '';
                while ($i + 1 < $n && $pattern[$i + 1] === $c) { $i++; }
                $items[] = [$c, ''];
            } else {
                $lit .= $c;
            }
        }
        $hour = $minute = $period = -1;
        foreach ($items as $i => [$field]) {
            if (($field === 'h' || $field === 'H' || $field === 'k' || $field === 'K') && $hour < 0) { $hour = $i; }
            elseif ($field === 'm' && $minute < 0) { $minute = $i; }
            elseif (($field === 'a' || $field === 'b' || $field === 'B') && $period < 0) { $period = $i; }
        }
        $sep = ':';
        if ($hour >= 0 && $minute > $hour) { $sep = implode('', array_map(fn($it) => $it[1], array_slice($items, $hour + 1, $minute - $hour - 1))); }
        return [$sep, $period >= 0 && $hour >= 0 && $period < $hour];
    }

    /** mygo's swatches: the colors a picker offers, by name. */
    private const SWATCHES = ['red' => 0xef4444, 'orange' => 0xf97316, 'yellow' => 0xeab308, 'green' => 0x22c55e, 'teal' => 0x14b8a6, 'blue' => 0x3b82f6,
        'indigo' => 0x6366f1, 'purple' => 0xa855f7, 'pink' => 0xec4899, 'brown' => 0x92400e, 'gray' => 0x71717a, 'black' => 0x000000];

    /**
     * A picker of $rgb at $alpha, as mygo's ColorPicker after AppKit's
     * color panel: a square choosing the saturation, across, and the
     * brightness, up, of the hue its slider chooses below it, a slider of
     * opacity, the color in hex, which the user edits, and swatches of
     * common colors. The arrows move across the square while it has the
     * focus. changed() reports a new color.
     */
    public function colorPicker(int &$rgb, float &$alpha): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $panel = $this->column(0, $t->space(2.5))->size($t->space(60), 0);
        $make = fn() => (object)['hsv' => self::toHSV($rgb), 'last' => [$rgb, $alpha], 'hex' => new TextState(self::hexOf($rgb, $alpha)), 'hue' => 0.0, 'opacity' => 0.0];
        $st = $rt?->local($panel->id, $make) ?? $make();
        if ($st->last !== [$rgb, $alpha]) { // the app set another color
            $st->hsv = self::toHSV($rgb); $st->last = [$rgb, $alpha]; $st->hex->text = self::hexOf($rgb, $alpha);
        }
        $set = function (float $h, float $sat, float $v, float $a) use (&$rgb, &$alpha, $st, $panel) {
            $sat = max(0.0, min(1.0, $sat)); $v = max(0.0, min(1.0, $v)); $a = max(0.0, min(1.0, $a));
            $st->hsv = [$h, $sat, $v];
            $next = self::fromHSV($h, $sat, $v);
            if ($next !== $rgb || $a !== $alpha) { $rgb = $next; $alpha = $a; $panel->changed = true; }
            $st->last = [$rgb, $alpha]; $st->hex->text = self::hexOf($rgb, $alpha);
        };
        [$h, $sat, $v] = $st->hsv;
          // The square of saturation and brightness.
          $sq = $this->box(0, $t->space(40), 0)->round($t->radius)->focusable()->cursor(Cursor::Crosshair)->role(Role::SLIDER)->label($this->texts()->get('saturation')); $sq->color = null; $sq->ownRing = true;
          if ($rt !== null) {
              if ($rt->dragged($sq->id) !== null && ($r = $rt->rectOf($sq->id)) !== null && $r[2] > 0 && $r[3] > 0) {
                  $set($h, ($rt->input->mouseX - $r[0]) / $r[2], 1 - ($rt->input->mouseY - $r[1]) / $r[3], $alpha);
              }
              if ($rt->focus === $sq->id) {
                  if ($this->shortcut(Key::RIGHT)) { $set($h, $sat + 0.01, $v, $alpha); }
                  elseif ($this->shortcut(Key::LEFT)) { $set($h, $sat - 0.01, $v, $alpha); }
                  elseif ($this->shortcut(Key::UP)) { $set($h, $sat, $v + 0.01, $alpha); }
                  elseif ($this->shortcut(Key::DOWN)) { $set($h, $sat, $v - 0.01, $alpha); }
              }
          }
          [$h, $sat, $v] = $st->hsv;
          $sq->range(0, 100, round($sat * 100), 1)->value($this->texts()->get('colorValue', ['saturation' => round($sat * 100) / 100, 'brightness' => round($v * 100) / 100]));
          $hue = self::fromHSV($h, 1, 1);
          $ring = $rt?->focusVisible($sq->id) ?? false;
          $sq->draw(function (DisplayList $list, Node $a, Theme $t) use ($hue, $sat, $v, $ring) {
              $list->gradient2($a->x, $a->y, $a->w, $a->h, 0xffffff, 1.0, $hue, 1.0, false, $t->radius);
              $list->gradient2($a->x, $a->y, $a->w, $a->h, 0x000000, 0.0, 0x000000, 1.0, true, $t->radius);
              $cx = $a->x + $a->w * $sat; $cy = $a->y + $a->h * (1 - $v); $d = $t->space(3.5); // the color chosen, ringed
              $list->ring($cx - $d / 2, $cy - $d / 2, $d, $d, 0xffffff, 1.0, $d / 2, 2.0);
              $list->ring($cx - $d / 2 - 1, $cy - $d / 2 - 1, $d + 2, $d + 2, 0x000000, 0.3, $d / 2 + 1, 1.0);
              if ($ring) { Painter::paintFocusRing($list, $a->x, $a->y, $a->w, $a->h, $t->radius, $t); }
          });
          // The hue, and the opacity.
          $st->hue = $h; $st->opacity = $alpha;
          $hs = $this->channelSlider($st->hue, 360, 'Hue', function (DisplayList $list, float $x, float $y, float $w, float $h) {
              $hues = [0xff0000, 0xffff00, 0x00ff00, 0x00ffff, 0x0000ff, 0xff00ff, 0xff0000];
              $sw = $w / 6;
              for ($i = 0; $i < 6; $i++) { $list->gradient2($x + $sw * $i, $y, $sw + 0.5, $h, $hues[$i], 1.0, $hues[$i + 1], 1.0); }
          });
          if ($hs->changed) { $set($st->hue, $sat, $v, $alpha); }
          $opaque = self::fromHSV($h, $sat, $v);
          $as = $this->channelSlider($st->opacity, 1, 'Opacity', function (DisplayList $list, float $x, float $y, float $w, float $h) use ($opaque, $t) {
              self::checkers($list, $x, $y, $w, $h, $t->space(1.5));
              $list->gradient2($x, $y, $w, $h, $opaque, 0.0, $opaque, 1.0);
          });
          if ($as->changed) { $set($h, $sat, $v, $st->opacity); }
          // The color, and its hex.
          $this->row(0, $t->space(2))->align(Node::CENTER);
            $now = $rgb; $nowA = $alpha;
            $well = $this->box($t->space(7), $t->space(7), 0)->round($t->radius)->border()->role(Role::NONE); $well->color = $t->surface;
            $well->draw(function (DisplayList $list, Node $a, Theme $t) use ($now, $nowA) {
                $list->pushClip($a->x + 1, $a->y + 1, $a->w - 2, $a->h - 2);
                self::checkers($list, $a->x + 1, $a->y + 1, $a->w - 2, $a->h - 2, $t->space(1.5));
                $list->rect($a->x + 1, $a->y + 1, $a->w - 2, $a->h - 2, $now, $nowA, $t->radius - 1);
                $list->popClip();
            });
            $in = $this->input($st->hex, '', 0)->label($this->texts()->get('hex'));
            $typed = $st->hex->seen !== null && $st->hex->seen !== $st->hex->text; $st->hex->seen = $st->hex->text;
            if ($typed && ($parsed = self::parseHex($st->hex->text)) !== null) {
                $hex = $st->hex->text;
                [$ph, $ps, $pv] = self::toHSV($parsed[0]); $set($ph, $ps, $pv, $parsed[1]);
                $st->hex->text = $hex; $st->hex->seen = $hex; // as typed
            }
            if (!$in->focused && $st->hex->text !== self::hexOf($rgb, $alpha)) { $st->hex->text = self::hexOf($rgb, $alpha); $st->hex->seen = $st->hex->text; }
          $this->end();
          // Swatches: a grid of six columns, Space(1.5) apart, Space(5) high, the chosen bordered 2 in the text color.
          $this->grid(6, $t->space(1.5));
            foreach (self::SWATCHES as $name => $color) {
                $said = $this->texts()->get('colorName', ['color' => $name]); // "Red", in the app's language
                $b = $this->key($name)->row(0, 0, 0, $color)->size(0, $t->space(5))->round($t->radius)->focusable()->tooltip($said)->label($said);
                if ($rgb === $color && $alpha === 1.0) { $b->border($t->text, 2.0); }
                $this->end();
                if ($this->clicked($b)) { [$ph, $ps, $pv] = self::toHSV($color); $set($ph, $ps, $pv, 1.0); }
            }
          $this->end();
        $this->end();
        return $panel;
    }

    /** mygo's channelSlider: a slider of a picker's channel from 0 to $max named $name, Space(4) high, padded by half its Space(3) knob, whose track $track paints in the rect it is given. */
    private function channelSlider(float &$v, float $max, string $name, Closure $track): Node
    {
        $t = $this->theme;
        $s = $this->sliderBase($v, 0, $max)->label($name)->paddingX($t->space(3) / 2);
        $s->grow = 1; $s->fh = $t->space(4);
        $s->held = $this->animate($s, 'held', ($this->runtime?->sliding($s->id) ?? false) ? 1 : 0, 0.15);
        $frac = $max > 0 ? $v / $max : 0.0; $held = $s->held; $ring = $this->runtime?->focusVisible($s->id) ?? false;
        $s->draw(function (DisplayList $list, Node $n, Theme $t) use ($track, $frac, $held, $ring) {
            $kw = $t->space(3);
            $bx = $n->x; $by = $n->y + $t->space(0.5); $bw = $n->w; $bh = $n->h - $t->space(1);
            $list->pushClip($bx, $by, $bw, $bh); $track($list, $bx, $by, $bw, $bh); $list->popClip(); // the track, in a capsule's clip were there one; the gradients fill the rect
            $x = $n->x + $kw / 2 + ($n->w - $kw) * $frac;
            $g = 1 + 0.2 * $held; $w = $kw * $g; $h = $n->h * $g; $kx = $x - $w / 2; $ky = $n->y + $n->h / 2 - $h / 2;
            $list->shadow($kx, $ky, $w, $h, 0x000000, 0.3, $w / 2, 3, 0, 1);
            $list->rect($kx, $ky, $w, $h, 0xffffff, 1.0, $w / 2);
            if ($ring) { Painter::paintFocusRing($list, $kx, $ky, $w, $h, $w / 2, $t); }
        });
        return $s;
    }

    /** mygo's checkers: the gray squares, $size wide, that show transparency, over white. */
    public static function checkers(DisplayList $list, float $x, float $y, float $w, float $h, float $size): void
    {
        $list->rect($x, $y, $w, $h, 0xffffff);
        for ($yy = $y, $row = 0; $yy < $y + $h; $yy += $size, $row++) {
            for ($xx = $x, $col = 0; $xx < $x + $w; $xx += $size, $col++) {
                if (($row + $col) % 2 === 0) { $list->rect($xx, $yy, min($size, $x + $w - $xx), min($size, $y + $h - $yy), 0xcccccc); }
            }
        }
    }

    /**
     * A swatch of $rgb at $alpha that opens a colorPicker below it, as
     * mygo's ColorWell after AppKit's: a click, Enter or Space opens it,
     * Escape or a click outside closes it. changed() reports a new color.
     */
    public function colorWell(int &$rgb, float &$alpha): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $b = $this->row(0, 0, 0, $t->surface)->padding($t->space(1), $t->space(1))->round($t->radius)->border()->focusable()
            ->role(Role::COLOR_WELL)->value(self::hexOf($rgb, $alpha));
        $b->description = self::hexOf($rgb, $alpha); // read as its value where buttons have none
        if ($this->hovered($b)) { $b->color = $rt->input->mouseDown ? $t->surfacePressed : $t->surfaceHover; }
          $now = $rgb; $nowA = $alpha;
          $sw = $this->box($t->space(9), $t->space(5), 0)->round($t->space(1))->role(Role::NONE); $sw->color = null;
          $sw->draw(function (DisplayList $list, Node $a, Theme $t) use ($now, $nowA) {
              $list->pushClip($a->x, $a->y, $a->w, $a->h);
              self::checkers($list, $a->x, $a->y, $a->w, $a->h, $t->space(1.25));
              $list->rect($a->x, $a->y, $a->w, $a->h, $now, $nowA, $t->space(1));
              $list->popClip();
          });
        $this->end();
        $make = fn() => (object)['open' => false];
        $local = $rt?->local($b->id, $make) ?? $make();
        if ($this->clicked($b)) { $local->open = !$local->open; }
        $b->expanded($local->open);
        $this->popoverBase($b, $local->open, function (Node $panel) use (&$rgb, &$alpha, $b, $t) { // the picker keeps its own width
            $this->stylePanel($panel);
            $this->column(0, 0)->padding($t->space(3) - $t->space(1), $t->space(3) - $t->space(1)); // the panel's Space(1) and this: Padding(Space(3))
              if ($this->colorPicker($rgb, $alpha)->changed) { $b->changed = true; }
            $this->end();
        });
        return $b;
    }

    /** The hue (0..360), saturation and value (0..1) of $rgb, mygo's toHSVA. */
    public static function toHSV(int $rgb): array
    {
        $r = (($rgb >> 16) & 255) / 255; $g = (($rgb >> 8) & 255) / 255; $b = ($rgb & 255) / 255;
        $hi = max($r, $g, $b); $lo = min($r, $g, $b); $d = $hi - $lo;
        $h = 0.0;
        if ($d == 0) { }
        elseif ($hi == $r) { $h = 60 * fmod(($g - $b) / $d, 6); }
        elseif ($hi == $g) { $h = 60 * (($b - $r) / $d + 2); }
        else { $h = 60 * (($r - $g) / $d + 4); }
        if ($h < 0) { $h += 360; }
        return [$h, $hi > 0 ? $d / $hi : 0.0, $hi];
    }

    /** The color of a hue, saturation and value, mygo's hsva.color. */
    public static function fromHSV(float $h, float $s, float $v): int
    {
        $c = $v * $s; $hh = fmod($h, 360) / 60; $y = $c * (1 - abs(fmod($hh, 2) - 1));
        [$r, $g, $b] = match (true) { $hh < 1 => [$c, $y, 0], $hh < 2 => [$y, $c, 0], $hh < 3 => [0, $c, $y], $hh < 4 => [0, $y, $c], $hh < 5 => [$y, 0, $c], default => [$c, 0, $y] };
        $m = $v - $c;
        $byte = fn(float $x) => (int)round(max(0, min(1, $x)) * 255);
        return ($byte($r + $m) << 16) | ($byte($g + $m) << 8) | $byte($b + $m);
    }

    /** $rgb as #rrggbb, or #rrggbbaa when not opaque. */
    public static function hexOf(int $rgb, float $alpha): string
    {
        $rgb &= 0xffffff; // a wide color's sRGB twin
        return $alpha >= 1.0 ? sprintf('#%06x', $rgb) : sprintf('#%06x%02x', $rgb, (int)round($alpha * 255));
    }

    /** The [rgb, alpha] of #rgb, #rgba, #rrggbb or #rrggbbaa (the # optional, around spaces), or null (mygo's parseHex). */
    public static function parseHex(string $s): ?array
    {
        $s = trim($s);
        if (str_starts_with($s, '#')) { $s = substr($s, 1); }
        if (!ctype_xdigit($s)) { return null; }
        return match (strlen($s)) {
            3 => [(int)hexdec($s[0] . $s[0] . $s[1] . $s[1] . $s[2] . $s[2]), 1.0],
            4 => [(int)hexdec($s[0] . $s[0] . $s[1] . $s[1] . $s[2] . $s[2]), hexdec($s[3] . $s[3]) / 255], // #rgba, as CSS
            6 => [(int)hexdec($s), 1.0],
            8 => [(int)hexdec(substr($s, 0, 6)), hexdec(substr($s, 6)) / 255],
            default => null,
        };
    }

    /**
     * A check box over the check boxes $fn builds, indented below it, as
     * mygo's CheckboxGroup (a list of permissions): checked when they all
     * are, mixed when some are, and a click, or Space while it has the
     * focus, checks them all, or none when they all are. changed() reports
     * a click.
     */
    public function checkboxGroup(string $label, Closure $fn): Node
    {
        $t = $this->theme;
        $g = $this->column(0, $t->space(2));
          $dummy = false; $head = $this->checkbox($label, $dummy); // its click is read below, its state set from the boxes
          $saved = $this->checks; $this->checks = [];
          $this->row(0, 0);
            $this->column(0, 0)->size($t->space(6), 0); $this->end(); // PaddingStart(Space(6))
            $this->column(0, $t->space(2), 1)->role(Role::GROUP)->label($label); $fn(); $this->end();
          $this->end();
          $boxes = $this->checks; $this->checks = $saved;
          $on = 0; foreach ($boxes as $b) { if ($b) { $on++; } }
          $all = $on === count($boxes) && $on > 0;
          if ($head->changed) { // its click, as checkbox() took it
              foreach ($boxes as &$b) { $b = !$all; } unset($b);
              $g->changed = true; $head->changed = false;
              $all = !$all; $on = $all ? count($boxes) : 0;
          }
          $head->on = $all; $head->mixed = !$all && $on > 0;
          $head->checked = $all ? 2 : ($on > 0 ? 3 : 1);
        $this->end();
        return $g;
    }

    /**
     * A path of $items separated by chevrons, as mygo's Breadcrumbs after
     * Finder's path bar: the items but the last are links, which a click
     * chooses, as Enter does, setting $chosen to its index, which
     * changed() reports. Long items shorten with an ellipsis as room runs
     * short.
     * @param list<string> $items
     */
    public function breadcrumbs(array $items, int &$chosen): Node
    {
        $t = $this->theme;
        $e = $this->row(0, $t->space(0.5))->align(Node::CENTER)->role(Role::GROUP);
          $n = count($items);
          foreach ($items as $i => $item) {
              if ($i > 0) { // a chevron pointing along the path, stroked 1.2 in the muted color
                  $this->box($t->space(3), $t->space(3), 0)->draw(function (DisplayList $list, Node $a, Theme $t) {
                      $cx = $a->x + $a->w / 2; $cy = $a->y + $a->h / 2; $d = $a->w / 6;
                      $list->line($cx - $d, $cy - 2 * $d, $cx + $d, $cy, $t->textMuted, 1.0, 1.2);
                      $list->line($cx + $d, $cy, $cx - $d, $cy + 2 * $d, $t->textMuted, 1.0, 1.2);
                  })->alpha = 0.0;
              }
              $last = $i === $n - 1;
              $b = $this->key($i)->row(0, 0)->padding($t->space(0.5), $t->space(1.5))->round($t->radius)->min($t->space(6), 0)->singleLine(); // shrinks with the others as room runs short
              if (!$last) { $b->focusable()->role(Role::LINK); if ($this->hovered($b)) { $b->color = $t->surfaceHover; } }
                $this->text($item, $t->fontSize, $last ? $t->text : $t->textMuted, $last ? Font::BOLD : 0)->singleLine(); // the last, where the path is: no link, weight 500 (bold here)
              $this->end();
              if (!$last && $this->clicked($b)) { $chosen = $i; $e->changed = true; }
          }
        $this->end();
        return $e;
    }

    /** What alertDialog returns in the frame Escape closes the alert, choosing no button. */
    public const ALERT_DISMISSED = -2;

    /**
     * An alert over the window while $open, as mygo's AlertDialog after
     * AppKit's: $title, $message and $buttons, the last the default, in
     * the accent with the focus, which Enter clicks. Escape closes it
     * choosing no button, whatever the buttons say; a click outside does
     * nothing. Returns the index of the button clicked in the frame it
     * is, which closes the alert, ALERT_DISMISSED in the frame Escape
     * closes it, and -1 otherwise. An alert that must be answered sets
     * $open back to true on ALERT_DISMISSED.
     */
    public function alertDialog(bool &$open, string $title, string $message, string ...$buttons): int
    {
        $rt = $this->runtime;
        $this->key('alert'); // keyed: it comes and goes, and its state must not be another node's
        if (!$open) { // closed, by a button, Escape or the app: the next opening focuses the default again
            $local = $rt?->local($this->nextId(), fn() => (object)['opening' => true]);
            if ($local !== null) { $local->opening = true; }
            $this->nextKey = null;
            return -1;
        }
        $t = $this->theme;
        $chosen = -1;
        $back = $this->column(0, 0, 0, 0x000000)->at(0, 0)->size($this->width, $this->height)->center()->block();
        $back->alpha = 0.4;
          $panel = $this->column($t->space(5), $t->space(3), 0, $t->background)->size(min($t->space(75), $this->width - $t->space(10)), 0)->round($t->space(2.5))->shadow(0, 10, 30, 0, 0x000000, 0.3)
              ->role(Role::ALERT_DIALOG)->label($title);
          $panel->description = $message;
            $this->text($title, $t->fontSize + 1, $t->text, Font::BOLD);
            if ($message !== '') { $this->text($message, $t->fontSize, $t->textMuted); }
            $this->column(0, 0)->size(0, $t->space(2)); $this->end(); // Margin(Space(2), 0, 0, 0) on the buttons
            $this->row(0, $t->space(2))->justify(Node::END);
              $make = fn() => (object)['opening' => true];
              $local = $rt?->local($back->id, $make) ?? $make();
              foreach ($buttons as $i => $label) {
                  $b = $i === count($buttons) - 1 ? $this->primaryButton($label) : $this->button($label);
                  if ($i === count($buttons) - 1 && $local->opening && $rt !== null) { $local->opening = false; $rt->focusOn($b->id); } // AutoFocus: the default, which Enter clicks
                  if ($this->clicked($b)) { $chosen = $i; }
              }
            $this->end();
          $this->end();
        $this->end();
        if ($chosen < 0 && $this->overlayShortcut($back, Key::ESCAPE)) { $chosen = self::ALERT_DISMISSED; } // the dialog on top's
        if ($chosen !== -1) { $open = false; }
        return $chosen;
    }

    /**
     * A scroll container of $n items in a grid, as mygo's GridView after
     * Photos' library and Finder's icon view, built with $item($i): as
     * many columns as fit items at least $minWidth wide, which share the
     * width left, and rows $height high, built only as they show, so
     * grids of millions of items are as fast as small ones; Space(2)
     * spaces the items. With $s->selects, a click chooses an item, as the
     * arrows do in two dimensions while the grid has the focus, with Home,
     * End and the first letters of an item's $s->label; changed() reports
     * a new choice, submitted() a double click or Enter.
     */
    public function gridView(GridState $s, int $n, float $minWidth, float $height, Closure $item): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $n = max($n, 0);
        $gap = $t->space(2);
        $id = $this->nextId(); // the list's node, built below: its width last frame gives the columns
        $width = $rt?->rectOf($id)[2] ?? 0.0;
        if ($width <= 0) { $width = $this->width; }
        $cols = max(1, (int)floor(($width + $gap) / ($minWidth + $gap)));
        if ($s->cols !== $cols && $s->cols > 0 && $s->started) { // keep the rows in view on the same items
            $s->rows->scrollTo(intdiv($s->rows->row * $s->cols, $cols), false);
        }
        $s->cols = $cols; $s->started = true;
        $rows = intdiv($n + $cols - 1, $cols);
        $focused = $s->selects && ($rt?->focused($id) ?? false);
        $changed = false; $submitted = false;
        $cursor = null; // the item chosen, which has the focus for assistive technology
        $grid = $this->list($s->rows, $rows, function (int $r) use ($s, $n, $cols, $rows, $gap, $height, $item, $t, $rt, $focused, &$changed, &$submitted, $id, &$cursor) {
            $this->row(0, $gap)->padding($gap / 2, 0)->align(Node::STRETCH)->role(Role::NONE);
              for ($i = $r * $cols; $i < min($n, ($r + 1) * $cols); $i++) {
                  // A border, clear unless chosen, keeps the content in place as it is.
                  $cell = $this->key($i)->column(0, 0, 1)->basis(0)->minWidth(0)->padding(2, 2)->size(0, $height)->round($t->radius)->role(Role::LIST_ITEM); // the items share the width (Grow(1).Basis(0).MinWidth(0))
                  $cell->setPos = $i + 1; $cell->setSize = $n;
                  if ($s->label !== null) { $cell->label(($s->label)($i)); }
                  if ($s->selects) {
                      $chosen = $s->chosen($i);
                      $cell->checked($chosen); $cell->choosable = true;
                      if ($i === $s->selected) { $cursor = $cell; }
                      if ($chosen && $focused) { $cell->color = Theme::mix($t->accent, $t->background, 0.8); $cell->border($t->accent, 2.0); } // Accent.Alpha(0.2)
                      elseif ($chosen) { $cell->color = $t->surfacePressed; $cell->border($t->border, 2.0); }
                      elseif ($this->hovered($cell)) { $cell->color = $t->surfaceHover; }
                      if ($i === $s->selected && $s->selection !== null && !$chosen && $rt?->focusVisible($id)) { // where the keys moved without choosing
                          $cell->draw(fn(DisplayList $list, Node $a, Theme $t) => Painter::paintFocusRing($list, $a->x + 3, $a->y + 3, $a->w - 6, $a->h - 6, $a->radius, $t));
                      }
                  }
                    $item($i);
                  $this->end();
                  if ($rt !== null && $s->reorder !== null) { // a press on the cell, moved 4 points, drags it (and the items chosen with it)
                      $d = $rt->dragged($cell->id);
                      if ($d !== null) {
                          if ($s->drag === null) {
                              $cr = $rt->rectOf($cell->id) ?? [0, 0, 0, 0];
                              $s->drag = ['items' => $s->draggedItems($i, $n), 'item' => $i, 'ox' => $rt->input->mouseX - $cr[0], 'oy' => $rt->input->mouseY - $cr[1], 'dist' => 0.0, 'far' => false];
                          }
                          $s->drag['dist'] += hypot($d[0], $d[1]);
                          $s->drag['far'] = $s->drag['far'] || $s->drag['dist'] >= 4;
                      }
                      if ($s->drag !== null && $s->drag['far'] && in_array($i, $s->drag['items'], true)) { self::fade($cell, 0.4); }
                  }
                  if ($rt !== null && $s->drag !== null && $s->drag['far']) {
                      $rt->clicked($cell->id); // the release that ends a drag is no click
                  } elseif ($s->selects && $rt !== null) {
                      if ($this->doubleClicked($cell)) { $submitted = true; }
                      if ($this->clicked($cell)) { $changed = $this->gridChoose($s, $i, $n, $rt->clickMods) || $changed; $rt->focusOn($id); }
                  }
              }
              for ($k = $n; $k < ($r + 1) * $cols && $r === $rows - 1; $k++) { $this->spacer(); } // empty places keep the last row's items as wide as the others
            $this->end();
        });
        if ($s->selects) { $grid->focusable = true; $grid->choosesItems = true; }
        $grid->rowRole = Role::NONE; $grid->setSize = $n; $grid->chooseMany = $s->selection !== null; // its items, not its rows, are the list's
        if ($cursor !== null) { $grid->activeDescendant($cursor); }
        if ($s->selects && $rt !== null && $rt->focus === $grid->id && $n > 0) { // the keys: the arrows in two dimensions, Home, End, Enter, the first letters
            $at = $s->selected; $in = $at >= 0 && $at < $n; $to = -1; $extend = false;
            [$back, $forth] = $this->arrows($grid);
            foreach ([[$forth, 1], [$back, -1], [Key::DOWN, $cols], [Key::UP, -$cols]] as [$key, $d]) {
                $plain = $this->shortcut($key); $shift = $s->selection !== null && $this->shortcut($key, Key::SHIFT);
                if (!$plain && !$shift) { continue; }
                $extend = $shift;
                if (!$in) { $to = 0; }
                elseif ($at + $d >= 0 && $at + $d < $n) { $to = $at + $d; }
                elseif ($d === $cols && intdiv($at, $cols) < intdiv($n - 1, $cols)) { $to = $n - 1; } // the last row, shorter
            }
            if ($this->shortcut(Key::HOME)) { $to = 0; }
            if ($this->shortcut(Key::END)) { $to = $n - 1; }
            if ($s->selection !== null && $this->shortcut('a', Key::COMMAND) && $s->selection->selectAll($n, $s->keyOf(...))) { $changed = true; }
            if ($rt->activated($grid->id) && $in) { $submitted = true; }
            if ($s->label !== null && ($typed = $rt->typed($grid->id)) !== '') {
                $now = $rt->now / 1e9;
                if ($now - $s->typedAt >= 1.0) { $s->typed = ''; }
                $s->typed .= $typed; $s->typedAt = $now;
                if (($j = self::typedRow($s->typed, $in ? $at : -1, $n, $s->label)) >= 0) { $to = $j; }
            }
            if ($to >= 0) {
                if ($extend) {
                    if ($s->selection->pivot < 0 || !$in) { $s->selection->pivotAt(max($at, 0), $s->keyOf(...)); }
                    $changed = $s->selection->extend($to, $n, $s->keyOf(...)) || $changed;
                    $s->selected = $to;
                } else {
                    $changed = $this->gridChoose($s, $to, $n, 0) || $changed;
                }
                $s->scrollIntoView($to);
            }
        }
        $grid->changed = $changed;
        if ($submitted) { $s->rows->submit(); }
        if ($s->drag !== null && $rt !== null) { $this->reorderItems($s, $grid, $n, $cols, $gap); }
        return $grid;
    }

    /** The items of a grid being dragged (GridState::$drag), as reorderRows does a list's rows: the line beside the cell they would go before, the ghost, the scrolling, the drop (mygo's GridState.reorder and dropAt). */
    private function reorderItems(GridState $s, Node $grid, int $n, int $cols, float $gap): void
    {
        $rt = $this->runtime;
        $drag = &$s->drag;
        $x = $rt->input->mouseX; $y = $rt->input->mouseY;
        // The item before which the items dropped go, and the cell of last frame the line goes beside, on which side: the cell under the pointer, or the nearest in its row; below the cells, past the last.
        $best = -1; $dist = INF; $cellK = null; $cellR = null;
        foreach ($grid->kids as $rk => $row) {
            foreach ($row->kids as $ck => $cell) {
                $r = $rt->rectOf($cell->id);
                if ($r === null || $y < $r[1] || $y >= $r[1] + $r[3]) { continue; }
                $d = abs($x - ($r[0] + $r[2] / 2));
                if ($d < $dist) { $best = ($s->rows->first + $rk) * $cols + $ck; $dist = $d; $cellK = [$rk, $ck]; $cellR = $r; }
            }
        }
        if ($best < 0) { $to = $n; $after = true; $last = $n - 1; $cellK = [intdiv($last, $cols) - $s->rows->first, $last % $cols]; }
        else { $after = $x >= $cellR[0] + $cellR[2] / 2; $to = $after ? $best + 1 : $best; }
        if (!$rt->held()) { // dropped: an item already there stays
            if ($drag['far'] && !(count($drag['items']) === 1 && ($to === $drag['items'][0] || $to === $drag['items'][0] + 1))) {
                ($s->reorder)($drag['items'], $to);
            }
            $drag = null;
            return;
        }
        if (!$drag['far']) { return; }
        $grid->drawOver(function (DisplayList $dl, Node $g, Theme $t) use ($cellK, $after, $gap) {
            $cell = $g->kids[$cellK[0]]->kids[$cellK[1]] ?? null;
            if ($cell === null) { return; }
            $lx = $after ? $cell->x + $cell->w + $gap / 2 : $cell->x - $gap / 2;
            $dl->rect($lx - 1, $cell->y + 4, 2, $cell->h - 8, $t->accent, 1.0, 1.0);
        });
        $this->dragScroll($grid, $s->rows, $y);
        $k = $drag['item'] - $s->rows->first * $cols;
        $src = $grid->kids[intdiv($k, $cols)]->kids[$k % $cols] ?? null;
        if ($src !== null && $k >= 0) { $this->dragGhost($src, count($drag['items']), $x - $drag['ox'], $y - $drag['oy']); }
    }

    /**
     * An SVG as an icon, as mygo's Icon: its shapes in the color of the
     * text around it (or $color, or its own textColor()), whatever colors
     * the SVG has, as high as the font size around it (or $size), keeping
     * its aspect ratio; given another size, it fits in it, centered.
     * Parse the SVG once, not in the view.
     */
    public function icon(SVG $svg, ?float $size = null, ?int $color = null): Node
    {
        $in = $this->inheritedText();
        $h = $size ?? $in->size;
        $color ??= $in->color;
        $n = $this->box($h * $svg->w / $svg->h, $h, 0); $n->color = null; // the box paints no face of its own; its alpha is the icon's (fade())
        $n->svg = $svg; // an image to assistive technology once labeled; else decoration
        $n->draw(function (DisplayList $list, Node $a, Theme $t, TextPainter $text) use ($svg, $color): void {
            $rgb = $a->ts?->color ?? $color ?? $t->text;
            Painter::paintIcon($list, $text, $svg, $a->x, $a->y, $a->w, $a->h, $a->gray ? Theme::luminance($rgb) : $rgb, $a->alpha, $a->rotation);
        });
        return $n;
    }

    /**
     * A picture of a person or a thing named $name, as mygo's Avatar: the
     * $image cropped to a circle, or the initials of $name on a color it
     * picks from it, 2.25 times the font size high.
     */
    public function avatar(string $name, ?Image $image = null): Node
    {
        $t = $this->theme;
        $size = $t->fontSize * 2.25;
        if ($image !== null) {
            $e = $this->image($image, $size, $size, $size / 2)->tooltip($name)->role(Role::IMAGE)->label($name);
            $e->fit = Node::COVER; // the shorter side fills the circle, the rest is cropped
            return $e;
        }
        $h = 2166136261; // the hue of the name's FNV-1a hash
        for ($i = 0, $n = strlen($name); $i < $n; $i++) { $h = (($h ^ ord($name[$i])) * 16777619) & 0xffffffff; }
        $e = $this->column(0, 0, 0, self::hsl($h % 360, 0.45, 0.55))->size($size, $size)->round($size / 2)->center()->tooltip($name)->role(Role::IMAGE)->label($name);
          $this->text(self::initials($name), $size * 0.4, 0xffffff, Font::BOLD)->singleLine(); // FontWeight(600)
        $this->end();
        return $e;
    }

    /** The first letters of the first and last words of $name, upper case. */
    public static function initials(string $name): string
    {
        $first = ''; $last = ''; $inWord = false;
        foreach (mb_str_split($name) as $r) {
            $letter = preg_match('/[\p{L}\p{N}]/u', $r) === 1;
            if ($letter && !$inWord) { if ($first === '') { $first = mb_strtoupper($r); } else { $last = mb_strtoupper($r); } }
            $inWord = $letter;
        }
        return $first . $last;
    }

    /** A color of hue (0..360), saturation and lightness (0..1), mygo's hslColor. */
    public static function hsl(float $h, float $s, float $l): int
    {
        $c = (1 - abs(2 * $l - 1)) * $s; $x = $c * (1 - abs(fmod($h / 60, 2) - 1)); $m = $l - $c / 2;
        [$r, $g, $b] = match (true) { $h < 60 => [$c, $x, 0], $h < 120 => [$x, $c, 0], $h < 180 => [0, $c, $x], $h < 240 => [0, $x, $c], $h < 300 => [$x, 0, $c], default => [$c, 0, $x] };
        return ((int)(($r + $m) * 255) << 16) | ((int)(($g + $m) * 255) << 8) | (int)(($b + $m) * 255);
    }

    /** Chooses item $i of a grid as a click with $mods does (mygo's GridState.click); whether the choice changed. */
    private function gridChoose(GridState $s, int $i, int $n, int $mods): bool
    {
        if ($s->selection !== null) { return $s->selection->click($i, $mods, $n, $s->keyOf(...), $s->selected); }
        if ($s->selected === $i) { return false; }
        $s->selected = $i;
        return true;
    }

    /** mygo's matching: the options starting with $query (ignoring case) first, then those containing it; all for an empty query. */
    public static function matching(array $options, string $query): array
    {
        $q = strtolower(trim($query));
        if ($q === '') { return $options; }
        $starts = []; $contains = [];
        foreach ($options as $o) {
            $l = strtolower($o);
            if (str_starts_with($l, $q)) { $starts[] = $o; } elseif (str_contains($l, $q)) { $contains[] = $o; }
        }
        return array_merge($starts, $contains);
    }

    /**
     * A panel built by $fn below $anchor (a node built this frame) while
     * $open, as mygo's Popover: a press outside the panel and the anchor,
     * or Escape, sets $open false, which dismissed() reports; the press goes
     * on to what is under it. Where there is no room below, the panel
     * shows above. It is built over everything else, at the window's root.
     */
    public function popover(Node $anchor, bool &$open, Closure $fn): ?Node
    {
        if (!$open) {
            return null;
        }
        $t = $this->theme;
        $rt = $this->runtime;
        $saved = $this->toRoot(); // at the root: over the view, placed in the window
        $panel = $this->key('popover:' . $anchor->id)->column(); // keyed as popoverBase's: what is built after it keeps its ids, the editor its focus under the notes' link popover
        $this->stylePanel($panel);
        $panel->attachTo($anchor, Anchor::BottomStart, Anchor::TopStart)->minWidth($rt?->rectOf($anchor->id)[2] ?? 0)->role(Role::POPUP); // at the anchor's start, the right right to left; above it where there is no room below
          $fn();
        $this->end();
        $this->back($saved);
        if ($rt !== null && ($this->shortcut(Key::ESCAPE) || ($rt->pressedAnywhere() && !$rt->pressed($panel->id) && !$rt->pressed($anchor->id)))) {
            $open = false;
            $panel->dismissed = true;
        }
        return $panel;
    }

    /**
     * A plain button with a chevron that drops a menu below it as the
     * pointer goes down on it, or for Enter, Space or Down while it has the
     * focus, which $build fills as Node::menu's (mygo's MenuButton):
     *
     *     $c->menuButton('Sort by', function (Menu $m) use ($app) {
     *         foreach (['Name', 'Date', 'Size'] as $by) {
     *             if ($m->item($by)->checked($app->sort === $by)->chosen()) { $app->sort = $by; }
     *         }
     *     });
     * @param Closure(Menu): void $build
     */
    public function menuButton(string $label, Closure $build): Node
    {
        $n = $this->button($label);
        $n->fw += $this->theme->space(1.5) + $this->theme->space(2.5); // room for the chevron
        $n->chevron = true;
        return $n->menu($build);
    }

    /** A menu button as menuButton() makes, whose menu myphp draws in the window with the theme's look (mygo's PopoverMenuButton; Node::popoverMenuButton). @param Closure(Menu): void $build */
    public function popoverMenuButton(string $label, Closure $build): Node
    {
        $n = $this->button($label);
        $n->fw += $this->theme->space(1.5) + $this->theme->space(2.5); // room for the chevron
        $n->chevron = true;
        return $n->popoverMenuButton($build);
    }

    /**
     * A field for a search, as mygo's SearchField: a glass at its left,
     * "Search" while empty, and a button clearing it at its right while it
     * holds any, as Escape does while it has the focus. changed() reports
     * a change of its text, $state->submitted Enter. A $width of 0 fills
     * what the row has left.
     */
    public function searchField(TextState $state, float $width = 200, ?float $size = null): Node
    {
        $t = $this->theme;
        $size ??= $t->fontSize;
        // mygo's field(): padding Space(1.5) by Space(2), the glass, the input and the clear button Space(1.5) apart.
        $glass = $t->space(3.75); $clear = $t->space(4); $pad = $t->space(2); $gap = $t->space(1.5);
        $parent = $this->stack[count($this->stack) - 1];
        $this->row(0, 0, $width <= 0 && $parent->dir === Node::ROW ? 1 : 0); // 0: fills what the parent has across (a column stretches it; a row needs it to grow)
          $n = $this->input($state, $this->texts()->get('search'), $width, $size)->inset($pad + $glass + $gap - Runtime::FIELD_INSET, $state->text === '' ? $pad - Runtime::FIELD_INSET : $pad + $clear + $gap - Runtime::FIELD_INSET);
          $n->search = true;
          $w = $width > 0 ? $width : ($this->runtime?->rectOf($n->id)[2] ?? 0.0); // as laid out last frame, for the clear button's place
          $this->box($glass, $glass, 0)->at($pad, ($n->fh - $glass) / 2)->draw(self::magnifier(...))->alpha = 0.0;
          if ($state->text !== '') { // the button clearing it: a disc in the muted color at half, with a cross in the background's
              $x = $this->column(0, 0, 0, $t->textMuted)->round($clear / 2)->size($clear, $clear)->at($w - $pad - $clear, ($n->fh - $clear) / 2)->cursor(Cursor::Pointer)->role(Role::BUTTON)->label($this->texts()->get('clear'))->keepFocus()
                  ->draw(function (DisplayList $list, Node $b, Theme $t) {
                      $k = $b->w * 0.3;
                      $list->line($b->x + $k, $b->y + $k, $b->x + $b->w - $k, $b->y + $b->h - $k, $t->background, 1.0, 1.5);
                      $list->line($b->x + $b->w - $k, $b->y + $k, $b->x + $k, $b->y + $b->h - $k, $t->background, 1.0, 1.5);
                  });
              $x->alpha = 0.5;
              $this->end();
              if ($this->clicked($x) || ($this->runtime?->focus === $n->id && $this->shortcut(Key::ESCAPE))) {
                  $state->text = ''; $state->setCursor(0);
              }
          }
        $this->end();
        $n->changed = $state->seen !== null && $state->seen !== $state->text;
        $state->seen = $state->text;
        return $n;
    }

    /** The magnifying glass of a field for searching, as mygo's (MingCute's search icon, Apache 2.0): a ring and a handle stroked 2 on its 24 grid, in the muted color. */
    private static function magnifier(DisplayList $list, Node $g, Theme $t): void
    {
        $k = $g->w / 24; $stroke = 2 * $k;
        $list->ring($g->x + 2 * $k, $g->y + 2 * $k, 12 * $k, 12 * $k, $t->textMuted, 1.0, 6 * $k, $stroke);
        $list->line($g->x + 14.5 * $k, $g->y + 14.5 * $k, $g->x + 20 * $k, $g->y + 20 * $k, $t->textMuted, 1.0, $stroke);
    }

    /**
     * A bar for finding text while $open, as mygo's FindBar after Safari's
     * and Xcode's: a field of $query, which takes the focus as the bar
     * opens with what was found before selected, how many $matches there
     * are, previous and next buttons choosing $current among them, and
     * Done. Enter in the field goes to the next match, Shift+Enter to the
     * previous, as do Cmd+G and Shift+Cmd+G; Escape and Done close the
     * bar. The app finds: it counts the matches of $query and shows match
     * $current, which changed() reports moving. Closed, it builds nothing
     * and returns null.
     */
    public function findBar(bool &$open, TextState $query, int $matches, int &$current): ?Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $bar = $this->key('find bar')->row(0, $t->space(2), 0, $t->surface)->padding($t->space(1.5), $t->space(3))->align(Node::CENTER)->role(Role::TOOLBAR)->label($this->texts()->get('find'));
        $make = fn() => (object)['opening' => true]; // its state goes as it closes: true in the frame it opens
        $local = $rt?->local($bar->id, $make) ?? $make();
        if (!$open) { // nothing: an empty box out of the flow, keeping the bar's key for its state to start over as it opens
            $local->opening = true;
            $bar->at(0, 0)->size(0, 0); $bar->color = null; $bar->padding(0, 0);
            $this->end();
            return null;
        }
        $bar->draw(function (DisplayList $list, Node $b, Theme $t) { $list->rect($b->x, $b->y + $b->h - 1, $b->w, 1, $t->border); }); // BorderWidth(0, 0, 1, 0)
        $current = max(0, min($current, $matches - 1));
        $step = function (int $d) use (&$current, $matches, $bar) { if ($matches > 0) { $current = ($current + $d + $matches) % $matches; $bar->changed = true; } };
        if ($this->shortcut('g', Key::COMMAND)) { $step(1); } elseif ($this->shortcut('g', Key::SHIFT | Key::COMMAND)) { $step(-1); }
          // The field: mygo's field() with a magnifier and the input, grown to at most Space(90).
          $glass = $t->space(3.75);
          $f = $this->row(0, $t->space(1.5), 1, $t->surface)->padding($t->space(1.5), $t->space(2.5))->round($t->radius)->border()->align(Node::CENTER)->cursor(Cursor::Text)->role(Role::NONE)->label($this->texts()->get('find'));
            $this->box($glass, $glass, 0)->draw(self::magnifier(...))->alpha = 0.0;
            [, $h] = $this->metrics->measure('X', $t->fontSize);
            $in = $this->add(new Node(Node::INPUT, 0, 0, 0, 0, $h, 1, $this->texts()->get('find'), null, $t->fontSize, $t->text));
            $in->edit = $query; $in->changed = $query->editedSinceBuilt(); $in->inset(-Runtime::FIELD_INSET, -Runtime::FIELD_INSET); $in->min($t->space(25), 0);
            $in->focused = $rt?->focused($in->id) ?? false;
            $in->search = true; $in->nameFrom = $f;
          $this->end();
          $fw = $rt?->rectOf($bar->id)[2] ?? 0.0;
          if ($fw > 0) { $f->size(min($t->space(90), $fw), 0); $f->grow = 0; } // MaxWidth(Space(90)): at most that of the bar's width last frame
          $f->borderColor = $in->focused ? $t->accent : ($this->hovered($f) ? Theme::mix($t->border, $t->text, 0.25) : null);
          if ($this->clicked($f) && $rt !== null) { $rt->focusOn($in->id); $in->focused = true; }
          if ($local->opening && $rt !== null) { $local->opening = false; $rt->focusOn($in->id); $in->focused = true; $query->selectAll(); }
          if ($query->submitted) { $query->submitted = false; $step(($rt?->submitMods() ?? 0) & Key::SHIFT ? -1 : 1); }
          if ($in->focused && $this->shortcut(Key::ESCAPE)) { $open = false; }
          $status = $query->text === '' ? '' : ($matches > 0 ? $this->texts()->get('matches', ['current' => $current + 1, 'total' => $matches]) : $this->texts()->get('noMatches'));
          $this->row(0, 0)->min($t->space(18), 0); $this->text($status, $t->fontSize, $t->textMuted)->role(Role::STATUS); $this->end();
          foreach ([-1, 1] as $d) { // previous and next: a chevron up or down, Space(3), stroked 1.5
              $btn = $this->key("step$d")->buttonFace($t->space(1.5), $t->space(2))->role(Role::BUTTON)->label($this->texts()->get($d < 0 ? 'previous' : 'next'));
              if ($matches === 0) { $btn->alpha = 0.5; $btn->focusable = false; }
                $this->box($t->space(3), $t->space(3), 0)->draw(function (DisplayList $list, Node $a, Theme $t) use ($d) {
                    $cx = $a->x + $a->w / 2; $cy = $a->y + $a->h / 2; $k = $a->w / 4; $s = $d < 0 ? 1 : -1;
                    $list->line($cx - $k, $cy + $s * $k / 2, $cx, $cy - $s * $k / 2, $t->text, 1.0, 1.5);
                    $list->line($cx, $cy - $s * $k / 2, $cx + $k, $cy + $s * $k / 2, $t->text, 1.0, 1.5);
                })->alpha = 0.0;
              $this->end();
              if ($matches > 0 && $this->clicked($btn)) { $step($d); }
          }
          $done = $this->button($this->texts()->get('done'));
          if ($this->clicked($done)) { $open = false; }
        $this->end();
        return $bar;
    }

    /**
     * A column of the fields $fn builds (field, fieldset), gap Space(3),
     * whose labels sit in a column of their own beside the controls, as
     * macOS's forms: aligned to the right, as wide as the widest, and
     * centered on their control, or on the first line of a text area, as
     * mygo levels them.
     */
    public function form(Closure $fn): Node
    {
        $f = $this->column(0, $this->theme->space(3));
        $saved = $this->form; $this->form = (object)['labels' => [], 'width' => 0.0];
          $fn();
          foreach ($this->form->labels as $box) { $box->size($this->form->width, $box->fh); } // as wide as the widest, measured as they were built
        $this->form = $saved;
        $this->end();
        return $f;
    }

    /**
     * A field, as mygo's Field: $label above the control $fn builds, or
     * beside it in a form; a click on the label focuses the control (or
     * clicks a check box, switch or radio button). $description shows
     * below the control muted, $error in the danger color, which an input
     * then borders in. The control is the first focusable $fn builds.
     */
    public function field(string $label, Closure $fn, string $description = '', string $error = ''): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $inForm = $this->form !== null;
        $f = $inForm ? $this->row(0, $t->space(3))->align(Node::START) : $this->column(0, $t->space(1.5));
          if ($inForm) {
              [$lw] = $this->metrics->measure($label, $t->fontSize);
              $this->form->width = max($this->form->width, $lw);
              $box = $this->row(0, 0)->justify(Node::END)->align(Node::CENTER); $this->form->labels[] = $box;
                $l = $this->text($label, $t->fontSize, $t->text);
              $this->end();
          } else {
              $l = $this->text($label, $t->fontSize, $t->text, Font::BOLD); // FontWeight(500): bold, the one weight besides regular
          }
          $content = $this->column(0, $t->space(1.5), $inForm ? 1 : 0); // in a form: the rest of the row
            $fn();
            if ($description !== '') { $this->text($description, $t->fontSize - 1, $t->textMuted); }
            if ($error !== '') { $this->text($error, $t->fontSize - 1, $t->danger); }
          $this->end();
        $this->end();
        $ctrl = self::fieldControl($content);
        if ($ctrl === null) { return $f; }
        // A control that names itself, as a check box, names the field's group; others are named by the label.
        if (self::namesItself($ctrl)) { $f->role(Role::GROUP)->label($label); } elseif ($ctrl->nameFrom === null || $ctrl->nameFrom->label === '') { $ctrl->nameFrom = $l; }
        if ($error !== '' || $description !== '') { $ctrl->description = implode("\n", array_filter([$error, $description], fn(string $s) => $s !== '')); } // the error first, as what to read first
        if ($error !== '') { $ctrl->invalid = true; }
        if ($inForm && $ctrl->kind === Node::TEXTAREA) { // level with the first line of a text area (mygo's)
            [, $lh] = $this->metrics->measure('X', $ctrl->fontSize);
            $box->fh = $lh + ($ctrl->bare ? 0.0 : 2 * Runtime::FIELD_INSET_Y);
        } elseif ($inForm) { $box->fh = $rt?->rectOf($ctrl->id)[3] ?? 0.0; } // level with the control: as high as it was last frame
        if ($error !== '' && $ctrl->kind === Node::INPUT) { $ctrl->borderColor = $t->danger; }
        $l->cursor = Cursor::Default;
        if ($this->clicked($l) && $rt !== null) {
            $to = self::focusIn($ctrl);
            if ($to !== null) { $rt->focusOn($to->id); }
            if (in_array($ctrl->kind, [Node::CHECKBOX, Node::SWITCH, Node::RADIO], true)) { $rt->press($ctrl->id); } // clicked next frame, as mygo's clickLater
        }
        return $f;
    }

    /** Whether a field's control names itself to assistive technology, by its label or its text (mygo's namesItself). */
    private static function namesItself(Node $e): bool
    {
        if ($e->label !== '') { return true; }
        return match ($e->role) {
            Role::BUTTON, Role::LINK, Role::CHECK_BOX, Role::RADIO, Role::SWITCH, Role::TOGGLE_BUTTON, Role::MENU_BUTTON, Role::TAB, Role::DISCLOSURE => Runtime::innerText($e) !== '',
            Role::AUTO => $e->kind === Node::BUTTON && Runtime::innerText($e) !== '',
            default => false,
        };
    }

    /** The control of a field: the first node inside $e that takes the focus, or a group of them (a focus group). */
    private static function fieldControl(Node $e): ?Node
    {
        foreach ($e->kids as $k) {
            if ($k->focusGroup !== 0) { return $k; }
            if ($k->focusable || $k->kind === Node::BUTTON || $k->kind === Node::INPUT || $k->kind === Node::TEXTAREA || $k->kind === Node::SLIDER) { return $k; }
            if (($c = self::fieldControl($k)) !== null) { return $c; }
        }
        return null;
    }

    /** What a click on the label of a field focuses: its control, or in a group of them, the one on, else the first. */
    private static function focusIn(Node $e): ?Node
    {
        if ($e->focusable || $e->kind === Node::BUTTON || $e->kind === Node::INPUT || $e->kind === Node::TEXTAREA || $e->kind === Node::SLIDER) { return $e; }
        $first = null; $chosen = null;
        $walk = function (Node $e) use (&$walk, &$first, &$chosen) {
            foreach ($e->kids as $k) {
                if ($chosen !== null) { return; }
                if ($k->focusable || $k->kind === Node::BUTTON) { $first ??= $k; if ($k->on) { $chosen = $k; } }
                $walk($k);
            }
        };
        $walk($e);
        return $chosen ?? $first;
    }

    /**
     * A group of the fields $fn builds under $legend (bold), as mygo's
     * Fieldset: gap Space(3), and Space(3) apart from what is above it, as
     * a section. In a form, its fields' labels line up with the others'.
     */
    public function fieldset(string $legend, Closure $fn): Node
    {
        $t = $this->theme;
        $parent = $this->stack[count($this->stack) - 1];
        $above = $parent->kids !== [];
        if ($above) { $this->column(0, 0)->size(0, $t->space(3)); $this->end(); } // Margin(Space(3), 0, 0, 0)
        $g = $this->column(0, $t->space(3))->role(Role::GROUP)->label($legend);
          $this->text($legend, $t->fontSize, $t->text, Font::BOLD); // FontWeight(600)
          $fn();
        $this->end();
        return $g;
    }

    /**
     * A dialog built by $fn over the dimmed window while $open, as mygo's
     * Modal: what is behind it is inert, Tab goes round what is inside;
     * a click outside the panel or Escape sets $open false, which
     * dismissed() reports.
     */
    public function modal(bool &$open, Closure $fn): ?Node
    {
        if (!$open) {
            return null;
        }
        $t = $this->theme;
        $back = $this->key('modal')->column(0, 0, 0, 0x000000)->at(0, 0)->size($this->width, $this->height)->center()->block(); // keyed: it comes and goes
        $back->alpha = 0.4;
          $panel = $this->column($t->space(5), $t->space(3), 0, $t->background)->round($t->space(2.5))->shadow(0, 10, 30, 0, 0x000000, 0.3)->role(Role::DIALOG);
            $fn();
          $this->end();
        $this->end();
        if ($this->overlayShortcut($back, Key::ESCAPE) || ($this->clicked($back) && !$this->hovered($panel))) { // Escape is the dialog on top's
            $open = false;
            $back->dismissed = true;
        }
        return $back;
    }

    /**
     * Asks screen readers to read $text out once, after what they are
     * reading, for news the keyboard focus does not bring, as a search
     * done or a file saved (mygo's Announce): a toast announces its text.
     * It does nothing while no assistive technology reads the window.
     */
    public function announce(string $text): void
    {
        $this->runtime?->announce($text);
    }

    /** Shows $message near the bottom of the window for a few seconds, as the outcome of what the user just did: one already showing shows anew, others stack above it, three at most; it shows while the pointer rests on it; screen readers read it out. addToast with $message as the id and the title (mygo's Toast). */
    public function toast(string $message): void
    {
        $this->addToast(new Toast($message, $message));
    }

    /** Shows $message as toast() does, for longer, with a button of $label that runs $action, as Undo after deleting, and closes the toast; Tab reaches the button (mygo's ToastAction). */
    public function toastAction(string $message, string $label, Closure $action): void
    {
        $this->addToast(new Toast($message, $message, timeout: Runtime::ACTION_TOAST_FOR, action: $label, onAction: $action));
    }

    /** Shows $t near the bottom of the window, as toast() does, and returns its id, to close it with closeToast (mygo's AddToast). */
    public function addToast(Toast $t): string
    {
        return $this->runtime?->addToast($t) ?? $t->id;
    }

    /** Closes the toast of $id, or every toast for '', running their onClose (mygo's CloseToast). */
    public function closeToast(string $id = ''): void
    {
        $this->runtime?->closeToast($id);
    }

    /** Whether this pass built the toasts already (toastViewportBase), which toasts() then leaves. */
    private bool $toastsBuilt = false;

    /**
     * Builds the window's toasts without a look, in place of the theme's
     * (mygo's ToastViewportBase): $fn(Node $viewport, list<Toast> $toasts)
     * styles $viewport, a column over the window the pointer goes through,
     * holding them at its bottom, and builds each with toastBase(). Call
     * it once in the view. Their time stops while the pointer rests on the
     * viewport, the focus is in it or the window is in the background.
     */
    public function toastViewportBase(Closure $fn): Node
    {
        return $this->toastViewport(true, $fn);
    }

    /** The toasts' viewport, none while no toast shows unless $keep. */
    private function toastViewport(bool $keep, Closure $fn): ?Node
    {
        $rt = $this->runtime;
        if ($rt === null || $this->toastsBuilt) {
            return null;
        }
        $this->toastsBuilt = true;
        $over = false;
        foreach ($rt->toastRoots as $id) { $over = $over || $rt->hovered($id) || $rt->focusWithin($id); } // the pointer resting on one of last frame's toasts, or the focus in one
        $rt->toastRoots = [];
        $rt->pauseToasts($over); // before the toasts whose time is over close
        $list = $rt->toastList();
        if ($list === [] && !$keep) {
            return null;
        }
        $viewport = null;
        $this->overlay(function () use (&$viewport, $fn, $list, $rt): void {
            $viewport = $this->key('myphp.toasts')->column()->absolute()->left(0)->top(0)->right(0)->bottom(0)->justify(Node::END)->passThrough(); // keyed: the identity of the engine's own, not of what a dialog just closed built there

              $fn($viewport, $list);
            $this->end();
        });
        if (!$rt->toastsPaused()) {
            $next = null;
            foreach ($list as $t) {
                [, $left] = $rt->toastTimes($t->id);
                if ($t->life() > 0) { $next = $next === null ? $left : min($next, $left); }
            }
            if ($next !== null) { $rt->after($next); } // a frame closes the toast whose time is over next
        }
        return $viewport;
    }

    /** The toast $t without a look, in the viewport of toastViewportBase: its root, a status that Escape closes while the focus is in it, built in until end() (mygo's ToastBase). */
    public function toastBase(Toast $t): ToastParts
    {
        $rt = $this->runtime;
        $key = $rt?->toastKey($t->id) ?: $t->id;
        [$age, $left] = $rt?->toastTimes($t->id) ?? [0.0, 0.0];
        $root = $this->key('toast/' . $key)->column()->role(Role::STATUS);
        if ($rt !== null) { $rt->toastRoots[] = $root->id; }
        if ($this->focusWithin($root) && $this->shortcut(Key::ESCAPE)) {
            $this->closeToast($t->id);
        }
        return new ToastParts($root, $this, $t->id, $age, $left);
    }

    /** The window's toasts in the theme's look, unless the view built them with toastViewportBase: the inverse of the theme, fading in and out, with the action's button (mygo's buildToasts). Window builds them after the view. */
    public function toasts(): void
    {
        $t = $this->theme;
        [$fill, $text] = $t->inverse();
        $this->toastViewport(false, function (Node $viewport, array $toasts) use ($t, $fill, $text): void {
            $viewport->bottom($t->space(6))->align(Node::CENTER);
            $viewport->gap = $t->space(2);
            foreach ($toasts as $ts) {
                $p = $this->toastBase($ts);
                $box = $p->root;
                $box->dir = Node::ROW; $box->gap = $t->space(4);
                $box->align(Node::CENTER)->padding($t->space(2.5), $t->space(4))->round($t->space(2))->maxWidth($this->width - $t->space(12))->background($fill)->textColor($text)->shadow(0, 6, 20, 0, 0x000000, 0.25);
                $fade = 0.2; $opacity = 1.0;
                if (!$this->runtime->toastsPaused()) {
                    if ($p->age() < $fade) {
                        $opacity = $p->age() / $fade; $this->runtime->animate();
                    } elseif ($p->left() > 0 && $p->left() < $fade) {
                        $opacity = $p->left() / $fade; $this->runtime->animate();
                    } elseif ($p->left() > 0) {
                        $this->runtime->after($p->left() - $fade);
                    }
                }
                $box->opacity($opacity);
                if ($ts->description === '') {
                    $this->text($ts->title);
                } else {
                    $this->column(0, $t->space(0.5))->shrink(1);
                      $this->text($ts->title)->fontWeight(600);
                      $this->text($ts->description)->textColor($text, 0.75);
                    $this->end();
                }
                if ($ts->action !== '') {
                    $b = $p->actionButton()->padding($t->space(1), $t->space(2.5))->round($t->radius)->textColor(Theme::mix($t->accent, $text, 0.35))->fontWeight(600);
                    if ($this->hovered($b)) { $b->background($text, 0.12); }
                      $this->text($ts->action);
                    $this->end();
                }
                $this->end();
            }
        });
    }

    /**
     * A table of $count rows under a header of $columns, as mygo's Table:
     * the rows are a list's (only those in view are built, $state keeps
     * the place and the choice, the keys move it), $cell($row, $col)
     * builds a cell's content, usually a text, $col the column's index in
     * $columns. Rows are as high as their tallest cell and at least
     * Space(8); cells pad Space(1.5) by Space(2.5).
     *
     * The user resizes a column by dragging the end edge of its header (the
     * right, the left right to left), fits it to its cells with a double
     * click there, and moves it by dragging the header: $state->columns
     * keeps the order and the widths. Columns wider than the table scroll
     * sideways, the header with them. A click on a sortable column's header
     * sorts by it ($state->sort; the app sorts its rows and sortChanged()
     * reports a click); with $state->sortBy, a Cmd- or Shift-click sorts by
     * it after the others.
     * @param list<TableColumn> $columns
     */
    public function table(ListState $state, array $columns, int $count, Closure $cell): Node
    {
        $t = $this->theme;
        $rowH = $t->space(8); $flexMin = $t->space(15);
        $rt = $this->runtime;
        $n = $this->column(0, 0, 1)->role(Role::TABLE);
        $n->chooseMany = $state->selection !== null; // assistive technology hears that its rows are chosen several at a time
        $drag = $rt?->local($n->id, fn() => new TableDrag()) ?? new TableDrag();
        $state->syncSort();
        $layout = $state->columns;
        $order = $layout->arrange($columns);
        $least = 0.0; // the least width of the rows: the columns', those sharing the room left at their least
        foreach ($order as $j) { $least += $layout->width($columns[$j]) ?? max($columns[$j]->minWidth, $flexMin); }
        $fitting = []; // the cells of the column fitting its cells
        $cells = function (Closure $fill, int $role) use ($columns, $order, $layout, $t, $flexMin, $drag, &$fitting): array {
            $boxes = [];
            foreach ($order as $j) {
                $col = $columns[$j];
                $w = $layout->width($col);
                // Keyed by its column, a cell keeps its state as the columns move: a header being dragged keeps the press.
                $box = $this->key('c' . $col->id())->row(0, 0, $w === null ? 1 : 0)->padding($t->space(1.5), $t->space(2.5))->align(Node::CENTER)->justify($col->align);
                if ($w === null) {
                    $box->size(max($col->minWidth, $flexMin), 0)->min(max($col->minWidth, $flexMin), 0); // a shared column measures its least width and grows: its content does not push it (mygo's Shrink(1))
                } else {
                    $box->size($w, 0)->shrink(0);
                }
                $box->role = $role;
                  $fill($j);
                $this->end();
                if ($drag->fit !== null && $drag->fit === $col->id()) { $fitting[] = $box; }
                $boxes[] = $box;
            }
            return $boxes;
        };
        // The header, which scrolls sideways with the rows: a click sorts by a sortable column, a drag moves a column, a drag at a header's end edge resizes it.
        $head = $this->row(0, 0)->align(Node::STRETCH)->size(0, $rowH)->role(Role::ROW)->clip();
        $edges = [];
        $heads = $cells(function (int $j) use ($columns, $state, $t, &$edges, $layout, $order) {
            $col = $columns[$j];
            $this->text($col->title, $t->fontSize, $t->textMuted, Font::BOLD);
            $place = $col->sortable ? $state->sortPlace($col->id()) : null;
            if ($place !== null) {
                if ($state->sortBy !== null && count($state->sortBy) > 1) { // its place among the columns sorted by, from 1
                    $this->text((string)($place[0] + 1), $t->fontSize * 0.75, $t->textMuted)->role(Role::NONE)->marginStart($t->space(1));
                }
                $this->text($place[1] ? '▾' : '▴', $t->fontSize - 2, $t->textMuted)->role(Role::NONE)->marginStart($t->space(1));
            }
            if (!$col->fixed && !($layout->width($col) === null && $j === $order[count($order) - 1])) { // the last column sharing the room left ends at the table's end: an edge there would resize nothing, as AppKit's has none (mygo's 976f17c)
                $edges[$j] = $this->key('g' . $col->id())->column()->absolute()->top(0)->bottom(0)->end(0)->size($t->space(2), 0)->cursor(Cursor::ResizeEW)->role(Role::NONE);
                $this->end();
            }
        }, Role::COLUMN_HEADER);
        if ($state->headerMenu !== null && $rt !== null) { // the header's context menu, told the column under the pointer, -1 past them (mygo's HeaderMenu)
            $head->contextMenu(function (\MyPHP\UI\Menu $m) use ($state, $heads, $order, $rt): void {
                $col = -1;
                foreach ($heads as $k => $h) {
                    $r = $rt->rectOf($h->id);
                    if ($r !== null && $rt->input->mouseX >= $r[0] && $rt->input->mouseX < $r[0] + $r[2]) { $col = $order[$k]; }
                }
                ($state->headerMenu)($m, $col);
            });
        }
        foreach ($order as $k => $j) {
            $col = $columns[$j]; $h = $heads[$k]; $id = $col->id();
            $h->label = $col->title; // the title alone, not the arrow beside it
            if ($col->sortable) {
                $place = $state->sortPlace($id);
                $h->sort = $place === null ? 0 : ($place[1] ? 2 : 1);
                if ($this->hovered($h)) { $h->color = $t->surfaceHover; }
            }
            if ($rt === null) {
                continue;
            }
            $edge = $edges[$j] ?? null;
            if ($edge !== null) { // asked first: a press on the edge resizes the column rather than moves it
                $this->clicked($edge); // its clicks are not the header's
                if ($this->doubleClicked($edge)) { $drag->fit = $id; }
                $d = $this->dragged($edge);
                if ($d !== null && $d[0] != 0) {
                    $dx = $edge->rtl ? -$d[0] : $d[0]; // the edge is on the left right to left: dragging it left widens
                    // The columns before it sharing the room left keep their widths, for the edge to follow the pointer.
                    foreach ($order as $k2 => $j2) {
                        if ($k2 === $k) { break; }
                        $r2 = $rt->rectOf($heads[$k2]->id);
                        if ($layout->width($columns[$j2]) === null && $r2 !== null) { $state->setWidth($columns[$j2], $r2[2]); }
                    }
                    $state->setWidth($col, ($layout->width($col) ?? $rt->rectOf($h->id)[2] ?? 0.0) + $dx);
                }
                $hot = $d !== null || $this->hovered($edge);
                $rtl = $edge->rtl;
                $color = $hot ? $t->accent : $t->border;
                $edge->draw(static function (DisplayList $list, Node $e) use ($rtl, $color): void {
                    $x = $rtl ? $e->x + 0.5 : $e->x + $e->w - 0.5;
                    $list->line($x, $e->y + $e->h / 4, $x, $e->y + $e->h * 3 / 4, $color, 1.0, 1.0);
                });
            }
            if (!$col->fixed) { // moving the column, once the pointer went a few points
                $d = $this->dragged($h);
                if ($d !== null) {
                    if ($drag->id !== $id) { $drag->id = $id; $drag->dx = 0.0; $drag->moving = false; }
                    $drag->dx += $d[0];
                    if (!$drag->moving && abs($drag->dx) > 4) { $drag->moving = true; }
                    if ($drag->moving) {
                        $this->moveColumn($state, $drag, $heads, $order, $columns);
                        $h->left($drag->dx)->opacity(0.9);
                        $h->color = $t->surfacePressed;
                    }
                } elseif ($drag->id === $id) { // released: a click, unless it moved the column
                    $moved = $drag->moving;
                    $drag->id = null; $drag->dx = 0.0; $drag->moving = false;
                    if ($moved) { $this->clicked($h); }
                }
            }
            if ($col->sortable && $this->clicked($h)) {
                $state->sortClicked($id, ($rt->clickMods & (Key::COMMAND | Key::SHIFT)) !== 0); // Cmd-click as Finder, Shift-click as web grids
                $n->changed = true;
            }
        }
        $this->end();
        $this->divider();
        $list = $this->list($state, $count, function (int $i) use ($cells, $cell, $rowH, $t, $state) {
            $row = $this->row(0, 0)->align(Node::STRETCH)->min(0, $rowH)->role(Role::NONE); // as high as its tallest cell, at least the header; the list's row holds its cells
            if ($state->isHeader($i)) { // a section's header spans the columns, built as column 0 (mygo's Header rows)
                $row->padding($t->space(1.5), $t->space(2.5))->align(Node::CENTER)->background($t->surface)->fontWeight(600);
                  $cell($i, 0);
                $this->end();
                return;
            }
            if ($state->roundRows) { // chosen and hovered rows rounded, as a list's; rows chosen together one block, rounded at its ends (mygo's RoundRows)
                $r = $t->radius;
                if ($state->chosen($i)) {
                    $top = $i > 0 && $state->chosen($i - 1) ? 0.0 : $r; $bottom = $i + 1 < $state->count && $state->chosen($i + 1) ? 0.0 : $r;
                    $row->radius($top, $top, $bottom, $bottom);
                } else {
                    $row->round($r);
                }
            }
            if ($state->selected === null && $this->hovered($row)) { $row->color = $t->surfaceHover; } // chosen rows show the pointer already
              $cells(fn(int $j) => $cell($i, $j), Role::CELL);
            $this->end();
        });
        $list->role = Role::NONE; $list->rowRole = Role::ROW; // the table's rows, under it
        $list->rowWidth($least);
        $head->scrollWith($list);
        $this->end();
        if ($drag->fit !== null) { // fits the column to its cells built in this frame and its header, before the table is measured (mygo's tableFit)
            $fit = $drag->fit;
            $n->colFit = static function () use ($fitting, $layout, $drag, $fit): void {
                $drag->fit = null;
                $w = 0.0;
                foreach ($fitting as $box) { $box->fw = 0; Layout::measure($box); $w = max($w, $box->mw); }
                foreach ($fitting as $box) { $box->fw = $w; $box->grow = 0; $box->shrink = 0; }
                $layout->widths[$fit] = $w;
            };
        }
        return $n;
    }

    /** Moves the column being dragged past a neighbor whose middle it passed, keeping it under the pointer (mygo's moveColumn). @param list<Node> $heads @param list<int> $order @param list<TableColumn> $columns */
    private function moveColumn(ListState $state, TableDrag $drag, array $heads, array $order, array $columns): void
    {
        $rt = $this->runtime;
        $at = null;
        foreach ($order as $k => $j) { if ($columns[$j]->id() === $drag->id) { $at = $k; } }
        if ($at === null) {
            return;
        }
        $sign = $heads[$at]->rtl ? -1.0 : 1.0; // the columns after it are to its left right to left
        $dx = $drag->dx * $sign;
        $to = $at;
        if ($dx > 0 && $at + 1 < count($order)) {
            $next = $rt->rectOf($heads[$at + 1]->id);
            if ($next !== null && $next[2] > 0 && $dx > $next[2] / 2) { $to = $at + 1; }
        } elseif ($dx < 0 && $at > 0) {
            $prev = $rt->rectOf($heads[$at - 1]->id);
            if ($prev !== null && $prev[2] > 0 && -$dx > $prev[2] / 2) { $to = $at - 1; }
        }
        if ($to === $at || $columns[$order[$to]]->fixed) {
            return;
        }
        $w = $rt->rectOf($heads[$to]->id)[2] ?? 0.0; // under the pointer still: the column's place moved by the other's width
        $drag->dx += $to > $at ? -$sign * $w : $sign * $w;
        $ids = array_map(fn(int $j) => $columns[$j]->id(), $order);
        [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];
        $state->columns->order = $ids;
    }

    /**
     * Two panes side by side (or $vertical: one above the other) with a
     * divider between them the user drags, or moves with the arrows once
     * it has the focus, as mygo's Split: $size is the first pane's width
     * (height), kept Space(10) from either edge; $first and $second build
     * the panes. With less room than that, the first pane gives way down to
     * Space(10), keeping $size for when there is room again. changed()
     * reports a move.
     */
    public function split(float &$size, Closure $first, Closure $second, bool $vertical = false): Node
    {
        return $this->splitPanes($size, $first, $second, $vertical, false);
    }

    /** split() with $percent the percentage of the room the first pane takes, from 0 to 100, so that both panes grow and shrink with the room, in proportion (mygo's SplitPercent, SplitVerticalPercent). */
    public function splitPercent(float &$percent, Closure $first, Closure $second, bool $vertical = false): Node
    {
        return $this->splitPanes($percent, $first, $second, $vertical, true);
    }

    /** split() and splitPercent(): $size is the first pane's size, or with $inPercent its percentage of the room (mygo's split). */
    private function splitPanes(float &$size, Closure $first, Closure $second, bool $vertical, bool $inPercent): Node
    {
        $t = $this->theme;
        $minPane = $t->space(10); $grip = $t->space(1.5); $step = $t->space(2.5);
        $n = $vertical ? $this->column(0, 0, 1)->align(Node::STRETCH) : $this->row(0, 0, 1)->align(Node::STRETCH);
        $rt = $this->runtime;
        $r = $rt?->rectOf($n->id);
        $total = $r === null ? 0.0 : ($vertical ? $r[3] : $r[2]); // the room to share, as the last frame laid it out
        $fit = fn(float $v): float => max($total > 0 ? min($v, $total - 1 - $minPane) : $v, $minPane); // leaving Space(10) on either side of the divider
        $share = min(max($size, 0.0), 100.0) / 100;
        // The first pane's size as the last frame laid it out: drags and keys move the divider from there, not from $size, which a split too small for it does not show.
        $shown = $size;
        if ($inPercent && $total > 0) { $shown = $fit($share * ($total - 1)); }
        elseif (!$inPercent && $total > 0 && $size > $minPane) { $shown = $fit($size); }
        $set = function (float $v) use (&$size, $total, $fit, $inPercent, $n) {
            $v = $fit($v);
            if ($inPercent) {
                if ($total <= 1) { return; }
                $v = $v * 100 / ($total - 1);
            }
            if ($v != $size) { $size = $v; $n->changed = true; }
        };
        $length = fn(Node $p, float $v) => $vertical ? $p->height($v) : $p->width($v);
        $least = fn(Node $p, float $v) => $vertical ? $p->minHeight($v) : $p->minWidth($v);
          // The layout sizes the panes, not $shown: it uses this frame's room.
          $a = $this->column(0, 0)->shrink(1);
          if ($inPercent) { $a->grow($share)->basis(0); $least($a, $minPane); } else { $length($a, $size); $least($a, min($size, $minPane)); }
            $first();
          $this->end();
          $div = $this->box($vertical ? 0 : 1, $vertical ? 1 : 0, $t->border)->shrink(0)->focusable()->role(Role::SPLITTER)->label($this->texts()->get('divider')); // the line takes the focus between the panes
          $b = $this->column(0, 0, 1)->shrink(1);
          if ($inPercent) { $b->grow(1 - $share)->basis(0); }
          $least($b, $minPane);
            $second();
          $this->end();
          // The handle the pointer drags spans a few points over either pane, above them, on the divider where this frame lays it out (mygo's placeOn).
          $rtl = !$vertical && $n->rtl; // right to left, the first pane is at the right
          $handle = $this->box(0, 0, 0)->absolute()->cursor($vertical ? Cursor::ResizeNS : Cursor::ResizeEW);
          $handle->placeOn = $div;
          $vertical ? $handle->left(0)->right(0)->top(-($grip - 1) / 2)->height($grip) : $handle->top(0)->bottom(0)->left(-($grip - 1) / 2)->width($grip);
          $handle->alpha = 0.0;
        $this->end();
        if ($rt !== null) {
            $held = $rt->dragged($handle->id);
            if ($held !== null) {
                $rt->focus = $div->id; // a press there focuses the line, as a press on it would
                if ($held[0] != 0 || $held[1] != 0) { // only a move changes the size, so a press keeps $size for when there is room again
                    $set($shown + ($vertical ? $held[1] : ($rtl ? -$held[0] : $held[0]))); // right to left, dragging it left widens the first pane
                }
            }
            if ($rt->focus === $div->id) {
                [$back, $forth] = $vertical ? [Key::UP, Key::DOWN] : $this->arrows($div);
                if ($this->shortcut($back)) { $set($shown - $step); }
                if ($this->shortcut($forth)) { $set($shown + $step); }
            }
            if ($held !== null || $rt->hovered($handle->id)) { $div->color = $t->accent; }
        }
        return $n;
    }

    /** A line across the container: horizontal in a column, vertical in a row ($vertical). */
    public function divider(bool $vertical = false): Node
    {
        return $this->add(new Node(Node::BOX, 0, 0, 0, $vertical ? 1 : 0, $vertical ? 0 : 1, 0, null, $this->theme->border));
    }

    /** Empty room that grows to take what the container has left, pushing what follows to the end. */
    public function spacer(): Node
    {
        return $this->add(new Node(Node::CONTAINER, Node::ROW, 0, 0, 0, 0, 1, null, null));
    }

    /** A spinner showing activity of unknown length, as mygo's: spokes turning, as high as the font size, in the muted text color, or the one textColor() gives it, as on a chosen row. */
    public function spinner(): Node
    {
        $size = $this->theme->fontSize * 1.25;
        return $this->add(new Node(Node::SPINNER, 0, 0, 0, $size, $size, 0, null, null))->role(Role::PROGRESS)->range(0, 1, -1); // of unknown length
    }

    /** A short text in a pill, as mygo's Badge: a count beside a sidebar's item, in the text color at a tenth. */
    public function badge(string $text): Node
    {
        $t = $this->theme;
        $size = $t->fontSize - 2;
        [$w, $h] = $this->metrics->measure($text, $size);
        $n = $this->column(0, 0, 0, $t->text)->round($h / 2)->size($w + $t->space(3), $h)->center(); // Space(1.5) a side
        $n->alpha = 0.1;
          $this->text($text, $size, $t->text);
        $this->end();
        return $n;
    }

    /**
     * Segments to choose one of, as mygo's Segmented: $options' labels in a
     * row, the chosen one raised; changed() reports a choice.
     * @param list<string> $options
     */
    public function segmented(array $options, int &$index): Node
    {
        $t = $this->theme;
        $index = max(0, min($index, count($options) - 1));
        $n = $this->row(1, 0, 0, $t->border)->round($t->radius)->role(Role::NONE); // the track: a border around a surface
          $track = $this->row($t->space(0.5), $t->space(0.5), 0, $t->surface)->round($t->radius - 1)->align(Node::STRETCH)->focusGroup(Node::HORIZONTAL, selects: true)->role(Role::RADIO_GROUP); // a radio group: the arrows choose
          $track->nameFrom = $n; // named by the control's label
          foreach ($options as $i => $label) {
              $seg = $this->column(0, 0, 0, $i === $index ? $t->surfacePressed : null)->padding($t->space(1), $t->space(3))->round(max($t->radius - $t->space(0.5), 0))->focusable()->center();
              $seg->on = $i === $index; // Tab enters the group at the one chosen
              $seg->role(Role::RADIO)->checked($seg->on); $seg->segment = true;
              if ($i !== $index && $this->hovered($seg)) { $seg->color = $t->surfaceHover; }
                $this->text($label, $t->fontSize, $t->text);
              $this->end();
              if ($this->clicked($seg) && $i !== $index) {
                  $index = $i;
                  $n->changed = true;
              }
          }
          $this->end();
        $this->end();
        return $n;
    }

    /**
     * Tabs, as mygo's Tabs: the labels in a row, the chosen one underlined
     * in the accent; the caller builds the chosen tab's content after it.
     * changed() reports a choice.
     * @param list<string> $labels
     */
    public function tabs(array $labels, int &$index): Node
    {
        $t = $this->theme;
        $n = $this->row(0, $t->space(1))->focusGroup(Node::HORIZONTAL, selects: true)->role(Role::TAB_LIST); // a tab list: one stop of Tab, the arrows choose
        foreach ($labels as $i => $label) {
            $on = $i === $index;
            $tab = $this->column(0, 0)->padding($t->space(2), $t->space(3))->round($t->radius)->focusable()
                ->draw(function (DisplayList $list, Node $tab, Theme $t) use ($on) {
                    if ($on) { // the underline, Space(0.5) high, Space(1.5) in from the ends
                        $in = $t->space(1.5); $h = $t->space(0.5);
                        $list->rect($tab->x + $in, $tab->y + $tab->h - $h, $tab->w - 2 * $in, $h, $t->accent, 1.0, $h / 2);
                    }
                });
            $tab->on = $on;
            $tab->role(Role::TAB)->checked($on);
            $this->text($label, $t->fontSize, $on || $this->hovered($tab) ? $t->text : $t->textMuted, $on ? Font::BOLD : 0);
            $this->end();
            if ($this->clicked($tab) && !$on) {
                $index = $i;
                $n->changed = true;
            }
        }
        $this->end();
        return $n;
    }

    /**
     * A disclosure, as mygo's Collapsible (SwiftUI's DisclosureGroup):
     * $label beside an arrow, which a click opens and closes, as do Enter
     * and Space, showing below it what $fn builds while $open. The arrow
     * turns and the content grows into view as it opens; changed()
     * reports a click.
     */
    public function collapsible(string $label, bool &$open, Closure $fn): Node
    {
        $t = $this->theme;
        $root = $this->column(0, 0);
          $head = $this->row(0, $t->space(1))->padding($t->space(1), 0)->round($t->radius)->align(Node::CENTER)->alignSelf(Node::START)->focusable()->role(Role::DISCLOSURE);
          $p = $this->collapsibleToggle($head, $open);
          if ($head->changed) { $root->changed = true; }
          if ($this->hovered($head)) { $head->color = $t->surfaceHover; }
            $this->column(0, 0)->size($t->space(0.5), 0); $this->end(); // PaddingStart(Space(0.5))
            $arrow = $this->box($t->space(4), $t->space(4), 0); $arrow->alpha = 0.0;
            $arrow->draw(fn(DisplayList $list, Node $a, Theme $t) => self::disclosure($list, $a, 90 * $p, $t->textMuted));
            $this->text($label, $t->fontSize, $t->text)->singleLine();
            $this->column(0, 0)->size($t->space(2), 0); $this->end(); // PaddingEnd(Space(2))
          $this->end();
          $this->collapsiblePanel($open, $p, $fn, $t->space(1), $t->space(5.5), 0, $t->space(1), $t->space(2)); // below the label
        $this->end();
        return $root;
    }

    /**
     * The trigger of a collapsible, as mygo's CollapsibleBase: a click on
     * $head, or Enter or Space while it has the focus, or assistive
     * technology, opens and closes it, which $head->changed reports. How
     * far it is open, from 0 closed to 1 open, moving between them over
     * 200 ms as it opens and closes (mygo's Progress).
     */
    private function collapsibleToggle(Node $head, bool &$open): float
    {
        if ($this->clicked($head)) { $open = !$open; $head->changed = true; }
        if (($e = $this->runtime?->expanded($head->id) ?? 0) !== 0 && ($e > 0) !== $open) { $open = $e > 0; $head->changed = true; } // assistive technology opening or closing it
        $head->expanded($open);
        return $this->animate($head, 'open', $open ? 1 : 0, 0.2);
    }

    /**
     * The panel of a collapsible open $p of the way, as mygo's Panel: what
     * $fn builds while $open, and as it opens and closes, when the panel
     * grows and shrinks with $p, cutting off what $fn built; in a column
     * $gap apart, padded $top, $start, $end and $bottom. Nothing while
     * closed.
     */
    private function collapsiblePanel(bool $open, float $p, Closure $fn, float $top, float $start, float $end, float $bottom, float $gap): void
    {
        if (!$open && $p == 0) {
            return;
        }
        $clip = $this->column(0, 0);
          $inner = $this->column(0, 0);
            if ($top > 0) { $this->column(0, 0)->size(0, $top); $this->end(); }
            $this->row(0, 0)->align(Node::START);
              if ($start > 0) { $this->column(0, 0)->size($start, 0); $this->end(); }
              $this->column(0, $gap, 1);
                $fn();
              $this->end();
              if ($end > 0) { $this->column(0, 0)->size($end, 0); $this->end(); }
            $this->end();
            if ($bottom > 0) { $this->column(0, 0)->size(0, $bottom); $this->end(); }
          $this->end();
        $this->end();
        if ($p < 1) {
            // The height the content had in the last frame, which is 0 as it starts to open, and grows as it does; Layout reads a height of 0 as none set.
            $clip->clipY()->size(0, max(1e-3, $p * ($this->runtime?->rectOf($inner->id)[3] ?? 0.0)));
        }
    }

    /** mygo's disclosurePath: a chevron pointing right in box $a, turned $deg clockwise, stroked 1.5 in $color. */
    public static function disclosure(DisplayList $list, Node $a, float $deg, int $color): void
    {
        $cx = $a->x + $a->w / 2; $cy = $a->y + $a->h / 2; $d = $a->w / 8;
        $r = deg2rad($deg); $cos = cos($r); $sin = sin($r);
        $at = fn(float $x, float $y) => [$cx + $x * $cos - $y * $sin, $cy + $x * $sin + $y * $cos];
        [$x0, $y0] = $at(-$d, -2 * $d); [$x1, $y1] = $at($d, 0); [$x2, $y2] = $at(-$d, 2 * $d);
        $list->line($x0, $y0, $x1, $y1, $color, 1.0, 1.5);
        $list->line($x1, $y1, $x2, $y2, $color, 1.0, 1.5);
    }

    /**
     * A stepper, as mygo's and AppKit's beside a field: a bordered column
     * of an up and a down arrow stepping $value by $step within [$min,
     * $max]; a press steps at once and keeps stepping while held, Up and
     * Down step while it has the focus, Home and End go to the ends.
     * changed() reports a new value.
     */
    public function stepper(int|float &$value, float $min, float $max, float $step = 1): Node
    {
        $t = $this->theme;
        $n = $this->column(1, 1, 0, $t->border)->round($t->radius)->focusable()->role(Role::STEPPER);
        $n->size($t->space(5), $t->space(7));
        $ticks = $this->runtime?->stepper($n->id, $n->rtl) ?? 0;
        $jump = $this->runtime?->jumped($n->id) ?? 0;
        $holding = $this->runtime?->holding($n->id) ?? 0;
        $v = $jump !== 0 ? ($jump > 0 ? $max : $min) : max($min, min($max, $value + $ticks * $step));
        foreach ([1 => '▴', -1 => '▾'] as $dir => $arrow) {
            $can = $dir > 0 ? $v < $max : $v > $min;
            $this->column(0, 0, 1, $holding === $dir ? $t->surfacePressed : $t->surface)->round($t->radius - 1)->center();
              $this->text($arrow, $t->fontSize - 2, $can ? $t->text : $t->textMuted)->role(Role::NONE);
            $this->end();
        }
        $this->end();
        if ($v != $value) {
            $value = is_int($value) ? (int)round($v) : $v;
            $n->changed = true;
        }
        $n->range($min, $max, $value, $step);
        return $n;
    }

    /**
     * The radio buttons $fn builds (radio, radioBase), Space(2) apart, a
     * column or, $row, a row: one stop of Tab, which goes to the one
     * chosen, the arrows choosing among them, as do Home and End; seen as
     * a radio group, named with label() (mygo's RadioGroup, its Row()).
     */
    public function radioGroup(Closure $fn, bool $row = false): Node
    {
        $g = ($row ? $this->row(0, $this->theme->space(2)) : $this->column(0, $this->theme->space(2)))
            ->role(Role::RADIO_GROUP)->focusGroup(Node::HORIZONTAL | Node::VERTICAL, selects: true);
          $fn();
        $this->end();
        return $g;
    }

    /** A radio button with a label: on while $value is $option, and sets it when chosen; changed() reports it. */
    public function radio(string $label, mixed &$value, mixed $option, ?float $size = null): Node
    {
        $t = $this->theme;
        $size ??= $t->fontSize;
        [$w, $h] = $this->metrics->measure($label, $size);
        $n = $this->add(new Node(Node::RADIO, 0, 0, 0, $w + $t->space(6), max($h, $t->space(4)), 0, $label, null, $size, $t->text));
        $n->focusable = true;
        if ($this->runtime?->clicked($n->id) && $value !== $option) {
            $value = $option;
            $n->changed = true;
        }
        $n->on = $value === $option;
        $n->role(Role::RADIO)->checked($n->on);
        return $n;
    }

    /**
     * A slider over [$min, $max] moving $value: a press or drag sets it
     * where the pointer is, the arrows move it by a hundredth of the range
     * while it has the focus, Page Up and Page Down by a tenth, Home and
     * End to the ends; changed() reports it.
     */
    public function slider(float &$value, float $min = 0, float $max = 1, float $width = 160): Node
    {
        return $this->sliderOf($value, $min, $max, 0.0, $width);
    }

    /** A slider setting $value to $min or a multiple of $step from it, up to $max, with a tick mark at each (none past 61), as AppKit's sliders with tick marks: dragging snaps to them, and the arrows step by step (mygo's StepSlider). */
    public function stepSlider(float &$value, float $min, float $max, float $step, float $width = 160): Node
    {
        return $this->sliderOf($value, $min, $max, $step, $width);
    }

    /** slider(), or with $step stepSlider(). */
    private function sliderOf(float &$value, float $min, float $max, float $step, float $width): Node
    {
        $t = $this->theme;
        $ticks = self::tickCount($min, $max, $step);
        $n = $this->add(new Node(Node::SLIDER, 0, 0, 0, $width, $t->space($ticks > 0 ? 7 : 5), 0, null, null, $t->fontSize, $t->text));
        $n->focusable = true;
        $n->ticks = $ticks;
        $n->paddingX($t->space(5) / 2); // the knob's half width each side: its center spans the values
        $n->minWidth($t->space(20)); // as mygo's: a slider of no width, growing in a row, keeps a track to drag
        $this->slide($n, $value, $min, $max, $step);
        $n->held = $this->animate($n, 'held', ($this->runtime?->sliding($n->id) ?? false) ? 1 : 0, 0.15);
        return $n;
    }

    /**
     * A slider without a look (mygo's SliderBase): dragging across its
     * content box, inside its padding, sets $value between $min and $max,
     * as the arrows, Home and End do while it has the focus, by a
     * hundredth of the range or $step; changed() reports a new value, and
     * assistive technology sees a slider of the range. Paint its track and
     * knob with draw(), where ($value - $min) / ($max - $min) puts it, and
     * pad it by half the knob's width; right to left $min is at the right.
     * $vertical goes up from $min at the bottom: pad it by half the knob's
     * height.
     */
    public function sliderBase(float &$value, float $min, float $max, float $step = 0.0, bool $vertical = false): Node
    {
        $n = $this->add(new Node(Node::SLIDER, 0, 0, 0, 0, 0, 0, null, null, $this->theme->fontSize, $this->theme->text));
        $n->focusable = true; $n->ownRing = true; $n->vertical = $vertical;
        $n->draw(static function (): void {}); // paints nothing of its own: the app's draw() replaces it
        $this->slide($n, $value, $min, $max, $step);
        return $n;
    }

    /** The input of slider $n: the pointer, the arrows by a step, Home and End to the ends; its range for assistive technology. */
    private function slide(Node $n, float &$value, float $min, float $max, float $step): void
    {
        $by = $step > 0 ? $step : ($max - $min) / 100; // the arrows move a step, or a hundredth of the range
        $was = $value;
        if ($this->runtime !== null) {
            $f = $this->runtime->slid($n->id);
            if ($f !== null) {
                $value = self::snap($min + $f * ($max - $min), $min, $max, $step);
            }
            $steps = $this->runtime->stepped($n->id);
            if ($steps !== 0) {
                $value = self::snap(max($min, min($max, $value + $steps * $by)), $min, $max, $step);
            }
            $pages = $this->runtime->paged($n->id);
            if ($pages !== 0) { // Page Up and Page Down: a tenth of the range, or a step when more
                $value = self::snap(max($min, min($max, $value + $pages * max($by, ($max - $min) / 10))), $min, $max, $step);
            }
            if (($j = $this->runtime->jumped($n->id)) !== 0) {
                $value = $j < 0 ? $min : self::snap($max, $min, $max, $step);
            }
            if ($value != $was) { $n->changed = true; } // only a value of its own, as mygo's set(): a move to where it is, an arrow at an end, is none
        }
        $n->fraction = $max > $min ? max(0.0, min(1.0, ($value - $min) / ($max - $min))) : 0.0;
        $n->role(Role::SLIDER)->range($min, $max, $value, $by);
    }

    /** $v rounded to $lo or a multiple of $step from it, within $hi, to the decimals of the step and of $lo, as browsers round their number inputs; $v itself for no step (mygo's snap). */
    public static function snap(float $v, float $lo, float $hi, float $step): float
    {
        if ($step <= 0) {
            return $v;
        }
        $v = $lo + round(($v - $lo) / $step) * $step;
        if ($v > $hi) { $v -= $step; }
        $decimals = static function (float $x): int { // after the point, written as briefly as it reads back the same
            $s = var_export(abs($x), true);
            if (preg_match('/^\d+(?:\.(\d+))?E([+-]?\d+)$/i', $s, $m)) { return max(strlen(rtrim($m[1], '0')) - (int)$m[2], 0); }
            $at = strpos($s, '.');
            return $at === false ? 0 : strlen(rtrim(substr($s, $at + 1), '0'));
        };
        $d = max($decimals($step), $decimals($lo));
        return $d >= 15 ? $v : round($v, $d);
    }

    /** How many tick marks a slider of $step shows, 0 for none or too many to tell apart (mygo's tickCount). */
    public static function tickCount(float $lo, float $hi, float $step): int
    {
        if ($step <= 0 || $hi <= $lo) {
            return 0;
        }
        $n = (int)floor(($hi - $lo) / $step + 1e-9) + 1;
        return $n > 61 ? 0 : $n;
    }

    /**
     * A drop-down choosing one of $options (labels) into $index, as mygo's
     * Select on SelectBase: a button showing the choice and a chevron,
     * whose popup below it lists the options, the choice on the surface
     * and the one the pointer or the arrows are on in the accent; a click,
     * Enter or Space chooses, Escape or a press outside closes it.
     * changed() reports a choice. $width sets its width, at least
     * Space(35) by default.
     * @param list<string> $options
     */
    public function select(array $options, int &$index, ?float $width = null): Node
    {
        $t = $this->theme;
        $sel = $this->selectBase($index);
        $b = $sel->trigger;
        $b->padding($t->space(1.5), $t->space(3.5))->round($t->radius)->border()->justify(Node::SPACE_BETWEEN)->min($t->space(35), 0)->background($t->surface); // mygo's styleButton, at least Space(35) wide whatever width it is given, as mygo's MinWidth
        if ($width !== null) { $b->width($width); }
        if ($this->hovered($b)) { $b->color = $this->runtime->input->mouseDown ? $t->surfacePressed : $t->surfaceHover; }
        $b->gap = $t->space(1.5);
        $label = $options[$index] ?? '';
        $b->value($label);
          $this->text($label, $t->fontSize, $t->text)->singleLine();
          $this->chevron();
        $this->end();
        $sel->popup(function (Node $panel) use ($sel, $options, $index, $t): void {
            $this->stylePanel($panel);
            $panel->scroll($this->runtime?->local($panel->id, fn() => new ScrollState) ?? new ScrollState); // options past the room the window has scroll (mygo's 39a5543): the panel is no taller than it (Layout::roomAlong)
            foreach ($options as $i => $opt) {
                $item = $sel->item($i)->padding($t->space(1.5), $t->space(2.5))->round($t->radius);
                $fg = $t->text;
                if ($item->highlighted) { $item->background($t->accent); $fg = $t->accentText; }
                elseif ($i === $index) { $item->background($t->surface); }
                  $this->text($opt, $t->fontSize, $fg)->singleLine();
                $this->end();
            }
        });
        return $b;
    }

    /** The arrow of a button opening something below it, Space(2.5) square in the muted text color (mygo's chevron). */
    private function chevron(): Node
    {
        $a = $this->box($this->theme->space(2.5), $this->theme->space(2.5), 0)->shrink(0);
        $a->color = null;
        $a->draw(fn(DisplayList $list, Node $a, Theme $t) => Painter::chevron($list, $a->x, $a->y, $a->w, $t->textMuted));
        return $a;
    }

    /** Gives the panel of a popup the theme's look: Space(1) below what opens it, padded Space(1), rounded, bordered, shadowed (mygo's stylePanel). */
    private function stylePanel(Node $panel): void
    {
        $t = $this->theme;
        $panel->margin($t->space(1), 0, 0, 0)->padding($t->space(1))->round($t->radius + 2)->background($t->background)->border()->shadow(0, 6, 20, 0, 0x000000, 0.18);
    }

    /**
     * A single-line text field editing $state. Click or Tab gives it the
     * keyboard; the text input client of the window then edits $state. A
     * $width of 0 fills what the row has left.
     */
    public function input(TextState $state, string $placeholder = '', float $width = 200, ?float $size = null): Node
    {
        $t = $this->theme;
        $size ??= $t->fontSize;
        [, $h] = $this->metrics->measure('X', $size);
        $fh = $h + $t->space(3) + 2; // mygo's: padding Space(1.5) by Space(2.5) and its border, a point each side, as a plain button's
        $n = $this->add(new Node(Node::INPUT, 0, 0, 0, $width, $fh, $this->growsAlong($width, $fh), $placeholder, $t->surface, $size, $t->text)); // the surface, bordered; 0: fills a row's remaining width, a column's across
        $n->bordered = true;
        $n->edit = $state;
        $n->changed = $state->editedSinceBuilt(); // typed, deleted, pasted or undone in since the last build (mygo's Changed)
        $n->metrics = $this->metrics; // a style of its own (fontSize()) measures its line again in layout
        $state->password = false; // until password() says otherwise, this frame
        $n->focused = $this->runtime?->focused($n->id) ?? false;
        return $n;
    }

    /**
     * A text area editing $state: wraps at its width, scrolls with the wheel
     * and to keep the caret in view, Enter inserts a line, Up/Down move by
     * wrapped lines, Cmd+Z undoes. A $width or $height of 0 fills the room
     * its container has left along its axis (grow), or stretches across
     * it, as its align says. With $maxLines, the
     * area is as high as its text wrapped at its width, from $minLines up
     * to $maxLines, past which it scrolls, as a message field grows with
     * what is typed (mygo's Lines): $height is then the least it is.
     */
    public function textarea(TextState $state, string $placeholder = '', float $width = 320, float $height = 120, ?float $size = null, int $minLines = 0, int $maxLines = 0): Node
    {
        $t = $this->theme;
        $state->multiline = true;
        $size ??= $t->fontSize;
        if ($maxLines > 0) { // as high as its lines, between min and max: wrapped at the width it had last frame when it grows
            $id = $this->nextId(Node::TEXTAREA);
            $w = $width > 0 ? $width : ($this->runtime?->rectOf($id)[2] ?? $this->width);
            $lines = $state->layout($this->metrics, $size, $w - 2 * Runtime::FIELD_INSET);
            $lines->measureFirst($maxLines); // the lines past those it shows need not be wrapped, as mygo's wrappedHeight
            $height = max($height, max($minLines, min($maxLines, $lines->count())) * $lines->lineHeight + 2 * Runtime::FIELD_INSET_Y);
        }
        $n = $this->add(new Node(Node::TEXTAREA, 0, 0, 0, $width, $height, $this->growsAlong($width, $height), $placeholder, $t->surface, $size, $t->text));
        $n->edit = $state;
        $n->changed = $state->editedSinceBuilt(); // typed, deleted, pasted or undone in since the last build (mygo's Changed)
        $n->metrics = $this->metrics; // a style of its own (fontSize()) measures its line again in layout
        $n->focused = $this->runtime?->focused($n->id) ?? false;
        return $n;
    }

    /** The grow of a control of size $w × $h, 0 for a side the room decides: 1 where that side is along its container, which it then fills; across one, it stretches as its container's align does (a column's width). */
    private function growsAlong(float $w, float $h): int
    {
        $parent = $this->stack[count($this->stack) - 1];
        return ($parent->dir === Node::ROW ? $w : $h) <= 0 ? 1 : 0;
    }

        /** input() editing a TextBuffer (mygo's TextInputBuffer): edits replace what changed in it, a change of the program's shows. */
    public function textInputBuffer(TextBuffer $buffer, string $placeholder = '', float $width = 200, ?float $size = null): Node
    {
        return $this->bufferControl($buffer, fn(TextState $s) => $this->input($s, $placeholder, $width, $size), Node::INPUT);
    }

    /** textarea() editing a TextBuffer (mygo's TextAreaBuffer), for large documents. */
    public function textAreaBuffer(TextBuffer $buffer, string $placeholder = '', float $width = 320, float $height = 120, ?float $size = null): Node
    {
        return $this->bufferControl($buffer, fn(TextState $s) => $this->textarea($s, $placeholder, $width, $height, $size), Node::TEXTAREA);
    }

    /** Whether the field's Enter was pressed this frame (resets the flag). */
    public function submitted(Node $n): bool
    {
        if ($n->edit !== null && $n->edit->submitted) {
            $n->edit->submitted = false;
            return true;
        }
        return false;
    }

    /** An image, or an SVG in its own colors (currentColor the text color), at $w × $h points (its own size when 0), corners rounded by $radius; $alpha fades it. */
    public function image(Image|SVG $image, float $w = 0, float $h = 0, float $radius = 0, float $alpha = 1.0): Node
    {
        if ($image instanceof SVG) {
            if ($w <= 0 && $h <= 0) { $w = $image->w; $h = $image->h; } elseif ($w <= 0) { $w = $h * $image->w / $image->h; } elseif ($h <= 0) { $h = $w * $image->h / $image->w; }
            $n = $this->add(new Node(Node::IMAGE, 0, 0, 0, $w, $h, 0, null, null));
            $n->svg = $image; $n->radius = $radius; $n->alpha = $alpha;
            return $n;
        }
        if ($w <= 0 && $h <= 0) {
            $w = $image->w / $this->scale; $h = $image->h / $this->scale;
        } elseif ($w <= 0) {
            $w = $h * $image->w / $image->h;
        } elseif ($h <= 0) {
            $h = $w * $image->h / $image->w;
        }
        $n = $this->add(new Node(Node::IMAGE, 0, 0, 0, $w, $h, 0, null, null));
        $n->image = $image; $n->radius = $radius; $n->alpha = $alpha;
        return $n;
    }

    public function box(float $w, float $h, int $color): Node
    {
        return $this->add(new Node(Node::BOX, 0, 0, 0, $w, $h, 0, null, $color));
    }

    /**
     * Shows a web view, made with Window::newWebView, in the node's content
     * box (mygo's ui.WebView). A web view has no size of its own: the node
     * stretches across its container, and takes the room along it with
     * grow() or a size:
     *
     *     $c->row()->grow(1);
     *       sidebar($c);
     *       $c->webView($app->docs)->grow(1);
     *     $c->end();
     *
     * The web view shows while a frame builds its node and hides when one
     * does not, keeping its page. The node paints a hole through the
     * window's content (the web view is under it), as rounded as its
     * radius and cut by the scroll containers and clips around it, so that
     * what paints after it shows over the page: popovers, menus, dialogs,
     * tooltips and toasts. The page takes the pointer where it shows and
     * nothing painted over it takes it, and the keyboard once clicked,
     * focused (WebView::focus) or tabbed into: Tab stops at the node. Its
     * background and border paint around the page, which its opacity
     * fades.
     */
    public function webView(\MyPHP\WebView $v): Node
    {
        $n = $this->add(new Node(Node::BOX, 0, 0, 0, 0, 0, 0, null, null));
        $n->webView = $v;
        $n->self = Node::STRETCH;
        $n->focusable = true; // Tab stops at it, to give its page the keyboard, which shows its own focus
        $n->ownRing = true;
        return $n;
    }

    /**
     * Shows the picture of a player (MyPHP\Media\Player) in the node's
     * content box, fitted and centered in it, black around it. Like a web
     * view's node, it has no size of its own and stretches across its
     * container; it paints a hole through the window's content, the
     * system showing the picture under it, so that what is built over it
     * shows over the picture (controls, a title, a spinner), rounded by
     * its radius and cut by the scroll containers and clips around it.
     * The picture takes no pointer: the node and what is over it do, as
     * any. It shows while a frame builds its node; one player shows in one
     * window at a time.
     *
     *     $c->column()->grow(1);
     *       $c->video($app->player)->grow(1);
     *       $c->row(12, 8)->absolute()->left(0)->right(0)->bottom(0); // over the picture
     *         controls($c, $app->player);
     *       $c->end();
     *     $c->end();
     */
    public function video(\MyPHP\Media\Player $p): Node
    {
        $n = $this->add(new Node(Node::BOX, 0, 0, 0, 0, 0, 0, null, null));
        $n->video = $p;
        $n->self = Node::STRETCH;
        return $n;
    }

    /**
     * A video player of the film $s plays (VideoPlayerState::open), laid
     * out as IINA's window (UI\VideoPlayer), black before one opens: the
     * picture, its control bar in the system's glass, the title bar over
     * it (for a window whose title bar is hidden), the messages at the top
     * left, the sidebar of quick settings, IINA's keys; $s keeps its state
     * across builds (the title, the volume, the sidebar), $o leaves parts
     * out. It fills what it is built in; the window keeps the picture's
     * shape and hides the traffic lights with the controls.
     */
    public function videoPlayer(VideoPlayerState $s, ?VideoPlayerOptions $o = null): Node
    {
        if ($this->nextKey === null) { $this->key('video player ' . spl_object_id($s)); } // by its state: whatever the app builds before it, its nodes keep their ids
        return VideoPlayer::build($this, $s, $o ?? new VideoPlayerOptions());
    }

    /**
     * The player's settings window, as IINA's Settings: a sidebar of its
     * pages under a search field, the sections of the page chosen under
     * it, the page beside it, its sections cards of a row a setting, by
     * IINA's strings. The settings are IINA's, by its names
     * (Media\PlayerSettings::default(), the app's, in its user data, whatever
     * player $s is): its General page's, and of its Video and Subtitles
     * pages the hardware decoder and the OpenSubtitles account and
     * languages (Login asks for the name and password, kept in the user's
     * Keychain); those of the app's windows (new windows, quitting, what
     * the app does at launch) are the app's to follow. For a window of a
     * hidden title bar over the sidebar material (WindowOptions:
     * titleBarStyle 'hiddenInset', vibrancy 'sidebar'), as IINA's is, 600
     * by 480; it names the window after the page shown.
     */
    public function videoPlayerSettings(VideoPlayerState $s): Node
    {
        return VideoPlayerSettings::build($this, $s);
    }

    /**
     * The player's welcome window, as IINA's: the app's icon, name and
     * version, Open… (the open panel, each file chosen to $open) and Open
     * URL… ($openURL); the film played last to resume, where the settings
     * keep recent files and resume (PlayerSettings' recordRecentFiles and
     * resumeLastPosition), and the recent documents (App::recentDocuments)
     * under it, one opened ($open) with a click, or Up and Down and
     * Return. For a window of a hidden title bar over the under-window
     * material, 560 by 440, as IINA's is.
     * @param Closure(string): void $open @param Closure(): void $openURL
     */
    public function videoPlayerWelcome(Closure $open, Closure $openURL): Node
    {
        return VideoPlayerWelcome::build($this, $open, $openURL);
    }

    /**
     * The player's playback history window, as IINA's: the films the
     * players played (PlayerSettings' recordPlaybackHistory), in groups by
     * the day played or the folder, the latest first, each with where it
     * was left and when it was played; a search in their names or paths;
     * a double click or Return plays the films chosen ($open, $newWindow
     * false), Command-Return in a new window (true), Delete forgets them,
     * the context menu shows them in the Finder or moves their files to the
     * Trash. For a window of a hidden title bar, 800 by 600, as IINA's is.
     * @param Closure(list<string>, bool): void $open
     */
    public function videoPlayerHistory(Closure $open): Node
    {
        return VideoPlayerHistory::build($this, $open);
    }

    /**
     * The app's log viewer, as IINA's: what Logger kept (of its level and
     * above), of a level and above, of the subsystems chosen, filtered by
     * what is typed; following the latest; saved, all or what shows;
     * copied with Command-C. For a window of a hidden title bar
     * ("hiddenInset"), 800 by 500; the video player's settings open one of
     * their own (Open the log viewer).
     */
    public function logViewer(): Node
    {
        return LogViewer::build($this);
    }
}
