<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use Closure;

/**
 * IINA's iina.subtitle: the plugin's subtitle providers (those its
 * Info.json's subtitleProviders declare), registered by their id, each an
 * object or an array of its functions: search(), the SubtitleItems found
 * (or CUSTOM_IMPLEMENTATION, where it shows them itself); download($item),
 * the files of an item, IINA paths ("@tmp/a.srt"); and, where it has one,
 * description($item), the item's desc where it has none. Searched from
 * Find Online Subtitles (MediaPlayerState::searchOnlineSubtitles).
 */
final class SubtitleApi extends Api
{
    public const CUSTOM_IMPLEMENTATION = 'custom-implementation';

    /** @var array<string, object|array<string, Closure>> */
    private array $providers = [];

    public function registerProvider(string $id, object|array $provider): void
    {
        $this->providers[$id] = $provider;
    }

    /** An item of $data, described by $desc ({name, left, right}). @param array<string, string>|null $desc */
    public function item(mixed $data, ?array $desc = null): SubtitleItem
    {
        return new SubtitleItem($data, $desc);
    }

    /** $provider's function $name, null for none. */
    private static function fn(object|array $provider, string $name): ?Closure
    {
        if (is_array($provider)) { return ($provider[$name] ?? null) instanceof Closure ? $provider[$name] : null; }
        return method_exists($provider, $name) ? Closure::fromCallable([$provider, $name]) : null;
    }

    /**
     * @internal MediaPlayerState: the subtitles provider $id finds (IINA's
     * __invokeSearch), each described; null where it shows them itself.
     * What is wrong throws, as IINA's fail().
     * @return ?list<SubtitleItem>
     */
    public function search(string $id): ?array
    {
        $p = $this->providers[$id] ?? throw new PluginError("The provider with id \"$id\" is not registered.");
        foreach (['search', 'download'] as $name) {
            if (self::fn($p, $name) === null) { throw new PluginError("provider.$name doesn't exist or is not a function."); }
        }
        $subs = self::fn($p, 'search')();
        if ($subs === self::CUSTOM_IMPLEMENTATION) { return null; }
        if (!is_array($subs) || array_filter($subs, fn($s) => !$s instanceof SubtitleItem) !== []) { throw new PluginError('provider.search should return an array of subtitle items.'); }
        $describe = self::fn($p, 'description');
        foreach ($subs as $sub) {
            if ($describe !== null && $sub->desc === null) { $sub->desc = $describe($sub); }
        }
        return array_values($subs);
    }

    /**
     * @internal MediaPlayerState: the files of $item provider $id downloads,
     * as paths (IINA's parsed). What is wrong throws.
     * @return list<string>
     */
    public function download(string $id, SubtitleItem $item): array
    {
        $p = $this->providers[$id] ?? throw new PluginError("The provider with id \"$id\" is not registered.");
        $urls = self::fn($p, 'download')?->__invoke($item);
        if (!is_array($urls) || array_filter($urls, fn($u) => !is_string($u)) !== []) { throw new PluginError('provider.download should return an array of strings.'); }
        return array_map(fn(string $u) => $this->parsePath($u)[0], array_values($urls));
    }
}
