<?php
declare(strict_types=1);

namespace MyPHP\Native;

use Closure;

/**
 * File drops onto a view: the NSDraggingDestination methods a view class
 * adds (defineClass) and the registration that makes AppKit send them.
 * The drop goes to $onDrop(view, paths, x, y) with the point in the
 * view's coordinates, y down.
 */
final class Drop
{
    private const OPERATION_COPY = 1; // NSDragOperationCopy
    private static ?Closure $onDrop = null;

    /** The handler every dropped file set reaches; Window sets it. */
    public static function handle(Closure $onDrop): void
    {
        self::$onDrop = $onDrop;
    }

    /** Lets AppKit drag files onto $view. */
    public static function register($view): void
    {
        $types = ObjC::msg('p:', ObjC::cls('NSMutableArray'), 'array');
        ObjC::msg('v:p', $types, 'addObject:', ObjC::str('public.file-url'));
        ObjC::msg('v:p', $view, 'registerForDraggedTypes:', $types);
    }

    /** The methods, for a view class whose y runs down ($flipped) or up. */
    public static function methods(bool $flipped): array
    {
        return [
            'draggingEntered:' => ['Q@:@', 'L(ppp)', fn($self, $cmd, $info) => self::OPERATION_COPY],
            'draggingUpdated:' => ['Q@:@', 'L(ppp)', fn($self, $cmd, $info) => self::OPERATION_COPY],
            'performDragOperation:' => ['B@:@', 'b(ppp)', function ($self, $cmd, $info) use ($flipped) {
                $paths = self::paths(ObjC::msg('p:', $info, 'draggingPasteboard'));
                if ($paths === []) {
                    return false;
                }
                $p = ObjC::msg('q:qp', $self, 'convertPoint:fromView:', ObjC::msg('q:', $info, 'draggingLocation'), null);
                $y = $flipped ? $p->y : ObjC::msg('r:', $self, 'bounds')->h - $p->y;
                if (self::$onDrop !== null) {
                    (self::$onDrop)($self, $paths, $p->x, $y);
                }
                return true;
            }],
        ];
    }

    /** @return list<string> the file paths on a pasteboard */
    private static function paths($pasteboard): array
    {
        $classes = ObjC::msg('p:', ObjC::cls('NSMutableArray'), 'array');
        ObjC::msg('v:p', $classes, 'addObject:', ObjC::cls('NSURL'));
        $options = ObjC::msg('p:', ObjC::cls('NSMutableDictionary'), 'dictionary');
        ObjC::msg('v:pp', $options, 'setObject:forKey:', ObjC::msg('p:b', ObjC::cls('NSNumber'), 'numberWithBool:', true), ObjC::str('NSPasteboardURLReadingFileURLsOnlyKey'));
        $urls = ObjC::msg('p:pp', $pasteboard, 'readObjectsForClasses:options:', $classes, $options);
        $paths = [];
        if ($urls !== null) {
            for ($i = 0, $n = ObjC::msg('L:', $urls, 'count'); $i < $n; $i++) {
                $paths[] = ObjC::phpStr(ObjC::msg('p:', ObjC::msg('p:L', $urls, 'objectAtIndex:', $i), 'path'));
            }
        }
        return $paths;
    }
}
