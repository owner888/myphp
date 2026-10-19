<?php
declare(strict_types=1);

namespace MyPHP\Transfer;

/** A value with alternative representations, in preference order (mygo's transfer.Item): one item can offer HTML and plain text of the same content. */
final class Item
{
    /** @var list<Representation> */
    public readonly array $reps;

    public function __construct(Representation ...$reps)
    {
        $seen = [];
        foreach ($reps as $r) {
            if (isset($seen[$r->format])) {
                throw new \InvalidArgumentException('transfer: duplicate representation');
            }
            $seen[$r->format] = true;
        }
        $this->reps = array_values($reps);
    }

    /** The formats it advertises, without calling providers. @return list<string> */
    public function formats(): array
    {
        return array_map(fn(Representation $r) => $r->format, $this->reps);
    }

    /** The bytes of $format; FormatUnavailable when it has none. */
    public function read(string $format): string
    {
        foreach ($this->reps as $r) {
            if ($r->format === $format) {
                return $r->read();
            }
        }
        throw new FormatUnavailable;
    }
}
