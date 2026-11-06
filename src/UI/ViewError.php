<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;
use MyPHP\Logger;

/**
 * A view that threw while it was built, in development: instead of the app
 * ending, the window shows the error — its message, where it was thrown,
 * the first calls of its trace — over the danger color, as Flutter's red
 * screen does, and the log says it once; the app and its state stay, and
 * the view saved fixed (a hot reload) shows again. A packaged app has no
 * such screen: the error ends it, as it always did.
 */
final class ViewError
{
    /** The error the log said last, so a view throwing every frame says it once. */
    private static string $said = '';

    /**
     * Builds $view into the context $make returns; when it throws, the
     * error into another, which it returns in its place.
     * @param Closure(): Context $make @param Closure(Context): void $view
     */
    public static function guard(Closure $make, Closure $view): Context
    {
        $c = $make();
        try {
            $view($c);
            self::$said = '';
            return $c;
        } catch (\Throwable $e) {
            $key = get_class($e) . $e->getMessage() . $e->getFile() . $e->getLine();
            if ($key !== self::$said) {
                self::$said = $key;
                Logger::error(self::describe($e) . '; the window shows it until the view, saved fixed, reloads', 'ui');
            }
            $c = $make();
            self::build($c, $e);
            return $c;
        }
    }

    /** "Error: message at file:line". */
    public static function describe(\Throwable $e): string
    {
        return get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
    }

    /** The screen of $e: its class and message, where it was thrown, the first calls of its trace, selectable to copy. */
    public static function build(Context $c, \Throwable $e): void
    {
        $t = $c->theme;
        $c->root->alignItems(Node::STRETCH)->justify(Node::START);
        $c->column($t->space(6), $t->space(3), 1, $t->danger)->selectable()->role(Role::GROUP)->label('View error');
          $c->text('The view threw: the app keeps its state, and the view, saved fixed, reloads', $t->fontSize, 0xffffff)->fontWeight(700);
          $c->text(get_class($e) . ': ' . $e->getMessage(), $t->fontSize * 1.2, 0xffffff)->fontWeight(600);
          $c->text($e->getFile() . ':' . $e->getLine(), $t->fontSize, 0xffffff)->font('monospace');
          foreach (array_slice(explode("\n", $e->getTraceAsString()), 0, 8) as $line) {
              $c->text($line, $t->fontSize * 0.9, 0xffffff)->font('monospace')->singleLine()->opacity(0.8);
          }
        $c->end();
    }
}
