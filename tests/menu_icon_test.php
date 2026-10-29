<?php
// Icons on menu items (mygo's menu_icon_test.go, 6a95d8f): a PNG image
// made into an icon once, shared by the items showing the same image, set
// and taken away; anything else refused; on macOS, the NSMenuItem's image,
// fit to 16 points, a template one tinted.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\{Menu, MenuIcon, MenuItem};

/** A PNG of $w × $h pixels of one color, made by hand. */
function tinyPng(int $w, int $h, int $rgba = 0xff0000ff): string
{
    $chunk = fn(string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    $row = "\0" . str_repeat(pack('N', $rgba), $w);
    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 6, 0, 0, 0)) . $chunk('IDAT', gzcompress(str_repeat($row, $h))) . $chunk('IEND', '');
}

$png = tinyPng(32, 24);
$a = MenuIcon::of($png);
check($a === MenuIcon::of($png), 'the same image: one icon');
check($a !== MenuIcon::of($png, true), 'a template of it another');
check($a !== MenuIcon::of(tinyPng(32, 24, 0x00ff00ff)), 'another image another');
$threw = false;
try { MenuIcon::of('GIF89a not a png'); } catch (\InvalidArgumentException) { $threw = true; }
check($threw, 'what is no PNG is refused');
$it = new MenuItem('Open', icon: $a);
same($a, $it->icon, 'an item shows it');
$it->setIcon(null);
same(null, $it->icon, 'and no longer');
if (PHP_OS_FAMILY === 'Darwin') {
    \MyPHP\Native\ObjC::boot(['Foundation', 'AppKit']);
    $flag = new MenuItem('Flag', icon: $a);
    $tmpl = new MenuItem('Template', icon: MenuIcon::of($png, true));
    $menu = new Menu([$flag, $tmpl, new MenuItem('Plain')]);
    $menu->build('Test');
    $img = \MyPHP\Native\ObjC::msg('p:', $flag->native, 'image');
    check($img !== null, 'on macOS, the item\'s image');
    $size = \MyPHP\Native\ObjC::msg('z:', $img, 'size');
    same([16.0, 12.0], [$size->w, $size->h], 'fit to 16 points, its aspect kept');
    check(!\MyPHP\Native\ObjC::msg('b:', $img, 'isTemplate') && \MyPHP\Native\ObjC::msg('b:', \MyPHP\Native\ObjC::msg('p:', $tmpl->native, 'image'), 'isTemplate'), 'a template image tinted');
    $flag->setIcon(null);
    same(null, \MyPHP\Native\ObjC::msg('p:', $flag->native, 'image'), 'taken away');
}
