<?php
// The automation plugin (mygo's plugins/automation protocol_test.go): the
// protocol against a fake app, the names of roles, states and actions,
// the socket's mode, its answers in order, a request that waits holding
// its connection's alone, stale and taken paths; and the runtime's side,
// the tree of a frame with its keys and an action on it while no
// assistive technology reads the window.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Plugins\Automation\{Driver, Failure, Protocol, Server};
use MyPHP\UI\{AccessNode, AccessTree, Context, Key, Role, TextState};

/** A fake app: records what requests ask of it. */
$fake = fn() => new class implements Driver {
    public AccessTree $access;
    /** @var array<int, string|int> */
    public array $keys = [];
    public array $acted = [], $sent = [], $menus = [];
    public string $png = '';
    public ?\Closure $evalFn = null;
    /** How many times idle answers that the window draws, before it is idle. */
    public int $busy = 0;

    public function __construct() { $this->access = new AccessTree; }
    public function windows(): array
    {
        return [['id' => 1, 'title' => 'Counter', 'kind' => 'content', 'bounds' => Protocol::rect(10, 20, 300, 200), 'contentBounds' => Protocol::rect(10, 48, 300, 172), 'focused' => true, 'visible' => true]];
    }
    public function tree(int $window): array
    {
        if ($window > 1) { throw new Failure(Failure::NO_WINDOW, "no window $window"); }
        return [1, $this->access, $this->keys];
    }
    public function act(int $window, int $id, int $action, string $text, int $from, int $to): void { $this->acted[] = [$id, $action, $text, $from, $to]; }
    public function input(int $window, array $events): void { array_push($this->sent, ...$events); }
    public function menu(int $window, array $path): void { $this->menus[] = $path; }
    public function capture(int $window): string { return $this->png; }
    public function eval(int $window, string $script): mixed { return ($this->evalFn)($script); }
    public function idle(int $window): array { return [1, $this->busy-- <= 0]; }
};
/** The answer to a line, as a client reads it. */
$do = function (Driver $d, string $line): array {
    $r = Protocol::handle($d, $line);
    check(is_array($r), "$line: answered at once");
    return json_decode(Protocol::encode($r), true);
};
$code = fn(array $m): string => $m['error']['code'] ?? '';

// Requests that fail, and their codes.
$d = $fake();
foreach ([
    ['not json', Failure::PARSE],
    ['[1, 2]', Failure::PARSE],
    ['{"id": 1}', Failure::INVALID],
    ['{"id": 1, "method": "fly"}', Failure::UNKNOWN_METHOD],
    ['{"id": 1, "method": "tree", "params": {"window": 1, "windwo": 2}}', Failure::INVALID],
    ['{"id": 1, "method": "tree", "params": {"window": "one"}}', Failure::INVALID],
    ['{"id": 1, "method": "tree", "params": [1]}', Failure::INVALID],
    ['{"id": 1, "method": "tree", "params": {"window": 7}}', Failure::NO_WINDOW],
    ['{"id": 1, "method": "windows", "params": {"window": 1}}', Failure::INVALID],
    ['{"id": 1, "method": "menu", "params": {"path": []}}', Failure::INVALID],
    ['{"id": 1, "method": "menu", "params": {"path": ["File", 2]}}', Failure::INVALID],
    ['{"id": 1, "method": "idle", "params": {"timeout": -1}}', Failure::INVALID],
] as [$line, $want]) {
    $m = $do($d, $line);
    same($want, $code($m), "$line: the error's code");
    check(!array_key_exists('result', $m), "$line: no result with the error");
}
same('a-7', $do($d, '{"id": "a-7", "method": "fly"}')['id'], 'the ID comes back as it went');
check(str_contains(Protocol::encode(Protocol::handle($d, '{')), '"id":null'), 'an unreadable line is answered with the ID null');
same("{\"id\":3,\"result\":{}}\n", Protocol::encode(Protocol::handle($d, '{"id": 3, "method": "menu", "params": {"path": ["File"]}}')), 'a request answering nothing but success has the result {}');

// windows
$w = $do($d, '{"id": 1, "method": "windows"}')['result']['windows'][0];
check($w['id'] === 1 && $w['kind'] === 'content' && $w['focused'] === true && $w['bounds']['width'] === 300, 'windows lists the windows');

