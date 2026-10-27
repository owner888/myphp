<?php
declare(strict_types=1);

namespace MyPHP\UI;

use MyPHP\MenuItem as NativeItem;

/**
 * A menu being built, by the function given to Node::contextMenu or
 * Node::menu (mygo's Menu). Its items show in the order they are added.
 * The function runs when the menu opens, collecting what it shows, and
 * again in the frame after an item was chosen, where the item's chosen()
 * reports it.
 */
final class Menu
{
    /** @internal @param ?list<NativeItem> $items what the menu shows, when it opens; null for the run after a choice */
    public function __construct(public readonly MenuRun $run, public ?array $items, private Runtime $rt, private int $text) {}

    private function add(string $label, string $type): MenuItem
    {
        $r = $this->run;
        $r->seq++;
        $it = new MenuItem($r, $r->seq, $label);
        if ($r->collect) {
            $it->p = new NativeItem($label, type: $type, id: (string)$r->seq);
            $this->items[] = $it->p;
            $r->labels[] = $label;
            $r->commands[] = '';
        }
        return $it;
    }

    /** Adds an item showing $label. */
    public function item(string $label): MenuItem { return $this->add($label, NativeItem::NORMAL); }

    /** Adds a line between groups of items. */
    public function separator(): void { $this->add('', NativeItem::SEPARATOR); }

    /** Adds the title $label of the group of items under it, chosen by none (NativeItem::HEADER, macOS 14). */
    public function header(string $label): void { $this->add($label, NativeItem::HEADER); }

    /** Adds an item showing $label that opens a submenu, which $build fills as the menu's function fills the menu. @param \Closure(Menu): void $build */
    public function submenu(string $label, \Closure $build): MenuItem
    {
        $it = $this->add($label, NativeItem::SUBMENU);
        $it->sub = true;
        $sub = new Menu($this->run, $it->p !== null ? [] : null, $this->rt, $this->text);
        $build($sub);
        if ($it->p !== null) {
            $it->p->submenu = $sub->items;
        }
        return $it;
    }

    /**
     * Adds the editing items of the text the menu opened on, as its menu has
     * them without a context menu of its own: Copy and Select All for
     * selectable text, the text field's items for a text input. Choosing one
     * does its command on the text, without reaching the menu's function.
     * It adds nothing to a menu that opened on no text (mygo's EditItems).
     */
    public function editItems(): void
    {
        if ($this->text === 0) {
            return;
        }
        foreach ($this->rt->editCommands($this->text) as [$label, $name, $on]) {
            if ($name === '') {
                $this->separator();
                continue;
            }
            $it = $this->item($label)->disabled(!$on);
            if ($this->run->collect) {
                $this->run->commands[$it->seq - 1] = $name;
            }
        }
    }
}
