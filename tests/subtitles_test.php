<?php
// Text subtitles (Media\Subtitles, Media\Cues): SubRip, ASS and WebVTT
// files, in UTF-8, UTF-16 and GB18030, as plain lines; the text of
// Matroska's and MP4's subtitle packets; the cues showing at a time; a
// Matroska file's subtitle tracks, which ffmpeg muxed from such files.
// Image subtitles (Media\Pgs, Media\VobSub): a PGS track of a .sup
// drawn and encoded for the test, and the VobSub ffmpeg made of it.
// ASS lines in their styles (Media\Ass): a header's PlayRes and styles,
// override tags, a file's and a Matroska track's lines.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Media\{Ass, AssLine, AssRun, Cues, Matroska, Pgs, Subtitles, SubtitlePicture, Track, VobSub};

$subs = function (string $ext, string $bytes): array {
    $f = sys_get_temp_dir() . '/myphp-subs-' . getmypid() . ".$ext";
    file_put_contents($f, $bytes);
    try { return Subtitles::file($f); } finally { unlink($f); }
};
$srt = "1\r\n00:00:00,200 --> 00:00:00,900\r\n第一句 <i>字幕</i>\r\n\r\n2\r\n00:00:01,000 --> 00:00:01,800\r\nSecond line<br>two rows &amp; more\r\n";
same(['S_TEXT/UTF8', [[0.2, 0.9, '第一句 字幕'], [1.0, 1.8, "Second line\ntwo rows & more"]]], $subs('srt', $srt), 'SubRip: its cues, markup left out, <br> a new line');
same($subs('srt', $srt), $subs('srt', mb_convert_encoding($srt, 'GB18030', 'UTF-8')), 'a SubRip file in GB18030 reads the same');
same($subs('srt', $srt), $subs('srt', "\xFF\xFE" . mb_convert_encoding($srt, 'UTF-16LE', 'UTF-8')), 'and one in UTF-16 with its byte order mark');
same($subs('srt', $srt), $subs('srt', "\xEF\xBB\xBF$srt"), 'and one in UTF-8 with one');
$vtt = "WEBVTT\n\nNOTE a comment\n\nintro\n00:01.500 --> 00:02.000 align:start\n<c.yellow>Hello</c>\n\n01:00:00.000 --> 01:00:01.250\nlate\n";
same(['S_TEXT/WEBVTT', [[1.5, 2.0, 'Hello'], [3600.0, 3601.25, 'late']]], $subs('vtt', $vtt), 'WebVTT: hours optional, settings and notes left out');
$ass = "[Script Info]\nScriptType: v4.00+\n\n[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\nComment: 0,0:00:00.00,0:00:05.00,Default,,0,0,0,,not shown\nDialogue: 0,0:00:01.00,0:00:02.50,Default,,0,0,0,,{\\an8\\i1}One, with commas\\Nand two{\\i0}\\hend\nDialogue: 0,0:00:00.30,0:00:01.20,Default,,0,0,0,,first\n";
same(['S_TEXT/ASS', [[0.3, 1.2, 'first'], [1.0, 2.5, "One, with commas\nand two end"]]], $subs('ass', $ass), 'ASS: its dialogue by its Format line, by start; tags left out, \N a new line, \h a space');
same("ASS 一行\n第二行", Subtitles::packet('S_TEXT/ASS', '0,0,Default,,0,0,0,,{\an8}ASS 一行\N第二行'), 'a Matroska ASS packet: its text after the eight fields');
same("line\nnext", Subtitles::packet('S_TEXT/UTF8', "line\r\nnext"), 'a SubRip packet');
same('Hi', Subtitles::packet('S_TEXT/TX3G', "\x00\x02Hi\x00\x00\x00\x0cstylxxxxxx"), 'an MP4 tx3g sample: its text, its style boxes left out');

$c = new Cues();
$c->add(1.0, 2.0, 'a'); $c->add(1.5, 3.0, 'b'); $c->add(4.0, INF, 'c'); $c->add(6.0, 7.0, 'd'); $c->add(1.0, 2.0, 'a');
same([[], ['a'], ['a', 'b'], ['b'], ['c'], ['c'], ['d'], []], [$c->at(0.5), $c->at(1.2), $c->at(1.7), $c->at(2.5), $c->at(4.0), $c->at(5.9), $c->at(6.5), $c->at(9)], 'the cues showing: together where they overlap, one of no end until the next');
same(4, $c->count(), 'a cue read again is kept once');
same([1.0, 1.5, 2.0, 3.0, 4.0, 6.0, 7.0, null], [$c->next(0.5), $c->next(1.2), $c->next(1.7), $c->next(2.5), $c->next(3.5), $c->next(5.0), $c->next(6.5), $c->next(7.0)], 'when the text showing changes next: a cue starting, or one showing ending');