// The tree: a button in a group, a text field and a slider; IDs as strings of their unsigned digits.
$tree = function () {
    $t = new AccessTree;
    $t->focus = 1 << 60;
    $t->nodes = [new AccessNode(7, -1, Role::GROUP), new AccessNode(1 << 60, 0, Role::BUTTON), new AccessNode(9, 0, Role::TEXT_FIELD), new AccessNode(-5, 0, Role::SLIDER)];
    [, $button, $field, $slider] = $t->nodes;
    $button->label = 'Add'; $button->states = AccessNode::FOCUSABLE; $button->actions = AccessNode::ACTION_PRESS | AccessNode::ACTION_FOCUS; $button->bounds = [1.0, 2.0, 30.0, 20.0];
    $field->value = 'hey'; $field->selStart = $field->selEnd = 3;
    [$slider->min, $slider->max, $slider->now] = [0.0, 10.0, 4.0];
    return $t;
};
$d = $fake();
$d->access = $tree();
$d->keys = [1 << 60 => 'add', 9 => 42];
$r = $do($d, '{"id": 1, "method": "tree"}')['result'];
same([1, '1152921504606846976'], [$r['window'], $r['focus']], "the tree's window and focus");
[$group, $button, $field, $slider] = $r['nodes'];
check(!isset($group['parent']) && $group['role'] === 'group', 'an element at the top has no parent');
same(['id' => '1152921504606846976', 'parent' => '7', 'role' => 'button', 'key' => 'add', 'label' => 'Add', 'bounds' => ['x' => 1, 'y' => 2, 'width' => 30, 'height' => 20], 'states' => ['focusable'], 'actions' => ['press', 'focus']], $button, 'a button');
check($field['key'] === 42 && $field['value'] === 'hey' && $field['selection'] === [3, 3], 'a text field: its key, value and selection');
same('18446744073709551611', $slider['id'], 'an ID with its top bit set goes unsigned');
check($slider['max'] === 10 && $slider['now'] === 4 && $slider['min'] === 0 && !isset($button['min']), "a slider's range, which a button has not");
same(-5, Protocol::parseId('18446744073709551611'), 'and comes back as the 64 bits it was');
same(1 << 60, Protocol::parseId(1152921504606846976), 'an ID as a number');
foreach (['18446744073709551616', '-1', 'seven', ''] as $bad) {
    try { Protocol::parseId($bad); check(false, "$bad: an ID"); } catch (Failure $e) { same(Failure::INVALID, $e->kind, "$bad is no ID"); }
}

// act, by ID and by key.
foreach ([
    '{"id": 1, "method": "act", "params": {"id": "1152921504606846976", "action": "press"}}',
    '{"id": 2, "method": "act", "params": {"key": "add", "action": "press"}}',
    '{"id": 3, "method": "act", "params": {"key": 42, "action": "setValue", "text": "hi"}}',
    '{"id": 4, "method": "act", "params": {"id": 9, "action": "selectText", "from": 1, "to": 2}}',
    '{"id": 5, "method": "act", "params": {"id": "18446744073709551611", "action": "increment"}}',
] as $line) {
    same(null, $do($d, $line)['error'] ?? null, "$line: no error");
}
same([
    [1 << 60, AccessNode::PRESS, '', 0, 0], [1 << 60, AccessNode::PRESS, '', 0, 0], [9, AccessNode::SET_VALUE, 'hi', 0, 0],
    [9, AccessNode::SELECT_TEXT, '', 1, 2], [-5, AccessNode::INCREMENT, '', 0, 0],
], $d->acted, 'the actions done');
$d->keys[-5] = 'add'; // two elements have it now
foreach ([
    ['{"id": 1, "method": "act", "params": {"action": "press"}}', Failure::INVALID],
    ['{"id": 1, "method": "act", "params": {"id": "7", "key": "add", "action": "press"}}', Failure::INVALID],
    ['{"id": 1, "method": "act", "params": {"id": "7", "action": "smash"}}', Failure::INVALID],
    ['{"id": 1, "method": "act", "params": {"id": "seven", "action": "press"}}', Failure::INVALID],
    ['{"id": 1, "method": "act", "params": {"key": "remove", "action": "press"}}', Failure::NO_ELEMENT],
    ['{"id": 1, "method": "act", "params": {"key": "42", "action": "press"}}', Failure::NO_ELEMENT],
    ['{"id": 1, "method": "act", "params": {"key": "add", "action": "press"}}', Failure::INVALID],
] as [$line, $want]) {
    same($want, $code($do($d, $line)), "$line: the error's code");
}

