<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** An element of an AccessTree (mygo's platform.AccessNode). */
final class AccessNode
{
    /** The states of an element. CHECKED is the choice among a tab list's tabs, a tree's items, and the rows of a list or a table that are SELECTABLE. */
    public const FOCUSABLE = 1 << 0, DISABLED = 1 << 1, CHECKED = 1 << 2, MIXED = 1 << 3, SELECTED = 1 << 4, EXPANDED = 1 << 5, MULTILINE = 1 << 6,
        PASSWORD = 1 << 7, READ_ONLY = 1 << 8, SELECTABLE = 1 << 9;
    /** OFFSCREEN is set on elements built but out of view, as the rows a list builds beyond its edges; MULTISELECTABLE on a list or a table whose user chooses several rows. */
    public const OFFSCREEN = 1 << 10, MULTISELECTABLE = 1 << 11;
    /** SEGMENT is set on the segments of a segmented control, as AppKit's subrole AXSegment says; SEARCH on a text field for searching; INVALID on a control whose value is not valid. */
    public const SEGMENT = 1 << 12, SEARCH = 1 << 13, INVALID = 1 << 14;
    /** Set on the header of the column a table's rows are sorted by. */
    public const SORT_ASCENDING = 1 << 15, SORT_DESCENDING = 1 << 16;
    /** EXPANDABLE is set on an item of a tree with children, which EXPANDED shows; VERTICAL on a slider going up. */
    public const EXPANDABLE = 1 << 17, VERTICAL = 1 << 18;

    /** The actions an element takes. SCROLL_INTO_VIEW scrolls the containers around it to show it; EXPAND opens and closes an item of a tree. */
    public const ACTION_PRESS = 1 << 0, ACTION_FOCUS = 1 << 1, ACTION_INCREMENT = 1 << 2, ACTION_DECREMENT = 1 << 3, ACTION_SET_VALUE = 1 << 4,
        ACTION_SCROLL_INTO_VIEW = 1 << 5, ACTION_EXPAND = 1 << 6;

    /** The actions of assistive technology on an element (Runtime::accessAction); SELECT_TEXT selects characters $from to $to of a text field's value. */
    public const PRESS = 0, FOCUS = 1, INCREMENT = 2, DECREMENT = 3, SET_VALUE = 4, SCROLL_INTO_VIEW = 5, EXPAND = 6, COLLAPSE = 7, SELECT_TEXT = 8;

    /** Names the element; $value is its value, as the text of a text field, for elements that have one. */
    public string $label = '', $value = '';
    /** @var array{0: float, 1: float, 2: float, 3: float} the element's box, in points of the window */
    public array $bounds = [0.0, 0.0, 0.0, 0.0];
    public int $states = 0;
    /** The range and the value of a slider or a progress bar; $now is below $min for progress of unknown length. $step is how far an increment moves the value, 0 for none. */
    public float $min = 0.0, $max = 0.0, $now = 0.0, $step = 0.0;
    /** The selection of a text field, in characters, and what it shows while empty. */
    public int $selStart = 0, $selEnd = 0;
    public string $placeholder = '';
    /** Tells more about the element than its name, as help text read after it: a tooltip. */
    public string $description = '';
    /** How deep an item of a tree is, or the rank of a heading, from 1; 0 for other nodes. */
    public int $level = 0;
    /** The place of an item in its set, from 1, and the size of the set, 0 when not given: a list's rows say which of all they are, whether or not the others are built, and the list how many rows it has. */
    public int $posInSet = 0, $setSize = 0;
    /** The ACTION_ bits of what the element takes. */
    public int $actions = 0;

    /**
     * @param int $id identifies the element from frame to frame
     * @param int $parent the index of the parent node in AccessTree::$nodes, -1 for the elements at the top
     * @param int $role a Role, from Role::GROUP
     */
    public function __construct(public int $id, public int $parent, public int $role) {}
}
