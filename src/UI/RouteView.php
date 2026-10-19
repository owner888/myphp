<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * The state of a view of the pages at a level (mygo's routeView): the
 * entry it showed last, and the one going away since the change, toward
 * deeper pages ($dir 1), back up (-1) or across (0); $focusing moves the
 * focus into the page shown, $announcing tells screen readers its title,
 * and $progress goes from 0 to 1 as the page comes in. Router's own.
 */
final class RouteView
{
    public ?RouteEntry $shown = null, $leaving = null;
    public int $dir = 0;
    public bool $focusing = false, $announcing = false;
    public ?MotionChannel $progress = null;
}
