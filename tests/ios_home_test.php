<?php
// An iPhone app's HOME is its container, not its Documents folder: App::path keeps the app's data in Library/Application
// Support and its caches in Library/Caches, which iCloud does not back up and Files does not show; Documents is the user's.
declare(strict_types=1);

namespace MyPHP\Tests;

$main = (string)file_get_contents(dirname(__DIR__) . '/src/Platform/iOS/launcher/main.m');
check(str_contains($main, 'setenv("HOME", [NSHomeDirectory() UTF8String], 1);'), 'HOME is the container');
check(!preg_match('/setenv\("HOME", \[docDir/', $main), 'and is not the Documents folder');
