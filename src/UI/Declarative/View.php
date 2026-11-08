<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use MyPHP\UI\{Context, Node, Track};

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
    /** What badge() shows at the end of the view's row. @internal */
    public int|string|null $badgeValue = null;

    /** Builds the view's own node(s) into $c, its content inside; returns the outer node, or null for none. */
    abstract protected function build(Context $c, Env $env): ?Node;

    // ---- the inherited modifiers -------------------------------------------

    /** The font of the text inside: a text style (Font::title…) or Font::system(size, weight). */
    public function font(\Font|FontSpec $font): static { $this->sets['font'] = $font instanceof \Font ? $font->spec() : $font; return $this; }

    public function fontWeight(\Weight $weight): static { $this->sets['weight'] = $weight; return $this; }

    public function bold(bool $on = true): static { $this->sets['weight'] = $on ? \Weight::bold : \Weight::regular; return $this; }

    public function italic(bool $on = true): static { $this->sets['italic'] = $on; return $this; }

    /** A line under the text, as SwiftUI's underline(). */
    public function underline(bool $on = true): static { $this->sets['underline'] = $on; return $this; }

    /** A line through the text, as SwiftUI's strikethrough(). */
    public function strikethrough(bool $on = true): static { $this->sets['strikethrough'] = $on; return $this; }

    public function monospaced(bool $on = true): static { $this->sets['mono'] = $on; return $this; }

    /** The color of the text and symbols inside. */
    public function foregroundStyle(\Color|ColorValue $color): static { $this->sets['color'] = ColorValue::of($color); return $this; }

    /** The accent of the controls inside. */
    public function tint(\Color|ColorValue $color): static { $this->sets['tint'] = ColorValue::of($color); return $this; }

    public function disabled(bool $disabled = true): static { if ($disabled) { $this->sets['disabled'] = true; } return $this; }

    public function buttonStyle(\ButtonStyle $style): static { $this->sets['buttonStyle'] = $style; return $this; }

    public function multilineTextAlignment(\TextAlignment $a): static { $this->sets['textAlign'] = $a; return $this; }

    public function lineLimit(?int $n, bool $reservesSpace = false): static { $this->sets['lineLimit'] = $n; $this->sets['linesReserved'] = $reservesSpace; return $this; }

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
    public function frame(?float $width = null, ?float $height = null, ?float $minWidth = null, ?float $maxWidth = null, ?float $minHeight = null, ?float $maxHeight = null, \Alignment $alignment = \Alignment::center, ?float $idealWidth = null, ?float $idealHeight = null): static
    {
        $this->mods[] = ['frame', [$width, $height, $minWidth, $maxWidth, $minHeight, $maxHeight, $alignment, $idealWidth, $idealHeight]];
        return $this;
    }

    /** The width a frame() asks for it, ideal or fixed, which an HSplitView gives its pane at first. @internal */
    public function idealWidth(): ?float
    {
        foreach ($this->mods as [$name, $a]) { if ($name === 'frame' && ($a[7] ?? $a[0]) !== null) { return $a[7] ?? $a[0]; } }
        return null;
    }

    /** The least width a frame() gives it. @internal */
    public function minWidth(): ?float
    {
        foreach ($this->mods as [$name, $a]) { if ($name === 'frame' && $a[2] !== null) { return $a[2]; } }
        return null;
    }

    /** A color behind the view; or $content, a view as large as it, under it, as SwiftUI's background(alignment:content:). */
    public function background(\Color|ColorValue|View $color, \Alignment $alignment = \Alignment::center): static
    {
        $this->mods[] = $color instanceof View ? ['overlay', [$color, $alignment, true]] : ['background', [ColorValue::of($color)]];
        return $this;
    }

    /** Blurs the view by $radius points, as SwiftUI's blur(radius:). */
    public function blur(float $radius): static { $this->mods[] = ['blur', [$radius]]; return $this; }

    /** $content over the view, as large as it, placed by $alignment, as SwiftUI's overlay: a shape stroked around it, a badge in a corner. */
    public function overlay(View $content, \Alignment $alignment = \Alignment::center): static { $this->mods[] = ['overlay', [$content, $alignment]]; return $this; }

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

    /**
     * Turns the view by $degrees about $axis, in 3D, as SwiftUI's rotation3DEffect: about the x axis it tips away,
     * seen foreshortened; an axis in the plane turned from x tips it that way. Not animated.
     * @param array{0: float, 1: float, 2: float} $axis
     */
    public function rotation3DEffect(float $degrees, array $axis = [1, 0, 0]): static { $this->mods[] = ['rotate3d', [$degrees, $axis]]; return $this; }

    /** Turns the view by $degrees about its middle, clockwise, the room it takes staying, as SwiftUI's rotationEffect(.degrees()). */
    public function rotationEffect(float $degrees): static { $this->mods[] = ['rotate', [$degrees]]; return $this; }

    /** Draws the view in grays, as SwiftUI's grayscale: any $amount above 0 takes all its color away. */
    public function grayscale(float $amount): static { if ($amount > 0) { $this->mods[] = ['gray', []]; } return $this; }

    /** Moves the view by $x and $y points from where it is laid out, the room it takes staying, as SwiftUI's offset. */
    public function offset(float $x = 0, float $y = 0): static { $this->mods[] = ['offset', [$x, $y]]; return $this; }

    /** Colors each pixel of the view with $shader's ColorEffect, its uniforms set by name, as SwiftUI's colorEffect. @param array<string, mixed> $uniforms */
    public function colorEffect(\MyPHP\UI\Shader\Shader $shader, array $uniforms = []): static { $this->events[] = ['effect', [$shader, $uniforms, null]]; return $this; }

    /** Moves each pixel of the view where $shader's DistortionEffect samples it, up to $maxSampleOffset away, as SwiftUI's distortionEffect. @param array<string, mixed> $uniforms */
    public function distortionEffect(\MyPHP\UI\Shader\Shader $shader, CGSize $maxSampleOffset, array $uniforms = []): static { $this->events[] = ['effect', [$shader, $uniforms, $maxSampleOffset]]; return $this; }

    /** Draws the view through $shader's LayerEffect, which samples its layer up to $maxSampleOffset away, as SwiftUI's layerEffect. @param array<string, mixed> $uniforms */
    public function layerEffect(\MyPHP\UI\Shader\Shader $shader, CGSize $maxSampleOffset, array $uniforms = []): static { $this->events[] = ['effect', [$shader, $uniforms, $maxSampleOffset]]; return $this; }

    /** Scales the view by $scale about its middle, the room it takes staying, as SwiftUI's scaleEffect. */
    public function scaleEffect(float $scale): static { $this->mods[] = ['scale', [$scale]]; return $this; }

    /** @var array{0: ?\MyPHP\UI\Animation, 1: mixed}|null */
    private ?array $animated = null;

    /**
     * Animates the changes of the view and of those inside it with $animation while $value changes, as SwiftUI's
     * animation(_:value:): its offset, scale, rotation and opacity, and the place and size of views coming, going
     * (transition) and matched (matchedGeometryEffect). A null animation makes them change at once.
     */
    public function animation(?\MyPHP\UI\Animation $animation, mixed $value): static { $this->animated = [$animation, $value]; return $this; }

    /** @var array{0: ?AnyTransition, 1: string|int|null}|null how the view comes and goes, and the identity it shares */
    private ?array $moves = null;

    /** How the view comes as it appears, and goes as it is no longer built, as SwiftUI's transition(.opacity), .scale, .slide, .move(edge:)… */
    public function transition(AnyTransition $t): static { $this->moves = [$t, $this->moves[1] ?? null]; return $this; }

    /**
     * The view as the one of $id in $in (a name of the views' group) the frame before showed elsewhere, even in
     * another stack: it comes from its place and size there, as SwiftUI's matchedGeometryEffect(id:in:).
     */
    public function matchedGeometryEffect(string|int $id, string|object $in = ''): static
    {
        $this->moves = [$this->moves[0] ?? null, (is_object($in) ? spl_object_id($in) : $in) . ':' . $id];
        return $this;
    }

    /**
     * A material behind the view, as SwiftUI's glassEffect: the Glass plugin's Glass (Liquid Glass), Blur or
     * ScrollEdge, with $tint, a color of the theme, over a Glass.
     */
    public function glassEffect(\MyPHP\UI\Material $material, \Color|ColorValue|null $tint = null): static { $this->mods[] = ['glass', [$material, $tint]]; return $this; }

    public function hidden(bool $hidden = true): static { if ($hidden) { $this->mods[] = ['hidden', []]; } return $this; }

    // ---- identity, events, accessibility -----------------------------------

    /** The view's identity across frames, as SwiftUI's .id(): it keeps its state, focus and animations by it. */
    public function id(string|int $id): static { $this->identity = $id; return $this; }

    /** The value a Picker's option, a TabView's tab… stands for. */
    /** The width of the sidebar this view is, in a NavigationSplitView, as SwiftUI's navigationSplitViewColumnWidth. */
    public function navigationSplitViewColumnWidth(float $width): static { $this->columnWidth = $width; return $this; }

    /** @internal what navigationSplitViewColumnWidth() set */
    public ?float $columnWidth = null;

    public function tag(mixed $value): static { $this->tagValue = $value; $this->hasTag = true; return $this; }

    /** A count or a word at the end of the view's row in a sidebar or a list, as SwiftUI's badge; 0 or null shows none. */
    public function badge(int|string|null $count): static { $this->badgeValue = $count === 0 || $count === '' ? null : $count; return $this; }

    /** The rows of the ListView inside on backgrounds that alternate, as SwiftUI's alternatingRowBackgrounds() on macOS. */
    public function alternatingRowBackgrounds(bool $enabled = true): static { $this->sets['alternatingRows'] = $enabled; return $this; }

    /** Runs $action as the view is clicked ($count times), as SwiftUI's onTapGesture: given a CGPoint, where, in the view's points, or the window's in $coordinateSpace global. */
    public function onTapGesture(Closure $action, int $count = 1, \CoordinateSpace $coordinateSpace = \CoordinateSpace::local): static { $this->events[] = ['tap', [$action, $count, $coordinateSpace]]; return $this; }

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
    public function contextMenu(View|array|null|Closure ...$items): static
    {
        if (isset($items['menu']) || isset($items['primaryAction'])) { // contextMenu(forSelectionType:menu:primaryAction:), a ListView's
            throw new \LogicException('contextMenu(menu:, primaryAction:) is a ListView(selection:)\'s: ListView($items, selection: $this->chosen)(…)->contextMenu(menu: fn(array $ids) => [Button(…)], primaryAction: fn(array $ids) => …)');
        }
        $this->events[] = ['menu', $items];
        return $this;
    }

    /**
     * A popover pointing at the view while $isPresented, of the view $content returns, at its ideal size; a click outside or Escape
     * closes it. As SwiftUI's on a Mac, it shows outside the window, in a flyout (an NSPopover on macOS), on the side of the view
     * $arrowEdge names, below by default; in a view of no window (a test's), inside it.
     */
    public function popover(bool &$isPresented, Closure $content, ?\Edge $arrowEdge = null): static
    {
        $this->events[] = ['popover', [&$isPresented, $content, $arrowEdge]];
        return $this;
    }

    /** Shows $message in a toast at the bottom of the window, which fades by itself, as it is set; $message is null again then. myphp's own: SwiftUI has no toast. */
    public function toast(?string &$message): static { $this->events[] = ['toast', [&$message]]; return $this; }

    /** Whether the text inside can be selected and copied, as SwiftUI's textSelection(.enabled). */
    public function textSelection(\TextSelectability $s): static { $this->events[] = ['select', $s]; return $this; }

    /** The appearance the app takes while the view shows: light, dark, or null to follow the system, as SwiftUI's preferredColorScheme. */
    public function preferredColorScheme(?\ColorScheme $scheme): static { $this->events[] = ['scheme', $scheme]; return $this; }

    /** The app's text size, whatever the system's, as SwiftUI's dynamicTypeSize: DynamicTypeSize::xxLarge, say; null follows the system's again. */
    public function dynamicTypeSize(?\DynamicTypeSize $size): static { $this->events[] = ['typeSize', $size]; return $this; }

    /** How the Pickers inside draw: menu, segmented, radioGroup, inline. */
    /** How the rows of the ListView inside draw: ListStyle::plain, rows of their content's height without separators, as SwiftUI's listStyle(.plain) on macOS. */
    public function listStyle(\ListStyle $style): static { $this->sets['listStyle'] = $style; return $this; }

    /** How the TextFields inside draw: TextFieldStyle::plain, without a face or border, as SwiftUI's textFieldStyle(.plain). */
    public function textFieldStyle(\TextFieldStyle $style): static { $this->sets['textFieldStyle'] = $style; return $this; }

    public function pickerStyle(\PickerStyle $style): static { $this->sets['pickerStyle'] = $style; return $this; }

    /**
     * The language the views inside say their text in, a BCP 47 tag
     * ('zh-Hans'), as SwiftUI's environment(\.locale, …): their keys looked
     * up in the app's catalogs in it, its numbers and dates.
     */
    /** What the views inside take from the environment: the language they say their text in; the way they lay out, right to left as Arabic's (layoutDirection), as SwiftUI's environment(\\.layoutDirection). */
    public function environment(?string $locale = null, ?\LayoutDirection $layoutDirection = null): static
    {
        if ($locale !== null || $layoutDirection === null) { $this->sets['locale'] = $locale; }
        if ($layoutDirection !== null) { $this->mods[] = ['direction', [$layoutDirection]]; }
        return $this;
    }

    /**
     * Drags the view out to other windows and apps as $data — or the Data
     * $data() makes as the drag starts — and to this process's typed
     * targets as $value, as SwiftUI's draggable; $options the operations
     * it permits and done(Result), which hears how it ended.
     */
    public function draggable(\MyPHP\Transfer\Data|Closure|string $data, ?\MyPHP\Transfer\DragOptions $options = null, mixed $value = null): static
    {
        if (is_string($data)) { [$data, $value] = [\MyPHP\Transfer\Data::text($data), $value ?? $data]; } // a string, as SwiftUI's draggable("…"): text to other apps, the string to a dropDestination(for: 'string') here
        $this->events[] = ['drag', [$data, $options ?? new \MyPHP\Transfer\DragOptions(), $value]];
        return $this;
    }

    /**
     * Takes what is dropped on the view, as SwiftUI's dropDestination(for:):
     * of DropOptions, data in one of its formats from this app or another,
     * $action(Drop); of a type ('string', 'array', a class), a value of it
     * dragged within the window (draggable(value:)), $action($value).
     * $isTargeted(true) as a drag of them comes over it, (false) as it leaves.
     */
    public function dropDestination(\MyPHP\Transfer\DropOptions|string $for, Closure $action, ?Closure $isTargeted = null): static
    {
        $this->events[] = ['drop', [$for, $action, $isTargeted]];
        return $this;
    }

    /** Runs $action(old, new) as $for(ScrollGeometry) changes, the view a ScrollView or a TextEditor scrolled, as SwiftUI's onScrollGeometryChange(for:of:action:). */
    public function onScrollGeometryChange(Closure $for, Closure $action): static { $this->events[] = ['scrollGeo', [$for, $action]]; return $this; }

    /** The ScrollView scrolls where $position is told to (scrollTo(y:), scrollTo(edge:)), as SwiftUI's scrollPosition($position). */
    public function scrollPosition(ScrollPosition $position): static { $this->events[] = ['scrollPos', $position]; return $this; }

    /** A search field over the view, bound to $text, as SwiftUI's searchable(text:prompt:) in a sidebar: the app filters what it shows by it. */
    public function searchable(string &$text, string $prompt = 'Search'): static
    {
        $this->mods[] = ['search', [&$text, $prompt]]; // a layer around what is before it, as a frame is
        return $this;
    }

    /**
     * Scopes under the field of searchable() (after it in the chain), bound
     * to $scope: Texts or Labels tagged with the values they choose, as
     * SwiftUI's searchScopes(_:scopes:), a segmented control across the
     * view under the field (a Picker's, pickerStyle(.segmented)).
     */
    public function searchScopes(mixed &$scope, View ...$scopes): static
    {
        for ($i = count($this->mods) - 1; $i >= 0; $i--) {
            if ($this->mods[$i][0] === 'search') {
                $this->mods[$i][1][2] = &$scope;
                $this->mods[$i][1][3] = $scopes;
                return $this;
            }
        }
        throw new \LogicException("searchScopes() goes after searchable(), whose field it is under: ->searchable(\$this->query)->searchScopes(\$this->scope, Text('All')->tag('all'), …)");
    }

    /**
     * A find bar over the view while $isPresented, as SwiftUI's
     * findNavigator(isPresented:) of a TextEditor, for any view: Cmd+F opens
     * it; its field is bound to $text, it shows $matches, the app's count of
     * them, and its arrows, Return and Cmd+G step $current through them,
     * which the app shows (onChange(of: $current)); Escape and Done close it.
     */
    public function findNavigator(bool &$isPresented, string &$text, int $matches = 0, int &$current = 0): static
    {
        $this->mods[] = ['find', [&$isPresented, &$text, $matches, &$current]]; // a layer around what is before it, as a frame is
        return $this;
    }

    /** Whether the view takes the pointer: false lets a press through to what is under it, as SwiftUI's allowsHitTesting; assistive technology still presses it. */
    public function allowsHitTesting(bool $enabled): static { $this->events[] = ['hit', $enabled]; return $this; }

    /** A gesture of the view's: WindowDragGesture() moves the window, as SwiftUI's gesture(WindowDragGesture()). */
    public function gesture(\WindowDragGesture|DragGesture $gesture): static
    {
        $this->events[] = $gesture instanceof DragGesture ? ['dragGesture', $gesture] : ['windowDrag', null];
        return $this;
    }

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

    /**
     * Runs $action(KeyPress) for $key pressed while the view shows and no
     * field takes it — an arrow, Escape, Space, or a character with its
     * modifiers in the KeyPress — as SwiftUI's onKeyPress of a focused view.
     */
    public function onKeyPress(\KeyEquivalent|string $key, Closure $action): static
    {
        $this->events[] = ['key', [$key instanceof \KeyEquivalent ? $key->key() : mb_strtolower($key), $action]];
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

    /** @internal the heights the sheet this view is the content of comes to rest at (presentationDetents) @var list<\PresentationDetent> */
    public array $sheetDetents = [];

    /**
     * The heights the sheet this view is the content of comes to rest at,
     * as SwiftUI's presentationDetents: [PresentationDetent::medium(),
     * PresentationDetent::large()] opens it at half the window, a drag
     * taking it to the whole and back, down past the smallest closing it.
     * @param list<\PresentationDetent> $detents
     */
    public function presentationDetents(array $detents): static { $this->sheetDetents = $detents; return $this; }

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
            if ($c->swipedRow !== null) { self::takeRoom($c->swipedRow, $env->axis, self::FLEX_X); } // across the row it is in (a list's), where a finger swipes it, as UIKit's cell
            $node ??= $row;
        } else {
            $node = $this->renderBox($c, $env);
        }
        foreach ($this->presentations as $p) { $p->render($c, $env); }
        return $node;
    }

    /** The search field of a searchable() layer, at the top of its box $box: bound to $f[0], its prompt $f[1]; with searchScopes(), $f[2] the scope and $f[3] its choices, a segmented control under it. @param array{0: string, 1: string, 2?: mixed, 3?: list<View>} $f */
    private static function searchField(Context $c, Env $env, Node $box, array &$f): void
    {
        $make = fn() => (object)['query' => new \MyPHP\UI\TextState($f[0]), 'app' => $f[0]];
        $kept = $c->runtime?->local($box->id, $make) ?? $make();
        if ($f[0] !== $kept->app) { $kept->query->text = $f[0]; $kept->query->setCursor(strlen($f[0])); } // set by the app
        $row = $c->row(8, 0)->alignSelf(Node::STRETCH);
        if ($c->runtime?->touch ?? false) { $row->padding(8, 16, 10, 16); } // a phone's: 16 in from the sides, as UISearchBar's
          $field = $c->searchField($kept->query, 0)->label(Localized::string($f[1], $env))->grow(1);
          $field->text = Localized::string($f[1], $env); // the prompt shows in it, as SwiftUI's
        $c->end();
        $f[0] = $kept->app = $kept->query->text;
        if (isset($f[3]) && $f[3] !== []) { // the scopes: a Picker's segments across under the field, as UISearchBar's scope bar
            $scope = &$f[2];
            $chosen = new Binding(get: function () use (&$scope) { return $scope; }, set: function ($v) use (&$scope): void { $scope = $v; });
            $bar = $c->row(0, 0)->alignSelf(Node::STRETCH);
            if ($c->runtime?->touch ?? false) { $bar->padding(0, 16, 10, 16); }
              $seg = (new Picker('', $chosen))(...$f[3])->pickerStyle(\PickerStyle::segmented)->render($c, $env->along(Node::ROW));
              if ($seg !== null) { $seg->grow = 1; $seg->shrink(1)->minWidth(0); foreach ($seg->kids as $k) { $k->basis(0)->grow = 1; } } // across the view, the segments sharing it
            $c->end();
        }
    }

    /** The find bar of a findNavigator() layer, at the top of its box $box. @param array{0: bool, 1: string, 2: int, 3: int} $f */
    private static function findBar(Context $c, Node $box, array &$f): void
    {
        $make = fn() => (object)['query' => new \MyPHP\UI\TextState($f[1]), 'app' => $f[1]];
        $kept = $c->runtime?->local($box->id, $make) ?? $make();
        if ($f[1] !== $kept->app) { $kept->query->text = $f[1]; $kept->query->setCursor(strlen($f[1])); } // set by the app
        if ($c->shortcut('f', \MyPHP\UI\Key::COMMAND)) { $f[0] = true; }
        $c->findBar($f[0], $kept->query, $f[2], $f[3])?->alignSelf(Node::STRETCH);
        $f[1] = $kept->app = $kept->query->text;
    }

    /** The view and its boxing modifiers. */
    private function renderBox(Context $c, Env $env): ?Node
    {
        // The boxing modifiers in layers, as SwiftUI's wrap the view: a frame is a box around what is before it, the view
        // keeping its own size in it, placed by the frame's alignment (one that takes the room filling it); a padding
        // after one that paints the box goes on a box around it too.
        $layers = [[]];
        $painted = false;
        foreach ($this->mods as $m) {
            if (in_array($m[0], ['frame', 'overlay', 'search', 'find', 'direction'], true) || ($painted && $m[0] === 'padding')) { $layers[] = []; $painted = false; }
            $layers[count($layers) - 1][] = $m;
            if (in_array($m[0], ['background', 'border', 'radius', 'clip', 'shadow', 'opacity', 'frame'], true)) { $painted = true; } // a padding after these goes around them: a frame's size is its own, the padding outside it
        }
        $outer = [];
        if ($this->identity !== null) { $c->key($this->identity); }
        if ($this->animated !== null && Lifecycle::ofId($c, $c->upcomingId())?->animatedChanged($this->animated[1])) { // animation(_:value:): what changes as its value changes animates (kept with the node's lifecycle, not its local state, which the view's own is)
            $env = $env->with(['animation' => $this->animated[0] ?? self::instant()]);
        }
        $anim = $env->animation ?? Transaction::current($c);
        for ($i = count($layers) - 1; $i > 0; $i--) { // the outer layers, boxes the view goes in, outermost first
            $first = $layers[$i][0][0];
            $box = $first === 'overlay' ? $c->grid([Track::fr()]) : $c->column(0, 0, 0)->alignItems($first === 'search' || $first === 'find' ? Node::STRETCH : Node::CENTER); // an overlay's, a cell the view and it share
            if ($first === 'direction') { $box->direction($layers[$i][0][1][0] === \LayoutDirection::rightToLeft ? \MyPHP\UI\Direction::RightToLeft : \MyPHP\UI\Direction::LeftToRight); } // before what is inside, which reads it
            if ($first === 'search') { self::searchField($c, $env, $box, $layers[$i][0][1]); } // over the view, in its box
            if ($first === 'find') { self::findBar($c, $box, $layers[$i][0][1]); }
            $outer[] = [$box, $layers[$i]];
        }
        $node = $this->build($c, $outer === [] ? $env : $env->inBox()); // in a box, a column: what takes the room takes the box's
        $whole = $outer === [] ? $node : $outer[0][0];
        if ($node !== null) {
            $this->apply($node, $layers[0], $env, $anim);
            $this->events($c, $node, $whole ?? $node, $env);
        }
        [$fixedW, $fixedH] = [$node !== null && $node->sizedW, $node !== null && $node->sizedH]; // a size of its own inside, which the boxes around keep
        foreach (array_reverse($outer, true) as $k => [$box, $mods]) {
            $at = $k === 0 ? $env : $env->inBox(); // the outermost box is in the stack; the others in the box around them, a column
            $view = $box->kids[0] ?? null; // the view, before an overlay's or a background's (which goes under it) is built
            if ($mods[0][0] === 'overlay') { self::overlaid($c, $box, $mods[0][1], $env); }
            $c->end();
            $flex = match ($mods[0][0]) { // an overlay takes the view's size, a search field or find bar over it the view's room
                'overlay' => $view === null ? 0 : self::flexOf($view, Node::COLUMN),
                'search', 'find' => $box->kids === [] ? 0 : self::flexOf($box->kids[count($box->kids) - 1], Node::COLUMN),
                default => self::flexOfKids($box),
            };
            self::takeRoom($box, $at->axis, $flex); // a box around a view that takes the room takes it
            $this->apply($box, $mods, $at, $anim);
            [$fixedW, $fixedH] = [$fixedW || $box->sizedW, $fixedH || $box->sizedH];
            if ($k === 0 && ($env->axis === Node::ROW ? $fixedW : $fixedH)) { $box->shrink(0); } // around a frame(width: 280), no smaller along the stack, as the frame
            if (count($box->kids) === 1 && $box->kids[0]->aspect > 0 && ($h = $box->fh > 0 ? $box->fh : $box->maxH) < INF) { // a picture scaled to fit in a box of a height: no higher, drawn within it (Node::CONTAIN), as SwiftUI's fits both
                $box->kids[0]->maxHeight(min($box->kids[0]->maxH, $h));
            }
            if (count($box->kids) === 1 && $box->kids[0]->aspect > 0 && $box->fh <= 0 && $box->aspect <= 0) {
                $box->aspectRatio($box->kids[0]->aspect); // a picture scaled to fit: the box as high as its width makes it, as the picture
            }
        }
        if ($this->moves !== null && $whole !== null) { // transition, matchedGeometryEffect: how it comes, goes and flies from where its match was
            [$t, $match] = $this->moves;
            $c->transition($whole, new \MyPHP\UI\ElementTransition(animation: $anim ?? \MyPHP\UI\Animation::default(), enter: $t?->insertion, exit: $t?->removal, match: $match));
        }
        return $whole;
    }

    /** Builds $a's view over the one view in $box, an overlay's cell, as large as it. @param array{View, \Alignment} $a */
    private static function overlaid(Context $c, Node $box, array $a, Env $env): void
    {
        $box->kids[0]?->columnStart(1)->rowStart(1);
        $kid = $a[0]->render($c, $env->along(Node::COLUMN));
        if ($kid === null) { return; }
        $kid->columnStart(1)->rowStart(1);
        $f = self::flexOf($kid, Node::COLUMN);
        $a[1]->place($kid, true);
        if ($f & self::FLEX_X) { $kid->justifySelf(Node::STRETCH); }
        if ($f & self::FLEX_Y) { $kid->alignSelf(Node::STRETCH); }
        if (($a[2] ?? false) && count($box->kids) > 1) { array_unshift($box->kids, array_pop($box->kids)); } // a background's: under the view
    }

    /** View $v filling a pane of a split: a column taking all of it. @internal */
    public static function fillPane(Context $c, Env $env, View $v): void
    {
        $c->column(0, 0, 1)->alignSelf(Node::STRETCH);
          $v->render($c, $env);
        $c->end();
    }

    /** Where scroll $n is, as the frame before laid it out: a ScrollView's or a TextEditor's. */
    private static function scrollGeometry(Context $c, Node $n): ?ScrollGeometry
    {
        if ($n->scroll instanceof \MyPHP\UI\ScrollState) {
            $r = $c->runtime?->rectOf($n->id) ?? [0, 0, 0, 0];
            return new ScrollGeometry([$n->scroll->x ?? 0.0, $n->scroll->y], [$r[2], $n->scroll->maxY + $r[3]], [$r[2], $r[3]]);
        }
        if ($n->edit instanceof \MyPHP\UI\TextState && $n->edit->multiline) {
            $e = $n->edit;
            return new ScrollGeometry([0.0, $e->scrollY], [0.0, $e->scrollRange + $e->visibleHeight], [0.0, $e->visibleHeight]);
        }
        return null;
    }

    /** Applies the boxing modifiers $mods to $node. */
    private function apply(Node $n, array $mods, Env $env, ?\MyPHP\UI\Animation $anim = null): void
    {
        // Offset, scale, rotation and opacity: its pose, which an animation in effect takes it to (at once otherwise), as SwiftUI animates them.
        $pose = null;
        $glass = false;
        foreach ($mods as [$name, $a]) {
            if ($name === 'glass') { $glass = true; }
            if (in_array($name, ['offset', 'scale', 'rotate', 'opacity'], true)) { $pose ??= ['x' => 0.0, 'y' => 0.0, 'scale' => 1.0, 'rotate' => 0.0, 'fade' => 0.0]; }
        }
        foreach ($mods as [$name, $a]) {
            if ($pose !== null) {
                match ($name) {
                    'offset' => $glass ? null : [$pose['x'], $pose['y']] = [$pose['x'] + $a[0], $pose['y'] + $a[1]],
                    'scale' => $pose['scale'] *= $a[0],
                    'rotate' => $pose['rotate'] += $a[0],
                    'opacity' => $pose['fade'] = 1 - (1 - $pose['fade']) * max(0.0, min(1.0, $a[0])),
                    default => null,
                };
                if ($name === 'scale' || $name === 'rotate' || $name === 'opacity' || ($name === 'offset' && !$glass)) { continue; }
            }
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
                    // A fixed size is the frame's, as SwiftUI's: neither grown nor shrunk along the stack it is in.
                    if ($w !== null) { $n->width($w); $env->axis === Node::ROW ? $n->shrink(0)->grow = 0 : ($n->self === Node::STRETCH && $n->alignSelf(Node::AUTO)); }
                    if ($h !== null) { $n->height($h); $env->axis === Node::COLUMN ? $n->shrink(0)->grow = 0 : ($n->self === Node::STRETCH && $n->alignSelf(Node::AUTO)); }
                    if ($minW !== null) { $n->minWidth($minW); }
                    if ($minH !== null) { $n->minHeight($minH); }
                    if ($maxW === INF) { $env->axis === Node::ROW ? $n->grow = max($n->grow, 1) : $n->alignSelf(Node::STRETCH); } elseif ($maxW !== null) { $n->maxWidth($maxW); }
                    if ($maxH === INF) { $env->axis === Node::COLUMN ? $n->grow = max($n->grow, 1) : $n->alignSelf(Node::STRETCH); } elseif ($maxH !== null) { $n->maxHeight($maxH); }
                    // Stretched across no wider (higher) than its largest size, it is centered in the room, as SwiftUI places a frame(maxWidth: 400) in a VStack, not at its start.
                    if ($maxW !== null && $maxW < INF && $env->axis === Node::COLUMN && $n->self === Node::STRETCH) { $n->widthPercent(100)->alignSelf(Node::CENTER); }
                    if ($maxH !== null && $maxH < INF && $env->axis === Node::ROW && $n->self === Node::STRETCH) { $n->heightPercent(100)->alignSelf(Node::CENTER); }
                    if ($align !== \Alignment::center || $maxW === INF || $maxH === INF || $w !== null || $h !== null) { $align->place($n); }
                    break;
                case 'background': $n->background($a[0]->rgb($env), $a[0]->alpha); break;
                case 'radius': $n->radius($a[0]); break;
                case 'clip': $a[0]->clip($n); break;
                case 'border': if ($a[0]->alpha > 0) { $n->border($a[1], $a[0]->rgb($env)); } break; // a clear one draws nothing: the engine's borders are opaque
                case 'shadow': $n->shadow($a[2], $a[3], $a[1] * 2, 0, $a[0]?->rgb($env) ?? 0x000000, $a[0]?->alpha ?? 0.2); break;
                case 'opacity': $n->opacity($a[0]); break;
                case 'rotate': $n->rotate($a[0]); break;
                case 'gray': $n->grayscale(); break;
                case 'blur': $n->blur($a[0]); break;
                case 'rotate3d':
                    [$deg, [$ax, $ay]] = $a;
                    $n->transform(\MyPHP\UI\Transform::rotationX($deg)->then(\MyPHP\UI\Transform::rotation(rad2deg(atan2($ay, $ax)))));
                    break;
                case 'overlay': case 'search': case 'find': case 'direction': break; // their boxes, built with them
                case 'offset': $n->left($a[0])->top($a[1]); break; // moved in the layout (a relative inset), so that a system material behind it, placed by the layout, moves with it
                case 'glass':
                    [$m, $tint] = $a;
                    if ($tint !== null && $m instanceof \MyPHP\Plugins\Glass\Glass) { $m = clone $m; $m->tint = ColorValue::of($tint)->rgb($env); }
                    $n->material($m);
                    break;
                case 'hidden': $n->invisible(); break;
            }
        }
        if ($pose !== null) {
            $n->animatePose(new \MyPHP\UI\Pose($pose['x'], $pose['y'], $pose['scale'] == 0 ? 1e-4 : $pose['scale'], $pose['rotate'], $pose['fade']), $anim ?? self::instant());
        }
    }

    private static ?\MyPHP\UI\Animation $instant = null;

    /** An animation that is none: a pose changed outside withAnimation and animation(_:value:) goes there at once. */
    private static function instant(): \MyPHP\UI\Animation
    {
        return self::$instant ??= new \MyPHP\UI\Animation(0.0001);
    }

    /** Flexibility: the room across (1) and down (2) node $n takes in a stack along $axis, as SwiftUI's flexible views take what they are offered. */
    public const FLEX_X = 1, FLEX_Y = 2;

    /** How flexible $n, in a stack along $axis, is: FLEX_X and FLEX_Y. @internal */
    public static function flexOf(Node $n, int $axis): int
    {
        $along = $n->grow > 0 ? ($axis === Node::ROW ? self::FLEX_X : self::FLEX_Y) : 0;
        $across = $n->self === Node::STRETCH || ($axis === Node::ROW ? $n->hPct : $n->wPct) >= 1.0 ? ($axis === Node::ROW ? self::FLEX_Y : self::FLEX_X) : 0; // stretched, or all the room's up to its largest size (a frame(maxWidth: 400))
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

    /** Follows the press on $n of DragGesture $g: its onChanged as it moves, its onEnded as it lets go. */
    private static function dragGesture(Context $c, Node $n, DragGesture $g): void
    {
        $n->draggable = true; // a press on it is its own: the gesture's, not a click's
        $rt = $c->runtime;
        $life = Lifecycle::of($c, $n);
        if ($rt === null || $life === null) { return; }
        $p = $rt->pressOf($n->id);
        $mouse = [$rt->input->mouseX, $rt->input->mouseY];
        $value = static function (array $start, array $at, array $v) use ($rt, $n): DragGestureValue {
            $local = static fn(array $w) => $rt->toLocal($n->id, $w[0], $w[1]) ?? $w;
            [$l, $s] = [$local($at), $local($start)];
            [$tx, $ty] = [$at[0] - $start[0], $at[1] - $start[1]];
            [$px, $py] = [\MyPHP\UI\Motion::project($tx, $v[0]), \MyPHP\UI\Motion::project($ty, $v[1])];
            return new DragGestureValue(new CGPoint($l[0], $l[1]), new CGPoint($s[0], $s[1]), new CGSize($tx, $ty), new CGSize($v[0], $v[1]),
                new CGPoint($s[0] + $px, $s[1] + $py), new CGSize($px, $py));
        };
        if ($p !== null && $p['down']) {
            $life->dragStart ??= $mouse;
            $life->dragVelocity = $c->dragVelocity($n);
            [$dx, $dy] = [$mouse[0] - $life->dragStart[0], $mouse[1] - $life->dragStart[1]];
            if (!$life->dragActive && sqrt($dx * $dx + $dy * $dy) >= $g->minimumDistance) { $life->dragActive = true; }
            if ($life->dragActive) {
                $v = $value($life->dragStart, $mouse, $life->dragVelocity);
                if ($life->dragLast === null || $v->translation != $life->dragLast->translation) { $life->dragLast = $v; if ($g->changed !== null) { ($g->changed)($v); } }
            }
            return;
        }
        if ($life->dragStart !== null) { // let go
            if ($life->dragActive && $g->ended !== null) { ($g->ended)($value($life->dragStart, $mouse, $p['v'] ?? $life->dragVelocity ?? [0.0, 0.0])); }
            [$life->dragStart, $life->dragVelocity, $life->dragLast, $life->dragActive] = [null, null, null, false];
        }
    }

    /** @var list<?\ColorScheme> the appearance preferredColorScheme() set last, none before */
    private static array $scheme = [];
    /** The DynamicTypeSize the app's views asked for last, in a list: [] before any. @var list<?\DynamicTypeSize> */
    private static array $typeSize = [];

    /** The events and accessibility of $node, the view's own; the gestures (a tap, the pointer over it, its menus, a tip) $whole's, the frame around it with what it holds, as SwiftUI's gestures after a frame. */
    private function events(Context $c, Node $node, Node $whole, Env $env): void
    {
        $changes = 0;
        foreach ($this->events as [$kind, $a]) {
            switch ($kind) {
                case 'scrollGeo':
                    $geo = self::scrollGeometry($c, $node);
                    if ($geo !== null && Lifecycle::of($c, $node)?->changed($changes++, $value = ($a[0])($geo), $old)) { ($a[1])($old, $value); $c->runtime?->invalidate(); }
                    break;
                case 'scrollPos':
                    if ($node->scroll instanceof \MyPHP\UI\ScrollState && ($asked = $a->take()) !== null) {
                        [$y, $edge] = $asked;
                        $node->scroll->scrollTo($edge === \Edge::bottom ? $node->scroll->maxY : ($edge === \Edge::top ? 0.0 : (float)$y), false);
                    }
                    break;
                case 'change':
                    if (Lifecycle::of($c, $node)?->changed($changes++, $a[0], $old)) { ($a[1])($old, $a[0]); $c->runtime?->invalidate(); } // what it changed shows in the next frame, built anew
                    break;
                case 'hover':
                    $over = $c->hovered($whole);
                    if (Lifecycle::of($c, $whole)?->hoverChanged($over)) { ($a)($over); }
                    break;
                case 'select':
                    $a === \TextSelectability::enabled ? $node->selectable() : $node->unselectable();
                    break;
                case 'scheme':
                    if (self::$scheme !== [$a]) { // the app's appearance, set as it changes
                        self::$scheme = [$a];
                        \MyPHP\App::setColorScheme(match ($a) { \ColorScheme::light => false, \ColorScheme::dark => true, null => null }); // the platform's theme in it: a phone's stays UIKit's
                    }
                    break;
                case 'typeSize':
                    if (self::$typeSize !== [$a]) { // the app's text size, set as it changes
                        self::$typeSize = [$a];
                        \MyPHP\App::setTextScale($a?->scale());
                    }
                    break;
                case 'drag':
                    [$data, $o, $value] = $a;
                    if ($value !== null) { $c->drag($whole, $value); }
                    $data instanceof Closure ? $c->dragDataFrom($whole, $data, $o) : $c->dragData($whole, $data, $o);
                    break;
                case 'drop':
                    [$o, $action, $targeted] = $a;
                    $over = is_string($o) ? $c->dragOver($whole, $o) : $c->dataDragOver($whole, $o);
                    if ($targeted !== null && Lifecycle::of($c, $whole)?->targetChanged($over)) { $targeted($over); }
                    if (is_string($o) ? $c->drop($whole, $o, $value) : $c->dropData($whole, $o, $value)) { $action($value); }
                    break;
                case 'hit':
                    $whole->ignoreInput(!$a);
                    break;
                case 'windowDrag':
                    $whole->dragWindow();
                    break;
                case 'effect':
                    [$shader, $uniforms, $offset] = $a;
                    $node->effect($shader->effect(new \MyPHP\UI\Shader\Options(uniforms: $uniforms, maxSampleOffset: $offset === null ? [0, 0] : [$offset->width, $offset->height])));
                    break;
                case 'dragGesture':
                    self::dragGesture($c, $whole, $a);
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
                    $whole->contextMenu(static fn(\MyPHP\UI\Menu $m) => Menu::items($m, $items, $env));
                    break;
                case 'toast':
                    if ($a[0] !== null) { $c->toast(Localized::string($a[0], $env)); $a[0] = null; } // $a[0] the binding
                    break;
                case 'popover':
                    $content = $a[1];
                    if ($c->runtime?->window?->get() instanceof \MyPHP\Window) { // a window's: in a flyout outside it, as SwiftUI's on a Mac
                        $c->runtime->local($whole->id, static fn() => new PopoverKept(), PopoverKept::class)->follow($c, $whole, $a[0], $content, $a[2]);
                        break;
                    }
                    $c->popover($whole, $a[0], static function () use ($c, $env, $content): void { View::renderAll($c, $env->along(Node::COLUMN), $content()); }); // $a[0] the binding: an array's reference stays one in its copies
                    break;
                case 'tap':
                    $whole->focusable(false);
                    if ($a[1] === 2 ? $c->doubleClicked($whole) : $c->clicked($whole)) {
                        if ((new \ReflectionFunction($a[0]))->getNumberOfParameters() === 0) { ($a[0])(); break; }
                        $rt = $c->runtime;
                        [$mx, $my] = [$rt?->input->mouseX ?? 0.0, $rt?->input->mouseY ?? 0.0];
                        $at = $a[2] === \CoordinateSpace::global ? [$mx, $my] : ($rt?->toLocal($whole->id, $mx, $my) ?? [0.0, 0.0]);
                        ($a[0])(new CGPoint($at[0], $at[1]));
                    }
                    break;
                case 'help': $whole->tooltip(Localized::string($a, $env)); break;
                case 'label': $node->label(Localized::string($a, $env)); break;
                case 'key':
                    foreach ([0, \MyPHP\UI\Key::SHIFT, \MyPHP\UI\Key::COMMAND, \MyPHP\UI\Key::OPTION, \MyPHP\UI\Key::CONTROL] as $mods) {
                        if ($c->shortcut($a[0], $mods)) { ($a[1])(\KeyPress::of($mods)); break; }
                    }
                    break;
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
