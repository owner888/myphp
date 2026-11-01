<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Loading;

/** The settings of an indicator, each meaning loading.dev's default when left as it is (mygo's loading.Options). */
final class Options
{
    public function __construct(
        /** The indicator's height in points, Loading::DEFAULT_SIZE if 0; its width the same, but for bouncingDots's and linearDots's rows of dots. */
        public float $size = 0,
        /** What the indicator draws in, 0xRRGGBB; the theme's text color if null. */
        public ?int $color = null,
        /** The seconds of one cycle of its motion, its own (as loading.dev's) if 0. */
        public float $duration = 0,
        /** Stops the indicator where it is; it goes on from there once false again, as CSS's paused animation-play-state. */
        public bool $paused = false,
        /** How the indicators that turn (arc, atom, clock, comet, dual, orbit, radar, ring, snake and trace) turn. */
        public Easing $easing = Easing::Linear,
        /** The end of the strokes of arc, cascade, dual, ring, snake and trace. */
        public Cap $cap = Cap::RoundCap,
        /** The order in which blocks's blocks shrink. */
        public Sweep $sweep = Sweep::Diagonal,
        /** Where ripple's rings go. */
        public RippleDirection $direction = RippleDirection::RippleOut,
        /** Where wave's bars grow from. */
        public WaveOrigin $origin = WaveOrigin::WaveCenter,
    ) {}
}
