<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Image, SVG};

/**
 * What a view is, as a value to compare with the frame before's (SwiftUI's
 * diff of view values): a stack whose value is the same builds the same
 * nodes, so its subtree is kept (View::renderAll, Context::reactiveMemo)
 * though the closure that made it ran again. Told only for the views whose
 * build reads nothing but their own values and the environment: a view of
 * another kind, a closure anywhere (an action, a row's builder), an object
 * of state, an event, a transition or an animation leave the view to be
 * built as before. docs/ui/performance.md
 */
final class ViewKey
{
    /** The views told by value: none runs a closure or keeps state while it is built. */
    private const VIEWS = [Stack::class => true, ZStack::class => true, Text::class => true, Label::class => true, ImageView::class => true,
        Spacer::class => true, Divider::class => true, ColorView::class => true, Shape::class => true];
    /** The values compared by what they hold. */
    private const VALUES = [ColorValue::class => true, FontSpec::class => true, CGSize::class => true, CGPoint::class => true, StrokeStyle::class => true, \EdgeInsets::class => true];
    /** The values compared by which one they are: a picture, an icon, loaded once and not changed. */
    private const SAME = [Image::class => true, SVG::class => true];
    /** The modifiers that only box, place or paint the view. */
    private const MODS = ['background' => true, 'blur' => true, 'border' => true, 'clip' => true, 'direction' => true, 'drawingGroup' => true, 'fixedSize' => true,
        'frame' => true, 'gray' => true, 'hidden' => true, 'ignoresSafeArea' => true, 'offset' => true, 'opacity' => true, 'overlay' => true, 'padding' => true,
        'priority' => true, 'radius' => true, 'rotate' => true, 'rotate3d' => true, 'scale' => true, 'shadow' => true];
    /** The events that only say something of the view: its tooltip, its accessibility. */
    private const EVENTS = ['help' => true, 'label' => true, 'hint' => true, 'accValue' => true, 'accessHidden' => true];
    /** The most values a view's key takes in (a Text's are about 25): past it, the view is built as before, a value it holds being too big to compare each frame. */
    private const LIMIT = 1 << 18;
    private const VIEW = "\0" . View::class . "\0";

    /** @var \WeakMap<View, array{0: int, 1: ?array, 2: int}>|null the keys told in frame [0], by view: a stack's are part of its parent's, told first; a view kept by the app and changed is told again the next frame */
    private static ?\WeakMap $told = null;
    private static int $frame = -1;
    private static int $left = 0;

    /**
     * $v's value and how many views it holds, or null when it is not told by value; $frame the frame being built.
     * @return array{0: array, 1: int}|null
     */
    public static function of(View $v, int $frame): ?array
    {
        self::$told ??= new \WeakMap();
        self::$frame = $frame;
        self::$left = self::LIMIT;
        $views = 0;
        $key = self::view($v, $views);
        return $key === null ? null : [$key, $views];
    }

    /** @param int $views counts the views in it */
    private static function view(View $v, int &$views): ?array
    {
        $told = self::$told[$v] ?? null;
        if ($told !== null && $told[0] === self::$frame) {
            $views += $told[2];
            return $told[1];
        }
        $key = self::viewOf($v, $n);
        self::$told[$v] = [self::$frame, $key, $n];
        $views += $n;
        return $key;
    }

    private static function viewOf(View $v, ?int &$views): ?array
    {
        $views = 1;
        if (!isset(self::VIEWS[$v::class])) { return null; }
        $props = (array)$v;
        if ($props["\0*\0wraps"] !== [] || $props["\0*\0presentations"] !== [] || $props["\0*\0swipe"] !== null
            || $props[self::VIEW . 'animated'] !== null || $props[self::VIEW . 'moves'] !== null || $props[self::VIEW . 'zIndex'] !== null) {
            return null; // what it does to the page around it (a title, a toolbar, a sheet, its order), or what moves
        }
        foreach ($props["\0*\0mods"] as [$name]) {
            if (!isset(self::MODS[$name])) { return null; }
        }
        foreach ($props["\0*\0events"] as [$name]) {
            if (!isset(self::EVENTS[$name])) { return null; }
        }
        $out = [$v::class];
        foreach ($props as $name => $value) {
            $kept = self::value($value, $views);
            if ($kept === self::class) { return null; }
            $out[$name] = $kept;
        }
        return $out;
    }

    /** $x as compared, or self::class when it is not told by value. Arrays are copied by value: a reference in one (a binding) compares as what it held then. */
    private static function value(mixed $x, int &$views): mixed
    {
        if (--self::$left < 0) { return self::class; }
        if ($x === null || is_scalar($x) || $x instanceof \UnitEnum) { return $x; }
        if (is_array($x)) {
            $out = [];
            foreach ($x as $k => $item) {
                $kept = self::value($item, $views);
                if ($kept === self::class) { return self::class; }
                $out[$k] = $kept;
            }
            return $out;
        }
        if ($x instanceof View) {
            return self::view($x, $views) ?? self::class;
        }
        if (isset(self::VALUES[$x::class])) {
            $out = [$x::class];
            foreach ((array)$x as $k => $item) {
                $kept = self::value($item, $views);
                if ($kept === self::class) { return self::class; }
                $out[$k] = $kept;
            }
            return $out;
        }
        if (isset(self::SAME[$x::class])) {
            return [$x::class, $x]; // compared as the same object (!==), kept so no other takes its id
        }
        return self::class; // a closure, a state, a binding, an object of the app's: what it gives may change while it stays the same object
    }
}
