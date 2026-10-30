<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Automation;

use MyPHP\Native\{Metal, ObjC, View};
use MyPHP\{EvalError, Rectangle, Window};
use MyPHP\UI\Key;

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
                Metal::requestFrame($w->contentView());
                return;
            }
        }
        throw new Failure(Failure::NO_ELEMENT, "window {$w->id()} shows no element " . Protocol::id($id));
    }

    public function input(int $window, array $events): void
    {
        $w = $this->content($window);
        $in = $w->input;
        $view = $w->contentView();
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
                    // An event of AppKit's, as the view takes the keyboard's: through the text system, which makes the field's commands; a key types nothing, its text a text event's.
                    [, $code, $chars, $mods] = $e;
                    $plain = ($mods & Key::SHIFT) !== 0 ? strtolower($chars) : $chars;
                    $ev = ObjC::msg('p:LqLdlpppbS', ObjC::cls('NSEvent'), 'keyEventWithType:location:modifierFlags:timestamp:windowNumber:context:characters:charactersIgnoringModifiers:isARepeat:keyCode:',
                        $e[0] === 'keyDown' ? 10 : 11, ObjC::point(0, 0), $mods, hrtime(true) / 1e9, ObjC::msg('l:', ObjC::msg('p:', $view, 'window'), 'windowNumber'), null,
                        ObjC::str($chars), ObjC::str($plain), false, $code); // NSEventTypeKeyDown, KeyUp
                    if ($e[0] === 'keyDown') {
                        View::automatedKey($view, $ev);
                    } else {
                        ObjC::msg('v:p', $view, 'keyUp:', $ev);
                    }
                    break;
                case 'text':
                    ObjC::msg('v:p', $view, 'insertText:', ObjC::str($e[1]));
                    break;
            }
        }
        Metal::requestFrame($view);
    }

    public function menu(int $window, array $path): void
    {
        $this->window($window);
        $menu = ObjC::msg('p:', ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication'), 'mainMenu');
        foreach ($path as $i => $title) {
            if ($menu === null) {
                throw new Failure(Failure::FAILED, 'menu ' . json_encode($path[$i - 1], JSON_UNESCAPED_UNICODE) . ' has no submenu');
            }
            $index = ObjC::msg('l:p', $menu, 'indexOfItemWithTitle:', ObjC::str($title));
            if ($index < 0) {
                throw new Failure(Failure::FAILED, 'no menu item ' . json_encode($title, JSON_UNESCAPED_UNICODE));
            }
            if ($i === count($path) - 1) {
                ObjC::msg('v:l', $menu, 'performActionForItemAtIndex:', $index);
                return;
            }
            $menu = ObjC::msg('p:', ObjC::msg('p:l', $menu, 'itemAtIndex:', $index), 'submenu');
        }
    }

    public function capture(int $window): string
    {
        $w = $this->window($window);
        try {
            return $w->capturePage();
        } catch (\RuntimeException $e) {
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
            return [$w->id(), !Metal::framePending($w->contentView())];
        }
        $p = $w->page() ?? throw new Failure(Failure::NO_WINDOW, "window {$w->id()} closed");
        if ($p->isLoading()) {
            return [$w->id(), false];
        }
        $this->eval($w->id(), 'true'); // the page answers scripts: it went past its load
        return [$w->id(), true];
    }
}
