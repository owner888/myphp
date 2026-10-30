<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Automation;

use Closure;
use MyPHP\UI\{AccessNode, AccessTree, Key, Role};
use stdClass;

/**
 * The protocol of the automation plugin (mygo's plugins/automation
 * protocol.go): a request is a JSON object on a line, {id, method,
 * params}; its answer {id, result} or {id, error: {code, message}}, on a
 * line of its own. handle() answers a line with a driver.
 *
 * Elements' IDs go as strings of their decimal digits, unsigned, which
 * JavaScript's numbers would round, and come as such or as numbers.
 *
 * @internal
 */
final class Protocol
{
    /** The longest line of a request, in bytes. */
    public const MAX_LINE = 16 << 20;

    /** The names of the roles, from Role::GROUP. */
    public const ROLES = [
        'group', 'text', 'button', 'link', 'checkBox', 'radio', 'switch', 'slider',
        'progress', 'textField', 'image', 'list', 'scroll', 'dialog', 'popup',
        'tooltip', 'popUpButton', 'tabList', 'tab', 'splitter', 'status', 'table',
        'row', 'cell', 'columnHeader', 'tree', 'treeItem', 'listItem', 'menuButton',
        'toolbar', 'radioGroup', 'toggleButton', 'comboBox', 'disclosure', 'meter',
        'stepper', 'colorWell', 'alertDialog', 'menu', 'menuBar', 'menuItem',
        'menuItemCheckBox', 'menuItemRadio', 'heading',
    ];

    /** The names of AccessNode's states, by bit. */
    public const STATES = [
        'focusable', 'disabled', 'checked', 'mixed', 'selected', 'expanded',
        'multiline', 'password', 'readOnly', 'selectable', 'offscreen',
        'multiselectable', 'segment', 'search', 'invalid', 'sortAscending',
        'sortDescending', 'expandable', 'vertical',
    ];

    /** The names of AccessNode's ACTION_ bits. */
    public const ACTIONS = ['press', 'focus', 'increment', 'decrement', 'setValue', 'scrollIntoView', 'expand'];

    /** act's actions: AccessNode's. */
    public const ACTION_KINDS = [
        'press' => AccessNode::PRESS, 'focus' => AccessNode::FOCUS, 'increment' => AccessNode::INCREMENT,
        'decrement' => AccessNode::DECREMENT, 'setValue' => AccessNode::SET_VALUE, 'scrollIntoView' => AccessNode::SCROLL_INTO_VIEW,
        'expand' => AccessNode::EXPAND, 'collapse' => AccessNode::COLLAPSE, 'selectText' => AccessNode::SELECT_TEXT,
        'insertText' => AccessNode::INSERT_TEXT,
    ];

    /** The keys of accelerators that have no character of their own, by their lowercase names: their key codes and what they type, as AppKit's key events carry it. */
    private const NAMED_KEYS = [
        'enter' => [Key::RETURN, "\r"], 'return' => [Key::RETURN, "\r"], 'escape' => [Key::ESCAPE, "\e"], 'esc' => [Key::ESCAPE, "\e"],
        'backspace' => [Key::DELETE, "\x7f"], 'tab' => [Key::TAB, "\t"], 'space' => [Key::SPACE, ' '],
        'delete' => [Key::FORWARD_DELETE, "\u{F728}"], 'insert' => [Key::INSERT, "\u{F727}"], 'home' => [Key::HOME, "\u{F729}"],
        'end' => [Key::END, "\u{F72B}"], 'pageup' => [Key::PAGE_UP, "\u{F72C}"], 'pagedown' => [Key::PAGE_DOWN, "\u{F72D}"],
        'left' => [Key::LEFT, "\u{F702}"], 'right' => [Key::RIGHT, "\u{F703}"], 'up' => [Key::UP, "\u{F700}"], 'down' => [Key::DOWN, "\u{F701}"],
        'f1' => [Key::F1, "\u{F704}"], 'f2' => [Key::F2, "\u{F705}"], 'f3' => [Key::F3, "\u{F706}"], 'f4' => [Key::F4, "\u{F707}"],
        'f5' => [Key::F5, "\u{F708}"], 'f6' => [Key::F6, "\u{F709}"], 'f7' => [Key::F7, "\u{F70A}"], 'f8' => [Key::F8, "\u{F70B}"],
        'f9' => [Key::F9, "\u{F70C}"], 'f10' => [Key::F10, "\u{F70D}"], 'f11' => [Key::F11, "\u{F70E}"], 'f12' => [Key::F12, "\u{F70F}"],
    ];

