<?php
declare(strict_types=1);

namespace MyPHP\Audio;

/**
 * What gives a voice its frames: a sound's samples, or a stream decoding
 * ahead (mygo's source). Its methods never wait.
 * @internal
 */
interface Source
{
    /**
     * Reads at most $frames frames, interleaved, and whether the source
     * ended: fewer than asked only at the end, where it says so, or while
     * a stream decodes, where it does not. It goes on from the loop's
     * start when looping.
     * @return array{0: list<float>, 1: bool}
     */
    public function read(int $frames): array;

    public function seek(int $frame): void;

    /** Whether it loops, from frame $start to $end (0 for its end). */
    public function setLoop(bool $loop, int $start, int $end): void;

    public function close(): void;
}
