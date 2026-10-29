<?php
// The app's log (Logger), as IINA's: what is of the level set and above, the
// subsystems "app" first and the others sorted, each line as IINA writes it,
// in the session's file once logToFile() is on; the log viewer
// (Context::logViewer) showing them, of a level and above.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Logger;
use MyPHP\UI\Context;

$logs = sys_get_temp_dir() . '/myphp-logs-' . getmypid();
\MyPHP\App::setPath(\MyPHP\PathName::Logs, $logs);
$before = Logger::count();
Logger::setLevel(Logger::DEBUG);
Logger::verbose('under the level');
Logger::debug('a message', 'zeta');
same($before + 1, Logger::count(), 'of the level and above');
Logger::logToFile(true);
Logger::error('an error', 'alpha');
Logger::warn('a warning');
$subs = Logger::subsystems(); $rest = array_slice($subs, 1); $sorted = $rest; sort($sorted, SORT_STRING);
check($subs[0] === 'app' && in_array('alpha', $rest, true) && in_array('zeta', $rest, true) && $rest === $sorted, 'its subsystems: "app" first, the others sorted: ' . implode(', ', $subs));
$entries = Logger::entries();
check(preg_match('/^\d\d:\d\d:\d\d\.\d{3} \[alpha\]\[e\] an error\n$/', Logger::line($entries[count($entries) - 2])) === 1, 'a line as IINA writes it: ' . Logger::line($entries[count($entries) - 2]));
$file = Logger::directory() . '/' . \MyPHP\App::name() . '.log';
$text = (string)@file_get_contents($file);
check(str_contains($text, '[app][w] a warning') && !str_contains($text, 'a message'), 'in the session\'s file once it is on, not before');
$err = null;
try { Logger::log(7, 'x'); } catch (\InvalidArgumentException $e) { $err = $e->getMessage(); }
same('Logger: no level 7', $err, 'no level but its four');
Logger::logToFile(false);
if (PHP_OS_FAMILY === 'Darwin') { // its symbols are the system's (SF Symbols)
    $h = new Headless(fn(Context $c) => $c->logViewer(), 800, 500);
    $h->frame(); $h->frame();
    check($h->byText('a message') !== null && $h->byText('an error') !== null && $h->byText('All subsystems - debug') !== null, 'the viewer: the messages, what it shows under its title');
}
@unlink($file); @rmdir(dirname($file));

// The app's kind's level (App::isDev): a development app logs from debug on, a production one from warnings on.
same(Logger::DEBUG, Logger::defaultLevel(), 'in development, from debug on');
putenv('MYPHP_ENV=production');
same(Logger::WARNING, Logger::defaultLevel(), 'in production, from warnings on');
putenv('MYPHP_ENV');
