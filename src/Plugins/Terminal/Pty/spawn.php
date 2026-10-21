<?php
// The child of a pseudo-terminal (Pty::start): run with the terminal's
// slave as its stdin, stdout and stderr, it makes a session of its own whose
// controlling terminal that is, so that the terminal's signals reach the
// process group in the foreground, drops the other descriptors it got, and
// becomes the program, as mygo's exec.Cmd with Setsid and Setctty does.
declare(strict_types=1);

$o = json_decode(base64_decode($argv[1] ?? ''), true);
if (!is_array($o)) {
    fwrite(STDERR, "pty: no program to run\n");
    exit(127);
}
$c = FFI::cdef('int setsid(void); int ioctl(int, unsigned long, ...); int close(int); int chdir(const char*);
    int execve(const char *path, char *const argv[], char *const envp[]); char *strerror(int);');
$c->setsid();
$c->ioctl(0, PHP_OS_FAMILY === 'Darwin' ? 0x20007461 : 0x540E, 0); // TIOCSCTTY
for ($fd = 3; $fd < 256; $fd++) { $c->close($fd); }
if ($o['dir'] !== '' && $c->chdir($o['dir']) !== 0) {
    fwrite(STDERR, "pty: cannot change to {$o['dir']}\n");
}
$strings = function (array $list) use ($c): array {
    $arr = $c->new('char*[' . (count($list) + 1) . ']', false);
    $keep = [];
    foreach (array_values($list) as $i => $s) {
        $keep[] = $b = $c->new('char[' . (strlen($s) + 1) . ']', false);
        FFI::memcpy($b, $s . "\0", strlen($s) + 1);
        $arr[$i] = $c->cast('char*', FFI::addr($b[0]));
    }
    $arr[count($list)] = null;
    return [$arr, $keep];
};
[$argv2] = $strings($o['args']);
[$envp] = $strings($o['env']);
$c->execve($o['path'], $argv2, $envp);
fwrite(STDERR, "pty: cannot run {$o['path']}\n");
exit(127);
