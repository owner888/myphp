<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * The inspector of native UI (mygo's ui/inspector.go): the nodes of a
 * window's frames in a panel docked at the right of the content, as
 * Chrome's developer tools show a page's: the tree of nodes as markup,
 * the styles, box model and properties of the one chosen in the tree or
 * picked in the window, which it outlines over the content with a tip,
 * how long frames take, and issues, as duplicate keys. Windows whose
 * developer tools are on (WindowOptions::$devTools) open it with View →
 * Toggle Developer Tools, F12 or Alt+Cmd+I, and pick with Shift+Cmd+C.
 *
 * The panel is built after the view, at the root, which the content
 * leaves room for: the view's Context is narrower by the panel's width,
 * and the root keeps that room clear with padding at its right. It shows
 * the nodes of the frame before, which the inspector notes once each frame
 * is laid out, asking for another frame when they changed
 * (InspectorPanel builds it, InspectorOverlay paints over the content,
 * InspectorDetails describes the node chosen).
 */
final class Inspector
{
    /** The width the panel starts at, and the key of its node under the root. */
    public const WIDTH = 420;
    public const KEY = 'myphp.inspector';
    /** The panel's tabs, and the tabs of the sidebar of its Elements tab. */
    public const ELEMENTS = 0, PERFORMANCE = 1, ISSUES = 2;
    public const STYLES = 0, COMPUTED = 1, PROPERTIES = 2;
    /** How many frames the Performance tab shows. */
    public const HISTORY = 120;

    /** Whether it is open, and while it picks a node in the content; swallow while the release of the press that picked goes nowhere. */
    public bool $open = false, $picking = false, $swallow = false;
    /** The node chosen and the node under the pointer, in the tree or the content while picking; reveal shows the chosen one in the tree, opening the nodes around it. */
    public ?int $selected = null, $hovered = null;
    public bool $reveal = false;
    /** Where the app built the node chosen: the file and line of the call, outside MyPHP's UI. */
    public string $source = '';
    /** The panel's id, its width and the width it leaves the content; the panel's tab, its sidebar's, and the height of the tree. */
    public ?int $panel = null;
    public float $width = 0, $appW = 0;
    public int $tab = self::ELEMENTS, $side = self::STYLES;
    public float $split = 0;
    /**
     * The nodes of the last frame, in the order of the tree: id, key (its
     * row's: the id, unless another node had it), depth, parent index,
     * whether it has children, name, keyText, label, text, w, h, leaving,
     * whether a transition moves it.
     * @var list<array{id: int, key: int, depth: int, parent: int, kids: bool, name: string, keyText: string, label: string, text: string, w: float, h: float, leaving: bool, trans: bool}>
     */
    public array $nodes = [];
    /** @var array<int, string|int> the keys the frame gave nodes, by id */
    public array $keys = [];
    /** What InspectorDetails::describe said of the node chosen, or null. */
    public ?array $sel = null;
    /** The hash of what the panel shows, worked out after each frame, and the one it was built from; changed asks for it built anew, as issues come. */
    public string $sum = '', $shown = '';
    public bool $changed = false;
    /** @var list<array{0: int, 1: bool}> the rows of the tree: a node's opening tag, or the closing tag (true) of a node open */
    public array $rows = [];
    /** @var array<int, bool> the nodes the user opened or closed, by id */
    public array $opened = [];
    public ListState $list;
    public ?int $listId = null;
    /** The find bar of the tree, its query, and the nodes matching it. */
    public bool $finding = false;
    public TextState $query;
    /** The query as the last frame had it. */
    public string $typed = '';
    public int $match = 0;
    /** @var list<int> */
    public array $matches = [];
    /** Where the breadcrumbs, the sidebar and the issues are scrolled. */
    public ScrollState $crumbs, $sideScroll, $issuesScroll, $perfScroll;
    /** The nodes of the frame painted with those ids, and the nodes around the chosen one, outermost first, whose text styles it inherits. @var list<Node> */
    public ?Node $selElem = null, $hoverElem = null;
    public array $selChain = [];
    /** How long the last frame took to build, lay out and paint, in nanoseconds; the nodes it had; the times of the last frames, $frames of them in all. */
    public array $times = [0, 0, 0];
    private int $lapAt = 0;
    public int $elements = 0;
    /** @var array<int, array{0: int, 1: int, 2: int}> */
    public array $history = [];
    public int $frames = 0;
    /** The refresh rate of the window's display, which the window sets (60 without one). */
    public float $hz = 60;
    /** The panel's theme (InspectorPanel::palette), Chrome's colors in the window's appearance. */
    public ?Theme $theme = null;
    public array $pal = [];
    /** The window's theme, which the content is built with: what nodes paint with where they set nothing. */
    public ?Theme $base = null;

    public function __construct()
    {
        $this->list = new ListState();
        $this->list->selected = -1;
        $this->query = new TextState();
        $this->crumbs = new ScrollState();
        $this->sideScroll = new ScrollState();
        $this->issuesScroll = new ScrollState();
        $this->perfScroll = new ScrollState();
    }

    /** The width a window $w wide leaves the content. */
    public function contentWidth(float $w): float
    {
        if (!$this->open) {
            return $this->appW = $w;
        }
        if ($this->width == 0) { $this->width = self::WIDTH; }
        $pw = min(max($this->width, 260), $w - 160);
        if ($w < 520) { $pw = $w / 2; }
        return $this->appW = max($w - $pw, 0);
    }

    /** A frame starts: the keys of the last one go, and the times count from here. */
    public function startFrame(): void
    {
        if (!$this->open) { return; }
        $this->keys = [];
        $this->times = [0, 0, 0];
        $this->lapAt = hrtime(true);
    }

    /** A pass of the frame's build starts (Runtime::frame builds again when a click changed what it showed). */
    public function startPass(): void
    {
        if ($this->open) { $this->lapAt = hrtime(true); }
    }

    /** Adds how long the frame's $phase (0 building, 1 laying out, 2 painting) took since the last lap; once painted, the frame's times join the history. */
    public function lap(int $phase): void
    {
        if (!$this->open) { return; }
        $now = hrtime(true);
        $this->times[$phase] += $now - $this->lapAt;
        $this->lapAt = $now;
        if ($phase === 2) {
            $this->history[$this->frames % self::HISTORY] = $this->times;
            $this->frames++;
        }
    }

    /** Opens or closes the inspector. */
    public function toggle(Runtime $rt): void
    {
        $this->open = !$this->open;
        $this->picking = false; $this->hovered = null;
        if (!$this->open) {
            $this->nodes = []; $this->rows = []; $this->sel = null; $this->selElem = $this->hoverElem = null; $this->selChain = [];
        }
        $rt->animationFrame();
        if ($rt->requestFrame !== null) { ($rt->requestFrame)(); }
    }

    /**
     * Takes an event before the view: F12 and Alt+Cmd+I toggle the
     * inspector, Shift+Cmd+C picks, Escape stops picking; while picking,
     * the pointer over the content is the inspector's: a press chooses
     * the node under it, and its release goes nowhere. Whether it took $e.
     */
    public function event(Runtime $rt, array $e): bool
    {
        if ($e[0] === 'key') {
            $mods = $e[3] & Key::MODIFIERS;
            if ($this->picking && $e[2] === Key::ESCAPE && $mods === 0) {
                $this->picking = false; $this->hovered = null;
                return true;
            }
            if (($e[2] === Key::F12 && $mods === 0) || ($e[2] === Key::I && $mods === (Key::OPTION | Key::COMMAND))) {
                $this->toggle($rt);
                return true;
            }
            if ($e[2] === Key::C && $mods === (Key::SHIFT | Key::COMMAND)) {
                if (!$this->open) { $this->toggle($rt); }
                $this->picking = !$this->picking; $this->hovered = null;
                $this->tab = self::ELEMENTS;
                return true;
            }
            return false;
        }
        if ($this->swallow && $e[0] === 'up') {
            $this->swallow = false;
            return true;
        }
        if (!$this->picking || !in_array($e[0], ['down', 'up', 'drag'], true) || $e[1] >= $this->appW) {
            return false;
        }
        if ($e[0] === 'down') {
            if (($id = $this->hitAt($rt, $e[1], $e[2])) !== null) {
                $this->choose($id);
                $this->reveal = true;
            }
            $this->picking = false; $this->hovered = null; $this->swallow = true;
        }
        return true;
    }

    /** The innermost node of the content at ($x, $y) in the last frame, the one painted last there, or null. */
    public function hitAt(Runtime $rt, float $x, float $y): ?int
    {
        for ($i = count($this->nodes) - 1; $i > 0; $i--) {
            if ($rt->contains($this->nodes[$i]['id'], $x, $y)) {
                return $this->nodes[$i]['id'];
            }
        }
        return null;
    }

    /** Chooses node $id. */
    public function choose(int $id): void
    {
        if ($id !== $this->selected) {
            $this->selected = $id; $this->source = '';
            $this->crumbs->x = PHP_FLOAT_MAX; // the breadcrumbs show the node chosen, at their end
            $this->sideScroll->y = 0;
        }
    }

    /** The file and line of the innermost call on the stack from outside MyPHP's UI: the app's code building a node, or "" (mygo's callSite). */
    public static function callSite(): string
    {
        $ui = __DIR__ . DIRECTORY_SEPARATOR;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $f) {
            if (isset($f['file']) && !str_starts_with($f['file'], $ui)) {
                return $f['file'] . ':' . $f['line'];
            }
        }
        return '';
    }

    /** Whether the panel shows other than what the last frame had: another frame shows it. */
    public function stale(): bool
    {
        return $this->open && ($this->sum !== $this->shown || $this->changed);
    }

    /** Notes the nodes of the frame laid out under $root, and what the panel will say of the one chosen. */
    public function snapshot(Runtime $rt, Node $root): void
    {
        $this->nodes = [];
        $this->selElem = $this->hoverElem = null; $this->selChain = [];
        $seen = []; $sum = [];
        $this->walk($rt, $root, 0, -1, [], $seen, $sum);
        $this->elements = count($this->nodes);
        $this->sel = $this->selElem === null ? null : InspectorDetails::describe($rt, $this, $this->selElem, $this->selChain);
        $sum[] = $this->sel === null ? '' : json_encode($this->sel);
        $sum[] = count($rt->warnings);
        $this->sum = hash('xxh64', implode("\0", $sum));
    }

    /** @param list<Node> $chain the nodes around $n, outermost first */
    private function walk(Runtime $rt, Node $n, int $depth, int $parent, array $chain, array &$seen, array &$sum): void
    {
        if ($n->id === $this->panel && $depth === 1) {
            return;
        }
        $i = count($this->nodes);
        $key = isset($seen[$n->id]) ? unpack('q', hash('xxh64', "$n->id/$i", true))[1] : $n->id; // a node with the id of another (a duplicate key) has a row of its own
        $seen[$key] = true;
        $text = match ($n->kind) { Node::TEXT => $n->text ?? '', Node::RICH => $n->plainText(), default => '' };
        $tr = $rt->trans[$n->id] ?? null;
        $this->nodes[] = ['id' => $n->id, 'key' => $key, 'depth' => $depth, 'parent' => $parent, 'kids' => false, 'name' => self::name($n, $depth),
            'keyText' => isset($this->keys[$n->id]) ? (string)$this->keys[$n->id] : '', 'label' => $n->label, 'text' => $text,
            'w' => $n->w, 'h' => $n->h, 'leaving' => $n->leaving !== 0, 'trans' => $tr !== null && $tr->built + 1 >= $rt->frameNumber];
        $sum[] = "$n->id/$depth/$n->x/$n->y/$n->w/$n->h/$text/$n->label/" . ($this->keys[$n->id] ?? '');
        if ($n->id === $this->selected) { $this->selElem = $n; $this->selChain = $chain; }
        if ($n->id === $this->hovered) { $this->hoverElem = $n; }
        $chain[] = $n;
        foreach ($n->kids as $k) {
            $this->walk($rt, $k, $depth + 1, $i, $chain, $seen, $sum);
        }
        $this->nodes[$i]['kids'] = count($this->nodes) - 1 > $i;
    }

    /** Whether the tree shows what is inside node $i: what the user chose, else whether it is near the root. */
    public function isOpen(int $i): bool
    {
        return $this->opened[$this->nodes[$i]['id']] ?? $this->nodes[$i]['depth'] < 3;
    }

    /** Opens the nodes around node $id; whether it opened one. */
    public function expandTo(int $id): bool
    {
        $i = $this->nodeOf($id);
        if ($i < 0) { return false; }
        $opened = false;
        for ($p = $this->nodes[$i]['parent']; $p >= 0; $p = $this->nodes[$p]['parent']) {
            if ($this->nodes[$p]['kids'] && !$this->isOpen($p)) {
                $this->opened[$this->nodes[$p]['id']] = true;
                $opened = true;
            }
        }
        return $opened;
    }

    /** The index of node $id, -1 for none. */
    public function nodeOf(?int $id): int
    {
        if ($id === null) { return -1; }
        foreach ($this->nodes as $i => $n) {
            if ($n['id'] === $id) { return $i; }
        }
        return -1;
    }

    /** Opens or closes node $i, and with $all the nodes inside it. */
    public function setOpen(int $i, bool $open, bool $all): void
    {
        $this->opened[$this->nodes[$i]['id']] = $open;
        if (!$all) { return; }
        for ($j = $i + 1, $n = count($this->nodes); $j < $n && $this->nodes[$j]['depth'] > $this->nodes[$i]['depth']; $j++) {
            if ($this->nodes[$j]['kids']) { $this->opened[$this->nodes[$j]['id']] = $open; }
        }
    }

    /** What the tree calls a node: its role, else its kind (mygo's elementName). */
    public static function name(Node $n, int $depth = 1): string
    {
        if ($n->role !== Role::AUTO && $n->role !== Role::NONE && ($r = self::roleName($n->role)) !== '') {
            return $r;
        }
        return match (true) {
            $depth === 0 => 'Root',
            $n->kind === Node::BUTTON => 'Button',
            $n->kind === Node::INPUT => 'TextInput',
            $n->kind === Node::TEXTAREA => 'TextArea',
            $n->kind === Node::CHECKBOX => 'Checkbox',
            $n->kind === Node::RADIO => 'Radio',
            $n->kind === Node::SWITCH => 'Switch',
            $n->kind === Node::SLIDER => 'Slider',
            $n->kind === Node::PROGRESS => 'Progress',
            $n->kind === Node::SPINNER => 'Spinner',
            $n->kind === Node::TEXT => 'Text',
            $n->kind === Node::RICH => 'RichText',
            $n->kind === Node::IMAGE => 'Image',
            $n->svg !== null => 'Icon',
            $n->kind === Node::BOX => 'Box',
            $n->gridCols !== null => 'Grid',
            $n->scroll !== null => 'Scroll',
            $n->dir === Node::ROW => 'Row',
            default => 'Column',
        };
    }

    public static function roleName(int $r): string
    {
        return match ($r) {
            Role::BUTTON => 'Button', Role::LINK => 'Link', Role::CHECK_BOX => 'Checkbox', Role::RADIO => 'Radio', Role::SWITCH => 'Switch',
            Role::SLIDER => 'Slider', Role::PROGRESS => 'Progress', Role::TEXT_FIELD => 'TextField', Role::LIST => 'List', Role::DIALOG => 'Dialog',
            Role::ALERT_DIALOG => 'AlertDialog', Role::POPUP => 'Popup', Role::TOOLTIP => 'Tooltip', Role::POP_UP_BUTTON => 'Select',
            Role::TAB_LIST => 'Tabs', Role::TAB => 'Tab', Role::SPLITTER => 'Splitter', Role::STATUS => 'Status', Role::TABLE => 'Table',
            Role::ROW => 'TableRow', Role::CELL => 'Cell', Role::COLUMN_HEADER => 'ColumnHeader', Role::TREE => 'Tree', Role::TREE_ITEM => 'TreeItem',
            Role::LIST_ITEM => 'ListItem', Role::MENU_BUTTON => 'MenuButton', Role::TOOLBAR => 'Toolbar', Role::RADIO_GROUP => 'RadioGroup',
            Role::TOGGLE_BUTTON => 'Toggle', Role::COMBO_BOX => 'Combobox', Role::DISCLOSURE => 'Disclosure', Role::METER => 'Meter',
            Role::STEPPER => 'Stepper', Role::COLOR_WELL => 'ColorWell', Role::MENU => 'Menu', Role::MENU_BAR => 'MenuBar', Role::MENU_ITEM => 'MenuItem',
            Role::MENU_ITEM_CHECK_BOX => 'MenuItemCheckbox', Role::MENU_ITEM_RADIO => 'MenuItemRadio', Role::HEADING => 'Heading',
            Role::GROUP => 'Group', Role::TEXT => 'Text', Role::IMAGE => 'Image', Role::SCROLL => 'Scroll',
            default => '',
        };
    }

    /** The role assistive technology sees, named as Chrome names roles, and whether it sees the node. @return array{0: string, 1: bool} */
    public static function accessibleRole(Runtime $rt, Node $n): array
    {
        $r = $rt->roleOf($n);
        return match ($r) {
            null => ['none', false],
            Role::SCROLL => ['scroll area', true],
            Role::TEXT_FIELD => ['textbox', true],
            Role::POP_UP_BUTTON => ['combobox', true],
            default => [strtolower(self::roleName($r)), true],
        };
    }

    /** $s cut to $n characters, on one line. */
    public static function clip(string $s, int $n): string
    {
        if (($i = strpos($s, "\n")) !== false) { $s = substr($s, 0, $i) . '…'; }
        return mb_strlen($s) <= $n ? $s : mb_substr($s, 0, $n) . '…';
    }

    /** A size: whole, or with one decimal. */
    public static function num(float $v): string
    {
        return $v == round($v) ? (string)(int)round($v) : number_format($v, 1, '.', '');
    }

    /** A length in points as CSS writes pixels. */
    public static function px(float $v): string
    {
        return $v == 0 ? '0' : self::num($v) . 'px';
    }

    /** Nanoseconds as milliseconds, to a tenth. */
    public static function ms(int|float $ns): string
    {
        return number_format($ns / 1e6, 1, '.', '');
    }

    /** A color as CSS writes it: hex, or oklch() for one outside the sRGB gamut, whose hex would be its nearest sRGB color and not the wide color a window drawing a wide gamut shows (mygo's colorText). */
    public static function color(int $rgb, float $alpha = 1.0): string
    {
        if (($wide = WideColor::wide($rgb)) !== null) {
            [$l, $c, $h] = Oklch::of(...$wide);
            $dec = static fn(float $v, int $n): string => str_contains($s = number_format($v, $n, '.', ''), '.') ? rtrim(rtrim($s, '0'), '.') : $s;
            return 'oklch(' . $dec($l, 3) . ' ' . $dec($c, 3) . ' ' . $dec($h, 1) . ($alpha < 1 ? ' / ' . $dec((float)(int)round($alpha * 255) / 255, 2) : '') . ')';
        }
        return $alpha >= 1 ? sprintf('#%06x', $rgb & 0xffffff) : sprintf('#%06x%02x', $rgb & 0xffffff, (int)round($alpha * 255));
    }
}
