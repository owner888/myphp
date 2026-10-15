<?php
declare(strict_types=1);

namespace MyPHP\Native;

/** The general pasteboard, plain text only for now. */
final class Pasteboard
{
    private const STRING_TYPE = 'public.utf8-plain-text';

    public static function text(): string
    {
        $pb = ObjC::msg('p:', ObjC::cls('NSPasteboard'), 'generalPasteboard');
        $s = ObjC::msg('p:p', $pb, 'stringForType:', ObjC::str(self::STRING_TYPE));
        return $s === null ? '' : ObjC::phpStr($s);
    }

    public static function setText(string $text): void
    {
        $pb = ObjC::msg('p:', ObjC::cls('NSPasteboard'), 'generalPasteboard');
        ObjC::msg('l:', $pb, 'clearContents');
        ObjC::msg('b:pp', $pb, 'setString:forType:', ObjC::str($text), ObjC::str(self::STRING_TYPE));
    }
}