$m = new Matroska(__DIR__ . '/testdata/clip-subs.mkv');
$tracks = array_values(array_filter($m->tracks(), fn(Track $t) => $t->type === Track::SUBTITLE));
same([['S_TEXT/UTF8', 'chi', true], ['S_TEXT/ASS', 'eng', false]], array_map(fn(Track $t) => [$t->codec, $t->language, $t->isDefault], $tracks), 'a Matroska file\'s subtitle tracks');
check(Subtitles::readable($tracks[0]) && Subtitles::readable($tracks[1]), 'both read');
$got = [];
while (($p = $m->next()) !== null) {
    if ($p->track->type === Track::SUBTITLE) { $got[] = [$p->track->codec, $p->time, round($p->duration, 3), Subtitles::packet($p->track->codec, $p->data)]; }
}
same([['S_TEXT/UTF8', 0.2, 0.7, '第一句 字幕'], ['S_TEXT/ASS', 0.3, 0.9, "ASS 一行, 带逗号\n第二行"], ['S_TEXT/UTF8', 1.0, 0.8, "Second line\ntwo rows"], ['S_TEXT/ASS', 1.0, 0.5, 'overlapping']], $got, 'their cues, timed by their block groups');

// PGS runs: a color's byte one pixel, 0 0 a line's end, 0 then L clear, 0 then 0x80|L C of C, 0x40 for a 14-bit L.
$colors = [1 => 'WWWW', 2 => 'BBBB'];
same('WWWW' . "\0\0\0\0" . 'BBBBBBBB' . str_repeat("\0\0\0\0", 4) . 'WWWWWWWWWWWW' . "\0\0\0\0", Pgs::decode(4, 3, "\x01\x00\x01\x00\x82\x02\x00\x00\x00\x04\x00\x00\x00\x83\x01", $colors), 'PGS runs: pixels, a clear run, a run of a color, lines ended, the last short and the rest clear');
same(str_repeat('WWWW', 70), Pgs::decode(70, 1, "\x00\xc0\x46\x01\x00\x00", $colors), 'a run of 14 bits');

// A PGS track: display sets of their compositions' pictures where they show on the 1920 by 1080 plane, in the palette's colors; sets of none clear.
$m = new Matroska(__DIR__ . '/testdata/clip-pgs.mkv');
$pgsTrack = array_values(array_filter($m->tracks(), fn(Track $t) => $t->type === Track::SUBTITLE))[0];
same(['S_HDMV/PGS', 'chi', true], [$pgsTrack->codec, $pgsTrack->language, Subtitles::readable($pgsTrack)], 'a PGS track, read');
$pgs = new Pgs(); $sets = []; $cues = new Cues();
while (($p = $m->next()) !== null) {
    if ($p->track !== $pgsTrack) { continue; }
    $pics = $pgs->displaySet($p->data);
    $cues->add($p->time, INF, $pics);
    $sets[] = [$p->time, array_map(fn(SubtitlePicture $q) => [$q->x, $q->y, $q->width, $q->height, $q->planeWidth, $q->planeHeight], $pics)];
}
same([[0.2, [[713, 900, 494, 78, 1920, 1080]]], [1.0, []], [1.2, [[770, 120, 380, 69, 1920, 1080], [755, 900, 410, 82, 1920, 1080]]], [1.9, []]], $sets, 'its display sets: a picture, a clear, two pictures, a clear');
$first = $cues->at(0.5)[0][0];
$px = $first->rgba();
same(494 * 78 * 4, strlen($px), 'its pixels, four bytes each');
$white = substr_count(implode('', str_split($px, 4)), "\xff\xff\xff\xff"); $black = substr_count(implode('', str_split($px, 4)), "\x00\x00\x00\xff");
check($white > 1000 && $black > 1000 && $white + $black + substr_count(implode('', str_split($px, 4)), "\0\0\0\0") === 494 * 78, "white text outlined in black on clear, as drawn (Y 235 and 16 of BT.709 in limited range): $white white, $black black");
same([1, 0, 2, 0], [count($cues->at(0.5)[0]), count($cues->at(1.1)), count($cues->at(1.5)[0]), count($cues->at(1.95))], 'its cues: until the next set, a clear one showing nothing');

