<?php
// Audio (mygo's audio_test.go, loop_test.go, vorbis_test.go and
// mp3_test.go): the mixer mixed by hand, as mygo's tests do; sounds and
// streams, overlapping, clipped, looped and loop ranges, pauses and volume
// that ramp, pan, speed, resampling, seeking, closing, orphaned streams;
// WAV, Ogg Vorbis and, on macOS, MP3; the null output's timing.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Audio\{Audio, Decoder, ErrFormat, Mixer, NullDevice, Output, Player, Sound, Stream};

const RATE = Mixer::RATE;
const RAMP = Mixer::RAMP;

/** Mixes by hand, with nothing playing. */
function setup(): void
{
    Output::$testMode = true;
    foreach (Mixer::$voices as $v) { $v->active = false; }
    Mixer::$voices = [];
    Mixer::$volume = 1.0;
}

/** Mixes $n frames, as the output does: the streams decode a little, then 256 frames are mixed. @return list<float> */
function render(int $n): array
{
    $out = [];
    for ($i = 0; $i < $n; $i += 256) {
        Stream::fillAll();
        array_push($out, ...Mixer::mix(min(256, $n - $i))[0]);
    }
    return $out;
}

function near(float $a, float $b, float $eps = 1e-4): bool { return abs($a - $b) < $eps; }

/** @return list<float> */
function ramp(int $n): array
{
    $s = [];
    for ($i = 0; $i < $n; $i++) { $s[] = ($i % 100) / 200; }
    return $s;
}

// A sound plays as decoded.
setup();
$samples = ramp(1000);
$p = Audio::newSound(RATE, 1, $samples)->play();
$out = render(1200);
$bad = -1;
foreach ($samples as $i => $s) { if (!near($out[2 * $i], $s) || !near($out[2 * $i + 1], $s)) { $bad = $i; break; } }
same(-1, $bad, 'a sound plays as decoded');
check(array_slice($out, 2000) === array_fill(0, 400, 0.0), 'silence after its end');
check(!$p->playing(), 'not playing after its end');
check(near($p->position(), $p->duration(), 1e-12), 'at its end, its position is its duration');
same(0, count(Mixer::$voices), 'no voices left');

// Stereo keeps its channels.
setup();
Audio::newSound(RATE, 2, [0.1, 0.2, 0.3, 0.4])->play();
$out = render(4);
check(near($out[0], 0.1) && near($out[1], 0.2) && near($out[2], 0.3) && near($out[3], 0.4), 'stereo keeps its channels');

// Play after the end plays again.
setup();
$p = Audio::newSound(RATE, 1, ramp(100))->play();
render(200);
$p->play();
check($p->playing(), 'play at the end starts again');
check(near(render(100)[100], 0.25), 'from the start');

// Sounds overlap, and clip.
setup();
$s = Audio::newSound(RATE, 1, [0.25, 0.25, 0.25, 0.25]);
$s->play(); $s->play();
check(near(render(2)[0], 0.5), 'two players mix');
setup();
$s = Audio::newSound(RATE, 1, [0.8, 0.8]);
$s->play(); $s->play();
same(1.0, render(1)[0], 'clipped to 1');

// A loop.
setup();
$samples = ramp(100);
$p = Audio::newSound(RATE, 1, $samples)->newPlayer()->setLoop(true);
$p->play();
$out = render(350);
$bad = -1;
for ($i = 0; $i < 350; $i++) { if (!near($out[2 * $i], $samples[$i % 100])) { $bad = $i; break; } }
same(-1, $bad, 'a loop plays again without a gap');
check($p->playing(), 'and keeps playing');
check(near($p->position(), 50 / RATE, 1e-12), 'its position goes round: ' . $p->position() * RATE);

