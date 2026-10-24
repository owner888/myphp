<?php
declare(strict_types=1);

namespace MyPHP\Native\Docs;

use Closure;
use MyPHP\{App, Dialog, MessageOptions, MessageResult, OpenDialogOptions, PathName, SaveDialogOptions, Window};

/**
 * What documents ask of the app: dialogs, the run loop's turns and timers,
 * the clock, the data directory, quitting again, and windows' frames.
 * Tests set them, so that the flows of documents run without AppKit, as
 * mygo's tests drive them with its fake backend.
 *
 * @internal
 */
final class Host
{
    /** @var ?Closure(MessageOptions): MessageResult */
    public static ?Closure $message = null;
    /** @var ?Closure(SaveDialogOptions): string */
    public static ?Closure $save = null;
    /** @var ?Closure(OpenDialogOptions): list<string> */
    public static ?Closure $open = null;
    /** @var ?Closure(Closure): void runs a function on the next turn of the run loop */
    public static ?Closure $later = null;
    /** @var ?Closure(float, Closure): void runs a function seconds from now */
    public static ?Closure $after = null;
    /** @var ?Closure(): float the time in seconds */
    public static ?Closure $now = null;
    /** Where the recovery copies go, App::path(PathName::UserData) when null. */
    public static ?string $dataDir = null;
    /** @var ?Closure(): void quits once the documents are taken care of (mygo's quitAgain) */
    public static ?Closure $quit = null;
    /** @var ?Closure(): ?Frame the frame of the window that has the keyboard */
    public static ?Closure $focused = null;

    public static function message(MessageOptions $o): MessageResult
    {
        return (self::$message ?? Dialog::message(...))($o);
    }

    public static function save(SaveDialogOptions $o): string
    {
        return (self::$save ?? Dialog::save(...))($o);
    }

    /** @return list<string> */
    public static function open(OpenDialogOptions $o): array
    {
        return (self::$open ?? Dialog::open(...))($o);
    }

    public static function later(Closure $fn): void
    {
        (self::$later ?? App::later(...))($fn);
    }

    public static function after(float $seconds, Closure $fn): void
    {
        (self::$after ?? App::after(...))($seconds, $fn);
    }

    public static function now(): float
    {
        return self::$now === null ? microtime(true) : (self::$now)();
    }

    public static function dataDir(): string
    {
        return self::$dataDir ?? App::path(PathName::UserData);
    }

    public static function quit(): void
    {
        (self::$quit ?? App::quit(...))();
    }

    /** The frame of the window that has the keyboard, which shows a document or not. */
    public static function focused(): ?Frame
    {
        if (self::$focused !== null) {
            return (self::$focused)();
        }
        $w = App::isReady() ? Window::focused() : null;
        return $w === null ? null : Registry::frameOf($w);
    }
}
