<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

/**
 * A persistent sequence (mygo's richtext seq): an AVL tree whose leaves
 * hold up to Seq::CHUNK items each, as the rope's leaves hold text, and
 * whose nodes count the items below them. Replacing items copies the
 * leaves it touches and the path to them and shares the rest with the
 * sequence before it, so a version is a pointer, kept as long as wanted.
 * A Text keeps its paragraphs in one, aligned with the lines of its text.
 * Null is the empty sequence; nodes and their items never change once
 * made.
 *
 * @internal
 */
final class Seq
{
    public const CHUNK = 32;

    /** @param list<mixed> $items only leaves hold items */
    private function __construct(
        public readonly ?Seq $left,
        public readonly ?Seq $right,
        public readonly array $items,
        public readonly int $n,
        public readonly int $height,
    ) {}

    public static function len(?Seq $s): int { return $s === null ? 0 : $s->n; }

    private static function height(?Seq $s): int { return $s === null ? 0 : $s->height; }

    /** @param list<mixed> $items */
    public static function leaf(array $items): ?Seq
    {
        return $items === [] ? null : new Seq(null, null, $items, count($items), 1);
    }

    private static function branch(?Seq $a, ?Seq $b): ?Seq
    {
        if ($a === null) { return $b; }
        if ($b === null) { return $a; }
        return new Seq($a, $b, [], $a->n + $b->n, 1 + max($a->height, $b->height));
    }

    private static function balance(Seq $s): Seq
    {
        if (self::height($s->left) - self::height($s->right) > 1) {
            $a = $s->left;
            if (self::height($a->left) < self::height($a->right)) {
                $b = $a->right;
                $a = self::branch(self::branch($a->left, $b->left), $b->right);
            }
            return self::branch($a->left, self::branch($a->right, $s->right));
        }
        if (self::height($s->right) - self::height($s->left) > 1) {
            $b = $s->right;
            if (self::height($b->right) < self::height($b->left)) {
                $a = $b->left;
                $b = self::branch($a->left, self::branch($a->right, $b->right));
            }
            return self::branch(self::branch($s->left, $b->left), $b->right);
        }
        return $s;
    }

    /** The items of $a followed by those of $b. */
    public static function join(?Seq $a, ?Seq $b): ?Seq
    {
        if ($a === null) { return $b; }
        if ($b === null) { return $a; }
        if ($a->left === null && $b->left === null && $a->n + $b->n <= self::CHUNK) {
            return self::leaf([...$a->items, ...$b->items]);
        }
        if ($a->height > $b->height + 1) {
            return self::balance(self::branch($a->left, self::join($a->right, $b)));
        }
        if ($b->height > $a->height + 1) {
            return self::balance(self::branch(self::join($a, $b->left), $b->right));
        }
        return self::branch($a, $b);
    }

    /** A sequence of $items. @param list<mixed> $items */
    public static function build(array $items): ?Seq
    {
        $n = count($items);
        if ($n <= self::CHUNK) { return self::leaf($items); }
        $m = intdiv($n, 2);
        return self::branch(self::build(array_slice($items, 0, $m)), self::build(array_slice($items, $m)));
    }

    /** The items before index $at and those from it. @return array{0: ?Seq, 1: ?Seq} */
    public static function split(?Seq $s, int $at): array
    {
        if ($s === null) { return [null, null]; }
        if ($at <= 0) { return [null, $s]; }
        if ($at >= $s->n) { return [$s, null]; }
        if ($s->left === null) {
            return [self::leaf(array_slice($s->items, 0, $at)), self::leaf(array_slice($s->items, $at))];
        }
        if ($at < $s->left->n) {
            [$a, $b] = self::split($s->left, $at);
            return [$a, self::join($b, $s->right)];
        }
        [$a, $b] = self::split($s->right, $at - $s->left->n);
        return [self::join($s->left, $a), $b];
    }

    /** $s with items $i up to $j replaced by $items. @param list<mixed> $items */
    public static function replace(?Seq $s, int $i, int $j, array $items): ?Seq
    {
        [$left, $rest] = self::split($s, $i);
        [, $right] = self::split($rest, $j - $i);
        return self::join(self::join($left, self::build($items)), $right);
    }

    /** Item $i. */
    public static function at(Seq $s, int $i): mixed
    {
        while ($s->left !== null) {
            if ($i < $s->left->n) {
                $s = $s->left;
            } else {
                $i -= $s->left->n;
                $s = $s->right;
            }
        }
        return $s->items[$i];
    }

    /** Calls $fn($i, $item) with the items from index $from on, until it returns false. */
    public static function each(?Seq $s, int $from, \Closure $fn): bool
    {
        return self::walk($s, $from, 0, $fn);
    }

    private static function walk(?Seq $s, int $from, int $base, \Closure $fn): bool
    {
        if ($s === null || $from >= $s->n) { return true; }
        if ($s->left === null) {
            for ($k = max($from, 0), $n = count($s->items); $k < $n; $k++) {
                if (!$fn($base + $k, $s->items[$k])) { return false; }
            }
            return true;
        }
        if ($from < $s->left->n && !self::walk($s->left, $from, $base, $fn)) { return false; }
        return self::walk($s->right, $from - $s->left->n, $base + $s->left->n, $fn);
    }
}