// A pause fades out and resumes, fading in.
setup();
$p = Audio::newSound(RATE, 1, array_fill(0, 10000, 0.5))->play();
render(1000);
$p->pause();
check(!$p->playing(), 'not playing after pause');
$out = render(RAMP + 10);
check($out[0] > 0.45 && $out[2 * intdiv(RAMP, 2)] < 0.3 && $out[2 * intdiv(RAMP, 2)] > 0.2, 'a pause fades out');
check($out[2 * (RAMP + 5)] == 0 && count(Mixer::$voices) === 0, 'then is silent');
$at = $p->position();
render(500);
same($at, $p->position(), 'a paused player stays');
$p->play();
$out = render(RAMP + 10);
check($out[0] < 0.05 && near($out[2 * (RAMP + 5)], 0.5), 'resuming fades in');

// Volume ramps; the master volume.
setup();
$p = Audio::newSound(RATE, 1, array_fill(0, 10000, 0.5))->play();
render(10);
$p->setVolume(0.5);
$out = render(RAMP * 2);
check($out[0] > 0.45 && near($out[2 * (RAMP + 10)], 0.25), 'volume ramps');
Audio::setVolume(0);
$out = render(RAMP * 2);
same(0.0, $out[2 * (RAMP + 10)], 'master volume 0');

// Pan.
setup();
Audio::newSound(RATE, 1, [0.5, 0.5, 0.5])->newPlayer()->setPan(-1)->play();
$out = render(2);
check(near($out[0], 0.5 * M_SQRT2) && near($out[1], 0), 'mono panned left keeps its power');
setup();
Audio::newSound(RATE, 2, [0.2, 0.4, 0.2, 0.4])->newPlayer()->setPan(1)->play();
$out = render(1);
check(near($out[0], 0) && near($out[1], 0.6), 'stereo panned right keeps both channels');

// Speed.
setup();
$p = Audio::newSound(RATE, 1, ramp(1000))->newPlayer()->setSpeed(2);
$p->play();
$out = render(100);
check(near($out[20], ramp(1000)[20]), 'at speed 2, frame 10 is frame 20');
check(near($p->position(), 200 / RATE, 1e-12), 'and it is twice as far');

// Resampling: a 1 kHz sine at 24 kHz plays at 1 kHz.
setup();
$rate = 24000; $samples = [];
for ($i = 0; $i < $rate / 10; $i++) { $samples[] = sin(2 * M_PI * 1000 * $i / $rate); }
$p = Audio::newSound($rate, 1, $samples)->play();
$out = render(intdiv(RATE, 20));
$bad = -1;
for ($i = 100; $i < RATE / 20; $i++) { if (abs($out[2 * $i] - sin(2 * M_PI * 1000 * $i / RATE)) > 0.01) { $bad = $i; break; } }
same(-1, $bad, 'resampled');
check(near($p->position(), 0.05, 1e-12), 'its position in its own frames');

// Seek and stop.
setup();
$samples = ramp(48000);
$p = Audio::newSound(RATE, 1, $samples)->newPlayer();
$p->seek(0.5);
same(0.5, $p->position(), 'seeked');
$p->play();
$out = render(RAMP + 20);
check(near($out[2 * (RAMP + 10)], $samples[24000 + RAMP + 10]), 'plays from there');
$p->stop();
check(!$p->playing() && $p->position() == 0, 'stopped at the start');

// Close.
setup();
$p = Audio::newSound(RATE, 1, ramp(1000))->play();
$p->close();
$p->play();
check(!$p->playing(), 'a closed player plays no more');
render(10);
same(0, count(Mixer::$voices), 'nor mixes');

/** Generates frames i/1000 of a channel each (+ the channel), with a length. */
final class GenDecoder implements Decoder
{
    public int $pos = 0;
    public bool $closed = false;
    public function __construct(public int $ch, public int $length) {}
    public function sampleRate(): int { return RATE; }
    public function channels(): int { return $this->ch; }
    public function len(): int { return $this->length; }
    public function close(): void { $this->closed = true; }
    public function read(int $frames): array
    {
        $out = [];
        for ($n = 0; $n < $frames && $this->pos < $this->length; $n++, $this->pos++) {
            for ($c = 0; $c < $this->ch; $c++) { $out[] = ($this->pos % 1000) / 1000 + $c; }
        }
        return $out;
    }
    public function setPosition(int $frame): void
    {
        if ($frame > $this->length) { throw new \RuntimeException('out of range'); }
        $this->pos = $frame;
    }
}

