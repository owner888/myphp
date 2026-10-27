<?php
// Text subtitles (Media\Subtitles, Media\Cues): SubRip, ASS and WebVTT
// files, in UTF-8, UTF-16 and GB18030, as plain lines; the text of
// Matroska's and MP4's subtitle packets; the cues showing at a time; a
// Matroska file's subtitle tracks, which ffmpeg muxed from such files.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Media\{Cues, Matroska, Subtitles, Track};

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

$m = new Matroska(__DIR__ . '/testdata/clip-subs.mkv');
$tracks = array_values(array_filter($m->tracks(), fn(Track $t) => $t->type === Track::SUBTITLE));
same([['S_TEXT/UTF8', 'chi', true], ['S_TEXT/ASS', 'eng', false]], array_map(fn(Track $t) => [$t->codec, $t->language, $t->isDefault], $tracks), 'a Matroska file\'s subtitle tracks');
check(Subtitles::readable($tracks[0]) && Subtitles::readable($tracks[1]), 'both read');
$got = [];
while (($p = $m->next()) !== null) {
    if ($p->track->type === Track::SUBTITLE) { $got[] = [$p->track->codec, $p->time, round($p->duration, 3), Subtitles::packet($p->track->codec, $p->data)]; }
}
same([['S_TEXT/UTF8', 0.2, 0.7, '第一句 字幕'], ['S_TEXT/ASS', 0.3, 0.9, "ASS 一行, 带逗号\n第二行"], ['S_TEXT/UTF8', 1.0, 0.8, "Second line\ntwo rows"], ['S_TEXT/ASS', 1.0, 0.5, 'overlapping']], $got, 'their cues, timed by their block groups');
