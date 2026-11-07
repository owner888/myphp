<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Animation, Context, Runtime};

/**
 * What withAnimation() changes the state under, as SwiftUI's transaction:
 * the animation the views take what changed to with, for the rest of the
 * frame it is called in (an action runs as a frame builds) and the next,
 * which builds anew with what it changed. @internal
 */
final class Transaction
{
    private static ?Runtime $runtime = null;
    private static ?Animation $animation = null;
    private static ?Runtime $set = null;
    private static int $until = -1;

    /** A frame of $c's window starts building: withAnimation in it is that window's. */
    public static function frame(Context $c): void
    {
        self::$runtime = $c->runtime;
    }

    /** Runs $body, the views animating what it changes with $animation (null: none, at once). */
    public static function with(?Animation $animation, \Closure $body): mixed
    {
        $rt = self::$runtime;
        [$was, $wasSet, $wasUntil] = [self::$animation, self::$set, self::$until];
        self::$animation = $animation ?? new Animation(0.0001);
        self::$set = $rt;
        self::$until = ($rt?->frameNumber ?? 0) + 1;
        try {
            return $body();
        } finally {
            $rt?->invalidate(); // the next frame builds what it changed, animated
            if ($rt === null) { [self::$animation, self::$set, self::$until] = [$was, $wasSet, $wasUntil]; }
        }
    }

    /** The animation the views of $c's frame take a change with, withAnimation's; null for none. */
    public static function current(Context $c): ?Animation
    {
        $rt = $c->runtime;
        if (self::$animation === null || $rt === null || self::$set !== $rt) { return null; }
        if ($rt->frameNumber > self::$until) { self::$animation = null; return null; }
        return self::$animation;
    }
}