// A stream: its first two channels, and Close closes its decoder.
setup();
$d = new GenDecoder(3, 5000);
$p = Audio::newPlayer($d);
check(near($p->duration(), 5000 / RATE, 1e-12), 'a stream\'s duration');
$p->play();
$out = render(1000);
$bad = -1;
for ($i = 0; $i < 1000; $i++) {
    if (!near($out[2 * $i], $i / 1000) || (!near($out[2 * $i + 1], $i / 1000 + 1) && $out[2 * $i + 1] != 1)) { $bad = $i; break; }
}
same(-1, $bad, 'a stream plays its first two channels');
$p->close();
check($d->closed, 'close closes the decoder');

// A stream loops without a gap; and a loop range.
setup();
$p = Audio::newPlayer(new GenDecoder(1, 300))->setLoop(true);
$p->play();
$out = [];
while (count($out) < 4000) { Stream::fillAll(); array_push($out, ...Mixer::mix(64)[0]); }
$bad = -1;
for ($i = 0; $i < 2000; $i++) { if (!near($out[2 * $i], ($i % 300) / 1000)) { $bad = $i; break; } }
same(-1, $bad, 'a stream loops without a gap');
$p->close();
setup();
$p = Audio::newPlayer(new GenDecoder(1, 5000))->setLoopRange(1000 / RATE, 3000 / RATE);
$p->play();
$out = [];
while (count($out) < 18000) { Stream::fillAll(); array_push($out, ...Mixer::mix(64)[0]); }
$bad = -1;
for ($i = 0; $i < 9000; $i++) {
    $f = $i >= 3000 ? 1000 + ($i - 3000) % 2000 : $i;
    if (!near($out[2 * $i], ($f % 1000) / 1000)) { $bad = $i; break; }
}
same(-1, $bad, 'a stream\'s intro plays once, then its loop');
$p->close();

// A stream seeks.
setup();
$p = Audio::newPlayer(new GenDecoder(1, 48000));
$p->seek(700 / RATE);
$p->play();
$out = render(RAMP + 20);
$i = RAMP + 10;
check(near($out[2 * $i], ((700 + $i) % 1000) / 1000), 'a stream plays from where it seeked');
$p->close();

// A stream whose player is gone plays to its end, then closes.
setup();
$d = new GenDecoder(1, 500);
$p = Audio::newPlayer($d);
$p->play();
$v = $p->v;
unset($p);
check($v->orphan, 'an orphan');
render(600);
check($v->closed && $d->closed, 'closed at its end');
// A paused one whose player is gone closes at once.
$d = new GenDecoder(1, 500);
$p = Audio::newPlayer($d);
unset($p);
check($d->closed, 'a paused stream dropped closes');

// A tune's intro plays once, then its loop, without a gap; looping no more, it plays on to its end.
setup();
$samples = ramp(100);
$p = Audio::newSound(RATE, 1, $samples)->newPlayer()->setLoopRange(30 / RATE, 60 / RATE);
$p->play();
$out = render(200);
$bad = -1;
for ($i = 0; $i < 200; $i++) { $want = $i >= 60 ? 30 + ($i - 60) % 30 : $i; if (!near($out[2 * $i], $samples[$want])) { $bad = $i; break; } }
same(-1, $bad, 'a loop range');
check(near($p->position(), 50 / RATE, 1e-12), '200 frames in: 4 loops of 30 and 20 more');
$p->setLoop(false);
$out = render(1000);
$last = -1;
for ($i = 0; $i < 1000; $i++) { if ($out[2 * $i] != 0) { $last = $i; } }
check($last >= 0 && near($out[2 * $last], $samples[99]) && !$p->playing(), 'looping no more, it plays to its end');
check(near($out[2 * ($last - 39)], $samples[60]), 'frames 60 to 99 end it');
setup();
$p = Audio::newSound(RATE, 1, ramp(100))->newPlayer();
$p->setLoopRange(60 / RATE, 30 / RATE);
check($p->v->loopStart === 0 && $p->v->loopEnd === 0 && $p->loop(), 'an end before the start loops it all');
$p->setLoopRange(10 / RATE, 3600);
check($p->v->loopStart === 10 && $p->v->loopEnd === 100, 'past the end, clamped to it');

