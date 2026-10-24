<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;
use MyPHP\{App, L10n};
use MyPHP\MenuItem as NativeItem;

/**
 * Context menus and menu buttons (mygo's menu.go): the secondary button,
 * or Control and a click, over a node with a context menu, a text input or
 * selectable text asks the next frame to build that node's menu, whose
 * function collects the items; the window shows it after the frame
 * ($popupMenu), and the item chosen reaches the function in the frame
 * after, where its chosen() reports it. Text inputs and selectable text
 * have one with their editing commands, which a context menu of their own
 * replaces, and editItems() puts back. A menu button's menu opens below it
 * as the pointer goes down on it, or for Enter, Space or Down.
 */
trait RuntimeMenu
{
    /** What the runtime knows of menus (mygo's menuState): the node whose menu the next frame builds, at (x, y), the text it opens on, whether it is a menu button's; the menu built, which shows after the frame; the item chosen from the one shown. */
    private int $menuAsked = 0, $menuText = 0, $menuChosen = 0, $menuChoice = 0, $menuChosenText = 0;
    private float $menuX = 0, $menuY = 0;
    private bool $menuButton = false, $menuChosenButton = false;
    private string $menuLabel = '';
    /** That a menu button's menu took the press, so its release is no click; that a context menu took the secondary button's, so its release is no right-click. */
    private bool $menuRelease = false, $menuTook = false;
    /** @var ?array{id: int, button: bool, items: list<NativeItem>, labels: list<string>, commands: ?list<string>, x: float, y: float, text: int} */
    private ?array $menuPending = null;
    /** @var array<int, int> the nodes of last frame with a context menu (1), a menu (2) or both, by id */
    private array $menuNodes = [];

    /**
     * Shows a menu built in a frame, once the frame is done (mygo's host
     * popupMenu): its items, where (window points), whether it opens to the
     * left, and what to call with the place of the item chosen (from 1),
     * or with 0 when it closes without one. The window sets it.
     * @var Closure(list<NativeItem>, float, float, bool, Closure(int): void): void
     */
    public Closure $popupMenu;

    private function initMenus(): void
    {
        $this->popupMenu = fn(array $items, float $x, float $y, bool $rtl, Closure $chosen) => null;
    }

    /** Notes the menus of $n for this frame's presses and keys. */
    private function recordMenus(Node $n): void
    {
        $f = ($n->contextMenu ? 1 : 0) | ($n->menuButton ? 2 : 0);
        if ($f !== 0 && !$n->isDisabled()) {
            $this->menuNodes[$n->id] = $f;
        }
    }

    /**
     * Builds the menu of node $id, its menu button's with $button, as it
     * opens, and again for the item chosen from it (mygo's buildMenu):
     * Node::contextMenu and Node::menu call it as the frame builds them.
     * @param Closure(Menu): void $build
     */
    public function buildMenu(int $id, bool $disabled, bool $button, Closure $build): void
    {
        if ($this->menuAsked === $id && $this->menuButton === $button) {
            $this->menuAsked = 0;
            if ($disabled) {
                return;
            }
            $run = new MenuRun(true);
            $m = new Menu($run, [], $this, $this->menuText);
            $build($m);
            $commands = array_filter($run->commands, fn(string $c) => $c !== '') !== [] ? $run->commands : null;
            $this->openMenu($id, $button, $m->items, $run->labels, $commands);
        } elseif ($this->menuChosen === $id && $this->menuChosenButton === $button) {
            $this->menuChosen = 0;
            $run = new MenuRun(false, $this->menuChoice, $this->menuLabel);
            $build(new Menu($run, null, $this, $this->menuChosenText));
            if ($run->chosen) {
                $this->consumed = true; // the choice may change what was built before: build again
            }
        }
    }

    /** The node whose context menu opens on text input or selectable text $s: $s, or the Selectable container whose text it is when the container has a context menu and $s has none (mygo's textMenu). */
    private function textMenu(int $s): int
    {
        $scope = isset($this->textIndex[$s]) ? $this->texts[$this->textIndex[$s]]->textScope : 0;
        if ((($this->menuNodes[$s] ?? 0) & 1) === 0 && $scope !== 0 && $scope !== $s && (($this->menuNodes[$scope] ?? 0) & 1) !== 0) {
            return $scope;
        }
        return $s;
    }

