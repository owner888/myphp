<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;
use MyPHP\Transfer\{Data, Format, Operation, Result};
use MyPHP\UI\DataDragEvent;

/**
 * The one native drag this process has on (mygo's dataSource): its window,
 * the random key of its session, which finds its data and value inside
 * this process alone while it lives (the system's data never holds a PHP
 * value), and what it is told when it ends, once. Main thread only.
 */
final class DataDrag
{
    private static ?self $active = null;
    private bool $finished = false;

    private function __construct(
        public readonly Window $window,
        public readonly string $token,
        private ?Data $data,
        private mixed $local,
        private ?Closure $done,
        public readonly int $operations,
    ) {}

    /**
     * Starts a drag of $data from $window (mygo's startDataDragPrepared),
     * $begin(DataDrag) handing it to the system after the input being
     * handled: what is wrong with it throws, and starts nothing.
     */
    public static function start(Window $window, Data $data, mixed $local, ?Closure $done, int $operations, Closure $begin): self
    {
        if (self::$active !== null) {
            throw new \RuntimeException('myphp: a native data drag is already active');
        }
        $formats = $data->formats();
        if ($formats === [] || in_array(Format::DRAG_SESSION, $formats, true)) {
            throw new \InvalidArgumentException('myphp: a data drag needs non-reserved representations');
        }
        $operations = Operation::allowed($operations);
        if ($operations === Operation::NONE) {
            throw new \InvalidArgumentException('myphp: no supported drag operation');
        }
        $src = new self($window, bin2hex(random_bytes(16)), $data->snapshot(), $local, $done, $operations);
        self::$active = $src;
        App::later(function () use ($src, $begin) {
            if (!$src->finished) {
                $begin($src);
            }
        });
        return $src;
    }

    /** The data as the drag snapshot it. */
    public function data(): Data
    {
        return $this->data ?? throw new \LogicException('the drag ended');
    }

    /**
     * Ends the drag, telling its source $r once (mygo's finish): an effect
     * it did not allow is an error; an error or a cancel has no effect, and
     * no effect is a cancel.
     */
    public function finish(Result $r): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        $op = $r->operation;
        if ($op !== Operation::NONE && (($op !== Operation::COPY && $op !== Operation::MOVE) || ($op & $this->operations) === 0)) {
            $r = new Result(error: new \RuntimeException('myphp: destination returned a disallowed drag operation'));
        }
        if ($r->error !== null || $r->canceled) {
            $r = new Result(Operation::NONE, $r->canceled, $r->error);
        }
        if ($r->operation === Operation::NONE && $r->error === null) {
            $r = new Result(Operation::NONE, true);
        }
        if (self::$active === $this) {
            self::$active = null;
        }
        $this->data = null; $this->local = null;
        $done = $this->done;
        $this->done = null;
        if ($done !== null) {
            $done($r);
        }
    }

    /** Cancels the drag $window sources, if any (mygo's cancelDataDrag): $native tells the system; its source is told at once. */
    public static function cancel(Window $window, Closure $native): void
    {
        $s = self::$active;
        if ($s !== null && $s->window === $window) {
            self::$active = null; // before callbacks or the system's cancel can come back in; its later end is ignored
            $native();
            $s->finish(new Result(canceled: true));
        }
    }

    /** Gives $d the value and data of this process's drag it carries the key of (mygo's resolveDataDrag). */
    public static function resolve(?DataDragEvent $d): void
    {
        if ($d === null) {
            return;
        }
        $d->local = null;
        if ($d->resolved) {
            $d->data = null; $d->resolved = false;
        }
        $s = self::$active;
        if ($s !== null && $d->session !== '' && $s->token === $d->session) {
            $d->local = $s->local; $d->data = $s->data;
            $d->resolved = true;
            $d->offer = new \MyPHP\Transfer\Offer($s->data->formats(), $d->offer->operations, $d->offer->suggested);
        }
    }
}
