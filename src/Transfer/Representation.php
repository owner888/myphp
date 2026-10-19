<?php
declare(strict_types=1);

namespace MyPHP\Transfer;

use Closure;

/** One format of an Item (mygo's transfer.Representation), made with bytes() or lazy(). */
final class Representation
{
    /** @param array{provider: ?Closure, bytes: ?string, error: ?\Throwable, done: bool} $value shared by the copies of a snapshot's representation */
    private function __construct(public readonly string $format, private \ArrayObject $value) {}

    /** A representation of $bytes; empty data is valid. */
    public static function bytes(string $format, string $bytes): self
    {
        Format::validate($format);
        return new self($format, new \ArrayObject(['provider' => null, 'bytes' => $bytes, 'error' => null, 'done' => true]));
    }

    /**
     * Advertises $format without producing its bytes: $provider() returns
     * them, at most once per snapshot, when a destination asks for this
     * format, and what it throws is kept too. Native integrations call it
     * on the main thread; it must return promptly.
     */
    public static function lazy(string $format, Closure $provider): self
    {
        Format::validate($format);
        return new self($format, new \ArrayObject(['provider' => $provider, 'bytes' => null, 'error' => null, 'done' => false]));
    }

    /** The bytes, made by the provider the first time; what it threw then, thrown again. */
    public function read(): string
    {
        $v = $this->value;
        if (!$v['done']) {
            $v['done'] = true;
            try {
                $b = ($v['provider'])();
                if (!is_string($b)) {
                    throw new \UnexpectedValueException('transfer: a provider returned ' . get_debug_type($b));
                }
                $v['bytes'] = $b;
            } catch (\Throwable $e) {
                $v['error'] = $e;
            }
        }
        if ($v['error'] !== null) {
            throw $v['error'];
        }
        return $v['bytes'];
    }

    /** A copy with a cache of its own, which a provider fills again (Data::snapshot). */
    public function fresh(): self
    {
        $p = $this->value['provider'];
        return $p === null ? new self($this->format, new \ArrayObject($this->value->getArrayCopy())) : self::lazy($this->format, $p);
    }
}
