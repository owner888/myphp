<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use MyPHP\Media\Player;
use MyPHP\Media\Track;

/**
 * IINA's iina.core.audio, .subtitle and .video: the player's tracks of a
 * type, their properties read and set: id (mpv's, 1 the first; null for
 * none), tracks, currentTrack; the audio's delay, volume (0 to 100 and
 * more) and muted; the subtitles' delay and secondID; and loadTrack(url),
 * a file of the type loaded (the video's MyPHP cannot).
 */
final class CoreTracks extends Api
{
    public function __construct(Instance $instance, private readonly string $type)
    {
        parent::__construct($instance);
    }

    /** @return list<Track> mpv's list of the type, 1 the first */
    private function tracks(): array
    {
        $p = $this->player()->player;
        return match ($this->type) {
            Track::AUDIO => array_values(array_filter($p?->audioTracks() ?? [], [Player::class, 'playable'])),
            Track::SUBTITLE => $p?->subtitleTracks() ?? [],
            default => array_values(array_filter([$p?->videoTrack()])),
        };
    }

    private function current(bool $second = false): ?Track
    {
        $p = $this->player()->player;
        return match ($this->type) {
            Track::AUDIO => $p?->audioTrack(),
            Track::SUBTITLE => $second ? $p?->secondarySubtitleTrack() : $p?->subtitleTrack(),
            default => $p?->videoTrack(),
        };
    }

    private function id(?Track $t): ?int
    {
        $i = $t === null ? false : array_search($t, $this->tracks(), true);
        return $i === false ? null : $i + 1;
    }

    /** @return array<string, mixed> IINA's serialize(track:) */
    private function serialize(Track $t): array
    {
        $p = $this->player()->player;
        $title = $t->name !== '' ? $t->name : null;
        return [
            'id' => $this->id($t),
            'title' => $title,
            'formattedTitle' => trim(($title ?? '') . ($t->language !== '' && $t->language !== 'und' ? " ({$t->language})" : '')) ?: "#{$this->id($t)}",
            'lang' => $t->language !== '' && $t->language !== 'und' ? $t->language : null,
            'codec' => $t->codec,
            'isDefault' => $t->isDefault,
            'isForced' => false,
            'isSelected' => $t === $this->current(),
            'isExternal' => $t->number < 0,
            'demuxW' => $t->width ?: null,
            'demuxH' => $t->height ?: null,
            'demuxChannelCount' => $t->channels ?: null,
            'demuxChannels' => null,
            'demuxSamplerate' => $t->sampleRate ?: null,
            'demuxFPS' => $t->frameDuration > 0 ? 1 / $t->frameDuration : null,
        ];
    }

    public function __get(string $prop): mixed
    {
        $s = $this->player();
        $p = $s->player;
        return match (true) {
            $prop === 'id' => $this->id($this->current()),
            $prop === 'tracks' => array_map($this->serialize(...), $this->tracks()),
            $prop === 'currentTrack' => ($t = $this->current()) === null ? null : $this->serialize($t),
            $prop === 'delay' && $this->type === Track::AUDIO => $p?->audioDelay() ?? 0.0,
            $prop === 'delay' && $this->type === Track::SUBTITLE => $p?->subtitleDelay() ?? 0.0,
            $prop === 'secondID' && $this->type === Track::SUBTITLE => $this->id($this->current(true)),
            $prop === 'volume' && $this->type === Track::AUDIO => $s->volume * 100,
            $prop === 'muted' && $this->type === Track::AUDIO => $p?->muted() ?? false,
            default => null,
        };
    }

    public function __set(string $prop, mixed $value): void
    {
        $s = $this->player();
        $p = $s->player;
        if ($p === null) { return; }
        $tag = ['audio' => 'audio', 'subtitle' => 'subtitle', 'video' => 'video'][$this->type];
        switch (true) {
            case $prop === 'id' || ($prop === 'secondID' && $this->type === Track::SUBTITLE):
                if (!is_int($value) && $value !== null) { $this->log("$tag.id: Should be a number", \MyPHP\Logger::ERROR); return; }
                $t = $value === null || $value === 0 ? null : ($this->tracks()[$value - 1] ?? null);
                match ($this->type) {
                    Track::AUDIO => $t !== null ? $p->setAudioTrack($t) : null,
                    Track::SUBTITLE => $prop === 'secondID' ? $p->setSecondarySubtitleTrack($t) : $p->setSubtitleTrack($t),
                    default => $this->log('core.video.id: the player shows its one video track', \MyPHP\Logger::WARNING),
                };
                return;
            case $prop === 'delay' && $this->type !== Track::VIDEO:
                if (!is_int($value) && !is_float($value)) { $this->log("core.$tag.delay: Should be a number", \MyPHP\Logger::ERROR); return; }
                $this->type === Track::AUDIO ? $s->setAudioDelay((float)$value) : $s->setSubtitleDelay((float)$value);
                return;
            case $prop === 'volume' && $this->type === Track::AUDIO:
                if (!is_int($value) && !is_float($value)) { $this->log('core.audio.volume: Should be a number', \MyPHP\Logger::ERROR); return; }
                $s->setVolume($value / 100);
                return;
            case $prop === 'muted' && $this->type === Track::AUDIO:
                if (!is_bool($value)) { $this->log('core.audio.muted: Should be a boolean value', \MyPHP\Logger::ERROR); return; }
                if ($value !== $p->muted()) { $s->toggleMute(); }
                return;
        }
    }

    /** Loads the file at $url as a track of the type (IINA's loadTrack): its permission as opening it. */
    public function loadTrack(mixed $url): void
    {
        if (!is_string($url)) { $this->log('loadTrack: the url must be a string', \MyPHP\Logger::ERROR); return; }
        $path = $this->shouldOpenURL($url);
        $s = $this->player();
        match ($this->type) {
            Track::AUDIO => $s->loadAudio($path),
            Track::SUBTITLE => $s->loadSubtitles($path),
            default => $this->fail('loadTrack: MyPHP plays one video track, of the film'),
        };
    }
}
