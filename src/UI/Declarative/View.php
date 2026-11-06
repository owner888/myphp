<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use MyPHP\UI\{Context, Node};

/**
 * A view, as SwiftUI's: a value the view's body returns, rendered every
 * frame into the engine's builder (Context), with its modifiers in the
 * order they were applied. A modifier returns the same view, changed: the
 * body builds a fresh tree every frame, so nothing is shared.
 *
 * Modifiers that box the content (padding, frame, background, border,
 * cornerRadius, shadow, opacity) apply in order, as SwiftUI's do: a padding
 * after a background pads outside it, which a box around the view gives;
 * the inherited ones (font, foregroundStyle, tint, disabled, buttonStyle…)
 * flow down to the views inside through the environment (Env).
 */
abstract class View
{
    /** @var list<array{0: string, 1: array<mixed>}> the boxing modifiers, in order */
    protected array $mods = [];
    /** @var array<string, mixed> what this view sets in the environment of the views inside it */
    protected array $sets = [];
    /** @var list<array{0: string, 1: mixed}> what happens to it: onTapGesture, help, accessibilityLabel… */
    protected array $events = [];
    public string|int|null $identity = null;
    public mixed $tagValue = null;
    public bool $hasTag = false;

    /** Builds the view's own node(s) into $c, its content inside; returns the outer node, or null for none. */
    abstract protected function build(Context $c, Env $env): ?Node;

    // ---- the inherited modifiers -------------------------------------------

    /** The font of the text inside: a text style (Font::title…) or Font::system(size, weight). */
    public function font(\Font|FontSpec $font): static { $this->sets['font'] = $font instanceof \Font ? $font->spec() : $font; return $this; }

    public function fontWeight(\Weight $weight): static { $this->sets['weight'] = $weight; return $this; }

    public function bold(bool $on = true): static { $this->sets['weight'] = $on ? \Weight::bold : \Weight::regular; return $this; }

    public function italic(bool $on = true): static { $this->sets['italic'] = $on; return $this; }

    public function monospaced(bool $on = true): static { $this->sets['mono'] = $on; return $this; }

    /** The color of the text and symbols inside. */
    public function foregroundStyle(\Color|ColorValue $color): static { $this->sets['color'] = ColorValue::of($color); return $this; }

    /** The accent of the controls inside. */
    public function tint(\Color|ColorValue $color): static { $this->sets['tint'] = ColorValue::of($color); return $this; }

    public function disabled(bool $disabled = true): static { if ($disabled) { $this->sets['disabled'] = true; } return $this; }

    public function buttonStyle(\ButtonStyle $style): static { $this->sets['buttonStyle'] = $style; return $this; }

    public function multilineTextAlignment(\TextAlignment $a): static { $this->sets['textAlign'] = $a; return $this; }

    public function lineLimit(?int $n): static { $this->sets['lineLimit'] = $n; return $this; }

    // ---- the boxing modifiers ----------------------------------------------

    /**
     * Room around the content: padding() is the platform's 16 points all
     * round, padding(8) 8 all round, padding(\Edge::horizontal, 20) on
     * those edges.
     */
    public function padding(\Edge|float|null $edges = null, ?float $length = null): static
    {
        if (!$edges instanceof \Edge) { [$length, $edges] = [$edges, \Edge::all]; }
        $this->mods[] = ['padding', [$edges, $length ?? 16.0]];
        return $this;
    }

    /** A size, or bounds (maxWidth: INF fills the room), the content placed in it by $alignment. */
    public function frame(?float $width = null, ?float $height = null, ?float $minWidth = null, ?float $maxWidth = null, ?float $minHeight = null, ?float $maxHeight = null, \Alignment $alignment = \Alignment::center): static
    {
        $this->mods[] = ['frame', [$width, $height, $minWidth, $maxWidth, $minHeight, $maxHeight, $alignment]];
        return $this;
    }

    public function background(\Color|ColorValue $color): static { $this->mods[] = ['background', [ColorValue::of($color)]]; return $this; }

    public function cornerRadius(float $radius): static { $this->mods[] = ['radius', [$radius]]; return $this; }

    /** Clips to a shape: RoundedRectangle(cornerRadius: 8), Capsule(), Circle(). */
    public function clipShape(Shape $shape): static { $this->mods[] = ['clip', [$shape]]; return $this; }

    public function border(\Color|ColorValue $color, float $width = 1): static { $this->mods[] = ['border', [ColorValue::of($color), $width]]; return $this; }

    public function shadow(\Color|ColorValue|null $color = null, float $radius = 4, float $x = 0, float $y = 2): static
    {
        $this->mods[] = ['shadow', [$color === null ? null : ColorValue::of($color), $radius, $x, $y]];
        return $this;
    }

