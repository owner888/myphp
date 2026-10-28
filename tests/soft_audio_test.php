<?php
// Vorbis decoded in PHP for the player (Native\SoftAudio, by the codec of
// the app's own sound, Audio\Vorbis\Codec): a Matroska track's headers
// unlaced from its CodecPrivate, its packets' frames as the Ogg decoder
// gives them of the same stream (the first packet completing none), as
// float PCM; a 5.1 track's channels put in WAVE's order, the center third;
// a seek's reset completing none again; and an external audio file's
// tracks added to a film (Media\Merged::add, mpv's audio-add): the
// film's tracks as they were, the file's audio renumbered, named after
// it, its frames in the order of the times with the film's.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Media\{Matroska, Track};
use MyPHP\Native\SoftAudio;

$audio = fn(Matroska $m): Track => array_values(array_filter($m->tracks(), fn(Track $t) => $t->type === Track::AUDIO))[0];
$m = new Matroska(__DIR__ . '/testdata/clip-vorbis.mkv');
$a = $audio($m);
check(SoftAudio::wanted($a) && \MyPHP\Media\Player::playable($a), 'a Vorbis track: decoded so, and playable');
$s = new SoftAudio($a);
same([2, 48000], [$s->channels(), $s->rate()], 'its channels and rate, of its identification header');
$counts = []; $frames = 0; $pcm = '';
while (($p = $m->next()) !== null) {
    if ($p->track !== $a) { continue; }
    [$b, $n] = $s->decode($p->data);
    $counts[] = $n; $frames += $n; $pcm .= $b;
}
same(0, $counts[0], 'the first packet completes no frames');
same($frames * 2 * 4, strlen($pcm), 'float PCM, interleaved: 4 bytes a sample');
check(abs($frames / 48000 - 2.0) < 0.1, "about the clip's 2 s: $frames frames");
$left = array_values(unpack('g*', $pcm));
$peak = max(array_map('abs', array_slice($left, 2 * 4800, 2 * 4800)));
check($peak > 0.1 && $peak <= 1.0, "a sine's samples: peak $peak");
$s->reset();
$m->seek(1.0);
while (($p = $m->next()) !== null && $p->track !== $a) {}
same(0, $s->decode($p->data)[1], 'after a seek\'s reset: the first packet completes none again');

$m = new Matroska(__DIR__ . '/testdata/clip-vorbis51.mka');
$s = new SoftAudio($audio($m));
$pcm = '';
while (($p = $m->next()) !== null) { $pcm .= $s->decode($p->data)[0]; }
$x = array_values(unpack('g*', $pcm));
$energy = array_fill(0, 6, 0.0);
foreach ($x as $i => $v) { $energy[$i % 6] += $v * $v; }
$loud = array_search(max($energy), $energy, true);
check($loud === 2 && $energy[2] > 100 * (array_sum($energy) - $energy[2]), '5.1 in WAVE\'s order: the center third (Vorbis\'s second): ' . json_encode(array_map(fn($e) => round($e, 1), $energy)));
same(SoftAudio::BITS[6], 63, 'its channel bits: left, right, center, LFE, back left and right');

$film = new Matroska(__DIR__ . '/testdata/clip.mkv');
$own = $film->tracks();
$m = new \MyPHP\Media\Merged([$film]);
same($own, $m->tracks(), 'a film merged alone: its tracks the very ones');
$added = $m->add(new Matroska(__DIR__ . '/testdata/clip-vorbis.mkv'), null, 'clip-vorbis.mkv');
same([[Track::AUDIO, 1002, 'A_VORBIS', 'clip-vorbis.mkv']], array_map(fn(Track $t) => [$t->type, $t->number, $t->codec, $t->name], $added), 'its audio added, renumbered from 1000, named after the file (its video left out)');
same(count($own) + 1, count($m->tracks()), 'beside the film\'s');
$m->seek(1.0);
$last = -INF; $ordered = true; $seen = [];
for ($i = 0; $i < 60 && ($p = $m->next()) !== null; $i++) {
    $ordered = $ordered && $p->time >= $last - 0.2; $last = max($last, $p->time);
    $seen[$p->track->number] = true;
}
check($ordered && isset($seen[1002]) && count($seen) >= 2, 'after a seek, the frames of both in the order of their times: ' . json_encode(array_keys($seen)));
