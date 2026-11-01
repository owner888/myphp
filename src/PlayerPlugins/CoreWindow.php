<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use MyPHP\Rectangle;
use MyPHP\Screen;

/**
 * IINA's iina.core.window: the player's window, its properties read and
 * set: loaded; frame {x, y, width, height} (MyPHP's: its top left from the
 * main screen's, not Cocoa's bottom left); fullscreen, pip, ontop,
 * visible, sidebar (the quick settings' tab shown, null for none),
 * screens [{frame, main, current}] (read only), miniaturized.
 */
final class CoreWindow extends Api
{
    public function __get(string $prop): mixed
    {
        $s = $this->player();
        $w = $s->window();
        if ($prop === 'loaded') { return $w !== null; }
        if ($w === null) { return null; }
        return match ($prop) {
            'frame' => self::rect($w->bounds()),
            'fullscreen' => $s->fullScreen(),
            'pip' => $s->pictureInPicture,
            'ontop' => $s->onTop,
            'visible' => $w->isVisible(),
            'sidebar' => $s->sidebar === null || $s->sidebar === 'playlist' ? null : $s->sidebar,
            'screens' => array_map(fn($d) => ['frame' => self::rect($d->bounds), 'main' => $d->primary, 'current' => $d->id === Screen::displayMatching($w->bounds())->id], Screen::displays()),
            'miniaturized' => $w->isMinimized(),
            default => null,
        };
    }

    public function __set(string $prop, mixed $value): void
    {
        $s = $this->player();
        $w = $s->window();
        if ($w === null) { return; }
        switch ($prop) {
            case 'frame':
                $f = is_array($value) ? $value : [];
                if (!is_numeric($f['x'] ?? null) || !is_numeric($f['y'] ?? null) || !is_numeric($f['width'] ?? null) || !is_numeric($f['height'] ?? null) || $f['width'] <= 0 || $f['height'] <= 0) {
                    $this->log('core.window.frame: Invalid frame', \MyPHP\Logger::ERROR);
                    return;
                }
                $w->setBounds(new Rectangle((int)$f['x'], (int)$f['y'], (int)$f['width'], (int)$f['height']));
                return;
            case 'fullscreen':
                if (is_bool($value) && $value !== $s->fullScreen()) { $s->perform(['cycle', 'fullscreen']); }
                return;
            case 'pip':
                if (is_bool($value) && $value !== $s->pictureInPicture) { $s->togglePictureInPicture(); }
                return;
            case 'ontop':
                if (is_bool($value)) { $s->setOnTop($value); }
                return;
            case 'sidebar':
                $s->showSidebar(is_string($value) ? $value : null);
                return;
            case 'miniaturized':
                if (is_bool($value)) { $value ? $w->minimize() : $w->restore(); }
                return;
            default:
                $this->log("core.window: $prop is not accessible", \MyPHP\Logger::WARNING);
        }
    }

    /** @return array{x: int, y: int, width: int, height: int} */
    private static function rect(Rectangle $r): array
    {
        return ['x' => $r->x, 'y' => $r->y, 'width' => $r->width, 'height' => $r->height];
    }
}