    public function opacity(float $opacity): static { $this->mods[] = ['opacity', [$opacity]]; return $this; }

    public function hidden(bool $hidden = true): static { if ($hidden) { $this->mods[] = ['hidden', []]; } return $this; }

    // ---- identity, events, accessibility -----------------------------------

    /** The view's identity across frames, as SwiftUI's .id(): it keeps its state, focus and animations by it. */
    public function id(string|int $id): static { $this->identity = $id; return $this; }

    /** The value a Picker's option, a TabView's tab… stands for. */
    public function tag(mixed $value): static { $this->tagValue = $value; $this->hasTag = true; return $this; }

    public function onTapGesture(Closure $action, int $count = 1): static { $this->events[] = ['tap', [$action, $count]]; return $this; }

    /** Runs $action the first frame the view shows, and again after it was gone. */
    public function onAppear(Closure $action): static { $this->events[] = ['appear', $action]; return $this; }

    public function onDisappear(Closure $action): static { $this->events[] = ['disappear', $action]; return $this; }

    /** Runs $action in a fiber (App::go) as the view appears: what waits in it (Http, an image) lets the app run. */
    public function task(Closure $action): static { $this->events[] = ['task', $action]; return $this; }

    /** A tooltip on the desktop; what screen readers say after the label. */
    public function help(string $text): static { $this->events[] = ['help', $text]; return $this; }

    /** Runs $action(old, new) when $value differs from the frame before, as SwiftUI's onChange(of:). */
    public function onChange(mixed $of, Closure $action): static { $this->events[] = ['change', [$of, $action]]; return $this; }

    /** Runs $action(true) as the pointer comes over the view and $action(false) as it leaves, as SwiftUI's onHover. */
    public function onHover(Closure $action): static { $this->events[] = ['hover', $action]; return $this; }

    /** The menu a secondary click on the view opens: Buttons, Toggles, Dividers, Sections and Menus, as SwiftUI's contextMenu. */
    public function contextMenu(View|array|null ...$items): static { $this->events[] = ['menu', $items]; return $this; }

    /** A popover pointing at the view while $isPresented, of the view $content returns; a click outside or Escape closes it. */
    public function popover(bool &$isPresented, Closure $content): static
    {
        $this->events[] = ['popover', [&$isPresented, $content]];
        return $this;
    }

    /** Whether the text inside can be selected and copied, as SwiftUI's textSelection(.enabled). */
    public function textSelection(\TextSelectability $s): static { $this->events[] = ['select', $s]; return $this; }

    /** The appearance the app takes while the view shows: light, dark, or null to follow the system, as SwiftUI's preferredColorScheme. */
    public function preferredColorScheme(?\ColorScheme $scheme): static { $this->events[] = ['scheme', $scheme]; return $this; }

    /** How the Pickers inside draw: menu, segmented, radioGroup, inline. */
    public function pickerStyle(\PickerStyle $style): static { $this->sets['pickerStyle'] = $style; return $this; }

    /**
     * The language the views inside say their text in, a BCP 47 tag
     * ('zh-Hans'), as SwiftUI's environment(\.locale, …): their keys looked
     * up in the app's catalogs in it, its numbers and dates.
     */
    public function environment(?string $locale = null): static { $this->sets['locale'] = $locale; return $this; }

    /** Whether the view takes the pointer: false lets a press through to what is under it, as SwiftUI's allowsHitTesting; assistive technology still presses it. */
    public function allowsHitTesting(bool $enabled): static { $this->events[] = ['hit', $enabled]; return $this; }

    /** A gesture of the view's: WindowDragGesture() moves the window, as SwiftUI's gesture(WindowDragGesture()). */
    public function gesture(\WindowDragGesture $gesture): static { $this->events[] = ['windowDrag', null]; return $this; }

    /** The keyboard focus as the view first shows, as SwiftUI's defaultFocus: a terminal, an editor, ready to type in. */
    public function defaultFocus(): static { $this->events[] = ['defaultFocus', null]; return $this; }

    /** The keyboard focus, bound to $isFocused, as SwiftUI's focused($isFocused) of a FocusState: true focuses the view, and a click elsewhere makes it false. */
    public function focused(bool &$isFocused): static
    {
        $this->events[] = ['focused', [&$isFocused]];
        return $this;
    }

    /** The controls inside show no title, which stays their accessibility label, as SwiftUI's labelsHidden. */
    public function labelsHidden(): static { $this->sets['labelsHidden'] = true; return $this; }