// A VobSub track: the .idx's plane and palette; a subpicture's bitmap of two fields, shown and stopped as its control sequences say.
$m = new Matroska(__DIR__ . '/testdata/clip-vobsub.mkv');
$vobTrack = array_values(array_filter($m->tracks(), fn(Track $t) => $t->type === Track::SUBTITLE))[0];
check($vobTrack->codec === 'S_VOBSUB' && Subtitles::readable($vobTrack) && str_starts_with($vobTrack->private, 'size: 1920x1080'), 'a VobSub track, read, its .idx');
$vob = new VobSub($vobTrack->private); $got = []; $firstVob = null;
while (($p = $m->next()) !== null) {
    if ($p->track !== $vobTrack) { continue; }
    [$from, $to, $pic] = $vob->packet($p->data);
    $firstVob ??= $pic;
    $got[] = [$p->time, $from, round($to, 2), [$pic->x, $pic->y, $pic->width, $pic->height, $pic->planeWidth, $pic->planeHeight]];
}
same([[0.2, 0.0, 0.8, [713, 900, 494, 78, 1920, 1080]], [1.2, 0.0, 0.69, [755, 120, 410, 862, 1920, 1080]]], $got, 'its subpictures: when they show and stop, where they are (the two lines of the second one picture)');
$alpha = fn(string $rgba): string => implode('', array_map(fn(string $q) => $q[3] === "\0" ? '.' : '#', str_split($rgba, 4)));
same($alpha($px), $alpha($firstVob->rgba()), 'the VobSub ffmpeg made of the PGS: the same pixels shown, its fields interlaced');

// ASS: PlayRes as libass takes it when the header leaves it out; styles by their Format line; override tags over them.
same([384, 288], [(new Ass(''))->playResX, (new Ass(''))->playResY], 'no PlayRes: 384 by 288');
same([1280, 1024], [(new Ass("[Script Info]\nPlayResY: 1024"))->playResX, (new Ass("[Script Info]\nPlayResY: 1024"))->playResY], 'PlayResY 1024 alone: 1280 wide');
same([640, 480], [(new Ass("[Script Info]\nPlayResX: 640"))->playResX, (new Ass("[Script Info]\nPlayResX: 640"))->playResY], 'PlayResX alone: 4:3');
$assTrack = array_values(array_filter((new Matroska(__DIR__ . '/testdata/clip-ass.mkv'))->tracks(), fn(Track $t) => $t->type === Track::SUBTITLE))[0];
$ass = new Ass($assTrack->private);
same([1920, 1080, true, true], [$ass->playResX, $ass->playResY, $ass->scaledBorder, $ass->wraps], 'the fixture\'s header');
$run = fn(AssRun $r) => [$r->text, $r->font, $r->size, $r->weight, $r->italic, $r->underline, sprintf('%06x', $r->color), round($r->alpha, 2), sprintf('%06x', $r->outlineColor), $r->border, $r->shadow, $r->borderStyle];
$l = $ass->line('A {\c&H0000FF&\b1}red{\r} and {\i1\u1}more{\i0\u0}\Ntwo', 'Default');
same([['A ', 'Arial', 72.0, 400, false, false, 'ffffff', 1.0, '000000', 4.0, 3.0, 1], ['red', 'Arial', 72.0, 700, false, false, 'ff0000', 1.0, '000000', 4.0, 3.0, 1], [' and ', 'Arial', 72.0, 400, false, false, 'ffffff', 1.0, '000000', 4.0, 3.0, 1],
    ['more', 'Arial', 72.0, 400, true, true, 'ffffff', 1.0, '000000', 4.0, 3.0, 1], ["\n", 'Arial', 72.0, 400, false, false, 'ffffff', 1.0, '000000', 4.0, 3.0, 1], ['two', 'Arial', 72.0, 400, false, false, 'ffffff', 1.0, '000000', 4.0, 3.0, 1]],
    array_map($run, $l->runs), 'runs of the style and its tags: \c in BGR, \b, \r back to the style, \i \u, \N a break');
