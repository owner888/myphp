<?php
// Text subtitles (Media\Subtitles, Media\Cues): SubRip, ASS and WebVTT
// files, in UTF-8, UTF-16 and GB18030, as plain lines; the text of
// Matroska's and MP4's subtitle packets; the cues showing at a time; a
// Matroska file's subtitle tracks, which ffmpeg muxed from such files.
// Image subtitles (Media\Pgs, Media\VobSub): a PGS track of a .sup
// drawn and encoded for the test, and the VobSub ffmpeg made of it.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Media\{Cues, Matroska, Pgs, Subtitles, SubtitlePicture, Track, VobSub};

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
