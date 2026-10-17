<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/**
 * Runs $command for a key (mygo's editor.Binding): $mods+$key (Key's
 * codes and modifier masks), followed by $thenMods+$thenKey when
 * $thenKey is set, as the chords Cmd+K Cmd+0. A binding to
 * Command::NO_COMMAND takes a key away from the editor.
 */
final class Binding
{
    /** @param ?int $thenKey null for no chord */
    public function __construct(public int $mods, public int $key, public int $thenMods, public ?int $thenKey, public int $command) {}
}
