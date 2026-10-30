<?php
declare(strict_types=1);

namespace MyPHP;

/** A player NowPlaying::control drives, as an Audio\Player (mygo's MediaPlayer); times are in seconds. */
interface MediaPlayer
{
    public function play(): void;

    public function pause(): void;

    public function stop(): void;

    public function playing(): bool;

    public function seek(float $seconds): void;

    public function position(): float;

    public function duration(): float;
}
