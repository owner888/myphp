<?php
declare(strict_types=1);

namespace MyPHP\UI;

use IntlChar;
use MyPHP\App;
use MyPHP\Logger;
use MyPHP\Media\PlayerSettings;
use MyPHP\PathName;
use Normalizer;
use RuntimeException;

/**
 * The player's key bindings, as IINA's (KeyMapping, KeyCodeHelper,
 * KeyBindingTranslator): sets of them in mpv's input.conf files, IINA's
 * five of its own (locked) and the user's (input_conf in the app's user
 * data); the one the settings name (currentInputConfigName) the keys the
 * players take. A binding is a key as mpv names it ("Ctrl+Alt+Shift+Meta+x",
 * "SPACE", "Meta+L") and an action, mpv's command ("seek 5", "cycle pause")
 * or IINA's ("#@iina toggle-pip"); shown as IINA shows it (⌃⌥⇧⌘, its
 * command read in words) unless the settings ask for the raw values.
 *
 * @internal MoviePlayer, MoviePlayerSettingsPages
 */
final class MoviePlayerKeys
{
    /** IINA's sets, by name: their files, of keybindings/. */
    public const DEFAULTS = ['IINA Default' => 'iina-default-input', 'mpv Default' => 'input', 'VLC Default' => 'vlc-default-input',
        'Movist Default' => 'movist-default-input', 'Movist v2 Default' => 'movist-v2-default-input'];
    public const FALLBACK = 'IINA Default';