// input: the events, as the window takes them.
$d = $fake();
same(null, $do($d, '{"id": 1, "method": "input", "params": {"events": [
    {"type": "click", "x": 5, "y": 6, "clicks": 2},
    {"type": "pointerDown", "x": 1, "y": 2, "button": "right", "mods": ["shift"]},
    {"type": "pointerMove", "x": 3, "y": 4},
    {"type": "wheel", "x": 1, "y": 2, "dy": 40, "precise": true},
    {"type": "key", "key": "Ctrl+Shift+Z"},
    {"type": "keyDown", "key": "Enter"},
    {"type": "keyUp", "key": "F5"},
    {"type": "key", "key": "Backspace", "mods": ["CmdOrCtrl"]},
    {"type": "text", "text": "héllo"}
]}}')['error'] ?? null, 'input');
same([
    ['move', 5.0, 6.0, 0],
    ['down', 5.0, 6.0, 0, 1, 0], ['up', 5.0, 6.0, 0, 1, 0],
    ['down', 5.0, 6.0, 0, 2, 0], ['up', 5.0, 6.0, 0, 2, 0],
    ['down', 1.0, 2.0, 1, 1, Key::SHIFT],
    ['move', 3.0, 4.0, 0],
    ['scroll', 1.0, 2.0, 0.0, 40.0, true, 0],
    ['keyDown', Key::Z, 'Z', Key::CONTROL | Key::SHIFT], ['keyUp', Key::Z, 'Z', Key::CONTROL | Key::SHIFT],
    ['keyDown', Key::RETURN, "\r", 0],
    ['keyUp', Key::F5, "\u{F708}", 0],
    ['keyDown', Key::DELETE, "\x7f", Key::COMMAND], ['keyUp', Key::DELETE, "\x7f", Key::COMMAND],
    ['text', 'héllo'],
], $d->sent, 'the events sent');
foreach ([
    '[{"type": "hover"}]',
    '[{"type": "key", "key": "Hyper+A"}]',
    '[{"type": "key", "key": "VolumeUp"}]',
    '[{"type": "key", "key": "Ctrl+"}]',
    '[{"type": "click", "button": "fourth"}]',
    '[{"type": "click", "mods": ["fn"]}]',
    '[{"type": "click", "x": "5"}]',
    '[{"type": "click", "z": 5}]',
    '[{"type": "text"}]',
    '[{"type": "click"}, 3]',
] as $events) {
    $n = count($d->sent);
    same(Failure::INVALID, $code($do($d, '{"id": 1, "method": "input", "params": {"events": ' . $events . '}}')), "$events: the error's code");
    same($n, count($d->sent), "$events: no event sent before it failed");
}

// menu, capture, eval and idle.
$png = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NNC5', 3, 2, 8, 6, 0, 0, 0) . "\0\0\0\0"; // as far as its size
$d = $fake();
$d->png = $png;
$d->evalFn = fn(string $s) => $s === 'throw 1' ? throw new \MyPHP\EvalError('Uncaught 1') : ['title' => 'E2E'];
$do($d, '{"id": 1, "method": "menu", "params": {"path": ["File", "New"]}}');
same([['File', 'New']], $d->menus, 'menu chooses the item of the path');
$r = $do($d, '{"id": 1, "method": "capture"}')['result'];
check($r['width'] === 3 && $r['height'] === 2 && base64_decode($r['png']) === $png, 'capture: the PNG whole, and its size');
same(['title' => 'E2E'], $do($d, '{"id": 1, "method": "eval", "params": {"script": "({title: document.title})"}}')['result']['value'], 'eval: the value');
$m = $do($d, '{"id": 1, "method": "eval", "params": {"script": "throw 1"}}');
check($code($m) === Failure::FAILED && str_contains($m['error']['message'], 'Uncaught 1'), 'eval that throws fails, with what it threw');
$d->busy = 2;
$wait = Protocol::handle($d, '{"id": 9, "method": "idle", "params": {"timeout": 60000}}');
check($wait instanceof \Closure, 'idle waits');
$got = [$wait(), $wait(), $wait()];
check($got[0] === null && $got[1] === null && $got[2] !== null && Protocol::encode($got[2]) === "{\"id\":9,\"result\":{}}\n", 'and answers once the window is idle');
$d->busy = PHP_INT_MAX;
$wait = Protocol::handle($d, '{"id": 9, "method": "idle", "params": {"timeout": 1}}');
usleep(3000);
$m = $wait();
same(Failure::TIMEOUT, $m['error']['code'] ?? '', 'idle times out');

// The names, as many as the roles, states and actions.
same(Role::HEADING - Role::GROUP + 1, count(Protocol::ROLES), 'a name for each role');
same(count(Protocol::ROLES), count(array_unique(Protocol::ROLES)), 'each its own');
same(AccessNode::VERTICAL, 1 << (count(Protocol::STATES) - 1), 'a name for each state');
same(AccessNode::ACTION_EXPAND, 1 << (count(Protocol::ACTIONS) - 1), 'a name for each action bit');
same(AccessNode::INSERT_TEXT + 1, count(Protocol::ACTION_KINDS), 'and for each action');

