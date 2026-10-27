<?php
declare(strict_types=1);

namespace MyPHP\Media;

/** A track of a media file (Matroska's TrackEntry): what it holds, in what codec, and how its frames decode. */
final class Track
{
    public const VIDEO = 'video', AUDIO = 'audio', SUBTITLE = 'subtitle', OTHER = 'other';

    public function __construct(
        public readonly int $number,
        public readonly string $type,
        /** Matroska's codec id: V_MPEG4/ISO/AVC, V_MPEGH/ISO/HEVC, A_AAC, A_AC3… */
        public readonly string $codec,
        /** What the decoder starts from: an avcC or hvcC record, an AudioSpecificConfig… */
        public readonly string $private,
        public readonly string $language,
        public readonly string $name,
        public readonly bool $isDefault,
        /** Seconds a frame, 0 when the file does not say. */
        public readonly float $frameDuration,
        public readonly int $width,
        public readonly int $height,
        public readonly float $sampleRate,
        public readonly int $channels,
        /** @internal the bytes header stripping took off the start of each frame, put back by Matroska */
        public readonly string $strip = '',
    ) {}
}
