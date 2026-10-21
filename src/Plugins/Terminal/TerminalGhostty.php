<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

use RuntimeException;

/**
 * Ghostty's themes (mygo's ghostty.go): those of the user's Ghostty, and
 * those Ghostty ships, which the plugin carries.
 *
 * @internal part of Terminal
 */
trait TerminalGhostty
{
    /**
     * A theme of Ghostty's by its name, as Ghostty's theme option takes it
     * ("Catppuccin Mocha", "Dracula", "Rose Pine Dawn"…): a theme file of
     * the user's in Ghostty's themes directory ($XDG_CONFIG_HOME/ghostty/
     * themes, ~/.config/ghostty/themes by default), else one of the themes
     * Ghostty ships, of any case; or the theme file a path names. Each call
     * returns a new Theme. It throws when there is none.
     */
    public static function ghosttyTheme(string $name): Theme
    {
        if (str_starts_with($name, '/')) {
            return self::readGhosttyTheme($name);
        }
        if ($name === '' || strpbrk($name, '/\\') !== false || $name === '.' || $name === '..') {
            throw new RuntimeException("terminal: \"$name\" names no theme of Ghostty's");
        }
        $path = self::ghosttyThemesDir() . '/' . $name;
        if (file_exists($path)) {
            return self::readGhosttyTheme($path);
        }
        $found = null;
        foreach (GhosttyThemes::THEMES as $t) {
            if ($t[0] === $name) { $found = $t; break; }
            if ($found === null && strcasecmp($t[0], $name) === 0) { $found = $t; }
        }
        if ($found === null) {
            throw new RuntimeException("terminal: Ghostty has no theme \"$name\"");
        }
        $c = $found[1];
        return new Theme($c[17], $c[16], $c[18], $c[19], $c[20], $c[21], array_slice($c, 0, 16));
    }