    /** The fields of an event of input, and their types. */
    private const EVENT_FIELDS = [
        'type' => 'string', 'x' => 'number', 'y' => 'number', 'button' => 'string', 'clicks' => 'int',
        'dx' => 'number', 'dy' => 'number', 'precise' => 'bool', 'key' => 'string', 'mods' => 'strings', 'text' => 'string',
    ];

    /**
     * The answer to a line: the response, or, for a request answered
     * later (idle), a function giving it once it is ready, null until then.
     * @return array<string, mixed>|Closure(): ?array<string, mixed>
     */
    public static function handle(Driver $d, string $line): array|Closure
    {
        try {
            $req = json_decode($line, false, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return self::error(null, new Failure(Failure::PARSE, $e->getMessage()));
        }
        if (!$req instanceof stdClass) {
            return self::error(null, new Failure(Failure::PARSE, 'the line is not a JSON object'));
        }
        $id = $req->id ?? null;
        try {
            $v = self::call($d, $req);
        } catch (\Throwable $e) {
            return self::error($id, $e);
        }
        if (!$v instanceof Closure) {
            return ['id' => $id, 'result' => $v];
        }
        return function () use ($v, $id): ?array {
            try {
                $r = $v();
            } catch (\Throwable $e) {
                return self::error($id, $e);
            }
            return $r === null ? null : ['id' => $id, 'result' => $r];
        };
    }

    /** The line of a response. */
    public static function encode(array $response): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        try {
            return json_encode($response, $flags | JSON_THROW_ON_ERROR) . "\n";
        } catch (\JsonException $e) { // a value JSON has no number for, as NaN
            return json_encode(self::error($response['id'] ?? null, new Failure(Failure::FAILED, 'the answer is no JSON: ' . $e->getMessage())), $flags) . "\n";
        }
    }

    /** A response of an error: a Failure's code, or failed for other exceptions. */
    public static function error(mixed $id, \Throwable $e): array
    {
        $f = $e instanceof Failure ? $e : new Failure(Failure::FAILED, $e->getMessage());
        return ['id' => $id, 'error' => ['code' => $f->kind, 'message' => $f->getMessage()]];
    }

