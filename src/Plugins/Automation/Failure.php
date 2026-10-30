<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Automation;

/**
 * A request the app could not answer (mygo's automation.Error): its
 * code, one of the constants, for the client to test, and its message to
 * read.
 *
 * @internal
 */
final class Failure extends \RuntimeException
{
    /** The line is not a JSON object. */
    public const PARSE = 'parse_error';
    /** No method, params the method does not take, or values it cannot use. */
    public const INVALID = 'invalid_request';
    public const UNKNOWN_METHOD = 'unknown_method';
    /** No window has the ID, or the app has none. */
    public const NO_WINDOW = 'no_window';
    /** No element of the window's last frame has the ID or the key. */
    public const NO_ELEMENT = 'no_element';
    /** The window cannot do it, as reading the elements of a page. */
    public const UNSUPPORTED = 'unsupported';
    /** idle gave up after its timeout. */
    public const TIMEOUT = 'timeout';
    /** The app could not do it, as a script that threw. */
    public const FAILED = 'failed';

    public function __construct(public readonly string $kind, string $message)
    {
        parent::__construct($message);
    }
}