// The socket: its mode, answers in order, one waiting holding its connection's alone.
$dir = sys_get_temp_dir() . '/myphp-automation-' . getmypid();
@mkdir($dir);
$path = "$dir/s";
$d = $fake();
$d->access = $tree();
$d->keys = [1 << 60 => 'add'];
$s = Server::listen($path);
clearstatcache();
same(0o600, fileperms($path) & 0o777, "the socket is the user's alone");
$read = function ($c, int $lines) use ($s, $d): array {
    $out = []; $buf = '';
    for ($i = 0; $i < 200 && count($out) < $lines; $i++) {
        $s->poll($d);
        $buf .= (string)fread($c, 1 << 20);
        while (($nl = strpos($buf, "\n")) !== false) { $out[] = substr($buf, 0, $nl); $buf = substr($buf, $nl + 1); }
        if (count($out) < $lines) { usleep(1000); }
    }
    return $out;
};
$c = stream_socket_client("unix://$path");
stream_set_blocking($c, false);
fwrite($c, "{\"id\": 1, \"method\": \"tree\"}\n\n{\"id\": 2, \"method\": \"nope\"}\n");
$lines = $read($c, 2);
check(str_contains($lines[0] ?? '', '"id":1,"result":{"window":1,'), 'the first answer first, on a line of its own');
check(str_contains($lines[1] ?? '', '"id":2,"error":{"code":"unknown_method"'), 'then the second');
$d->busy = 3;
$c2 = stream_socket_client("unix://$path");
stream_set_blocking($c2, false);
fwrite($c, "{\"id\": 3, \"method\": \"idle\"}\n{\"id\": 4, \"method\": \"windows\"}\n");
usleep(1000);
$s->poll($d);
fwrite($c2, "{\"id\": 5, \"method\": \"windows\"}\n");
check(str_contains($read($c2, 1)[0] ?? '', '"id":5,'), 'a connection is answered while another waits');
$lines = $read($c, 2);
check(str_contains($lines[0] ?? '', '"id":3,"result":{}') && str_contains($lines[1] ?? '', '"id":4,'), 'the one waiting answered once idle, then the request after it');
fwrite($c, "{\"id\": 6, \"method\": \"windows\"}");
stream_socket_shutdown($c, STREAM_SHUT_WR);
check(str_contains($read($c, 1)[0] ?? '', '"id":6,'), 'the last line, without its newline, as the client closes');
fclose($c); fclose($c2);
// Another app's socket is left alone, as is a file that is no socket.
try { Server::listen($path)->close(); check(false, 'listened on a socket another app listens on'); } catch (\RuntimeException $e) { check(str_contains($e->getMessage(), 'another app listens'), 'a socket another app listens on is left alone'); }
file_put_contents("$dir/f", '');
try { Server::listen("$dir/f")->close(); check(false, 'listened in place of a file'); } catch (\RuntimeException $e) { check(str_contains($e->getMessage(), 'not a socket'), 'a file that is no socket is left alone'); }
@unlink("$dir/f");
$s->close();
check(!file_exists($path), 'the socket goes once closed');
// One left by an app that ended without removing it is replaced.
$left = stream_socket_server("unix://$path");
fclose($left);
check(file_exists($path), 'a socket left behind');
$s = Server::listen($path);
$s->close();
check(!file_exists($path), 'replaced, and gone once closed');
@rmdir($dir);

// The runtime's side: the tree of the last frame with the keys Context::key gave, whether or not assistive technology reads the window, and an action on it.
$n = 0; $name = new TextState('');
$t = new Headless(function (Context $c) use (&$n, $name) {
    $c->column(20, 10);
      $c->text("Count: $n");
      if ($c->clicked($c->key('add')->button('Add'))) { $n++; }
      $c->key(7)->input($name);
    $c->end();
});
$t->frame();
[$at, $keys] = $t->rt->automationTree();
same(null, $t->rt->accessTree, 'no assistive technology reads the window');
$byKey = array_flip(array_map('strval', $keys));
check(isset($byKey['add'], $byKey['7']), 'the keys of the nodes Context::key keyed');
$add = $byKey['add'];
check(array_filter($at->nodes, fn(AccessNode $a) => $a->id === $add && $a->role === Role::BUTTON && $a->label === 'Add') !== [], 'the button of key add, in the tree');
$t->input->push(['access', $add, AccessNode::PRESS, '', 0, 0]);
$t->frame();
same(1, $n, 'pressing it clicks it');
$t->rt->automationTree();
$t->input->push(['access', (int)$byKey['7'], AccessNode::SET_VALUE, 'Ada', 0, 0]);
$t->frame();
same('Ada', $name->text, "setting the field's value types it");
