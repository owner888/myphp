<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * A plural category of the Unicode CLDR (mygo's i18n.Plural): the form of a
 * word or a phrase a language uses for a number. English has two for
 * cardinal numbers, "1 file" (One) and "2 files" (Other), and four for
 * ordinal ones, "1st", "2nd", "3rd" and "4th"; Japanese has one, Arabic
 * six. Its value is its keyword in CLDR and in messages; Plural::tryFrom
 * reads one (mygo's ParsePlural).
 */
enum Plural: string
{
    case Zero = 'zero';
    case One = 'one';
    case Two = 'two';
    case Few = 'few';
    case Many = 'many';
    case Other = 'other';

    /** @internal the category's place in CLDR's order, its bit in the tables */
    public function index(): int
    {
        return match ($this) { self::Zero => 0, self::One => 1, self::Two => 2, self::Few => 3, self::Many => 4, self::Other => 5 };
    }

    /** @internal the categories of a mask of the tables, in order @return list<Plural> */
    public static function ofMask(int $mask): array
    {
        return array_values(array_filter(self::cases(), fn(Plural $p) => ($mask & (1 << $p->index())) !== 0));
    }
}
