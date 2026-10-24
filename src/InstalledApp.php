<?php
declare(strict_types=1);

namespace MyPHP;

/** An application installed on the Mac, as Shell::installedApps lists it (mygo's InstalledApp). */
final class InstalledApp
{
    public function __construct(
        /** The name Finder shows, in the user's language, without .app. */
        public readonly string $name,
        /** Its bundle identifier, or its path for a bundle without one. */
        public readonly string $id,
        /** Its .app bundle, whose icon Shell::fileIcon draws. */
        public readonly string $path,
    ) {}
}
