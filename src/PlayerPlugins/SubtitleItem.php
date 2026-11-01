<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

/**
 * A subtitle a plugin's provider found (IINA's JavascriptPluginSubtitleItem):
 * its data, the provider's own, and how it is described in the list to
 * choose from, desc {name, left, right}.
 */
final class SubtitleItem
{
    /** @param array<string, string>|null $desc */
    public function __construct(public readonly mixed $data, public ?array $desc = null) {}
}