same([2, null, 40, 40, 60, "A red and more\ntwo"], [$l->alignment, $l->position, $l->marginLeft, $l->marginRight, $l->marginVertical, $l->text()], 'its alignment, margins and text');
$l = $ass->line('{\an7\pos(100,200)\fnGeorgia\fs30\3c&HFF0000&\bord1\shad0\alpha&H80&}x', 'Box', 2, 5, 6, 7);
same([[['x', 'Georgia', 30.0, 400, true, false, 'ffffff', 0.5, '0000ff', 1.0, 0.0, 3]], 7, [100.0, 200.0], [5, 6, 7], 2], [array_map($run, $l->runs), $l->alignment, $l->position, [$l->marginLeft, $l->marginRight, $l->marginVertical], $l->layer], '\an \pos \fn \fs \3c \bord \shad \alpha, BorderStyle 3; the event\'s margins and layer');
$l = $ass->line('{\t(0,500,\fs80)\move(10,20,300,400)\fad(100,100)\k20}moving{\p1}m 0 0 l 10 10{\p0}', 'Nope');
same([[10.0, 20.0], 72.0, 'moving', [['m', 0.0, 0.0], ['l', 10.0, 10.0], ['c']]], [$l->position, $l->runs[0]->size, $l->text(), $l->runs[1]->drawing], '\move at its start; \t \fad \k left out; a drawing a run of no text; a style it has not: Default');
// Drawings (\p), as libass reads them: their level's scale, lines, Beziers, a B-spline closed, \pbo; one of no points shows nothing.
$d = $ass->line('{\an7\pos(0,0)\p2\pbo4}m 0 0 l 200 0 200 100 b 100 150 50 150 0 100', 'Default')->runs[0];
same([[['m', 0.0, 0.0], ['l', 100.0, 0.0], ['l', 100.0, 50.0], ['b', 50.0, 75.0, 25.0, 75.0, 0.0, 50.0], ['c']], [0.0, 0.0, 100.0, 75.0], 4.0, ''],
    [$d->drawing, $d->drawingBounds(), $d->baselineOffset, $d->text], '\p2 halves: m, l of two points, b; its bounds; \pbo');
$sp = $ass->line('{\p1}m 0 0 s 60 0 60 60 0 60 c', 'Default')->runs[0]->drawing;
same([6, 'b', ['c']], [count($sp), $sp[1][0], $sp[5]], 's: a B-spline from the pen, c closing it with its first three points again (four segments, Beziers)');
same([60.0, 20.0], [$sp[1][1], $sp[1][2]], 'a B-spline segment as a Bezier: its first control point (2 P1 + P2) / 3');
same(null, $ass->line('{\p1}m 0 0', 'Default'), 'a drawing of one point shows nothing');
// \frx \fry \fax \fay \blur \be: a run's; turned in 3D or sheared, a line laid out on its own; \t animates them, over the whole line when it gives no times (libass's).
$l = $ass->line('{\frx30\fry-20\fax0.5\fay-0.25\blur3\be2\t(\blur9)}x', 'Default', 0, 0, 0, 0, 0.0, 2.0);
$r = $l->runs[0];
same([30.0, -20.0, 0.5, -0.25, 3.0, 2, true], [$r->rotationX, $r->rotationY, $r->shearX, $r->shearY, $r->blur, $r->edgeBlur, $l->alone()], 'the tags a run\'s; it alone');
same([6.0, 9.0], [$l->at(1.0)->runs[0]->blur, $l->at(2.0)->runs[0]->blur], '\t of no times: over the line\'s two seconds');
// \kf and \K sweep: how far across its syllable as it is sung, the secondary before, the primary after.
$k = $ass->line('{\1c&H0000FF&\2c&HFF0000&\K50}ab{\kf50}cd', 'Default');
same([[0.5, null, 0x0000ff], [null, 0.5, 0xff0000]], [[$k->at(0.25)->runs[0]->sweep, $k->at(0.25)->runs[1]->sweep, $k->at(0.25)->runs[1]->color], [$k->at(0.75)->runs[0]->sweep, $k->at(0.75)->runs[1]->sweep, $k->at(0.75)->runs[0]->color]],
    'half way through the first syllable, the second in the secondary; then the second half swept, the first in the primary');
same([0x0000ff, 1.0], [$k->runs[0]->secondary, $k->runs[0]->secondaryAlpha], 'a run knows its secondary, which the sweep starts from');
// \clip and \iclip: a rectangle, or a drawing of a scale; \iclip what it covers cut away; \t moves a rectangle toward another.
$c1 = $ass->line('{\\iclip(2,m 0 0 l 20 0 20 20)}x', 'Default');
same([null, [['m', 0.0, 0.0], ['l', 10.0, 0.0], ['l', 10.0, 10.0], ['c']], true, true], [$c1->clip, $c1->clipDrawing, $c1->clipInverted, $c1->alone()], '\\iclip of a drawing, its scale 2: halved, cut away, alone');
same([[1.0, 2.0, 3.0, 4.0], true], [$ass->line('{\\iclip(3,4,1,2)}x', 'Default')->clip, $ass->line('{\\iclip(3,4,1,2)}x', 'Default')->clipInverted], '\\iclip of a rectangle, its corners in order');
$c2 = $ass->line('{\\clip(0,0,100,100)\\t(0,1000,\\clip(100,100,200,200))}x', 'Default', 0, 0, 0, 0, 0.0, 2.0);
same([[0.0, 0.0, 100.0, 100.0], [50.0, 50.0, 150.0, 150.0], [100.0, 100.0, 200.0, 200.0]], [$c2->clip, $c2->at(0.5)->clip, $c2->at(1.5)->clip], '\\t(\\clip): halfway at half a second, there after its second');
same(true, $ass->line('x{\\frz10}y', 'Default')->alone(), 'a run turned past the first: the line alone');
same(8, $ass->line('{\a6}top', 'Default')->alignment, "SSA's \a6: top center");

