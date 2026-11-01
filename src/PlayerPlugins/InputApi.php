<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use Closure;
use MyPHP\UI\MediaPlayerKeys;
use MyPHP\UI\MediaPlayerState;

/**
 * IINA's iina.input (JavascriptAPIInput, PluginInputManager): a player's
 * keys (mpv's names, normalized) and mouse buttons (MOUSE, RIGHT_MOUSE,
 * OTHER_MOUSE) listened to as they go down, up, or the mouse drags; one
 * listener of an instance for each, the later in its place, null none.
 * A listener of PRIORITY_HIGH or more is told before the player handles
 * the input (its key binding), the others after; one returning true
 * stops it there (handle()). Its listeners go with the instance.
 */
final class InputApi extends Api
{
    public const PRIORITY_LOW = 100, PRIORITY_HIGH = 200;
    public const MOUSE = '*mouse', RIGHT_MOUSE = '*rightMouse', OTHER_MOUSE = '*otherMouse';
    public const KEY_DOWN = 0, KEY_UP = 1, MOUSE_DOWN = 2, MOUSE_UP = 3, MOUSE_DRAG = 4;

    /** @var array<string, array<int, array{0: Closure, 1: int}>> by input, by event: the callback and its priority */
    private array $listeners = [];

    public function __construct(Instance $instance)
    {
        parent::__construct($instance);
        $instance->onCleanUp(function (): void { $this->listeners = []; });
    }

    /** mpv's key $code in its one form (IINA's KeyCodeHelper.normalizeMpv). */
    public function normalizeKeyCode(string $code): string
    {
        return MediaPlayerKeys::normalize($code);
    }

    /** The player's key bindings by key: its key, action and whether it is IINA's. @return array<string, array{key: string, action: string, isIINACommand: bool}> */
    public function getAllKeyBindings(): array
    {
        $out = [];
        foreach (MediaPlayerKeys::current($this->player()->settings()) as $m) {
            $out[$m['normal']] = ['key' => $m['normal'], 'action' => implode(' ', $m['action']), 'isIINACommand' => $m['iina']];
        }
        return $out;
    }

    public function onKeyDown(string $key, ?Closure $callback, ?int $priority = null): void
    {
        $this->listen(self::KEY_DOWN, MediaPlayerKeys::normalize($key), $callback, $priority);
    }

    public function onKeyUp(string $key, ?Closure $callback, ?int $priority = null): void
    {
        $this->listen(self::KEY_UP, MediaPlayerKeys::normalize($key), $callback, $priority);
    }

    public function onMouseDown(string $button, ?Closure $callback, ?int $priority = null): void
    {
        $this->listen(self::MOUSE_DOWN, $button, $callback, $priority);
    }

    public function onMouseUp(string $button, ?Closure $callback, ?int $priority = null): void
    {
        $this->listen(self::MOUSE_UP, $button, $callback, $priority);
    }

    public function onMouseDrag(string $button, ?Closure $callback, ?int $priority = null): void
    {
        $this->listen(self::MOUSE_DRAG, $button, $callback, $priority);
    }

    private function listen(int $event, string $input, ?Closure $callback, ?int $priority): void
    {
        $this->player();
        unset($this->listeners[$input][$event]);
        if ($callback !== null) { $this->listeners[$input][$event] = [$callback, $priority ?? self::PRIORITY_LOW]; }
    }

    /**
     * Handles $event of $input in player $s as IINA's PluginInputManager
     * does: its plugins' listeners of high priority, then $handler (whether
     * the player handled it), then the others, $args given to each; one
     * returning true stops it. Whether it was taken: stopped or handled
     * (else the input goes on, IINA's defaultHandler).
     * @param array<string, mixed> $args @param ?Closure(): bool $handler
     */
    public static function handle(MediaPlayerState $s, string $input, int $event, array $args, ?Closure $handler = null): bool
    {
        $found = [];
        foreach ($s->plugins() as $i) {
            $l = $i->iina->input?->listeners[$input][$event] ?? null;
            if ($l !== null) { $found[] = [$i, $l[0], $l[1]]; }
        }
        usort($found, fn(array $a, array $b) => $b[2] <=> $a[2]);
        foreach ($found as [$i, $fn, $priority]) {
            if ($priority >= self::PRIORITY_HIGH && $i->callNow($fn, $args) === true) { return true; }
        }
        $handled = $handler !== null && $handler();
        foreach ($found as [$i, $fn, $priority]) {
            if ($priority < self::PRIORITY_HIGH && $i->callNow($fn, $args) === true) { return true; }
        }
        return $handled;
    }

    /** Whether a plugin of player $s listens to $event of $input. */
    public static function listened(MediaPlayerState $s, string $input, int $event): bool
    {
        foreach ($s->plugins() as $i) {
            if (isset($i->iina->input?->listeners[$input][$event])) { return true; }
        }
        return false;
    }
}
