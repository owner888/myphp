<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use FFI;
use FFI\CData;
use MyPHP\{Display, Point, Rectangle};
use MyPHP\Platform;

/** The monitors (mygo's windows screen): their areas in points, a monitor's pixels over its DPI's scale (96 a point). */
final class Screen implements Platform\Screen
{
    public function displays(): array
    {
        $u = Win32::user32();
        $out = [];
        Win32::eachMonitor(function (CData $m) use ($u, &$out): void {
            $info = $u->new('MONITORINFOEXW');
            $info->cbSize = FFI::sizeof($info);
            if (!$u->GetMonitorInfoW($m, FFI::addr($info))) {
                return;
            }
            $scale = self::dpi($m) / 96;
            $rect = fn(CData $r): Rectangle => new Rectangle((int)round($r->left / $scale), (int)round($r->top / $scale), (int)round(($r->right - $r->left) / $scale), (int)round(($r->bottom - $r->top) / $scale));
            $out[] = new Display(Win32::addr($m), Win32::str($u->cast('uint16_t *', FFI::addr($info->szDevice[0]))), $rect($info->rcMonitor), $rect($info->rcWork),
                $scale, 0, false, ($info->dwFlags & 1) !== 0); // MONITORINFOF_PRIMARY
        });
        return $out;
    }

    public function cursorPoint(): Point
    {
        $u = Win32::user32();
        $pt = $u->new('POINT');
        $u->GetCursorPos(FFI::addr($pt));
        $scale = self::dpi($u->MonitorFromPoint($pt, 2 /* MONITOR_DEFAULTTONEAREST */)) / 96;
        return new Point((int)round($pt->x / $scale), (int)round($pt->y / $scale));
    }

    /** The effective DPI of monitor $m, 96 where it says none. */
    public static function dpi(?CData $m): int
    {
        if ($m === null) {
            return 96;
        }
        $s = Win32::shcore();
        $x = $s->new('uint32_t'); $y = $s->new('uint32_t');
        return $s->GetDpiForMonitor($m, 0 /* MDT_EFFECTIVE_DPI */, FFI::addr($x), FFI::addr($y)) === 0 && $x->cdata > 0 ? $x->cdata : 96;
    }
}
