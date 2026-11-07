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
    public ?\PickerStyle $pickerStyle = null;
    public ?\TextFieldStyle $textFieldStyle = null;
    public ?\ListStyle $listStyle = null;
    /** A Form's row: a control's title at its start and the control at its end. */
    public bool $formRow = false;
    public bool $radioRow = false;
    /** Controls show no title, which stays their accessibility label (labelsHidden). */
    public bool $labelsHidden = false;
    /** The language the views inside say their text in (environment(locale:)), null for the app's. */
    public ?string $locale = null;
    public ?\DatePickerStyle $datePickerStyle = null;
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
    public ?string $title = null;
    public ?\TitleDisplayMode $titleMode = null;
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

    public function __construct(public readonly Theme $theme) {}

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

    /** The environment inside a frame's box, a column, the stack's axis kept. @internal */
    public function inBox(): self
    {
        if ($this->axis === Node::COLUMN) { return $this; }
        $e = clone $this;
        $e->axis = Node::COLUMN;
        return $e;
    }
}