    private const MODIFIERS = ['Ctrl', 'Alt', 'Shift', 'Meta'];
    /** IINA's names of the keys by their codes: [plain, shifted] (KeyCodeHelper.keyMap). */
    private const KEYS = [0x00 => ['a', 'A'], 0x01 => ['s', 'S'], 0x02 => ['d', 'D'], 0x03 => ['f', 'F'], 0x04 => ['h', 'H'], 0x05 => ['g', 'G'], 0x06 => ['z', 'Z'], 0x07 => ['x', 'X'],
        0x08 => ['c', 'C'], 0x09 => ['v', 'V'], 0x0B => ['b', 'B'], 0x0C => ['q', 'Q'], 0x0D => ['w', 'W'], 0x0E => ['e', 'E'], 0x0F => ['r', 'R'], 0x10 => ['y', 'Y'], 0x11 => ['t', 'T'],
        0x12 => ['1', '!'], 0x13 => ['2', '@'], 0x14 => ['3', 'SHARP'], 0x15 => ['4', '$'], 0x16 => ['6', '^'], 0x17 => ['5', '%'], 0x18 => ['=', 'PLUS'], 0x19 => ['9', '('],
        0x1A => ['7', '&'], 0x1B => ['-', '_'], 0x1C => ['8', '*'], 0x1D => ['0', ')'], 0x1E => [']', '}'], 0x1F => ['o', 'O'], 0x20 => ['u', 'U'], 0x21 => ['[', '{'], 0x22 => ['i', 'I'],
        0x23 => ['p', 'P'], 0x25 => ['l', 'L'], 0x26 => ['j', 'J'], 0x27 => ["'", '"'], 0x28 => ['k', 'K'], 0x29 => [';', ':'], 0x2A => ['\\', '|'], 0x2B => [',', '<'], 0x2C => ['/', '?'],
        0x2D => ['n', 'N'], 0x2E => ['m', 'M'], 0x2F => ['.', '>'], 0x32 => ['`', '~'], 0x41 => ['KP_DEC', null], 0x43 => ['*', null], 0x45 => ['+', null], 0x4B => ['/', null],
        0x4C => ['KP_ENTER', null], 0x4E => ['-', null], 0x51 => ['=', null], 0x52 => ['KP0', null], 0x53 => ['KP1', null], 0x54 => ['KP2', null], 0x55 => ['KP3', null], 0x56 => ['KP4', null],
        0x57 => ['KP5', null], 0x58 => ['KP6', null], 0x59 => ['KP7', null], 0x5B => ['KP8', null], 0x5C => ['KP9', null], 0x24 => ['ENTER', null], 0x30 => ['TAB', null], 0x31 => ['SPACE', null],
        0x33 => ['BS', null], 0x35 => ['ESC', null], 0x40 => ['F17', null], 0x4F => ['F18', null], 0x50 => ['F19', null], 0x5A => ['F20', null], 0x60 => ['F5', null], 0x61 => ['F6', null],
        0x62 => ['F7', null], 0x63 => ['F3', null], 0x64 => ['F8', null], 0x65 => ['F9', null], 0x67 => ['F11', null], 0x69 => ['F13', null], 0x6A => ['F16', null], 0x6B => ['F14', null],
        0x6D => ['F10', null], 0x6F => ['F12', null], 0x71 => ['F15', null], 0x72 => ['INS', null], 0x73 => ['HOME', null], 0x74 => ['PGUP', null], 0x75 => ['DEL', null], 0x76 => ['F4', null],
        0x77 => ['END', null], 0x78 => ['F2', null], 0x79 => ['PGDWN', null], 0x7A => ['F1', null], 0x7B => ['LEFT', null], 0x7C => ['RIGHT', null], 0x7D => ['DOWN', null], 0x7E => ['UP', null],
        0x7F => ['POWER', null]];
    /** How IINA shows mpv's names of keys and modifiers (mpvSymbolToPrettyMacKey). */
    private const PRETTY = ['Meta' => '⌘', 'Shift' => '⇧', 'Alt' => '⌥', 'Ctrl' => '⌃', 'SHARP' => '#', 'ENTER' => '↩︎', 'KP_ENTER' => '↩︎', 'SPACE' => '␣', 'IDEOGRAPHIC_SPACE' => '␣',
        'BS' => '⌫', 'DEL' => '⌦', 'KP_DEL' => '⌦', 'INS' => 'Ins', 'KP_INS' => 'Ins', 'TAB' => '⇥', 'ESC' => '⎋', 'UP' => '↑', 'DOWN' => '↓', 'LEFT' => '←', 'RIGHT' => '→', 'PGUP' => '⇞',
        'PGDWN' => '⇟', 'HOME' => '↖︎', 'END' => '↘︎', 'PLAY' => "▶︎\u{2006}❙\u{200A}❙", 'PREV' => '◀︎◀︎', 'NEXT' => '▶︎▶︎', 'PLUS' => '+', 'KP0' => '0', 'KP1' => '1', 'KP2' => '2', 'KP3' => '3',
        'KP4' => '4', 'KP5' => '5', 'KP6' => '6', 'KP7' => '7', 'KP8' => '8', 'KP9' => '9'];
    /** mpv's command words before a command that IINA leaves out as it reads one. */
    private const PREFIXES = ['no-osd', 'osd-auto', 'osd-bar', 'osd-msg-bar', 'raw', 'repeatable', 'expand-properties'];
    /** How IINA reads mpv's commands' arguments (MPVCommandFormat.strings). */
    private const FORMATS = ['seek' => 'command value opt:relative|absolute|absolute-percent|relative-percent|exact|keyframes', 'set' => 'command property value',
        'add' => 'command property value#+1', 'cycle' => 'command property dir:up|down', 'cycle-values' => 'command property values...', 'multiply' => 'command property factor',
        'quit' => 'command value', 'sub-seek' => 'command skip:1|-1'];

    /** @var array<string, array{0: int, 1: list<array<string, mixed>>}> the bindings read, by file, with its modification time */
    private static array $read = [];

    /** The folder of the user's sets (IINA's input_conf), made the first time. */
    public static function userDir(): string
    {
        $dir = App::path(PathName::UserData) . '/input_conf';
        if (!is_dir($dir) && !mkdir($dir, 0777, true)) { throw new RuntimeException("myphp: cannot make $dir"); }
        return $dir;
    }

