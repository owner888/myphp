<?php
declare(strict_types=1);

namespace MyPHP\Platform\Darwin;

use MyPHP\Native\ObjC;
use MyPHP\Platform;

/** A session's WKWebsiteDataStore, retained until close() (mygo's darwin dataStore). */
final class DataStore implements Platform\DataStore
{
    /** @param mixed $ns the WKWebsiteDataStore, retained */
    public function __construct(public readonly mixed $ns) {}

    public function close(): void
    {
        ObjC::msg('v:', $this->ns, 'release');
    }
}