    /** Whether node $id is a text input, or selectable text, which has editing commands. */
    private function editable(int $id): bool
    {
        return isset($this->fields[$id]) || isset($this->textIndex[$id]);
    }

    /** The ids of last frame's nodes under (x, y) that menus look at, innermost first (mygo's hit chain, as far as menus go). @return list<int> */
    private function menuChain(float $x, float $y): array
    {
        $ids = array_keys($this->menuNodes + $this->fields + $this->textIndex + $this->selectScopes + $this->unselectables);
        $ids = array_values(array_filter($ids, fn(int $id) => $this->under($id, $x, $y)));
        usort($ids, fn(int $a, int $b) => $this->order[$b] <=> $this->order[$a]);
        return $ids;
    }

    /** The innermost node of $chain with a context menu, a text input's or selectable text's, or none (mygo's menuTarget). @param list<int> $chain */
    private function menuTarget(array $chain): int
    {
        foreach ($chain as $id) {
            if ((($this->menuNodes[$id] ?? 0) & 1) !== 0 || $this->editable($id) || isset($this->selectScopes[$id])) {
                return $id;
            }
            if (isset($this->unselectables[$id])) {
                return 0;
            }
        }
        return 0;
    }

    /** Has the next frame build the context menu of node $id, or its menu button's, to show at (x, y) (mygo's askMenu). */
    private function askMenu(int $id, float $x, float $y, bool $button): void
    {
        $this->menuAsked = $id; $this->menuX = $x; $this->menuY = $y; $this->menuButton = $button; $this->menuText = 0;
        $this->needsFrame = true;
    }

    /**
     * A press of the secondary button at (x, y): when a node there has a
     * context menu, it opens, and menuPress reports true. A text input takes
     * the focus, and the caret unless the press is in its selection; in
     * selectable text, a press in the selection keeps it (mygo's menuPress).
     */
    private function menuPress(float $x, float $y): bool
    {
        $s = $this->menuTarget($this->menuChain($x, $y));
        if ($s === 0) {
            return false;
        }
        if (isset($this->selectScopes[$s]) && ($p = $this->textSelectionAt($s, $x, $y)) !== null) {
            $s = $p[0]; // in a Selectable container, the paragraph nearest
        }
        if (!$this->textSelectionMenu($s, $x, $y) && isset($this->fields[$s])) {
            if ($this->focus !== $s) {
                $this->focus = $s;
                $this->focusVisible = false;
            }
            $state = $this->fields[$s][0];
            $before = [$state->cursor, $state->anchor];
            $this->placeCaret($s, $x, $y, false);
            [$a, $b] = [min($before), max($before)];
            if ($a !== $b && $state->cursor >= $a && $state->cursor <= $b) {
                [$state->cursor, $state->anchor] = $before; // in the selection: it stays
            }
        }
        $text = 0;
        if ($this->editable($s)) {
            $text = $s;
            $s = $this->textMenu($s);
        }
        $this->askMenu($s, $x, $y, false);
        $this->menuText = $text;
        return true;
    }

    /** A right-click in selectable text: it takes the focus, and a click outside the selection, or with none showing, collapses it there (mygo's textSelectionMenu). Whether $s is selectable text. */
    private function textSelectionMenu(int $s, float $x, float $y): bool
    {
        $i = $this->textIndex[$s] ?? null;
        if ($i === null) {
            return false;
        }
        $n = $this->texts[$i];
        $p = $this->textHit($n, $x, $y);
        $bounds = $this->selectionBounds();
        if (!$this->selectionVisible($n) || $bounds === null || !$bounds[2] || $this->comparePoints($p, $bounds[0]) < 0 || $this->comparePoints($p, $bounds[1]) > 0) {
            $this->selection = new TextSelection($n->textScope, $p);
        }
        $this->focus = $s;
        $this->focusVisible = false;
        return true;
    }

