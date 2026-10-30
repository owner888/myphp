<?php
// Clips (mygo's ui/video_test.go): a clip shows each frame as it comes
// due, asking for the frame with after(), ends at its last frame or plays
// again when it loops; a paused one holds its frame; one out of the view
// or out of sight stands and goes on from there; less motion shows the
// first frame; a clip of no frames, or whose first does not decode,
// makes no player. On macOS, GIFs decode through ImageIO.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{ClipDecoder, ClipFrame, ClipPlayer, Context, GifDecoder};

/** 1×1 frames of the colors given, a tenth of a second each, counting its rewinds and whether it closed. */
final class ClipColors implements ClipDecoder
{
    public int $next = 0, $rewinds = 0, $decoded = 0;
    public bool $closed = false;
    /** @param list<int> $colors 0xRRGGBB */
    public function __construct(private array $colors, private ?\Throwable $fail = null) {}
    public function next(): ?ClipFrame
    {
        if ($this->fail !== null) { throw $this->fail; }
        if ($this->next === count($this->colors)) { return null; }
        $c = $this->colors[$this->next++];
        $this->decoded++;
        return new ClipFrame(1, 1, pack('C4', $c >> 16, ($c >> 8) & 255, $c & 255, 255), 0.1);
    }
    public function rewind(): void { $this->next = 0; $this->rewinds++; }
    public function close(): void { $this->closed = true; }
}

const RED = 0xff0000, GREEN = 0x00ff00, BLUE = 0x0000ff;

