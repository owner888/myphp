<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use MyPHP\{Display, Point, Rectangle};
use MyPHP\Platform;
use MyPHP\Native\Mem;

/** GDK's monitors (mygo's linux screen): on X11 in the screen's coordinates; on Wayland each its own. */
final class Screen implements Platform\Screen
{
    public function displays(): array
    {
        $f = Gtk::ffi();
        $display = $f->gdk_display_get_default();
        if ($display === null) {
            return [];
        }
        $primary = $f->gdk_display_get_primary_monitor($display);
        $out = [];
        for ($i = 0, $n = $f->gdk_display_get_n_monitors($display); $i < $n; $i++) {
            $m = $f->gdk_display_get_monitor($display, $i);
            $g = $f->new('GdkRectangle'); $wa = $f->new('GdkRectangle');
            $f->gdk_monitor_get_geometry($m, \FFI::addr($g));
            $f->gdk_monitor_get_workarea($m, \FFI::addr($wa));
            $model = $f->gdk_monitor_get_model($m);
            $out[] = new Display($i + 1, (string)$model, new Rectangle($g->x, $g->y, $g->width, $g->height), new Rectangle($wa->x, $wa->y, $wa->width, $wa->height),
                (float)$f->gdk_monitor_get_scale_factor($m), 0, false, $primary !== null ? Mem::addr($m) === Mem::addr($primary) : $i === 0);
        }
        return $out;
    }

    public function cursorPoint(): Point
    {
        $f = Gtk::ffi();
        $pointer = $f->gdk_seat_get_pointer($f->gdk_display_get_default_seat($f->gdk_display_get_default()));
        $x = $f->new('int[1]'); $y = $f->new('int[1]');
        $f->gdk_device_get_position($pointer, null, $x, $y);
        return new Point($x[0], $y[0]);
    }
}