    /** The menu key or Shift+F10: the context menu of the focused node, or of the innermost around it with one, below a text input's caret or the node's top (mygo's menuKey). Whether there was one. */
    private function menuKey(): bool
    {
        if ($this->focus === null) {
            return false;
        }
        $s = 0;
        for ($id = $this->focus; $id !== 0; $id = $this->parents[$id] ?? 0) { // the focus chain
            if ((($this->menuNodes[$id] ?? 0) & 1) !== 0 || $this->editable($id) || isset($this->selectScopes[$id])) {
                $s = $id;
                break;
            }
            if (isset($this->unselectables[$id])) {
                return false;
            }
        }
        if ($s === 0) {
            return false;
        }
        [$vx, $vy, , $vh] = $this->visible[$s] ?? $this->rects[$s];
        $x = $vx; $y = $vy + min($vh, 32);
        $text = 0;
        if (isset($this->fields[$s]) && $this->input->focus !== null && $s === $this->focus) {
            [$cx, $cy, $cw, $ch] = $this->input->caretRect(TextState::utf16Index($this->input->focus->display(), $this->input->focus->displayCaret()));
            $x = $cx; $y = $cy + $ch;
        }
        if ($this->editable($s)) {
            $text = $s;
            $s = $this->textMenu($s);
        }
        $this->askMenu($s, $x, $y, false);
        $this->menuText = $text;
        return true;
    }

    /** Has the next frame build the menu of menu button $id, to show below it (mygo's openMenuButton and menuCorner). */
    private function openMenuButton(int $id): void
    {
        [$vx, $vy, , $vh] = $this->visible[$id] ?? $this->rects[$id];
        $this->askMenu($id, $vx, $vy + $vh, true);
    }

    /** Keeps the menu built for node $id to show once the frame is done (mygo's openMenu). @param list<NativeItem> $items @param list<string> $labels @param ?list<string> $commands */
    private function openMenu(int $id, bool $button, array $items, array $labels, ?array $commands): void
    {
        if ($items === []) {
            return;
        }
        $this->menuPending = ['id' => $id, 'button' => $button, 'items' => $items, 'labels' => $labels, 'commands' => $commands, 'x' => $this->menuX, 'y' => $this->menuY, 'text' => $this->menuText];
    }

    /** Opens the menu asked for that no context menu built, after the frame's build: a text input's or selectable text's, with its editing commands (mygo's resolveMenu). */
    private function resolveMenu(): void
    {
        $id = $this->menuAsked;
        if ($id === 0) {
            return;
        }
        $this->menuAsked = 0;
        if (isset($this->rects[$id]) && $this->editable($id) && !$this->menuButton) {
            $this->editMenu($id);
        }
    }

    /** Hands the menu built in the frame to the window, which shows it after the frame (mygo's showMenu). */
    private function showMenu(): void
    {
        $p = $this->menuPending;
        if ($p === null) {
            return;
        }
        $this->menuPending = null;
        ($this->popupMenu)($p['items'], $p['x'], $p['y'], false, fn(int $choice) => $this->menuChosen($p, $choice));
    }

    /** The item $choice (from 1) chosen from menu $p: the next frame reports it to its function, or the text does the editing command (mygo's menuChosen). @param array{id: int, button: bool, items: list<NativeItem>, labels: list<string>, commands: ?list<string>, x: float, y: float, text: int} $p */
    private function menuChosen(array $p, int $choice): void
    {
        if ($choice < 1 || $choice > count($p['labels'])) {
            return;
        }
        if ($p['commands'] !== null && $p['commands'][$choice - 1] !== '') { // an editing command, of the text's own menu or of editItems()
            $target = $p['text'] !== 0 ? $p['text'] : $p['id'];
            $this->editCommand($target, $p['commands'][$choice - 1]);
        } else {
            $this->menuChosen = $p['id']; $this->menuChosenButton = $p['button']; $this->menuChoice = $choice;
            $this->menuLabel = $p['labels'][$choice - 1]; $this->menuChosenText = $p['text'];
        }
        $this->needsFrame = true;
        if ($this->requestFrame !== null) {
            ($this->requestFrame)();
        }
    }

    /** An editing command of a menu on text input or selectable text $id: Copy and Select All on selectable text; Cut, Copy, Paste, Delete and Select All on a field. */
    private function editCommand(int $id, string $name): void
    {
        if (isset($this->textIndex[$id])) {
            $this->textSelectionCommand($name, $this->texts[$this->textIndex[$id]]);
            return;
        }
        $state = $this->fields[$id][0] ?? null;
        if ($state === null) {
            return;
        }
        switch ($name) {
            case 'copy': if ($state->hasSelection()) { ($this->writeClipboard)($state->selectedText()); } break;
            case 'cut': if ($state->hasSelection()) { ($this->writeClipboard)($state->selectedText()); $state->insert(''); } break;
            case 'delete': if ($state->hasSelection()) { $state->insert(''); } break;
            case 'selectAll': $state->selectAll(); break;
            case 'paste':
                $s = str_replace(["\r\n", "\r"], "\n", ($this->readClipboard)());
                if ($state->onPaste === null || !($state->onPaste)($s)) { // the app may take the paste
                    $state->insert($state->multiline ? $s : str_replace("\n", ' ', $s));
                }
                break;
        }
    }