(function () {
    $now = 0;
    $clip = function (ClipPlayer $p, ?bool &$visible = null) use (&$now): array {
        $visible ??= true;
        $h = poseTester(function (Context $c) use ($p, &$visible): void { if ($visible) { $c->clip($p, 16, 16); } }, $now, 100, 100);
        $shows = function () use ($p): int { $px = (fn() => $this->image)->call($p)->pixels(); return (ord($px[0]) << 16) | (ord($px[1]) << 8) | ord($px[2]); };
        return [$h, $shows];
    };
    $step = function ($h, float $s) use (&$now): void { $now += (int)round($s * 1e9); $h->frame(1); };

    // It plays each frame as it comes due, asking for the frame with after(), and ends at its last.
    $d = new ClipColors([RED, GREEN, BLUE]);
    $p = new ClipPlayer($d);
    [$h, $shows] = $clip($p);
    same(RED, $shows(), 'clip: the first frame');
    check(abs($h->rt->nextWake() - 0.1) < 1e-6, 'clip: the next frame asked for in 100 ms: ' . $h->rt->nextWake());
    $step($h, 0.099);
    same(RED, $shows(), 'clip: before the second is due');
    $step($h, 0.001);
    same(GREEN, $shows(), 'clip: as the second is due');
    $step($h, 0.1);
    same(BLUE, $shows(), 'clip: the third');
    $step($h, 1.0);
    same([BLUE, false, null, null], [$shows(), $p->playing(), $p->err(), $h->rt->nextWake()], 'clip: ended at its last frame, asking for none');
    // Played again, it starts over.
    $p->play();
    $h->frame(1);
    same([RED, true], [$shows(), $p->playing()], 'clip: played again, from its start');
    $step($h, 0.1);
    same(GREEN, $shows(), 'clip: played again, the second frame');
    // Looping, the first frame follows the last.
    $p = (new ClipPlayer(new ClipColors([RED, GREEN])))->setLoop(true);
    [$h, $shows] = $clip($p);
    $seen = [];
    for ($i = 0; $i < 4; $i++) { $step($h, 0.1); $seen[] = $shows(); }
    same([GREEN, RED, GREEN, RED], $seen, 'clip: looping');
    check($p->playing(), 'clip: a looping one plays on');
    // Late, the frames that came due meanwhile pass over: the last shows, where the clock puts it.
    $p = new ClipPlayer(new ClipColors([RED, GREEN, BLUE, RED]));
    [$h, $shows] = $clip($p);
    $step($h, 0.25);
    same(BLUE, $shows(), 'clip: late, the frame the clock puts it at');
    check(abs($h->rt->nextWake() - 0.05) < 1e-6, 'clip: and the next as it comes due');

    // Paused, it holds its frame and asks for none, and goes on from it.
    $p = new ClipPlayer(new ClipColors([RED, GREEN, BLUE]));
    [$h, $shows] = $clip($p);
    $step($h, 0.15);
    $p->pause();
    $h->frame(1);
    same([GREEN, false, null], [$shows(), $p->playing(), $h->rt->nextWake()], 'clip: paused');
    $now += 10_000_000_000;
    $p->play();
    $h->frame(1);
    same(GREEN, $shows(), 'clip: played after a pause, at its frame');
    $step($h, 0.05);
    same(BLUE, $shows(), 'clip: the rest of the frame after the pause');

    // Out of the view it stands, and goes on from there.
    $p = new ClipPlayer(new ClipColors([RED, GREEN, BLUE]));
    $visible = true;
    [$h, $shows] = $clip($p, $visible);
    $step($h, 0.15);
    same(GREEN, $shows(), 'clip: playing');
    $visible = false;
    $h->frame(1);
    same(null, $h->rt->nextWake(), 'clip: out of the view, no frame asked for');
    $now += 10_000_000_000;
    $visible = true;
    $h->frame(1);
    same(GREEN, $shows(), 'clip: back in the view, where it stood');
    $step($h, 0.1);
    same(BLUE, $shows(), 'clip: back in the view, the next frame');
    // Out of sight, the same; the time it showed after its last frame before does not count once some of the window shows again.
    $p = new ClipPlayer(new ClipColors([RED, GREEN, BLUE]));
    [$h, $shows] = $clip($p);
    $now += 50_000_000; // shown 50 ms, no frame built, then out of sight, where none builds, for 10 s
    $now += 10_000_000_000;
    $h->rt->occluded = false;
    $h->rt->shownAt = $now;
    $h->frame(1);
    $step($h, 0.099);
    same(RED, $shows(), 'clip: shown again, the first frame for 100 ms more');
    $step($h, 0.001);
    same(GREEN, $shows(), 'clip: shown again, the second');

    // A frame built out of sight, as for a key typed into a covered window: it stands, and asks for its next frame, which waits for the window.
    $p = new ClipPlayer(new ClipColors([RED, GREEN, BLUE]));
    [$h, $shows] = $clip($p);
    $h->rt->occluded = true;
    $h->frame(1);
    $now += 1_000_000_000;
    $h->frame(1);
    same([RED, true], [$shows(), $h->rt->nextWake() !== null], 'clip: out of sight, it stands, its next frame asked for');

    // Less motion: the first frame, nothing decoded; then on from where it stood.
    $d = new ClipColors([RED, GREEN, BLUE]);
    $p = new ClipPlayer($d);
    [$h, $shows] = $clip($p);
    $step($h, 0.15);
    $h->rt->reduceMotion = true; // as the preferences change: a frame at once
    $before = $d->decoded;
    $h->frame(1);
    same([RED, $before, null], [$shows(), $d->decoded, $h->rt->nextWake()], 'clip: less motion, the first frame, nothing decoded, no frame asked for');
    $now += 1_000_000_000;
    $h->rt->reduceMotion = false;
    $h->frame(1);
    same(GREEN, $shows(), 'clip: motion again, where it stood');
    $step($h, 0.05);
    same(BLUE, $shows(), 'clip: motion again, the next frame');

    // Errors: none frames, a first frame that fails; a later one that fails ends it at the frame before.
    $err = null;
    try { new ClipPlayer(new ClipColors([])); } catch (\RuntimeException $e) { $err = $e->getMessage(); }
    same('myphp: a clip of no frames', $err, 'clip: of no frames, no player');
    $err = null;
    try { new ClipPlayer(new ClipColors([RED], new \RuntimeException('bad'))); } catch (\RuntimeException $e) { $err = $e->getMessage(); }
    same('bad', $err, 'clip: a first frame that does not decode, no player');
    $d = new ClipColors([RED]);
    $p = new ClipPlayer($d);
    $p->close();
    check($d->closed && !$p->playing(), 'clip: closed, its decoder too');

    // On macOS, a GIF: its frames, composed as their disposal says, for as long as their delays say.
    if (PHP_OS_FAMILY === 'Darwin') {
        $gif = function (array $colors, int $delay): string { // 2×2 frames of solid colors, delay hundredths of a second each
            $g = "GIF89a" . pack('vvCCC', 2, 2, 0, 0, 0) . "\x21\xff\x0bNETSCAPE2.0\x03\x01\x00\x00\x00";
            foreach ($colors as $c) {
                $g .= "\x21\xf9\x04\x00" . pack('v', $delay) . "\x00\x00"; // no disposal, the delay, no transparency
                $g .= "\x2c" . pack('vvvvC', 0, 0, 2, 2, 0x80) . pack('C6', $c >> 16, ($c >> 8) & 255, $c & 255, 0, 0, 0); // a local table of 2
                $g .= "\x02\x02\x84\x51\x00"; // LZW: min code size 2, four pixels of index 0 (clear, 0, 6, 0, end)
            }
            return $g . "\x3b";
        };
        $d = new GifDecoder($gif([RED, GREEN, BLUE], 5));
        $got = [];
        while (($f = $d->next()) !== null) { $got[] = [$f->w, $f->h, (ord($f->rgba[0]) << 16) | (ord($f->rgba[1]) << 8) | ord($f->rgba[2]), round($f->duration, 3)]; }
        same([[2, 2, RED, 0.05], [2, 2, GREEN, 0.05], [2, 2, BLUE, 0.05]], $got, 'GIF: its frames and their delays');
        $d->rewind();
        same(RED, (ord($d->next()->rgba[0]) << 16), 'GIF: rewound, the first again');
        same(0.1, round((new GifDecoder($gif([RED], 1)))->next()->duration, 3), 'GIF: a frame of 10 ms or less shows for 100 ms');
        $err = null;
        try { new GifDecoder('PNG'); } catch (\RuntimeException $e) { $err = $e->getMessage(); }
        same('myphp: not a GIF', $err, 'GIF: what is none refused');
    }
})();
