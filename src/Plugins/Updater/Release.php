<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Updater;

use Closure;

/**
 * An update a check found (mygo's release): MyPHP\Update, or what tests
 * make.
 *
 * @internal
 */
final class Release
{
    /** @param Closure(?Closure(int, int): void, Closure(): bool): void $install installs it, with its progress, until canceled */
    public function __construct(public readonly string $version, public readonly string $notes, public readonly Closure $install) {}
}
