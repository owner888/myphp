<?php
declare(strict_types=1);

namespace MyPHP\Native;

use MyPHP\{WindowState, WindowStateStore};
use RuntimeException;

/** The default WindowStateStore (mygo's stateFile): a JSON file, written through a temporary one. @internal */
final class WindowStateFile implements WindowStateStore
{
    public function __construct(private readonly string $path) {}

    public function loadWindowStates(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }
        $json = json_decode((string)file_get_contents($this->path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($json)) {
            throw new RuntimeException("{$this->path} is no object");
        }
        $states = [];
        foreach ($json as $key => $v) {
            if (($st = WindowState::fromArray($v)) !== null) {
                $states[(string)$key] = $st;
            }
        }
        return $states;
    }

    public function saveWindowStates(array $states): void
    {
        $tmp = "{$this->path}.tmp";
        $json = json_encode(array_map(fn(WindowState $s) => $s->toArray(), $states), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);
        if (file_put_contents($tmp, $json) === false || !rename($tmp, $this->path)) {
            throw new RuntimeException("cannot write {$this->path}");
        }
    }
}
