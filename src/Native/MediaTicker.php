<?php
declare(strict_types=1);

namespace MyPHP\Native;

use Closure;

/**
 * The timer that tops up the players' renderers, 20 times a second while
 * one plays: an NSTimer in the main run loop's common modes, so that it
 * runs on while a window is resized or a menu is open.
 *
 * @internal
 */
final class MediaTicker
{
    public const INTERVAL = 0.05;
    /** @var array<int, Closure(): void> what each player does at a tick, by its object id */
    private static array $ticks = [];
    private static $timer = null, $target = null;

    public static function add(object $player, Closure $tick): void
    {
        self::$ticks[spl_object_id($player)] = $tick;
        if (self::$timer !== null) {
            return;
        }
        if (self::$target === null) {
            ObjC::defineClass('MyPHPMediaTicker', 'NSObject', [
                'tick:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $timer) {
                    foreach (self::$ticks as $fn) {
                        $fn();
                    }
                }],
            ]);
            self::$target = ObjC::msg('p:', ObjC::alloc('MyPHPMediaTicker'), 'init');
        }
        self::$timer = ObjC::msg('p:', ObjC::msg('p:dpppb', ObjC::cls('NSTimer'), 'timerWithTimeInterval:target:selector:userInfo:repeats:', self::INTERVAL, self::$target, ObjC::sel('tick:'), null, true), 'retain');
        ObjC::msg('v:pp', ObjC::msg('p:', ObjC::cls('NSRunLoop'), 'mainRunLoop'), 'addTimer:forMode:', self::$timer, Metal::commonMode());
    }

    public static function remove(object $player): void
    {
        unset(self::$ticks[spl_object_id($player)]);
        if (self::$ticks === [] && self::$timer !== null) {
            ObjC::msg('v:', self::$timer, 'invalidate');
            ObjC::msg('v:', self::$timer, 'release');
            self::$timer = null;
        }
    }
}
