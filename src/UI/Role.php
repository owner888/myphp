<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * What a node is to assistive technology, such as screen readers (mygo's
 * Role and platform.AccessRole): widgets set their own (Node::role); other
 * nodes get one from what they do: one that is clicked and takes the
 * focus is a button, a text is a text, a scroll container a scroll area,
 * and one with a label, or that takes the focus, a group. The tree's
 * nodes (AccessNode) have a role from GROUP on.
 */
final class Role
{
    /** AUTO finds the role from what the node does; NONE leaves the node out, but not its children. */
    public const AUTO = 0, NONE = 1;
    public const GROUP = 2, TEXT = 3, BUTTON = 4, LINK = 5, CHECK_BOX = 6, RADIO = 7, SWITCH = 8, SLIDER = 9, PROGRESS = 10, TEXT_FIELD = 11,
        IMAGE = 12, LIST = 13, SCROLL = 14, DIALOG = 15, POPUP = 16, TOOLTIP = 17, POP_UP_BUTTON = 18, TAB_LIST = 19, TAB = 20, SPLITTER = 21,
        STATUS = 22, TABLE = 23, ROW = 24, CELL = 25, COLUMN_HEADER = 26, TREE = 27, TREE_ITEM = 28;
    /** An item of a list: the rows of a list are. */
    public const LIST_ITEM = 29;
    /** A button opening a menu (Context::menuButton). */
    public const MENU_BUTTON = 30;
    /** TOOLBAR holds controls (Context::toolbar), RADIO_GROUP radio buttons (Context::segmented), and TOGGLE_BUTTON is a button that stays pressed (Context::toggle). */
    public const TOOLBAR = 31, RADIO_GROUP = 32, TOGGLE_BUTTON = 33;
    /** A text input with a popup of options (Context::combobox). */
    public const COMBO_BOX = 34;
    /** A button showing or hiding content below it, as the header of a collapsible, expanded while open. */
    public const DISCLOSURE = 35;
    /** METER shows a value in a range (Context::meter), and STEPPER steps a value in one (Context::stepper). */
    public const METER = 36, STEPPER = 37;
    /** A button showing a color, which it opens a picker of (Context::colorWell). */
    public const COLOR_WELL = 38;
    /** A dialog asking about something important (Context::alertDialog). */
    public const ALERT_DIALOG = 39;
    /** MENU holds the items of a menu drawn in the window, MENU_BAR the titles of menus along it, MENU_ITEM is an item of either, and MENU_ITEM_CHECK_BOX and MENU_ITEM_RADIO items showing a choice, checked with Node::checked. */
    public const MENU = 40, MENU_BAR = 41, MENU_ITEM = 42, MENU_ITEM_CHECK_BOX = 43, MENU_ITEM_RADIO = 44;
    /** The title of a section, named by its text, its rank set with Node::level. */
    public const HEADING = 45;

    /** Whether nodes of a role have a value in a range (AccessNode's min, max and now). */
    public static function ranged(int $r): bool
    {
        return $r === self::SLIDER || $r === self::PROGRESS || $r === self::METER || $r === self::STEPPER;
    }

    /** Whether nodes of a role take the text inside them as their name, rather than showing it as nodes of its own (mygo's leafRole). */
    public static function leaf(int $r): bool
    {
        return !in_array($r, [self::GROUP, self::LIST, self::SCROLL, self::DIALOG, self::ALERT_DIALOG, self::POPUP, self::STATUS, self::TAB_LIST, self::TABLE,
            self::ROW, self::CELL, self::TREE, self::LIST_ITEM, self::TOOLBAR, self::RADIO_GROUP, self::MENU, self::MENU_BAR], true);
    }
}