    /** @return array<string, string> the user's sets: their files by their names, sorted */
    public static function userConfigs(): array
    {
        $out = [];
        $dir = self::userDir();
        foreach (scandir($dir) ?: [] as $f) { // listed, not globbed: a static app's phar has no glob
            if ($f[0] !== '.' && str_ends_with($f, '.conf')) { $out[pathinfo($f, PATHINFO_FILENAME)] = "$dir/$f"; }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @return list<string> the sets' names: IINA's, then the user's */
    public static function configNames(): array
    {
        return [...array_keys(self::DEFAULTS), ...array_keys(self::userConfigs())];
    }

    /** The file of set $name, null for none. */
    public static function path(string $name): ?string
    {
        return isset(self::DEFAULTS[$name]) ? __DIR__ . '/keybindings/' . self::DEFAULTS[$name] . '.conf' : (self::userConfigs()[$name] ?? null);
    }

    /** Whether set $name is the user's, which can change (IINA's are locked). */
    public static function editable(string $name): bool
    {
        return !isset(self::DEFAULTS[$name]);
    }

    /**
     * The bindings of the set the settings name, IINA's default where it is
     * gone or does not read (IINA's fallback, told by its alert).
     * @return list<array{key: string, action: list<string>, iina: bool, comment: ?string, normal: string}>
     */
    public static function current(PlayerSettings $set): array
    {
        $name = $set->get('currentInputConfigName');
        $path = self::path($name);
        $read = $path === null ? null : self::read($path);
        if ($read === null) {
            Logger::warn("Cannot read the key binding set \"$name\": " . self::FALLBACK . "'s instead", 'keybindings');
            return self::read((string)self::path(self::FALLBACK)) ?? [];
        }
        return $read;
    }

    /** The bindings of file $path, read again when it changed; null for one that does not read. @return ?list<array<string, mixed>> */
    public static function read(string $path): ?array
    {
        clearstatcache(true, $path);
        $mtime = @filemtime($path);
        if ($mtime === false) { return null; }
        if (($r = self::$read[$path] ?? null) !== null && $r[0] === $mtime) { return $r[1]; }
        $text = @file_get_contents($path);
        if ($text === false) { return null; }
        return (self::$read[$path] = [$mtime, self::parse($text)])[1];
    }

    /**
     * Bindings of input.conf $text, as IINA reads them (KeyMapping.parseInputConf):
     * a line a key and its action, "#@iina " before IINA's own, a "#" after
     * them its comment; other lines of "#" comments.
     * @return list<array{key: string, action: list<string>, iina: bool, comment: ?string, normal: string}>
     */
    public static function parse(string $text): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $iina = false;
            if (trim($line) === '') { continue; }
            if ($line[0] === '#') {
                if (!str_starts_with($line, '#@iina')) { continue; }
                $iina = true;
                $line = substr($line, 6);
            }
            $comment = null;
            if (($sharp = strpos($line, '#')) !== false) {
                $comment = substr($line, $sharp + 1);
                $line = substr($line, 0, $sharp);
            }
            $parts = preg_split('/[ \t]+/', trim($line), 2) ?: [];
            if (count($parts) < 2) {
                Logger::warn("Skipped corrupted line in input.conf: $line", 'keybindings');
                continue;
            }
            $out[] = self::mapping($parts[0], $parts[1], $iina, $comment);
        }
        return $out;
    }

    /** A binding of key $key and action $action ("@iina …" IINA's). @return array{key: string, action: list<string>, iina: bool, comment: ?string, normal: string} */
    public static function mapping(string $key, string $action, bool $iina = false, ?string $comment = null): array
    {
        $action = trim($action);
        if (str_starts_with($action, '@iina')) { $iina = true; $action = trim(substr($action, 5)); }
        return ['key' => trim($key), 'action' => array_values(array_filter(preg_split('/\s+/', $action) ?: [], fn(string $w) => $w !== '')), 'iina' => $iina, 'comment' => $comment, 'normal' => self::normalize(trim($key))];
    }

    /** Bindings as an input.conf (KeyMapping.generateInputConf). @param list<array<string, mixed>> $mappings */
    public static function generate(array $mappings): string
    {
        $out = "# Generated by IINA\n\n";
        foreach ($mappings as $m) {
            $out .= ($m['iina'] ? '#@iina ' : '') . $m['key'] . ' ' . implode(' ', $m['action']) . (($m['comment'] ?? '') !== '' ? '   #' . $m['comment'] : '') . "\n";
        }
        return $out;
    }

    /** The binding's action as written, "@iina " before IINA's (its readableAction). */
    public static function rawAction(array $m): string
    {
        return ($m['iina'] ? '@iina ' : '') . implode(' ', $m['action']);
    }

