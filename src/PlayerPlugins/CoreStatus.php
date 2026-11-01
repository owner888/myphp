<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

/**
 * IINA's iina.core.status, read only: paused, idle, position, duration
 * (seconds, null without a film), speed, videoWidth, videoHeight,
 * isNetworkResource, url, title.
 */
final class CoreStatus extends Api
{
    public function __get(string $prop): mixed
    {
        $s = $this->player();
        $p = $s->player;
        return match ($prop) {
            'paused' => $p !== null && !$p->playing(),
            'idle' => $p === null,
            'position' => $p?->time(),
            'duration' => $p?->duration(),
            'speed' => $p?->rate() ?? 1.0,
            'videoWidth' => $p?->videoTrack() === null ? null : $p->size()[0],
            'videoHeight' => $p?->videoTrack() === null ? null : $p->size()[1],
            'isNetworkResource' => $p?->remote() ?? false,
            'url' => $p === null ? null : (preg_match('~^[a-z]+://~i', $p->path()) === 1 ? rawurldecode($p->path()) : 'file://' . $p->path()),
            'title' => $p === null ? '' : ($p->title() === basename($p->path()) ? pathinfo($p->title(), PATHINFO_FILENAME) : $p->title()), // without the file's extension
            default => null,
        };
    }

    public function __set(string $prop, mixed $value): void
    {
        $this->log("core.status: $prop is not accessible", \MyPHP\Logger::WARNING);
    }
}
