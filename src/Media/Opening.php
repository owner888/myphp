<?php
declare(strict_types=1);

namespace MyPHP\Media;

/**
 * What a plugin's hooks made of a film as it opens (mpv's on_load,
 * on_load_fail and on_preloaded, as IINA's plugins use them): the URL to
 * open in its place (stream-open-filename; "memory://" a playlist's text),
 * its title (force-media-title), the headers to send (user-agent,
 * http-header-fields), the audio added (audio-add: a video's audio apart),
 * the subtitles added (sub-add) and its chapters (chapter-list).
 */
final class Opening
{
    public ?string $title = null;
    /** @var array<string, string> */
    public array $headers = [];
    /** @var list<string> */
    public array $audio = [];
    /** @var list<array{url: string, name: string, language: string, ext: string}> */
    public array $subtitles = [];
    /** @var list<Chapter> */
    public array $chapters = [];

    public function __construct(public readonly string $path, public string $url) {}

    /** Whether the hooks made anything of it. */
    public function changed(): bool
    {
        return $this->url !== $this->path || $this->audio !== [] || $this->subtitles !== [] || $this->title !== null || $this->headers !== [];
    }
}
