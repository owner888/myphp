<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;
use MyPHP\Transfer\{Data, Drop, DragOptions, DropOptions, Offer};

/**
 * Drags the system carries (mygo's data_drag.go and drop.go): data dragged
 * out of the window to other apps or into it from them, and files dropped
 * from the Finder. Values dragged within the window are drag() and drop().
 */
trait ContextTransfer
{
    /**
     * Makes $n a native drag source of $data once a primary press moves a
     * few points (mygo's DragData): it goes to other windows and apps
     * taking one of its formats. A source also given drag($n, $value)
     * offers that value to the typed targets of this process alone. The
     * operation is copy unless $o says otherwise, the image the node as it
     * shows unless $o->preview; $o->done hears how it ended, once.
     */
    public function dragData(Node $n, Data $data, DragOptions $o = new DragOptions()): Node
    {
        return $this->dragDataFrom($n, fn() => $data, $o);
    }

    /**
     * Makes $n a native drag source as dragData() does, of the data $data
     * returns as the drag starts, as a selection that changes (mygo's
     * DragDataFrom). Representation::lazy defers single formats.
     *
     * @param Closure(): Data $data
     */
    public function dragDataFrom(Node $n, Closure $data, DragOptions $o = new DragOptions()): Node
    {
        $n->dataSource = [$data, $o];
        return $n;
    }

    /**
     * Makes $n take native drags offering one of $o's formats with one of
     * its operations, and reports whether one was dropped on it since the
     * last frame, setting $drop to its data, read in those formats, and the
     * effect (mygo's DropData). The innermost node under the pointer taking
     * a drag gets it. A disabled node takes none.
     */
    public function dropData(Node $n, DropOptions $o, ?Drop &$drop = null): bool
    {
        $n->dataTarget = $o;
        $d = $this->runtime?->dataDroppedOn($n->id);
        if ($d === null) {
            return false;
        }
        $drop = $d;
        return true;
    }

    /** Makes $n take native drags as dropData() does, and reports whether one it takes is over it, setting $offer to what it offers, unread: for showing it would take it (mygo's DataDragOver). */
    public function dataDragOver(Node $n, DropOptions $o, ?Offer &$offer = null): bool
    {
        $n->dataTarget = $o;
        $f = $this->runtime?->dataOffer($n->id);
        if ($f === null) {
            return false;
        }
        $offer = $f;
        return true;
    }

    /**
     * Makes $n take files dropped from other apps, and gives the paths
     * dropped on it since the last frame, or null (mygo's DroppedFiles).
     * The innermost node under the pointer taking files gets them; files
     * no node takes go to the window's 'fileDrop' listeners.
     *
     * @return ?list<string>
     */
    public function droppedFiles(Node $n): ?array
    {
        $n->takesFiles = true;
        return $this->runtime?->filesDroppedOn($n->id);
    }

    /** Makes $n take files as droppedFiles() does, and reports whether files dragged from another app are over it (mygo's FileDragOver). */
    public function fileDragOver(Node $n): bool
    {
        $n->takesFiles = true;
        return $this->runtime?->filesOver($n->id) ?? false;
    }
}
