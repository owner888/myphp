<?php
// Ghostty's themes and the links a terminal finds in its text (mygo's
// plugins/terminal ghostty_test.go and links_test.go): no library needed.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Plugins\Terminal\{GhosttyThemes, Links, Terminal};

(function () {
    $th = Terminal::parseGhosttyTheme(<<<'T'
# A theme
palette = 0=#123
palette = 0b1=#aaabbbccc
palette = 0x2 = 445566
palette = 200=#ffffff
background = "#fdf6e3"
foreground=#657b83
cursor-color = cell-foreground
cursor-text = #002b36
selection-background = #eee8d5
selection-foreground = #586e75
font-family = "Iosevka"
T);
    same([0x112233, 0xaabbcc, 0x445566], array_slice($th->palette, 0, 3), 'the palette');
    same([0xfdf6e3, 0x657b83, null, 0x002b36, 0xeee8d5, 0x586e75], [$th->background, $th->foreground, $th->cursor, $th->cursorText, $th->selection, $th->selectionText], 'the colors');
    // What the theme leaves out comes from the light theme, as its background is light.
    same(Terminal::lightTheme()->palette[3], $th->palette[3], 'the light defaults');
    check(Terminal::parseGhosttyTheme('') == Terminal::darkTheme(), 'an empty theme is the dark theme');
    foreach (['background = red', 'palette = 300=#000000', 'palette = #000000', 'background'] as $bad) {
        try {
            Terminal::parseGhosttyTheme($bad);
            check(false, "\"$bad\" parsed");
        } catch (\RuntimeException $e) {
            check(str_contains($e->getMessage(), 'line 1'), "\"$bad\": {$e->getMessage()}");
        }
    }
})();

(function () {
    $old = getenv('XDG_CONFIG_HOME');
    $config = sys_get_temp_dir() . '/myphp-ghostty-' . getmypid();
    putenv("XDG_CONFIG_HOME=$config");
    try {
        $names = array_map(fn($t) => $t[0], GhosttyThemes::THEMES);
        $sorted = $names;
        sort($sorted, SORT_STRING);
        check(count($names) >= 500 && $sorted === $names, count($names) . ' themes, sorted');
        $mocha = Terminal::ghosttyTheme('Catppuccin Mocha');
        same([0x1e1e2e, 0xcdd6f4, 0xf38ba8, 0xf5e0dc, 0x1e1e2e, true], [$mocha->background, $mocha->foreground, $mocha->palette[1], $mocha->cursor, $mocha->selectionText, $mocha->isDark()], 'Catppuccin Mocha');
        check(Terminal::ghosttyTheme('catppuccin mocha') == $mocha, 'names of another case');
        check(Terminal::ghosttyTheme('Catppuccin Mocha') !== $mocha, 'two calls return two themes');
        foreach (['', 'No Such Theme', '../Dracula', '.'] as $bad) {
            try {
                Terminal::ghosttyTheme($bad);
                check(false, "\"$bad\" found a theme");
            } catch (\RuntimeException) {
                check(true, '');
            }
        }
        // The user's themes come first, as in Ghostty.
        $dir = "$config/ghostty/themes";
        mkdir($dir, 0755, true);
        file_put_contents("$dir/Mine", "background = #102030\n");
        file_put_contents("$dir/Dracula", "background = #010203\n");
        file_put_contents("$dir/Broken", "background = nope\n");
        foreach (['Mine' => 0x102030, 'Dracula' => 0x010203, "$dir/Mine" => 0x102030] as $name => $bg) {
            same($bg, Terminal::ghosttyTheme($name)->background, "the user's $name");
        }
        try {
            Terminal::ghosttyTheme('Broken');
            check(false, 'a broken theme parsed');
        } catch (\RuntimeException $e) {
            check(str_contains($e->getMessage(), 'Broken'), "a broken theme: {$e->getMessage()}");
        }
        $all = Terminal::ghosttyThemes();
        check(in_array('Mine', $all, true) && in_array('Catppuccin Latte', $all, true) && count(array_unique($all)) === count($all), 'ghosttyThemes has the user\'s and Ghostty\'s, once each');
        same(count($names) + 2, count($all), 'as many names');
    } finally {
        foreach (glob("$config/ghostty/themes/*") ?: [] as $f) { unlink($f); }
        @rmdir("$config/ghostty/themes"); @rmdir("$config/ghostty"); @rmdir($config);
        putenv($old === false ? 'XDG_CONFIG_HOME' : "XDG_CONFIG_HOME=$old");
    }
})();

(function () {
    $row = mb_str_split("see https://example.com/a_(b)?q=1. and (http://x.org/y), mailto:me@x.org!");
    $cols = array_keys($row);
    foreach ([0 => '', 4 => 'https://example.com/a_(b)?q=1', 20 => 'https://example.com/a_(b)?q=1', 33 => '', 40 => 'http://x.org/y', 58 => 'mailto:me@x.org'] as $col => $want) {
        same($want, Links::urlAt($row, $cols, $col)[0], "urlAt($col)");
    }
    foreach ([
        ['Updated internal/app/embed.go:51 and more', 10, 'internal/app/embed.go:51'],
        ['  ⎿  Read /Users/me/repo/main.go (12 lines)', 12, '/Users/me/repo/main.go'],
        ['see ./notes/todo.txt.', 8, './notes/todo.txt'],
        ['open ~/Downloads/clip.mp4', 10, '~/Downloads/clip.mp4'],
        ['main.go:250:3: undefined: x', 2, 'main.go:250:3'],
        ['(src/a.go)', 3, 'src/a.go'],
        ['just words here', 5, ''],
        ['version 1.2.3 ok', 9, ''],
        ['write(notes.md)', 8, 'notes.md'],
        ['notes.md (new file +2)', 2, 'notes.md'],
        ['See notes.md.', 7, 'notes.md'],
        ['notes.md:2:3', 2, 'notes.md:2:3'],
    ] as [$s, $col, $want]) {
        $r = mb_str_split($s);
        same($want, Links::pathAt($r, array_keys($r), $col)[0], "pathAt(\"$s\", $col)");
    }
    $r = mb_str_split('go to https://x.org/a. now');
    same(['https://x.org/a', 6, 21], Links::urlAt($r, array_keys($r), 10), 'urlAt: the columns the URL spans');
    $r = mb_str_split('write(notes.md)');
    same(['notes.md', 6, 14], Links::pathAt($r, array_keys($r), 8), 'pathAt: the columns a bare file name spans');
})();
