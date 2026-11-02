<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Automation;

use MyPHP\{EvalError, Rectangle, Window};
use MyPHP\UI\Key;
use RuntimeException;

/**
 * The app's windows, as the protocol drives them (mygo's automation.go):
 * their native UI's elements, events given to the content as its view's
 * are, the application menu's items, and pages' scripts.
 *
 * @internal
 */
final class AppDriver implements Driver
{
    /** @var array<int, true> the windows a button was pressed in by input and not released, by ID: a move drags */
    private array $held = [];

    public function windows(): array
    {
        $out = [];
        foreach (Window::all() as $w) {
            if ($w->isDestroyed()) {
                continue;
            }
            $info = ['id' => $w->id(), 'title' => $w->title(), 'kind' => $w->runtime !== null ? 'content' : 'page'];
            if ($w->runtime === null && ($p = $w->page()) !== null) {
                $info['url'] = $p->url();
            }
            $info += ['bounds' => self::rect($w->bounds()), 'contentBounds' => self::rect($w->contentBounds()), 'focused' => $w->isFocused(), 'visible' => $w->isVisible()];
            $out[] = $info;
        }
        return $out;
    }

    private static function rect(Rectangle $r): array
    {
        return Protocol::rect($r->x, $r->y, $r->width, $r->height);
    }

    /** Window $id, or for 0 the focused window, else the first. */
    private function window(int $id): Window
    {
        if ($id !== 0) {
            $w = Window::byId($id);
            if ($w === null || $w->isDestroyed()) {
                throw new Failure(Failure::NO_WINDOW, "no window $id");
            }
            return $w;
        }
        foreach ([Window::focused(), ...Window::all()] as $w) {
            if ($w !== null && !$w->isDestroyed()) {
                return $w;
            }
        }
        throw new Failure(Failure::NO_WINDOW, 'the app has no window');
    }

    /** Window $id, which shows native UI. */
    private function content(int $id): Window
    {
        $w = $this->window($id);
        if ($w->runtime === null) {
            throw new Failure(Failure::UNSUPPORTED, "window {$w->id()} shows a web page: use eval");
        }
        return $w;
    }

    public function tree(int $window): array
    {
        $w = $this->content($window);
        return [$w->id(), ...$w->runtime->automationTree()];
    }

    public function act(int $window, int $id, int $action, string $text, int $from, int $to): void
    {
        $w = $this->content($window);
        [$t] = $w->runtime->automationTree(); // and the runtime knows the frame's nodes, to act on
        foreach ($t->nodes as $n) {
            if ($n->id === $id) {
                $w->input->push(['access', $id, $action, $text, $from, $to]);
                $w->native()->requestFrame();
                return;
            }
        }
        throw new Failure(Failure::NO_ELEMENT, "window {$w->id()} shows no element " . Protocol::id($id));
    }

    public function input(int $window, array $events): void
    {
        $w = $this->content($window);
        $in = $w->input;
        $native = $w->native();
        foreach ($events as $e) {
            switch ($e[0]) {
                case 'move':
                case 'down':
                case 'up':
                case 'scroll':
                    [$x, $y] = [$e[1], $e[2]];
                    $in->mouse($x, $y);
                    $in->mods = end($e);
                    if ($e[0] === 'move') {
                        $in->push(isset($this->held[$w->id()]) ? ['drag', $x, $y, hrtime(true)] : ['move', $x, $y]);
                    } elseif ($e[0] === 'scroll') {
                        $lines = $e[5] ? 1 : 10; // a wheel's notches are lines, as the view takes them
                        $in->push(['scroll', $x, $y, $e[3] * $lines, $e[4] * $lines, $e[5], false]);
                    } else {
                        if ($e[0] === 'down') { $this->held[$w->id()] = true; } else { unset($this->held[$w->id()]); }
                        $in->push([$e[0], $x, $y, $e[3], $e[4], $e[5], hrtime(true)]);
                    }
                    break;
                case 'keyDown':
                case 'keyUp':
                    // As the keyboard's, but typing nothing: its text is a text event's.
                    [, $code, $chars, $mods] = $e;
                    $native->automatedKey($e[0] === 'keyDown', $code, $chars, ($mods & Key::SHIFT) !== 0 ? strtolower($chars) : $chars, $mods);
                    break;
                case 'text':
                    $native->automatedText($e[1]);
                    break;
            }
        }
        $native->requestFrame();
    }

    public function menu(int $window, array $path): void
    {
        $w = $this->window($window);
        try {
            $w->native()->chooseMenuItem($path);
        } catch (RuntimeException $e) {
            throw new Failure(Failure::FAILED, $e->getMessage());
        }
    }

    public function resize(int $window, int $width, int $height): void
    {
        $this->window($window)->setContentSize($width, $height);
    }

    public function capture(int $window): string
    {
        $w = $this->window($window);
        try {
            return $w->capturePage();
        } catch (RuntimeException $e) {
            throw new Failure(Failure::FAILED, $e->getMessage());
        }
    }

    public function eval(int $window, string $script): mixed
    {
        $w = $this->window($window);
        $p = $w->page();
        if ($p === null) {
            throw new Failure(Failure::UNSUPPORTED, "window {$w->id()} shows native UI, not a web page: use tree");
        }
        try {
            return $p->eval($script);
        } catch (EvalError $e) {
            throw new Failure(Failure::FAILED, $e->getMessage());
        }
    }

    public function idle(int $window): array
    {
        $w = $this->window($window);
        if ($w->runtime !== null) {
            return [$w->id(), !$w->native()->framePending()];
        }
        $p = $w->page() ?? throw new Failure(Failure::NO_WINDOW, "window {$w->id()} closed");
        if ($p->isLoading()) {
            return [$w->id(), false];
        }
        $this->eval($w->id(), 'true'); // the page answers scripts: it went past its load
        return [$w->id(), true];
    }
}