    /** Opens the menu of text input or selectable text $id, with the editing commands macOS's text fields have in theirs (mygo's editMenu). */
    private function editMenu(int $id): void
    {
        $items = []; $labels = []; $names = [];
        foreach ($this->editCommands($id) as $i => [$label, $name, $on]) {
            $items[] = $name === '' ? NativeItem::separator() : new NativeItem($label, disabled: !$on, id: (string)($i + 1));
            $labels[] = $label; $names[] = $name;
        }
        $this->openMenu($id, false, $items, $labels, $names);
    }

    /**
     * The items of the editing menu of node $id (mygo's editCommands):
     * Copy and Select All for selectable text, Cut, Copy, Paste and Select
     * All for a text input; label, command and whether it is enabled, a
     * separator without a command.
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    public function editCommands(int $id): array
    {
        $t = L10n::in(App::locale());
        $separator = ['', '', false];
        if (isset($this->textIndex[$id])) {
            $n = $this->texts[$this->textIndex[$id]];
            $bounds = $this->selectionBounds();
            $selected = $this->selection->scope === $n->textScope && $bounds !== null && $bounds[2];
            return [[$t->get('menuCopy'), 'copy', $selected], $separator, [$t->get('menuSelectAll'), 'selectAll', self::runeCount($n) > 0]];
        }
        $state = $this->fields[$id][0] ?? null;
        if ($state === null) {
            return [];
        }
        $selected = $state->hasSelection();
        return [[$t->get('menuCut'), 'cut', $selected && !$state->readOnly], [$t->get('menuCopy'), 'copy', $selected], [$t->get('menuPaste'), 'paste', !$state->readOnly],
            $separator, [$t->get('menuSelectAll'), 'selectAll', $state->text !== '']];
    }

    /** A key with modifiers written as menus take it, as "Super+Shift+z" (mygo's accelerator); "" for a key menus cannot show. */
    public static function accelerator(int $mods, int $key): string
    {
        $name = self::keyName($key);
        if ($name === '') {
            return '';
        }
        $out = '';
        foreach ([[Key::COMMAND, 'Super'], [Key::CONTROL, 'Ctrl'], [Key::OPTION, 'Alt'], [Key::SHIFT, 'Shift']] as [$m, $s]) {
            if (($mods & $m) !== 0) { $out .= $s . '+'; }
        }
        return $out . $name;
    }

    /** The name menus give a key (mygo's keyName). */
    private static function keyName(int $k): string
    {
        static $names = null;
        if ($names === null) {
            $names = [];
            foreach (str_split('abcdefghijklmnopqrstuvwxyz') as $ch) { $names[constant(Key::class . '::' . strtoupper($ch))] = $ch; }
            for ($i = 0; $i <= 9; $i++) { $names[constant(Key::class . '::N' . $i)] = (string)$i; }
            for ($i = 1; $i <= 12; $i++) { $names[constant(Key::class . '::F' . $i)] = 'F' . $i; }
            $names += [Key::RETURN => 'Enter', Key::ENTER => 'Enter', Key::ESCAPE => 'Escape', Key::DELETE => 'Backspace', Key::TAB => 'Tab', Key::SPACE => 'Space',
                Key::FORWARD_DELETE => 'Delete', Key::INSERT => 'Insert', Key::HOME => 'Home', Key::END => 'End', Key::PAGE_UP => 'PageUp', Key::PAGE_DOWN => 'PageDown',
                Key::LEFT => 'Left', Key::RIGHT => 'Right', Key::UP => 'Up', Key::DOWN => 'Down', Key::MINUS => '-', Key::EQUAL => '=', Key::COMMA => ',',
                Key::PERIOD => '.', Key::SLASH => '/', Key::SEMICOLON => ';', Key::QUOTE => "'", Key::BRACKET_LEFT => '[', Key::BRACKET_RIGHT => ']',
                Key::BACKSLASH => '\\', Key::GRAVE => '`'];
        }
        return $names[$k] ?? '';
    }
}