    /**
     * mpv's key $key in its one form (KeyCodeHelper.normalizeMpv): its
     * modifiers Ctrl, Alt, Shift, Meta in that order, Shift as the key's
     * upper case where it has one, "#" SHARP and "+" PLUS, other names in
     * capitals; a sequence (more than one "-") as it is.
     */
    public static function normalize(string $key): string
    {
        if ($key === 'default-bindings' || substr_count($key, '-') > 1) { return $key; }
        if ($key === '+') { return 'PLUS'; }
        $parts = explode('+', str_replace('++', '+PLUS', $key));
        $k = (string)array_pop($parts);
        if ($k === '#') { $k = 'SHARP'; } elseif ($k === '+') { $k = 'PLUS'; } elseif (mb_strlen($k) > 1) { $k = strtoupper($k); }
        static $upper = null, $uppers = null;
        if ($upper === null) {
            $upper = []; foreach (self::KEYS as [$lo, $up]) { if ($up !== null) { $upper[$lo] = $up; } }
            $uppers = array_flip(array_values($upper));
        }
        $mods = [];
        foreach ($parts as $m) {
            if (strcasecmp($m, 'Shift') === 0) {
                if (isset($upper[$k])) { $k = $upper[$k]; } elseif (!isset($uppers[$k])) { $mods['Shift'] = true; }
            } elseif (strcasecmp($m, 'Meta') === 0) { $mods['Meta'] = true; }
            elseif (strcasecmp($m, 'Ctrl') === 0) { $mods['Ctrl'] = true; }
            elseif (strcasecmp($m, 'Alt') === 0) { $mods['Alt'] = true; }
            else { $mods[strtoupper($m)] = true; }
        }
        $out = array_values(array_filter(self::MODIFIERS, fn(string $m) => isset($mods[$m])));
        foreach (array_keys($mods) as $m) { if (!in_array($m, self::MODIFIERS, true)) { $out[] = $m; } }
        $out[] = $k;
        return implode('+', $out);
    }

    /**
     * mpv's name of a key pressed (KeyCodeHelper.mpvKeyCode): what it types
     * without its modifiers where mpv takes it (one code point, NFKC, of the
     * categories it allows), Shift then in it; else IINA's name of its code;
     * its modifiers before it, Ctrl, Alt, Shift, Meta. '' for a key of no name.
     */
    public static function fromEvent(int $code, int $mods, string $plain): string
    {
        $char = class_exists(Normalizer::class) ? (string)Normalizer::normalize($plain, Normalizer::FORM_KC) : $plain;
        if (self::allowed($char)) {
            $mods &= ~Key::SHIFT;
        } elseif (isset(self::KEYS[$code])) {
            $char = self::KEYS[$code][0];
        } else {
            return '';
        }
        if ($char === '#') { $char = 'SHARP'; }
        $out = '';
        foreach ([[Key::CONTROL, 'Ctrl'], [Key::OPTION, 'Alt'], [Key::SHIFT, 'Shift'], [Key::COMMAND, 'Meta']] as [$m, $name]) {
            if (($mods & $m) !== 0) { $out .= "$name+"; }
        }
        return $out . $char;
    }

    /** Whether $c is one character mpv takes as a key's name in input.conf (KeyCodeHelper.isAllowedInConfFile). */
    private static function allowed(string $c): bool
    {
        if (mb_strlen($c) !== 1) { return false; }
        $type = IntlChar::charType(mb_ord($c));
        return !in_array($type, [IntlChar::CHAR_CATEGORY_CONTROL_CHAR, IntlChar::CHAR_CATEGORY_FORMAT_CHAR, IntlChar::CHAR_CATEGORY_LINE_SEPARATOR, IntlChar::CHAR_CATEGORY_PARAGRAPH_SEPARATOR,
            IntlChar::CHAR_CATEGORY_PRIVATE_USE_CHAR, IntlChar::CHAR_CATEGORY_SPACE_SEPARATOR, IntlChar::CHAR_CATEGORY_UNASSIGNED, IntlChar::CHAR_CATEGORY_SURROGATE], true);
    }

    /** A binding's key as the settings show it: as written where they ask for raw values, else as a Mac writes it ("⌃⌥⇧⌘X"). */
    public static function displayKey(array $m, bool $raw): string
    {
        if ($raw) { return $m['key']; }
        $parts = explode('+', $m['normal']);
        $key = (string)array_pop($parts);
        $key = self::PRETTY[$key] ?? $key;
        if (grapheme_strlen($key) !== 1) { return $m['normal']; } // a name of no one character (F1, Ins, a sequence): as it is
        $shift = in_array('Shift', $parts, true) || IntlChar::isupper(mb_ord(mb_substr($key, 0, 1)));
        $mods = (in_array('Ctrl', $parts, true) ? '⌃' : '') . (in_array('Alt', $parts, true) ? '⌥' : '') . ($shift ? '⇧' : '') . (in_array('Meta', $parts, true) ? '⌘' : '');
        return $mods . mb_strtoupper($key);
    }