    /** Radio buttons in a row rather than a column, as SwiftUI's horizontalRadioGroupLayout. */
    public function horizontalRadioGroupLayout(): static { $this->sets['radioRow'] = true; return $this; }

    /** How the DatePickers inside draw: a field (compact, field) or the calendar (graphical). */
    public function datePickerStyle(\DatePickerStyle $style): static { $this->sets['datePickerStyle'] = $style; return $this; }

    public function accessibilityLabel(string $label): static { $this->events[] = ['label', $label]; return $this; }

    /**
     * A shortcut that does what a click does, as SwiftUI's: a character
     * ('s') or a KeyEquivalent (KeyEquivalent::upArrow), with $modifiers,
     * ⌘ by default ([] for none).
     * @param list<\EventModifiers> $modifiers
     */
    public function keyboardShortcut(\KeyEquivalent|string $key, array $modifiers = [\EventModifiers::command]): static
    {
        $this->events[] = ['shortcut', [$key instanceof \KeyEquivalent ? $key->key() : $key, \EventModifiers::mask($modifiers)]];
        return $this;
    }

    // ---- rendering -------------------------------------------------------------

    // ---- navigation and presentations ----------------------------------------

    /** The title of the page of a NavigationStack this view is (its navigationBar shows it), as SwiftUI's. */
    public function navigationTitle(string $title): static { $this->sets['title'] = $title; $this->wraps[] = ['title', $title]; return $this; }

    /** NavigationBarItem::TitleDisplayMode::large: the title large at the top of the page's ScrollView or ListView, under the bar once scrolled, as iOS's. */
    public function navigationBarTitleDisplayMode(\TitleDisplayMode $mode): static { $this->sets['titleMode'] = $mode; return $this; }

    /** The buttons at the trailing end of the navigation bar while this page shows, as SwiftUI's .toolbar { … }. */
    public function toolbar(View|array|null ...$items): static { $this->sets['toolbar'] = $items; $this->wraps[] = ['toolbar', $items]; return $this; }

    /** A sheet rising from the bottom while $isPresented, of the view $content returns; a tap outside, Escape or Back closes it. */
    public function sheet(bool &$isPresented, Closure $content): static
    {
        $p = new Presentation('sheet', $isPresented);
        $p->content = $content;
        $this->presentations[] = $p;
        return $this;
    }

    /**
     * An alert while $isPresented: $title, $message, and $actions, Buttons
     * whose action runs as one is chosen (a cancel role's last, the default
     * one), as SwiftUI's .alert(_:isPresented:actions:message:).
     * @param list<Button> $actions
     */
    public function alert(string $title, bool &$isPresented, array $actions = [], string $message = ''): static
    {
        $p = new Presentation('alert', $isPresented);
        [$p->title, $p->actions, $p->message] = [$title, $actions === [] ? [new Button('OK')] : $actions, $message];
        $this->presentations[] = $p;
        return $this;
    }

    /** Choices from the bottom while $isPresented, as SwiftUI's .confirmationDialog: an action sheet on a phone. @param list<Button> $actions */
    public function confirmationDialog(string $title, bool &$isPresented, array $actions): static
    {
        $p = new Presentation('confirm', $isPresented);
        [$p->title, $p->actions] = [$title, $actions];
        $this->presentations[] = $p;
        return $this;
    }

    /** Buttons a swipe toward the start shows at the end of a row, the first nearest it, as SwiftUI's .swipeActions; a full swipe runs the first. */
    public function swipeActions(Button ...$buttons): static { $this->swipe = $buttons; return $this; }

    /** Pull to refresh the ScrollView or ListView inside: $action runs in a fiber, the spinner showing until it ends, as SwiftUI's .refreshable. */
    public function refreshable(Closure $action): static { $this->sets['refresh'] = $action; return $this; }

    /** @var list<array{0: string, 1: mixed}> */
    protected array $wraps = [];
    /** @var list<Presentation> */
    protected array $presentations = [];
    /** @var list<Button>|null */
    protected ?array $swipe = null;

    /** Builds the view into $c in the environment $env: an app's view never calls it, Content and the views inside do. @internal */
    public function render(Context $c, Env $env): ?Node
    {
        $env = $this->sets === [] ? $env : $env->with($this->sets);
        foreach ($this->wraps as [$kind, $a]) {
            if ($kind === 'title') { $env->route?->title(Localized::string($a, $env)); }
            if ($kind === 'toolbar') { $env->stack?->setToolbar($env, $a); }
        }
        if ($this->swipe !== null) {
            $labels = array_map(fn(Button $b) => ($b->role === \ButtonRole::destructive ? '!' : '') . $b->title, $this->swipe);
            $node = null;
            $row = $c->swipeActions($chosen, function () use ($c, $env, &$node): void { $node = $this->renderBox($c, $env); }, ...$labels);
            if ($chosen >= 0) { $this->swipe[$chosen]->run(); }
            $node ??= $row;
        } else {
            $node = $this->renderBox($c, $env);
        }
        foreach ($this->presentations as $p) { $p->render($c, $env); }
        return $node;
    }

