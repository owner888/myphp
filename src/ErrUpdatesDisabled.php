<?php
declare(strict_types=1);

namespace MyPHP;

/** What Updater::check() throws in builds without updates: development builds, and builds of apps without updates in myphp.json (mygo's ErrUpdatesDisabled). */
final class ErrUpdatesDisabled extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('myphp: updates are not enabled for this build');
    }
}
