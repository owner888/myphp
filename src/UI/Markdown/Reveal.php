<?php
declare(strict_types=1);

namespace MyPHP\UI\Markdown;

/**
 * How Markdown::stream shows text as it comes (mygo's Reveal): rather than
 * in the bursts a network delivers, at an even pace that catches up with
 * what came within $catchUp, a unit at a time, each fading in, as the chat
 * apps show replies.
 */
final class Reveal
{
    public function __construct(
        public RevealUnit $unit = RevealUnit::ByWord,
        /** How long text that came waits at most before it shows, in seconds: 0.25 when 0. Text comes in faster the more there is waiting. */
        public float $catchUp = 0,
        /** How long text takes to fade in, in seconds: 0.3 when 0, none when negative, nor when the desktop asks for less motion. */
        public float $fade = 0,
    ) {}
}
