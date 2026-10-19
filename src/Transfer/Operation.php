<?php
declare(strict_types=1);

namespace MyPHP\Transfer;

/** What a native drag does (mygo's transfer.Operation): a bitmask of COPY and MOVE, or one of them. */
final class Operation
{
    public const NONE = 0, COPY = 1, MOVE = 2;

    /** $o, the safe default (copy) for none, without the effects not supported. */
    public static function allowed(int $o): int
    {
        return $o === self::NONE ? self::COPY : $o & (self::COPY | self::MOVE);
    }

    /** The one effect the source and the destination share: the source's suggestion (a modifier key's) when allowed, else copy before move. */
    public static function negotiate(int $source, int $destination, int $suggested): int
    {
        $allowed = $source & self::allowed($destination);
        if (($suggested === self::COPY || $suggested === self::MOVE) && ($allowed & $suggested) !== 0) {
            return $suggested;
        }
        if (($allowed & self::COPY) !== 0) {
            return self::COPY;
        }
        return ($allowed & self::MOVE) !== 0 ? self::MOVE : self::NONE;
    }
}
