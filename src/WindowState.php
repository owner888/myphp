<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * What WindowOptions::$stateKey remembers of a window (mygo's WindowState):
 * its bounds in the normal state, in screen points from the primary
 * display's top left, which it gets back once it leaves the maximized or
 * full screen state, and that state.
 */
final class WindowState
{
    public function __construct(
        public readonly int $x = 0,
        public readonly int $y = 0,
        public readonly int $width = 0,
        public readonly int $height = 0,
        public readonly bool $maximized = false,
        public readonly bool $fullScreen = false,
    ) {}

    /** The state as window-state.json keeps it, mygo's JSON. @return array<string, int|bool> */
    public function toArray(): array
    {
        $a = ['x' => $this->x, 'y' => $this->y, 'width' => $this->width, 'height' => $this->height];
        if ($this->maximized) { $a['maximized'] = true; }
        if ($this->fullScreen) { $a['fullScreen'] = true; }
        return $a;
    }

    /** A state of toArray()'s shape, or null for anything else. */
    public static function fromArray(mixed $a): ?self
    {
        if (!is_array($a)) {
            return null;
        }
        foreach (['x', 'y', 'width', 'height'] as $k) {
            if (!is_int($a[$k] ?? null)) { return null; }
        }
        return new self($a['x'], $a['y'], $a['width'], $a['height'], ($a['maximized'] ?? false) === true, ($a['fullScreen'] ?? false) === true);
    }
}
