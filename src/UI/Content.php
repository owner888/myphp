<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;

/**
 * Native UI for a window: a view function of the app's state, run every
 * frame with a Context to build into. One Content can serve several windows.
 */
final class Content
{
    private function __construct(public readonly Closure $view) {}

    /** @param Closure(Context): void $view */
    public static function view(Closure $view): self
    {
        return new self($view);
    }

    /**
     * The view of the file at $path, which returns it: `return function
     * (Context $c): void { … };`, run bound to $state (ViewFile): in it, and
     * in the closures it makes, `$this` is $state, private members and
     * `self::` included, so the state stays in the app's class and its view
     * in the file; $c->state is $state for the files of a Router's pages.
     * In development it hot reloads (ViewFile).
     */
    public static function file(string $path, ?object $state = null): self
    {
        $file = ViewFile::of($path, $state);
        return new self(static function (Context $c) use ($file, $state): void {
            $c->state = $state;
            ($file->view())($c);
        });
    }
}