// WAV.
function wav(int $tag, int $bits, int $ch, string $data): string
{
    return 'RIFF' . pack('V', 36 + strlen($data) + 10) . 'WAVE' . 'LIST' . pack('V', 1) . "\0\0" // odd, padded
        . 'fmt ' . pack('VvvVVvv', 16, $tag, $ch, 22050, 22050 * $ch * intdiv($bits, 8), $ch * intdiv($bits, 8), $bits)
        . 'data' . pack('V', strlen($data)) . $data;
}
foreach ([
    'u8' => [1, 8, "\x80\xc0\x40\x00"],
    's16' => [1, 16, pack('v4', 0, 0x4000, 0xc000, 0x8000)],
    's24' => [1, 24, "\0\0\0\0\0\x40\0\0\xc0\0\0\x80"],
    's32' => [1, 32, pack('V4', 0, 0x40000000, 0xc0000000, 0x80000000)],
    'f32' => [3, 32, pack('g4', 0, 0.5, -0.5, -1)],
    'f64' => [3, 64, pack('e4', 0, 0.5, -0.5, -1)],
] as $kind => [$tag, $bits, $data]) {
    $s = Audio::decodeSound(wav($tag, $bits, 2, $data));
    same([22050, 2, 2], [$s->rate, $s->channels, $s->frames()], "a $kind WAV's format");
    same([0.0, 0.5, -0.5, -1.0], array_values(unpack('g4', $s->samples)), "its samples");
    check(near($s->duration(), 2 / 22050, 1e-12), 'its duration');
}
$data = '';
for ($i = 0; $i < 100; $i++) { $data .= pack('v', $i * 100); }
$r = fopen('php://memory', 'w+b'); fwrite($r, wav(1, 16, 1, $data)); rewind($r);
[$d, $name] = Audio::newDecoder($r);
same(['wav', 100], [$name, $d->len()], 'a WAV decoder');
$d->setPosition(40);
$got = $d->read(4);
check(count($got) === 4 && $got[0] == 4000 / 32768, 'seeks');
$threw = false;
try { Audio::decodeSound('not audio at all'); } catch (ErrFormat) { $threw = true; }
check($threw, 'an unknown format');

// Ogg Vorbis: tone.ogg is 0.5 s of a 440 Hz sine at 22.05 kHz, in stereo.
$ogg = (string)file_get_contents(__DIR__ . '/testdata/tone.ogg');
$r = fopen('php://memory', 'w+b'); fwrite($r, $ogg); rewind($r);
[$d, $name] = Audio::newDecoder($r);
check($name === 'vorbis' && $d->sampleRate() === 22050 && $d->channels() === 2 && $d->len() >= 11025 && $d->len() <= 11025 + 128, "Vorbis: $name, {$d->sampleRate()} Hz, {$d->channels()} channels, {$d->len()} frames");
$all = [];
while (($s = $d->read(1000)) !== []) { array_push($all, ...$s); }
same(2 * $d->len(), count($all), 'decodes its frames');
$zc = 0;
for ($i = 2, $n = count($all); $i < $n; $i += 2) { if (($all[$i - 2] < 0) !== ($all[$i] < 0)) { $zc++; } }
$f = $zc / 2 / ((count($all) / 2) / 22050);
check(abs($f - 440) <= 5, "a sine of 440 Hz: $f");
$snd = Audio::decodeSound($ogg);
check($snd->duration() >= 0.5 && $snd->duration() <= 0.51, 'its duration: ' . $snd->duration());
$d->setPosition(5000);
$got = $d->read(4);
check(count($got) === 8 && near($got[0], $all[10000], 1e-6) && near($got[7], $all[10007], 1e-6), 'seeks exactly');
$d->setPosition(0);
check(near($d->read(1)[0], $all[0], 1e-9), 'and back to the start');