    /** The names ghosttyTheme() takes, sorted: those of the user's theme files and of the themes Ghostty ships. @return list<string> */
    public static function ghosttyThemes(): array
    {
        $names = array_map(fn(array $t) => $t[0], GhosttyThemes::THEMES);
        $dir = self::ghosttyThemesDir();
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $e) {
            if (!str_starts_with($e, '.') && !is_dir("$dir/$e")) { $names[] = $e; }
        }
        usort($names, fn(string $a, string $b) => strcmp(strtolower($a), strtolower($b)) ?: strcmp($a, $b));
        $out = [];
        foreach ($names as $n) {
            if ($out === [] || $out[count($out) - 1] !== $n) { $out[] = $n; }
        }
        return $out;
    }

    /** The directory of the user's themes of Ghostty, as Ghostty finds it. */
    private static function ghosttyThemesDir(): string
    {
        $dir = (string)getenv('XDG_CONFIG_HOME');
        if ($dir === '') { $dir = getenv('HOME') . '/.config'; }
        return "$dir/ghostty/themes";
    }

    private static function readGhosttyTheme(string $path): Theme
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new RuntimeException("terminal: open $path: no such file or directory");
        }
        try {
            return self::parseGhosttyTheme($data);
        } catch (RuntimeException $e) {
            throw new RuntimeException($e->getMessage() . " in $path");
        }
    }

    /**
     * Reads a theme in Ghostty's format, the lines of Ghostty's
     * configuration that set colors:
     *
     *     palette = 0=#45475a
     *     …
     *     palette = 15=#a6adc8
     *     background = #1e1e2e
     *     foreground = #cdd6f4
     *     cursor-color = #f5e0dc
     *     cursor-text = #1e1e2e
     *     selection-background = #f5e0dc
     *     selection-foreground = #1e1e2e
     *
     * Colors are in hex (#rgb, #rrggbb, rrggbb…). Other lines of Ghostty's
     * configuration are ignored, as are the palette's colors past the 16th,
     * and the colors set to cell-foreground or cell-background, which the
     * terminal then picks itself (see Theme). The colors a theme leaves out
     * are those of darkTheme(), or lightTheme() when its background is
     * light. It throws on a line it cannot read.
     */
    public static function parseGhosttyTheme(string $data): Theme
    {
        $th = new Theme;
        $th->palette = array_fill(0, 16, 0);
        $set = [];
        foreach (preg_split('/\r?\n/', $data) as $i => $line) {
            $n = $i + 1;
            $line = trim($line);
            if ($line === '' || $line[0] === '#') { continue; }
            if (!str_contains($line, '=')) {
                throw new RuntimeException("terminal: line $n of the theme sets nothing");
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (strlen($value) >= 2 && $value[0] === '"' && $value[-1] === '"') { $value = substr($value, 1, -1); }
            $index = null;
            switch ($key) {
                case 'background': $field = 'background'; break;
                case 'foreground': $field = 'foreground'; break;
                case 'cursor-color': $field = 'cursor'; break;
                case 'cursor-text': $field = 'cursorText'; break;
                case 'selection-background': $field = 'selection'; break;
                case 'selection-foreground': $field = 'selectionText'; break;
                case 'palette':
                    $parts = explode('=', $value, 2);
                    $index = count($parts) === 2 ? self::parseUint(trim($parts[0])) : null;
                    if ($index === null || $index > 255) {
                        throw new RuntimeException("terminal: line $n of the theme: \"$value\" is no palette entry, as 0=#000000");
                    }
                    if ($index >= 16) { continue 2; }
                    $field = 'palette';
                    $value = trim($parts[1]);
                    $key = "palette$index";
                    break;
                default:
                    continue 2;
            }
            if ($value === '' || $value === 'cell-foreground' || $value === 'cell-background') {
                self::setThemeColor($th, $field, $index, null);
                $set[$key] = false;
                continue;
            }
            $c = self::parseHexColor($value);
            if ($c === null) {
                throw new RuntimeException("terminal: line $n of the theme: \"$value\" is no color in hex");
            }
            self::setThemeColor($th, $field, $index, $c);
            $set[$key] = true;
        }
        $def = ($set['background'] ?? false) && !$th->isDark() ? self::lightTheme() : self::darkTheme();
        if (!($set['background'] ?? false)) { $th->background = $def->background; }
        if (!($set['foreground'] ?? false)) { $th->foreground = $def->foreground; }
        for ($i = 0; $i < 16; $i++) {
            if (!($set["palette$i"] ?? false)) { $th->palette[$i] = $def->palette[$i]; }
        }
        return $th;
    }

    /** Sets a color of a theme: null unsets it, as zero does in mygo (black for the foreground and background, which the defaults then fill). */
    private static function setThemeColor(Theme $th, string $field, ?int $index, ?int $c): void
    {
        if ($index !== null) {
            $th->palette[$index] = $c ?? 0;
        } elseif ($field === 'background' || $field === 'foreground') {
            $th->$field = $c ?? 0;
        } else {
            $th->$field = $c;
        }
    }

    /** An unsigned integer as Go's strconv.ParseUint(s, 0, 8) reads one: decimal, 0x hex, 0o or 0 octal, 0b binary. */
    private static function parseUint(string $s): ?int
    {
        $s = str_replace('_', '', $s);
        $v = match (true) {
            preg_match('/^0[xX][0-9a-fA-F]+$/', $s) === 1 => hexdec(substr($s, 2)),
            preg_match('/^0[bB][01]+$/', $s) === 1 => bindec(substr($s, 2)),
            preg_match('/^0[oO]?[0-7]+$/', $s) === 1 => octdec(ltrim(substr($s, 1), 'oO')),
            preg_match('/^[0-9]+$/', $s) === 1 => (int)$s,
            default => null,
        };
        return $v === null ? null : (int)$v;
    }

    /** A color as Ghostty reads one in hex: #rgb, #rrggbb, #rrrgggbbb or #rrrrggggbbbb, or the first two without #. */
    private static function parseHexColor(string $s): ?int
    {
        $hex = $s;
        if (str_starts_with($s, '#')) {
            $hex = substr($s, 1);
        } elseif (strlen($hex) !== 3 && strlen($hex) !== 6) {
            return null;
        }
        $n = strlen($hex);
        if ($n === 0 || $n % 3 !== 0 || $n > 12 || preg_match('/^[0-9a-fA-F]+$/', $hex) !== 1) {
            return null;
        }
        $w = intdiv($n, 3);
        $rgb = 0;
        for ($i = 0; $i < 3; $i++) {
            $v = hexdec(substr($hex, $i * $w, $w));
            $rgb = ($rgb << 8) | intdiv((int)$v * 255, (1 << (4 * $w)) - 1);
        }
        return $rgb;
    }
}
