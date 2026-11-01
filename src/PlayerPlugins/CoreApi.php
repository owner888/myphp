<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use MyPHP\App;
use MyPHP\Media\Track;

/**
 * IINA's iina.core: the player's controls (open, osd, pause, resume, stop,
 * seek, seekTo, setSpeed, chapters, the interface hidden), the history,
 * the recent documents, the versions; and its window, status, audio,
 * subtitle and video, objects whose properties read and set the player's
 * (IINA's proxies).
 */
final class CoreApi extends Api
{
    public readonly CoreWindow $window;
    public readonly CoreStatus $status;
    public readonly CoreTracks $audio, $subtitle, $video;

    public function __construct(Instance $instance)
    {
        parent::__construct($instance);
        $this->window = new CoreWindow($instance);
        $this->status = new CoreStatus($instance);
        $this->audio = new CoreTracks($instance, Track::AUDIO);
        $this->subtitle = new CoreTracks($instance, Track::SUBTITLE);
        $this->video = new CoreTracks($instance, Track::VIDEO);
    }

    /** Opens $url in the player (a file, or the web's with the network's permission). */
    public function open(string $url): void
    {
        $this->player()->open($this->shouldOpenURL($url));
    }

    /** Shows $message on the screen, "From plugin <name>" under it (the show-osd permission). */
    public function osd(string $message): void
    {
        $this->require(Plugin::OSD);
        $this->player()->say($message, "From plugin {$this->instance->plugin->name}");
    }

    public function pause(): void
    {
        $this->player()->pause();
    }

    public function resume(): void
    {
        $this->player()->resume();
    }

    public function stop(): void
    {
        $this->player()->stop();
    }

    /** $seconds on (back when negative); to the very time where $exact, else a keyframe. */
    public function seek(float $seconds, bool $exact = false): void
    {
        $this->player()->seekBy($seconds, $exact);
    }

    /** To $seconds from the start. */
    public function seekTo(float $seconds): void
    {
        $s = $this->player();
        $s->seekBy($seconds - ($s->player?->time() ?? 0.0), true);
    }

    public function setSpeed(float $speed): void
    {
        $this->player()->setSpeed($speed);
    }

    /** @return list<array{title: string, start: float}> */
    public function getChapters(): array
    {
        return array_map(fn($ch) => ['title' => $ch->title, 'start' => $ch->start], $this->player()->player?->chapters() ?? []);
    }

    public function playChapter(int $index): void
    {
        $this->player()->playChapter($index);
    }

    /** Shows the player's controls, or hides them for good ($visible false), as IINA's disableUI. */
    public function setUIVisibility(bool $visible): void
    {
        $this->player()->setUIDisabled(!$visible);
    }

    /** The films played (IINA's history): name, url, date (a Unix time), progress (seconds, null for none), duration. @return list<array<string, mixed>> */
    public function getHistory(): array
    {
        return $this->player()->historyEntries();
    }

    /** @return list<array{name: string, url: string}> */
    public function getRecentDocuments(): array
    {
        return array_map(fn(string $p) => ['name' => basename($p), 'url' => preg_match('~^[a-z]+://~i', $p) === 1 ? $p : 'file://' . $p], App::running() ? App::recentDocuments() : []);
    }

    /** The app's version and build, and what plays the films ("mpv" IINA's mpv's: MyPHP's player). @return array<string, string> */
    public function getVersion(): array
    {
        return ['iina' => App::version(), 'build' => App::version(), 'mpv' => \MyPHP\Media\Player::VIDEO_OUTPUT];
    }
}