    /** Performs a request: its result, or a function giving it later. */
    private static function call(Driver $d, stdClass $req): mixed
    {
        $method = $req->method ?? '';
        if (!is_string($method)) {
            throw new Failure(Failure::PARSE, 'the method is not a string');
        }
        $params = $req->params ?? null;
        switch ($method) {
            case '':
                throw new Failure(Failure::INVALID, 'the request has no method');
            case 'windows':
                self::fields("params of $method", $params, []);
                return ['windows' => $d->windows()];
            case 'tree':
                $p = self::fields("params of $method", $params, ['window' => 'int']);
                [$w, $t, $keys] = $d->tree($p['window']);
                return self::treeJSON($w, $t, $keys);
            case 'act':
                return self::act($d, self::fields("params of $method", $params, [
                    'window' => 'int', 'id' => 'id', 'key' => 'json', 'action' => 'string', 'text' => 'string', 'from' => 'int', 'to' => 'int',
                ]));
            case 'input':
                $p = self::fields("params of $method", $params, ['window' => 'int', 'events' => 'list']);
                $d->input($p['window'], self::events($p['events']));
                return new stdClass;
            case 'menu':
                $p = self::fields("params of $method", $params, ['window' => 'int', 'path' => 'strings']);
                if ($p['path'] === []) {
                    throw new Failure(Failure::INVALID, 'menu needs the path of the item, as ["File", "New"]');
                }
                $d->menu($p['window'], $p['path']);
                return new stdClass;
            case 'capture':
                $p = self::fields("params of $method", $params, ['window' => 'int']);
                $png = $d->capture($p['window']);
                $r = ['png' => base64_encode($png), 'width' => 0, 'height' => 0];
                if (strlen($png) >= 24 && str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
                    ['w' => $r['width'], 'h' => $r['height']] = unpack('Nw/Nh', $png, 16); // the IHDR's
                }
                return $r;
            case 'eval':
                $p = self::fields("params of $method", $params, ['window' => 'int', 'script' => 'string']);
                return ['value' => $d->eval($p['window'], $p['script'])];
            case 'idle':
                $p = self::fields("params of $method", $params, ['window' => 'int', 'timeout' => 'int']);
                if ($p['timeout'] < 0) {
                    throw new Failure(Failure::INVALID, 'timeout is negative');
                }
                $deadline = $p['timeout'] > 0 ? hrtime(true) + $p['timeout'] * 1_000_000 : null;
                return function () use ($d, $p, $deadline): ?stdClass {
                    [$w, $idle] = $d->idle($p['window']);
                    if ($idle) {
                        return new stdClass;
                    }
                    if ($deadline !== null && hrtime(true) > $deadline) {
                        throw new Failure(Failure::TIMEOUT, "window $w still draws after {$p['timeout']} ms");
                    }
                    return null;
                };
        }
        throw new Failure(Failure::UNKNOWN_METHOD, 'no method ' . json_encode($method, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * The fields of an object, the ones $types names, each its type's zero
     * when it is left out or null; refuses other fields and values of
     * other types.
     * @param array<string, string> $types
     * @return array<string, mixed>
     */
    private static function fields(string $what, mixed $raw, array $types): array
    {
        $out = [];
        foreach ($types as $f => $type) {
            $out[$f] = self::zero($type);
        }
        if ($raw === null) {
            return $out;
        }
        if (!$raw instanceof stdClass) {
            throw new Failure(Failure::INVALID, "$what: not an object");
        }
        foreach (get_object_vars($raw) as $f => $v) {
            $f = (string)$f;
            if (!isset($types[$f])) {
                throw new Failure(Failure::INVALID, "$what: unknown field " . json_encode($f, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
            $out[$f] = $v === null ? self::zero($types[$f]) : self::value("$what: $f", $types[$f], $v);
        }
        return $out;
    }

    private static function zero(string $type): mixed
    {
        return match ($type) {
            'int', 'id' => 0, 'number' => 0.0, 'bool' => false, 'string' => '', 'strings', 'list' => [], 'json' => null,
        };
    }

    /** $v as a value of $type. */
    private static function value(string $what, string $type, mixed $v): mixed
    {
        $ok = match ($type) {
            'int' => is_int($v),
            'number' => is_int($v) || is_float($v),
            'bool' => is_bool($v),
            'string' => is_string($v),
            'list' => is_array($v),
            'strings' => is_array($v) && array_filter($v, 'is_string') === $v,
            'id' => is_int($v) || is_string($v),
            'json' => true,
        };
        if (!$ok) {
            throw new Failure(Failure::INVALID, "$what is not " . match ($type) {
                'int' => 'an integer', 'number' => 'a number', 'bool' => 'a boolean', 'string' => 'a string', 'list' => 'a list', 'strings' => 'a list of strings',
                'id' => 'an element ID',
            });
        }
        return match ($type) {
            'number' => (float)$v,
            'id' => self::parseId($v),
            default => $v,
        };
    }

    /** An element's ID from its decimal digits, unsigned: the 64 bits of the int. */
    public static function parseId(int|string $v): int
    {
        $s = (string)$v;
        if (preg_match('/^\d{1,20}$/', $s) !== 1) {
            throw new Failure(Failure::INVALID, 'an element ID is a string of digits, not ' . json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        // In two halves of 32 bits, which PHP's ints hold whole: their products would turn to floats.
        $hi = $lo = 0;
        foreach (str_split($s) as $c) {
            $lo = $lo * 10 + (int)$c;
            $hi = $hi * 10 + ($lo >> 32);
            $lo &= 0xFFFFFFFF;
            if ($hi > 0xFFFFFFFF) {
                throw new Failure(Failure::INVALID, "an element ID has 64 bits, not $s");
            }
        }
        return $hi << 32 | $lo;
    }

    /** The decimal digits of an element's ID, unsigned. */
    public static function id(int $id): string
    {
        return sprintf('%u', $id);
    }

    /** Performs an action of assistive technology on the element of the ID or the key. */
    private static function act(Driver $d, array $p): stdClass
    {
        $kind = self::ACTION_KINDS[$p['action']] ?? throw new Failure(Failure::INVALID, 'no action ' . json_encode($p['action'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $hasKey = $p['key'] !== null;
        if ($p['id'] === 0 && !$hasKey) {
            throw new Failure(Failure::INVALID, 'act needs the id or the key of an element');
        }
        if ($p['id'] !== 0 && $hasKey) {
            throw new Failure(Failure::INVALID, 'act takes an id or a key, not both');
        }
        $id = $p['id'];
        if ($hasKey) {
            $want = self::json($p['key']);
            [, $t, $keys] = $d->tree($p['window']);
            $n = 0;
            foreach ($t->nodes as $node) {
                if (isset($keys[$node->id]) && self::json($keys[$node->id]) === $want) {
                    $id = $node->id;
                    $n++;
                }
            }
            if ($n === 0) {
                throw new Failure(Failure::NO_ELEMENT, "no element has the key $want");
            }
            if ($n > 1) {
                throw new Failure(Failure::INVALID, "$n elements have the key $want: act on one by its id");
            }
        }
        $d->act($p['window'], $id, $kind, $p['text'], $p['from'], $p['to']);
        return new stdClass;
    }

    private static function json(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * The tree request's answer: the elements of a window's last frame,
     * in tree order, a parent before its children.
     * @param array<int, string|int> $keys
     */
    public static function treeJSON(int $window, AccessTree $t, array $keys): array
    {
        $r = ['window' => $window];
        if ($t->focus !== 0) {
            $r['focus'] = self::id($t->focus);
        }
        $nodes = [];
        foreach ($t->nodes as $n) {
            $j = ['id' => self::id($n->id)];
            if ($n->parent >= 0 && isset($t->nodes[$n->parent])) {
                $j['parent'] = self::id($t->nodes[$n->parent]->id);
            }
            $j['role'] = self::ROLES[$n->role - Role::GROUP] ?? "role{$n->role}";
            if (isset($keys[$n->id])) {
                $j['key'] = $keys[$n->id];
            }
            foreach (['label', 'value', 'placeholder', 'description'] as $f) {
                if ($n->$f !== '') { $j[$f] = $n->$f; }
            }
            $j['bounds'] = self::rect(...$n->bounds);
            if (($s = self::names($n->states, self::STATES)) !== []) { $j['states'] = $s; }
            if (($s = self::names($n->actions, self::ACTIONS)) !== []) { $j['actions'] = $s; }
            if (Role::ranged($n->role)) {
                [$j['min'], $j['max'], $j['now'], $j['step']] = [$n->min, $n->max, $n->now, $n->step];
            }
            if ($n->role === Role::TEXT_FIELD || $n->role === Role::COMBO_BOX) {
                $j['selection'] = [$n->selStart, $n->selEnd];
            }
            foreach (['level', 'posInSet', 'setSize'] as $f) {
                if ($n->$f !== 0) { $j[$f] = $n->$f; }
            }
            $nodes[] = $j;
        }
        $r['nodes'] = $nodes;
        if ($t->announcements !== []) {
            $r['announcements'] = $t->announcements;
        }
        return $r;
    }

    /** A rectangle as the protocol gives it. */
    public static function rect(float $x, float $y, float $w, float $h): array
    {
        return ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h];
    }

    /** @return list<string> the names of the bits set */
    private static function names(int $bits, array $of): array
    {
        $out = [];
        foreach ($of as $i => $name) {
            if (($bits & 1 << $i) !== 0) { $out[] = $name; }
        }
        return $out;
    }

    /**
     * The events of an input request, as the window takes them: ['move',
     * x, y, mods], ['down' or 'up', x, y, button, clicks, mods], ['scroll',
     * x, y, dx, dy, precise, mods], ['keyDown' or 'keyUp', key code,
     * characters, mods] and ['text', text]; mods are Key's masks. All are
     * checked before any goes.
     * @return list<array>
     */
    public static function events(array $list): array
    {
        $out = [];
        foreach (array_values($list) as $i => $raw) {
            if (!$raw instanceof stdClass) {
                throw new Failure(Failure::INVALID, "event $i is not an object");
            }
            $e = self::fields("event $i", $raw, self::EVENT_FIELDS);
            $mods = self::modifiers($e['mods'], $i);
            $button = match ($e['button']) {
                '', 'left' => 0, 'right' => 1, 'middle' => 2,
                default => throw new Failure(Failure::INVALID, "event $i: no button " . self::json($e['button']) . ': left, right or middle'),
            };
            $clicks = max($e['clicks'], 1);
            [$x, $y] = [$e['x'], $e['y']];
            switch ($e['type']) {
                case 'pointerMove':
                    $out[] = ['move', $x, $y, $mods];
                    break;
                case 'pointerDown':
                case 'pointerUp':
                    $out[] = [$e['type'] === 'pointerDown' ? 'down' : 'up', $x, $y, $button, $clicks, $mods];
                    break;
                case 'click':
                    $out[] = ['move', $x, $y, $mods];
                    for ($n = 1; $n <= $clicks; $n++) {
                        $out[] = ['down', $x, $y, $button, $n, $mods];
                        $out[] = ['up', $x, $y, $button, $n, $mods];
                    }
                    break;
                case 'wheel':
                    $out[] = ['scroll', $x, $y, $e['dx'], $e['dy'], $e['precise'], $mods];
                    break;
                case 'key':
                case 'keyDown':
                case 'keyUp':
                    [$code, $chars, $keyMods] = self::key($e['key'], $i);
                    if ($e['type'] !== 'keyUp') { $out[] = ['keyDown', $code, $chars, $keyMods | $mods]; }
                    if ($e['type'] !== 'keyDown') { $out[] = ['keyUp', $code, $chars, $keyMods | $mods]; }
                    break;
                case 'text':
                    if ($e['text'] === '') {
                        throw new Failure(Failure::INVALID, "event $i: text has no text");
                    }
                    $out[] = ['text', $e['text']];
                    break;
                default:
                    throw new Failure(Failure::INVALID, "event $i: no event type " . self::json($e['type']));
            }
        }
        return $out;
    }

    /** Key's masks of modifiers by name; CmdOrCtrl is Command, as on macOS. */
    private static function modifier(string $name): ?int
    {
        return match (strtolower($name)) {
            'shift' => Key::SHIFT,
            'ctrl', 'control' => Key::CONTROL,
            'alt', 'option' => Key::OPTION,
            'super', 'cmd', 'command', 'meta', 'cmdorctrl', 'commandorcontrol' => Key::COMMAND,
            default => null,
        };
    }

    /** @param list<string> $names */
    private static function modifiers(array $names, int $i): int
    {
        $mods = 0;
        foreach ($names as $name) {
            $mods |= self::modifier($name) ?? throw new Failure(Failure::INVALID, "event $i: no modifier " . self::json($name));
        }
        return $mods;
    }

    /**
     * The key of an accelerator, as "CmdOrCtrl+Shift+Z" or "Enter": its
     * key code, what it types, and its modifiers.
     * @return array{0: int, 1: string, 2: int}
     */
    private static function key(string $accel, int $i): array
    {
        $parts = explode('+', $accel);
        $name = trim(array_pop($parts));
        $mods = 0;
        foreach ($parts as $m) {
            $mods |= self::modifier(trim($m)) ?? throw new Failure(Failure::INVALID, "event $i: no modifier " . self::json(trim($m)) . ' in ' . self::json($accel));
        }
        if ($name === '') {
            throw new Failure(Failure::INVALID, 'event ' . $i . ': ' . self::json($accel) . ' names no key');
        }
        if (isset(self::NAMED_KEYS[strtolower($name)])) {
            [$code, $chars] = self::NAMED_KEYS[strtolower($name)];
            return [$code, $chars, $mods];
        }
        $c = mb_strtolower($name, 'UTF-8');
        $code = mb_strlen($c, 'UTF-8') === 1 ? self::codeOf($c) : null;
        if ($code === null) {
            throw new Failure(Failure::INVALID, "event $i: native UI has no key " . self::json($name));
        }
        return [$code, ($mods & Key::SHIFT) !== 0 ? strtoupper($c) : $c, $mods];
    }

    /** The key code of the key that types $c on a US layout, or null. */
    private static function codeOf(string $c): ?int
    {
        static $codes = null;
        if ($codes === null) {
            $codes = [];
            for ($code = 0; $code < 128; $code++) {
                if (($ch = Key::usChar($code)) !== null) { $codes[$ch] = $code; }
            }
        }
        return $codes[$c] ?? null;
    }
}
