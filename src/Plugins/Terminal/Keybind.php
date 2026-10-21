<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

/**
 * Binds a key, with exactly $mods held (Key::COMMAND…), to an action, as a
 * line of Ghostty's keybind option (mygo's terminal.Keybind): the view does
 * the action instead of sending the key to the program. Performable, as
 * Ghostty's performable: prefix, takes the key only when the action does
 * something; a key the action leaves goes on as if unbound.
 */
final class Keybind
{
    public function __construct(
        public int $mods,
        public int $key,
        public Action $action,
        public bool $performable = false,
    ) {}

    /** The bindings of $o: its keybinds over the defaults, each replacing those of its key, in order, the defaults first. @internal @return list<Keybind> */
    public static function of(Options $o): array
    {
        $b = $o->noDefaultKeybinds ? [] : Terminal::defaultKeybinds();
        foreach ($o->keybinds as $k) {
            $b = array_values(array_filter($b, fn(Keybind $x) => $x->mods !== $k->mods || $x->key !== $k->key));
            if ($k->action !== Action::ActionNone) { $b[] = $k; }
        }
        return $b;
    }
}