    /** A binding's action as the settings show it: as written where they ask for raw values, else read in words (KeyBindingTranslator). */
    public static function displayAction(Context $c, array $m, bool $raw): string
    {
        return $raw ? self::rawAction($m) : self::readable($c, $m['action'], $m['iina']);
    }

    /** mpv's or IINA's command $action read in words, as IINA reads it (KeyBindingTranslator.readableCommand). @param list<string> $action */
    public static function readable(Context $c, array $action, bool $iina): string
    {
        $tx = $c->texts();
        $s = fn(string $k): ?string => \MyPHP\L10n::catalog()->message('en', $id = 'keyBinding' . implode('', array_map('ucfirst', preg_split('/[^A-Za-z0-9]+/', $k, -1, PREG_SPLIT_NO_EMPTY) ?: []))) !== null ? $tx->get($id) : null; // IINA's KeyBinding.strings, where it has the string
        $cmds = array_values(array_filter($action, fn(string $w) => !in_array($w, self::PREFIXES, true)));
        if ($cmds === []) { return ''; }
        $cmd = $cmds[0];
        if ($iina) { return $s("iina.$cmd") ?? $cmd; }
        if (isset(self::FORMATS[$cmd]) && ($tmpl = $s("read.$cmd")) !== null) {
            $data = [];
            foreach (explode(' ', self::FORMATS[$cmd]) as $i => $part) {
                if (str_contains($part, '#')) {
                    [$k, $default] = explode('#', $part, 2);
                    $data[$k] = $cmds[$i] ?? $default;
                } elseif (str_contains($part, ':')) {
                    [$k, $choices] = explode(':', $part, 2);
                    $data[$k . '_' . ($cmds[$i] ?? explode('|', $choices)[0])] = 'true';
                    $data[$k] = $cmds[$i] ?? null;
                } elseif (str_ends_with($part, '...')) {
                    $data[substr($part, 0, -3)] = implode(' ', array_slice($cmds, $i));
                } else {
                    $data[$part] = $cmds[$i] ?? null;
                }
            }
            if (isset($data['property']) && ($t = $s('opt.' . $data['property'])) !== null) { $data['property'] = $t; }
            if (isset($data['value'])) {
                $value = (string)$data['value'];
                if ($value === 'yes' || $value === 'no') { $data['value'] = $s($value); }
                elseif ($cmd === 'add' && !str_starts_with($value, '-') && !str_starts_with($value, '+')) { $data['value'] = "+$value"; }
                if ($cmd === 'seek') {
                    $opt = explode('+', (string)($data['opt'] ?? 'relative'));
                    if (array_filter($opt, fn(string $o) => str_starts_with($o, 'absolute')) !== []) { $data['abs'] = 'true'; }
                    elseif (str_starts_with($value, '-')) { $data['bwd'] = 'true'; $data['value'] = substr($value, 1); }
                    else { $data['fwd'] = 'true'; }
                    if (array_filter($opt, fn(string $o) => str_ends_with($o, 'percent')) !== []) { $data['per'] = 'true'; } else { $data['sec'] = 'true'; }
                    if (in_array('exact', $opt, true)) { $data['exact'] = 'true'; } elseif (in_array('keyframes', $opt, true)) { $data['kf'] = 'true'; }
                }
            }
            return self::mustache($tmpl, $data);
        }
        if (($t = $s("cmd.$cmd")) !== null) { $cmds[0] = $t; }
        return implode(' ', $cmds);
    }

    /** Mustache's {{name}}, {{{name}}} and {{#name}}…{{/name}} (shown where it is set), as IINA's templates use them. @param array<string, ?string> $data */
    private static function mustache(string $t, array $data): string
    {
        do {
            $before = $t;
            $t = (string)preg_replace_callback('/\{\{#([^}]+)\}\}(.*?)\{\{\/\1\}\}/s', fn(array $m) => ($data[$m[1]] ?? null) !== null && $data[$m[1]] !== '' ? $m[2] : '', $t);
        } while ($t !== $before);
        return (string)preg_replace_callback('/\{\{\{?([^}]+?)\}?\}\}/', fn(array $m) => (string)($data[trim($m[1])] ?? ''), $t);
    }
}