// A Matroska ASS track's packets read in its header's styles; a file's lines; cues of lines read again kept once.
$m = new Matroska(__DIR__ . '/testdata/clip-ass.mkv');
$at = array_values(array_filter($m->tracks(), fn(Track $t) => $t->type === Track::SUBTITLE))[0];
$cues = new Cues(); $h = new Ass($at->private);
while (($p = $m->next()) !== null) { if ($p->track === $at) { $cues->add($p->time, $p->time + $p->duration, Subtitles::assPacket($h, $p->data)); } }
$shown = $cues->at(1.0);
same(["白色描边 红色 与斜体\n第二行 粗体 下划线", 'Top: yellow, bold, dark blue outline', 'At three quarters', 'A box behind'], array_map(fn(AssLine $l) => $l->text(), $shown), "the fixture's lines at 1 s");
same([2, 8, 5, 7], array_map(fn(AssLine $l) => $l->alignment, $shown), 'at the bottom, the top, the middle at \pos, and the top left');
$cues->add(0.1, 1.9, $shown[0]);
same(4, count($cues->at(1.0)), 'a line read again: once');
$file = Subtitles::assFile(__DIR__ . '/testdata/styled.ass');
same([[0.1, 1.9, 2], [0.1, 1.9, 8], [0.1, 1.9, 5], [0.5, 1.9, 7]], array_map(fn(array $q) => [$q[0], $q[1], $q[2]->alignment], $file), 'an .ass file\'s lines in their styles');

// ASS that changes as it shows (AssLine::at): \move between its points, \fad in and out, \t to its tags, \k's syllables sung; \frz \org \fscx \clip on lines laid out alone.
$motion = [];
foreach (Subtitles::assFile(__DIR__ . '/testdata/motion.ass') as [$start, $end, $l]) { $motion[$l->text()] = $l; }
$mv = $motion['Moving across'];
same([true, [300.0, 120.0], [0.0, 0.0], [660.0, 0.0], [1320.0, 0.0]], [$mv->changes(), $mv->at(1.0)->position, $mv->at(0)->moved, $mv->at(1.0)->moved, $mv->at(2.0)->moved], '\move: at its start, moved from it to its end over the line\'s time (as a whole, to glide)');
$fd = $motion['Fading in and out'];
same([0.0, 0.5, 1.0, 1.0, 0.5, 0.0], array_map(fn(float $t) => round($fd->at($t)->alpha, 3), [0.0, 0.4, 0.8, 1.2, 1.6, 2.0]), '\fad(800,800): in from clear, out to clear at its end');
$gy = $motion['Growing yellow'];
same([[64.0, 'ffffff'], [87.0, 'ffff80'], [110.0, 'ffff00']], array_map(fn(float $t) => [round($gy->at($t)->runs[0]->size, 1), sprintf('%06x', $gy->at($t)->runs[0]->color)], [0.0, 1.0, 2.0]), '\t(0,2000,\fs110\c): its size and color between');
$ka = $motion['Karaoke'];
$sung = fn(float $t) => implode('', array_map(fn(AssRun $r) => $r->color === 0xffffff ? strtoupper($r->text) : strtolower($r->text), $ka->at($t)->runs));
same(['KAraoke', 'KARAoke', 'KARAOKE'], [$sung(0.2), $sung(0.5), $sung(1.6)], '\k50: each syllable in the secondary colour until sung, as libass at 0.5 s');
same([20.0, [480.0, 900.0], true], [$motion['Turned twenty']->runs[0]->rotation, $motion['Turned twenty']->origin, $motion['Turned twenty']->alone()], '\frz around \org');
same([1.6, 1.0], [$motion['Wide']->runs[0]->scaleX, $motion['Wide']->runs[0]->scaleY], '\fscx160');
same([760.0, 900.0, 1160.0, 1000.0], $motion['Clipped text here']->clip, '\clip, a rectangle');
same(false, (new Ass(''))->line('still', 'Default')->changes(), 'a line of none: the same at every time');
