<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node, Route, Theme};

/**
 * What the views inside a view inherit, as SwiftUI's environment: the font,
 * the foreground color, the tint, whether they are disabled, the style of
 * their buttons, the axis of the stack they are in, the page of a
 * NavigationStack they are on. A modifier sets it for the views inside
 * (View::font…); a new Env each time, the outer one unchanged.
 */
final class Env
{
    public ?FontSpec $font = null;
    public ?\Weight $weight = null;
    public bool $italic = false, $mono = false, $disabled = false, $underline = false, $strikethrough = false;
    public ?ColorValue $color = null, $tint = null;
    public ?\ButtonStyle $buttonStyle = null;
    public ?\LabelStyle $labelStyle = null;
    public ?\PickerStyle $pickerStyle = null;
    public ?\TextFieldStyle $textFieldStyle = null;
    public ?\ListStyle $listStyle = null;
    /** A Form's row: a control's title at its start and the control at its end. */
    public bool $formRow = false;
    /** Direct child of a Form row (not nested inside an HStack/VStack): single-line TextField and segmented Picker render automatic leading labels. */
    public bool $directFormRow = false;
    public bool $radioRow = false;
    /** Controls show no title, which stays their accessibility label (labelsHidden). */
    public bool $labelsHidden = false;
    /** The language the views inside say their text in (environment(locale:)), null for the app's. */
    public ?string $locale = null;
    public ?\DatePickerStyle $datePickerStyle = null;
    public ?\RedactionReasons $redacted = null;
    public ?\TextAlignment $textAlign = null;
    public ?int $lineLimit = null;
    /** A vertical TextField as high as its lineLimit's lines however few it holds (lineLimit(n, reservesSpace: true)). */
    public bool $linesReserved = false;
    /** The axis of the stack the view is in: Node::COLUMN (a VStack, the window) or Node::ROW (an HStack). */
    public int $axis = Node::COLUMN;
    /** The axis of the stack the view is in, through the frames around it (a Divider's, down an HStack even framed). */
    public int $stackAxis = Node::COLUMN;
    /** The page of a NavigationStack the view is on, which navigationTitle() names, and the stack. */
    public ?Route $route = null;
    public ?NavigationStack $stack = null;
    /** Whether a bar above takes the toolbar of the view built (a NavigationSplitView's detail): no bar of its own. @internal */
    public bool $toolbarTaken = false;
    public ?string $title = null;
    public ?\TitleDisplayMode $titleMode = null;
    /** What the page's scroll is painted in, which the stack and tabs around it take behind their bars (iOS's bars, clear over the page). */
    public ?Backdrop $backdrop = null;
    /** @var list<mixed>|null */
    public ?array $toolbar = null;
    /** Whether the view is a row of a ListView, Section or Form, which draws NavigationLinks and Buttons as cells. */
    public bool $inList = false;
    /** The rows of a ListView on alternating backgrounds (alternatingRowBackgrounds). */
    public bool $alternatingRows = false;
    /** The ScrollViewReader the view is in, whose proxy scrolls the ListView inside. */
    public ?ScrollViewProxy $scrollProxy = null;
    /** The animation the views inside take a change with (animation(_:value:) as its value changed), null for none. */
    public ?\MyPHP\UI\Animation $animation = null;
    /** What a pull of the ScrollView or ListView inside runs (refreshable). */
    public ?\Closure $refresh = null;
    /** Whether the scroll views inside show their bars, and on which axis (scrollIndicators): [visibility, axis], null for the system's. @var ?array{0: \ScrollIndicatorVisibility, 1: \Axis} */
    public ?array $scrollIndicators = null;
    /** How a finger scrolling the scroll views inside closes the keyboard (scrollDismissesKeyboard), null for the system's (it does not). */
    public ?\ScrollDismissesKeyboardMode $scrollDismissesKeyboard = null;
    /** Whether a List's or Form's own background shows behind its sections (scrollContentBackground), null for the system's (it does). */
    public ?\Visibility $scrollContentBackground = null;
    /** Whether the scroll views inside leave their content unclipped at their edges (scrollClipDisabled). */
    public bool $scrollClipDisabled = false;

    public function __construct(public Theme $theme) {}

    /** The environment of a window's root view. */
    public static function root(Context $c): self
    {
        return new self($c->theme);
    }

    /** This environment with $sets (View::$sets) over it. @param array<string, mixed> $sets */
    public function with(array $sets): self
    {
        $e = clone $this;
        foreach ($sets as $k => $v) { $e->$k = $v; }
        return $e;
    }

    /** This environment inside a stack along $axis. */
    public function along(int $axis): self
    {
        if ($axis === $this->axis && $axis === $this->stackAxis) { return $this; }
        $e = clone $this;
        $e->axis = $e->stackAxis = $axis;
        return $e;
    }

    /**
     * The environment's properties a kept subtree is not built again for: the page's plumbing (its route, stack, bars,
     * title), made anew each frame, and the theme, which Context::reactiveMemo compares itself. Every other property is
     * in memoKey(), so one added to Env is compared with no list to update (reactive_test checks this list).
     */
    public const NOT_IN_MEMO_KEY = ['theme', 'route', 'stack', 'toolbarTaken', 'title', 'titleMode', 'backdrop', 'toolbar', 'scrollProxy', 'animation', 'refresh'];

    /** What of the environment a kept subtree (Computed, a stack told by value) is built again for when it changes. @internal @return array<string, mixed> */
    public function memoKey(): array
    {
        $key = get_object_vars($this);
        foreach (self::NOT_IN_MEMO_KEY as $name) { unset($key[$name]); }
        foreach ($key as $name => $v) {
            if (is_object($v) && !$v instanceof \UnitEnum) { $key[$name] = [$v::class, (array)$v]; } // a font, a color: made anew each frame, compared by what it is
        }
        return $key;
    }

    /** The environment inside a frame's box, a column, the stack's axis kept. @internal */
    public function inBox(): self
    {
        if ($this->axis === Node::COLUMN) { return $this; }
        $e = clone $this;
        $e->axis = Node::COLUMN;
        return $e;
    }
}
