<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use MyPHP\UI\SpringOptions;

/**
 * A keyframe of a KeyframeTrack, as SwiftUI's LinearKeyframe, CubicKeyframe,
 * SpringKeyframe and MoveKeyframe: the value to reach and how long it takes
 * from the keyframe before. Made by those functions. @internal
 */
final class Keyframe
{
    public const LINEAR = 0, CUBIC = 1, SPRING = 2, MOVE = 3;

    public function __construct(
        public readonly int $kind,
        public readonly float $to,
        public readonly float $duration,
        public readonly ?Closure $curve = null,
        public readonly ?SpringOptions $spring = null,
    ) {}
}
