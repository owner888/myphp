<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use Closure;
use MyPHP\Logger;

/**
 * IINA's iina.playlist of the player, while a film plays: its entries
 * {filename, title, isPlaying, isCurrent}, added (their permission as
 * opening them), removed, moved, played; the next and the one before;
 * the builder of the items its playlist's menu gets for an entry.
 */
final class PlaylistApi extends Api
{
    /** The function giving the items of the playlist's menu, null for none (IINA's menuItemBuilder). */
    public ?Closure $menuItemBuilder = null;

    private function playing(): bool
    {
        if ($this->player()->player === null) {
            $this->log('Playlist API is only available when playing files.', Logger::ERROR);
            return false;
        }
        return true;
    }

    /** @return list<array{filename: string, title: ?string, isPlaying: bool, isCurrent: bool}> */
    public function list(): array
    {
        if (!$this->playing()) { return []; }
        $l = $this->player()->playlist;
        $out = [];
        foreach ($l?->files() ?? [] as $i => $f) {
            $title = $l->title($f);
            $out[] = ['filename' => $f, 'title' => $title !== basename($f) ? $title : null, 'isPlaying' => $i === $l->index(), 'isCurrent' => $i === $l->index()];
        }
        return $out;
    }

    public function count(): int
    {
        return $this->player()->playlist?->count() ?? 0;
    }

    /** Adds $url (or a list of them) before entry $at, at the end for -1: whether all could be. @param string|list<string> $url */
    public function add(string|array $url, int $at = -1): bool
    {
        if (!$this->playing()) { return false; }
        if ($at >= $this->count()) { $this->log('playlist.add: Invalid index.', Logger::ERROR); return false; }
        $paths = is_array($url) ? $url : [$url];
        $allowed = [];
        foreach ($paths as $p) {
            try { $allowed[] = $this->shouldOpenURL((string)$p); } catch (PluginError $e) { $this->log($e->getMessage(), Logger::ERROR); }
        }
        $this->player()->addToPlaylist($allowed, $at);
        return count($allowed) === count($paths);
    }

    /** Removes entry $index (or those of a list). @param int|list<int> $index */
    public function remove(int|array $index): bool
    {
        if (!$this->playing()) { return false; }
        $all = is_array($index) ? $index : [$index];
        foreach ($all as $i) {
            if (!is_int($i) || $i < 0 || $i >= $this->count()) { $this->log('playlist.remove: Invalid index.', Logger::ERROR); return false; }
        }
        rsort($all);
        foreach ($all as $i) { $this->player()->playlist?->remove($i); }
        return true;
    }

    public function move(int $index, int $to): bool
    {
        if (!$this->playing()) { return false; }
        $n = $this->count();
        if ($index < 0 || $index >= $n || $to < 0 || $to >= $n || $index === $to) { $this->log('playlist.move: Invalid index.', Logger::ERROR); return false; }
        $this->player()->playlist?->move($index, $to);
        return true;
    }

    public function play(int $index): void
    {
        if (!$this->playing()) { return; }
        $f = $this->player()->playlist?->files()[$index] ?? null;
        if ($f === null) { $this->log('playlist.play: Invalid index.', Logger::ERROR); return; }
        $this->player()->open($f);
    }

    public function playNext(): void
    {
        if ($this->playing()) { $this->player()->next(); }
    }

    public function playPrevious(): void
    {
        if ($this->playing()) { $this->player()->previous(); }
    }

    /** Has $builder give the items of the playlist's menu for the entries chosen (their indices): a list of PluginMenuItem. */
    public function registerMenuItemBuilder(Closure $builder): void
    {
        $this->menuItemBuilder = $builder;
    }
}