// MP3, through the system's decoder: tone.mp3 is 0.5 s of a 440 Hz sine at 44.1 kHz, in mono.
if (PHP_OS_FAMILY === 'Darwin') {
    $mp3 = (string)file_get_contents(__DIR__ . '/testdata/tone.mp3');
    $r = fopen('php://memory', 'w+b'); fwrite($r, $mp3); rewind($r);
    [$d, $name] = Audio::newDecoder($r);
    check($name === 'mp3' && $d->sampleRate() === 44100, "MP3: $name, {$d->sampleRate()} Hz");
    check($d->len() >= 22050 && $d->len() <= 22050 + 4 * 1152, "its length: {$d->len()}");
    $snd = Audio::decodeSound($mp3);
    check($snd->duration() >= 0.5 && $snd->duration() <= 0.61, 'its duration');
    $d->setPosition(10000);
    $buf = [];
    while (count($buf) < 8820 * $d->channels() && ($s = $d->read(1024)) !== []) { array_push($buf, ...$s); }
    $zc = 0; $step = $d->channels();
    for ($i = $step, $n = count($buf); $i < $n; $i += $step) { if (($buf[$i - $step] < 0) !== ($buf[$i] < 0)) { $zc++; } }
    $f = $zc / 2 / ((count($buf) / $step) / 44100);
    check(abs($f - 440) <= 5, "decodes 440 Hz from frame 10000: $f");
}

// Where no device opens, the output plays on time, unheard.
setup();
$p = Audio::newSound(RATE, 1, array_fill(0, intdiv(RATE, 10), 0.0))->play();
$dev = new NullDevice(0.0, retry: false);
$t = 0.0;
while ($p->playing() && $t < 2) { $t += 0.005; $dev->tick($t); }
check($t >= 0.095 && $t <= 0.12, "100 ms play in $t s");
setup();

// A longer Vorbis file of long and short blocks, two channels coupled: what libvorbis decodes (chirp.json, through libsndfile), here and after seeks.
$ref = json_decode((string)file_get_contents(__DIR__ . '/testdata/chirp.json'), true);
$r = fopen(__DIR__ . '/testdata/chirp.ogg', 'rb');
[$d] = Audio::newDecoder($r);
same($ref['frames'], $d->len(), 'its length');
$all = [];
while (($s = $d->read(4096)) !== []) { array_push($all, ...$s); }
same(2 * $ref['frames'], count($all), 'decodes every frame');
foreach ($ref as $at => $want) {
    if ($at === 'frames') { continue; }
    $err = 0.0;
    foreach ($want as $i => $v) { $err = max($err, abs($all[2 * (int)$at + $i] - $v)); }
    check($err < 2e-4, "frames from $at as libvorbis decodes them: $err off");
    $d->setPosition((int)$at);
    $got = $d->read(16);
    $err = 0.0;
    foreach ($want as $i => $v) { $err = max($err, abs($got[$i] - $v)); }
    check($err < 2e-4, "and after seeking there: $err off");
}
fclose($r);

// How far ahead the output mixes (mygo's SetLatency): the interactive default, four buffers; playback, twelve; an output open opens again.
same([\MyPHP\Audio\Latency::Interactive, 4, 12], [Audio::latency(), \MyPHP\Audio\Latency::Interactive->buffers(), \MyPHP\Audio\Latency::Playback->buffers()], 'latencies: about 85 ms and 256 ms ahead');
Audio::setLatency(\MyPHP\Audio\Latency::Playback);
same(\MyPHP\Audio\Latency::Playback, Audio::latency(), 'set');
Audio::setLatency(\MyPHP\Audio\Latency::Interactive);
