<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * The elements a phone's screen reader moves through, of a frame's tree for
 * assistive technology (AccessTree): VoiceOver's UIAccessibilityElements
 * and TalkBack's virtual views, as the phones' Windows give them to the
 * system. A screen reader on a phone reads what one swipe reaches, so the
 * tree goes flat, in reading order: containers (groups, lists, toolbars,
 * dialogs) are no elements, what is in them is; a row of a list is one
 * element, its texts and images its name, its context menu and swipe
 * actions its own, the controls in it elements after it, as UIKit's cells.
 * What is nameless and inactive, or of no size, is left out; what a list
 * built beyond its edges stays, OFFSCREEN, which the screen reader scrolls
 * into view as it reaches it (SCROLL_INTO_VIEW), as it does UIKit's cells. The elements go as JSON, for the launchers'
 * Objective-C and Java, and only when they changed (encode). Not mygo's.
 */
final class AccessElements
{
    /** The kinds of elements, as the launchers map them to traits and classes. */
    private const KINDS = [
        Role::TEXT => 'text', Role::BUTTON => 'button', Role::LINK => 'link', Role::CHECK_BOX => 'checkbox', Role::RADIO => 'radio',
        Role::SWITCH => 'switch', Role::SLIDER => 'slider', Role::PROGRESS => 'progress', Role::TEXT_FIELD => 'field', Role::IMAGE => 'image',
        Role::POP_UP_BUTTON => 'popup', Role::TAB => 'tab', Role::ROW => 'row', Role::CELL => 'row', Role::TREE_ITEM => 'row',
        Role::LIST_ITEM => 'row', Role::MENU_BUTTON => 'popup', Role::TOGGLE_BUTTON => 'checkbox', Role::COMBO_BOX => 'field',
        Role::DISCLOSURE => 'button', Role::METER => 'progress', Role::STEPPER => 'slider', Role::COLOR_WELL => 'button',
        Role::MENU_ITEM => 'button', Role::MENU_ITEM_CHECK_BOX => 'checkbox', Role::MENU_ITEM_RADIO => 'radio', Role::HEADING => 'heading',
        Role::COLUMN_HEADER => 'heading', Role::STATUS => 'text',
    ];

    /** What acts on an element: an action other than scrolling it into view. */
    private const ACTS = AccessNode::ACTION_PRESS | AccessNode::ACTION_FOCUS | AccessNode::ACTION_INCREMENT | AccessNode::ACTION_DECREMENT
        | AccessNode::ACTION_SET_VALUE | AccessNode::ACTION_EXPAND;

    /** The last JSON given, which the next frame's is given only if it differs from. */
    private string $last = '';

    /**
     * The elements of $tree: id, kind, label, value, hint, the box (window points), the AccessNode states and actions,
     * the custom actions, the range of a slider, the heading level. @return list<array<string, mixed>>
     */
    public static function of(AccessTree $tree): array
    {
        $out = [];
        $at = []; // the element each node of the tree went into, by its index: its own, or the row it is folded into
        foreach ($tree->nodes as $i => $n) {
            $into = $n->parent >= 0 ? ($at[$n->parent] ?? null) : null; // the row around it, if its parent folded into one
            $kind = self::KINDS[$n->role] ?? null;
            $acts = ($n->actions & self::ACTS) !== 0;
            if ($into !== null && !$acts && ($kind === null || in_array($kind, ['text', 'image', 'heading'], true))) { // a row's text or image: the row's name already has it
                $out[$into]['actions'] |= $n->actions & AccessNode::ACTION_SHOW_MENU;
                $at[$i] = $into;
                continue;
            }
            if ($kind === null || $n->bounds[2] <= 0 || $n->bounds[3] <= 0) { continue; }
            if ($n->label === '' && $n->value === '' && !$acts && $n->customActions === [] && ($n->actions & AccessNode::ACTION_SHOW_MENU) === 0) { continue; } // nothing to read or do
            $e = ['id' => $n->id, 'kind' => ($n->states & AccessNode::SEARCH) !== 0 ? 'search' : $kind, 'label' => $n->label, 'value' => self::value($n),
                'hint' => $n->description !== '' ? $n->description : $n->placeholder,
                'x' => round($n->bounds[0], 1), 'y' => round($n->bounds[1], 1), 'w' => round($n->bounds[2], 1), 'h' => round($n->bounds[3], 1),
                'states' => $n->states, 'actions' => $n->actions & ~AccessNode::ACTION_SCROLL_INTO_VIEW, 'custom' => $n->customActions, 'level' => $n->level];
            if (Role::ranged($n->role)) { $e['min'] = $n->min; $e['max'] = $n->max; $e['now'] = $n->now; }
            $out[] = $e;
            if ($kind === 'row') { $at[$i] = count($out) - 1; } // what is inside it folds into it
        }
        return $out;
    }

    /**
     * The JSON of $tree's elements, what is on top (the dialog or menu the others are behind, 0 for none), the focus, the
     * name of the action that opens a context menu, in the app's language, and the announcements: null when nothing
     * changed since the last, which the screen reader keeps.
     */
    public function encode(AccessTree $tree, string $showMenu = 'Show Menu'): ?string
    {
        $top = 0;
        foreach ($tree->nodes as $n) {
            if ($n->role === Role::DIALOG || $n->role === Role::ALERT_DIALOG || $n->role === Role::MENU) { $top = $n->id; }
        }
        $state = json_encode(['elements' => self::of($tree), 'top' => $top, 'focus' => $tree->focus, 'showMenu' => $showMenu], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($state === $this->last && $tree->announcements === []) { return null; }
        $this->last = $state;
        return substr($state, 0, -1) . ',"say":' . json_encode($tree->announcements, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . '}';
    }

    /** What the screen reader says of an element's value: a field's text, a slider's, a progress's percent. */
    private static function value(AccessNode $n): string
    {
        if ($n->value !== '' || !Role::ranged($n->role)) { return $n->value; }
        if ($n->now < $n->min) { return ''; } // of unknown length
        if ($n->role === Role::PROGRESS && $n->max > $n->min) { return (string)(int)round(($n->now - $n->min) / ($n->max - $n->min) * 100) . '%'; }
        return rtrim(rtrim(number_format($n->now, 2, '.', ''), '0'), '.');
    }
}
