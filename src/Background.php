<?php
declare(strict_types=1);

namespace MyPHP;

use InvalidArgumentException;

/**
 * A window's background (WindowOptions::$backgroundColor, mygo's
 * background): one color, or a light and a dark one that follow the app's
 * appearance; each [r, g, b, a], from 0 to 255.
 */
final class Background
{
    /** @param array{0: int, 1: int, 2: int, 3: int} $light @param array{0: int, 1: int, 2: int, 3: int} $dark */
    private function __construct(public readonly array $light, public readonly array $dark) {}

    /** Parses a CSS color (#rgb, #rgba, #rrggbb, #rrggbbaa, rgb(r g b), rgba(r, g, b, a), transparent), or light-dark(<light>, <dark>). */
    public static function parse(string $s): self
    {
        $args = strtolower(trim($s));
        if (!str_starts_with($args, 'light-dark(')) {
            $c = self::color($s);
            return new self($c, $c);
        }
        $args = substr($args, strlen('light-dark('));
        if (str_ends_with($args, ')')) {
            $args = substr($args, 0, -1);
            $depth = 0; // split at the comma outside of rgb(…)
            for ($i = 0, $n = strlen($args); $i < $n; $i++) {
                if ($args[$i] === '(') { $depth++; } elseif ($args[$i] === ')') { $depth--; } elseif ($args[$i] === ',' && $depth === 0) {
                    try {
                        return new self(self::color(substr($args, 0, $i)), self::color(substr($args, $i + 1)));
                    } catch (InvalidArgumentException) {
                    }
                }
            }
        }
        throw new InvalidArgumentException("invalid color \"$s\"");
    }

    /** The color for the dark appearance or the light one. @return array{0: int, 1: int, 2: int, 3: int} */
    public function for(bool $dark): array
    {
        return $dark ? $this->dark : $this->light;
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} */
    private static function color(string $s): array
    {
        $s = strtolower(trim($s));
        $bad = new InvalidArgumentException("invalid color \"$s\"");
        if ($s === 'transparent') {
            return [0, 0, 0, 0];
        }
        if (str_starts_with($s, '#')) {
            $hex = substr($s, 1);
            if (strlen($hex) === 3 || strlen($hex) === 4) {
                $hex = implode('', array_map(fn($c) => $c . $c, str_split($hex)));
            }
            if (strlen($hex) === 6) {
                $hex .= 'ff';
            }
            if (strlen($hex) !== 8 || !ctype_xdigit($hex)) {
                throw $bad;
            }
            $v = hexdec($hex);
            return [$v >> 24 & 255, $v >> 16 & 255, $v >> 8 & 255, $v & 255];
        }
        if (str_starts_with($s, 'rgb')) {
            $open = strpos($s, '('); $end = strrpos($s, ')');
            if ($open === false || $end === false || $end < $open) {
                throw $bad;
            }
            $parts = preg_split('/[, \/]+/', substr($s, $open + 1, $end - $open - 1), -1, PREG_SPLIT_NO_EMPTY);
            if (count($parts) !== 3 && count($parts) !== 4) {
                throw $bad;
            }
            $c = [0, 0, 0, 255];
            foreach ($parts as $i => $p) {
                $pct = str_ends_with($p, '%');
                $num = $pct ? substr($p, 0, -1) : $p;
                if (!is_numeric($num)) {
                    throw $bad;
                }
                $v = (float)$num;
                if ($pct) { $v = $v / 100 * 255; } elseif ($i === 3) { $v *= 255; }
                $c[$i] = (int)round(min(max($v, 0), 255));
            }
            return $c;
        }
        throw $bad;
    }
}