    /** The view and its boxing modifiers. */
    private function renderBox(Context $c, Env $env): ?Node
    {
        // The boxing modifiers in layers: a padding or frame after one that paints the box goes on a box around it.
        $layers = [[]];
        $painted = false;
        foreach ($this->mods as $m) {
            if ($painted && ($m[0] === 'padding' || $m[0] === 'frame')) { $layers[] = []; $painted = false; }
            $layers[count($layers) - 1][] = $m;
            if (in_array($m[0], ['background', 'border', 'radius', 'clip', 'shadow', 'opacity'], true)) { $painted = true; }
        }
        $outer = [];
        if ($this->identity !== null) { $c->key($this->identity); }
        for ($i = count($layers) - 1; $i > 0; $i--) { // the outer layers, boxes the view goes in, outermost first
            $outer[] = [$c->column(0, 0, 0)->alignItems($env->axis === Node::ROW ? Node::CENTER : Node::CENTER), $layers[$i]];
        }
        $node = $this->build($c, $env);
        if ($node !== null) {
            $this->apply($node, $layers[0], $env);
            $this->events($c, $node, $env);
        }
        foreach (array_reverse($outer) as [$box, $mods]) {
            $c->end();
            self::takeRoom($box, $env->axis, self::flexOfKids($box)); // a box around a view that takes the room takes it
            $this->apply($box, $mods, $env);
        }
        return $outer === [] ? $node : $outer[0][0];
    }

    /** Applies the boxing modifiers $mods to $node. */
    private function apply(Node $n, array $mods, Env $env): void
    {
        foreach ($mods as [$name, $a]) {
            switch ($name) {
                case 'padding':
                    [$edges, $l] = $a;
                    [$t, $r, $b, $s] = $n->sides();
                    $n->padding(
                        $edges->top() ? $t + $l : $t, $edges->trailing() ? $r + $l : $r,
                        $edges->bottom() ? $b + $l : $b, $edges->leading() ? $s + $l : $s);
                    break;
                case 'frame':
                    [$w, $h, $minW, $maxW, $minH, $maxH, $align] = $a;
                    if ($w !== null) { $n->width($w); $env->axis === Node::ROW ? $n->grow = 0 : ($n->self === Node::STRETCH && $n->alignSelf(Node::AUTO)); }
                    if ($h !== null) { $n->height($h); $env->axis === Node::COLUMN ? $n->grow = 0 : ($n->self === Node::STRETCH && $n->alignSelf(Node::AUTO)); }
                    if ($minW !== null) { $n->minWidth($minW); }
                    if ($minH !== null) { $n->minHeight($minH); }
                    if ($maxW === INF) { $env->axis === Node::ROW ? $n->grow = max($n->grow, 1) : $n->alignSelf(Node::STRETCH); } elseif ($maxW !== null) { $n->maxWidth($maxW); }
                    if ($maxH === INF) { $env->axis === Node::COLUMN ? $n->grow = max($n->grow, 1) : $n->alignSelf(Node::STRETCH); } elseif ($maxH !== null) { $n->maxHeight($maxH); }
                    if ($align !== \Alignment::center || $maxW === INF || $maxH === INF || $w !== null || $h !== null) { $align->place($n); }
                    break;
                case 'background': $n->background($a[0]->rgb($env), $a[0]->alpha); break;
                case 'radius': $n->radius($a[0]); break;
                case 'clip': $a[0]->clip($n); break;
                case 'border': $n->border($a[1], $a[0]->rgb($env)); break;
                case 'shadow': $n->shadow($a[2], $a[3], $a[1] * 2, 0, $a[0]?->rgb($env) ?? 0x000000, $a[0]?->alpha ?? 0.2); break;
                case 'opacity': $n->opacity($a[0]); break;
                case 'hidden': $n->invisible(); break;
            }
        }
    }

    /** Flexibility: the room across (1) and down (2) node $n takes in a stack along $axis, as SwiftUI's flexible views take what they are offered. */
    public const FLEX_X = 1, FLEX_Y = 2;

