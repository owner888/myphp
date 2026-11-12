<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;

/**
 * Native UI for a window: a view of the app's state, built every frame, as
 * SwiftUI's body: a closure returning the root View (VStack, Text…), or a
 * view file (Content::file). One Content can serve several windows. A
 * closure taking a Context builds with the engine's builder, @internal: the
 * framework's own windows.
 */
final class Content
{
    private function __construct(public readonly Closure $view) {}

    /** A window's view: fn() => VStack()(…), as a view file returns it. @param Closure(): mixed $view */
    public static function view(Closure $view): self
    {
        return new self(self::takesContext($view) ? $view : static fn(Context $c) => self::render($view, $c)); // the window calls it with its Context: a declarative view rendered into it
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
            self::render($file->view(), $c);
        });
    }

    /**
     * Builds a window's view $view, which returns the root View, into $c: in
     * the middle of the window, as SwiftUI's, and inside the safe area on a
     * phone (out of the status bar, the cutout and the home indicator, above
     * the keyboard), the window's background reaching the edges. A
     * NavigationStack or a TabView keeps out of it itself, its bars reaching
     * into it. @internal Content's and the tests'
     */
    public static function render(Closure $view, Context $c): void
    {
        if (self::takesContext($view)) { // the engine's own builder, the framework's @internal
            $view($c);
            return;
        }
        require_once __DIR__ . '/Declarative/functions.php';
        Declarative\Transaction::frame($c);
        $c->root->alignItems(Node::CENTER)->justify(Node::CENTER);
        if ($c->vibrancy()) { $c->root->color = null; } // a window of a material: it shows through, a SwiftUI view having no background of its own
        $root = $view();
        if ($root instanceof Declarative\NavigationStack || $root instanceof Declarative\TabView || ($c->safeArea === [0.0, 0.0, 0.0, 0.0] && $c->keyboard == 0.0)) {
            Declarative\View::renderAll($c, Declarative\Env::root($c), $root);
            return;
        }
        $c->safeArea(function () use ($c, $root): void {
            // what it builds is inside: a NavigationStack in it keeps out of nothing more
            $c->safeArea = [0.0, 0.0, 0.0, 0.0];
            Declarative\View::renderAll($c, Declarative\Env::root($c), $root);
        })->alignItems(Node::CENTER)->justify(Node::CENTER);
    }

    /** @var \WeakMap<Closure, bool>|null */
    private static ?\WeakMap $takes = null;

    /** Whether view $fn builds with a Context (the engine's builder) rather than returning a View. */
    private static function takesContext(Closure $fn): bool
    {
        self::$takes ??= new \WeakMap();
        return self::$takes[$fn] ??= (($p = new \ReflectionFunction($fn)->getParameters()[0] ?? null) !== null && ($t = $p->getType()) instanceof \ReflectionNamedType && $t->getName() === Context::class);
    }
}
