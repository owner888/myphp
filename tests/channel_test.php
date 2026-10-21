<?php
// Channels' flow control (mygo's channel.go): a value asks the page to
// acknowledge it every half window, and a channel a window ahead of the
// page is full until the page says it took enough.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\{Channel, ChannelClosed};

(function () {
    $sent = [];
    $forgotten = 0;
    $ch = new Channel(function (array $m) use (&$sent) { $sent[] = $m; }, function () use (&$forgotten) { $forgotten++; }, 7, 's');
    $chunk = str_repeat('x', 100_000);
    for ($i = 0; $i < 12; $i++) { $ch->send($chunk); }
    $asks = array_keys(array_filter($sent, fn($m) => ($m['a'] ?? 0) === 1));
    same([5, 11], $asks, 'every half window a value asks for an acknowledgment');
    check($ch->full(), 'a window ahead of the page');
    $room = 0;
    $ch->whenRoom(function () use (&$room) { $room++; });
    same(0, $room, 'waits for room');
    $ch->ack(6);
    same(1, $room, 'the page took the first half: room');
    check(!$ch->full(), 'no longer full');
    $ch->whenRoom(function () use (&$room) { $room++; });
    same(2, $room, 'room at once');
    for ($i = 0; $i < 6; $i++) { $ch->send($chunk); }
    check($ch->full(), 'full again');
    $ch->whenRoom(function () use (&$room) { $room++; });
    $ch->closedByPage();
    same([3, 1], [$room, $forgotten], 'a channel the page closed wakes its waiter');
    try { $ch->send(1); check(false, 'sent on a closed channel'); } catch (ChannelClosed) { check(true, 'a closed channel throws'); }
    $ch2 = new Channel(function (array $m) use (&$sent) { $sent[] = $m; }, fn() => null, 8, 's');
    $ch2->close();
    same(['t' => 'chan', 'c' => 8, 'k' => 's', 'end' => true], end($sent), 'close tells the page');
})();