    /** How flexible $n, in a stack along $axis, is: FLEX_X and FLEX_Y. @internal */
    public static function flexOf(Node $n, int $axis): int
    {
        $along = $n->grow > 0 ? ($axis === Node::ROW ? self::FLEX_X : self::FLEX_Y) : 0;
        $across = $n->self === Node::STRETCH ? ($axis === Node::ROW ? self::FLEX_Y : self::FLEX_X) : 0;
        return $along | $across;
    }

    /** How flexible the kids of container $n are, together. @internal */
    public static function flexOfKids(Node $n): int
    {
        $f = 0;
        foreach ($n->kids as $k) { $f |= self::flexOf($k, $n->dir); }
        return $f;
    }

    /** Makes $n, in a stack along $axis, take the room in $flex (FLEX_X, FLEX_Y). @internal */
    public static function takeRoom(Node $n, int $axis, int $flex): void
    {
        $along = $axis === Node::ROW ? self::FLEX_X : self::FLEX_Y;
        if ($flex & $along) { $n->grow = max($n->grow, 1); }
        if ($flex & ~$along & (self::FLEX_X | self::FLEX_Y)) { $n->alignSelf(Node::STRETCH); }
    }

    /** @var list<?\ColorScheme> the appearance preferredColorScheme() set last, none before */
    private static array $scheme = [];

    /** The events and accessibility of $node. */
    private function events(Context $c, Node $node, Env $env): void
    {
        $changes = 0;
        foreach ($this->events as [$kind, $a]) {
            switch ($kind) {
                case 'change':
                    if (Lifecycle::of($c, $node)?->changed($changes++, $a[0], $old)) { ($a[1])($old, $a[0]); }
                    break;
                case 'hover':
                    $over = $c->hovered($node);
                    if (Lifecycle::of($c, $node)?->hoverChanged($over)) { ($a)($over); }
                    break;
                case 'select':
                    $a === \TextSelectability::enabled ? $node->selectable() : $node->unselectable();
                    break;
                case 'scheme':
                    if (self::$scheme !== [$a]) { // the app's appearance, set as it changes
                        self::$scheme = [$a];
                        \MyPHP\App::setTheme(match ($a) { \ColorScheme::light => \MyPHP\UI\Theme::light(), \ColorScheme::dark => \MyPHP\UI\Theme::dark(), null => null });
                    }
                    break;
                case 'hit':
                    $node->ignoreInput(!$a);
                    break;
                case 'windowDrag':
                    $node->dragWindow();
                    break;
                case 'defaultFocus':
                    $node->autoFocus();
                    break;
                case 'focused':
                    $rt = $c->runtime;
                    $node->focusable();
                    $kept = Lifecycle::of($c, $node);
                    if ($rt !== null && $kept !== null) {
                        $id = $node->id;
                        $a[0] = $kept->focus($a[0], $rt->focus === $id, static function () use ($rt, $id): void { $rt->focus = $id; }); // $a[0] the binding
                    }
                    break;
                case 'menu':
                    $items = $a;
                    $node->contextMenu(static fn(\MyPHP\UI\Menu $m) => Menu::items($m, $items, $env));
                    break;
                case 'popover':
                    $content = $a[1];
                    $c->popover($node, $a[0], static function () use ($c, $env, $content): void { View::renderAll($c, $env->along(Node::COLUMN), $content()); }); // $a[0] the binding: an array's reference stays one in its copies
                    break;
                case 'tap':
                    $node->focusable(false);
                    if ($a[1] === 2 ? $c->doubleClicked($node) : $c->clicked($node)) { ($a[0])(); }
                    break;
                case 'help': $node->tooltip(Localized::string($a, $env)); break;
                case 'label': $node->label(Localized::string($a, $env)); break;
                case 'shortcut':
                    if ($c->shortcut($a[0], $a[1])) { $this->shortcut(); }
                    break;
                case 'appear': case 'disappear': case 'task':
                    $life[] = [$kind, $a];
                    break;
            }
        }
        if (isset($life)) { Lifecycle::note($c, $node, $life); }
    }

    /** What the view's keyboardShortcut does: a button's action. */
    protected function shortcut(): void {}

    /** Renders $content, views, arrays of them and nulls, into $c. @param iterable<View|array|null>|View|null $content */
    public static function renderAll(Context $c, Env $env, iterable|View|null $content): void
    {
        if ($content === null) { return; }
        if ($content instanceof View) { $content->render($c, $env); return; }
        foreach ($content as $v) {
            if ($v === null || $v === false) { continue; }
            if (is_array($v) || $v instanceof \Traversable) { self::renderAll($c, $env, $v); continue; }
            if (!$v instanceof View) { throw new \TypeError('a view\'s content holds views, arrays of them and null, not ' . get_debug_type($v)); }
            $v->render($c, $env);
        }
    }
}
