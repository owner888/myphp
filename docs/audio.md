# Audio

`MyPHP\Audio` plays sound from PHP: effects decoded into memory, music
streamed as it plays, and sound an app makes itself. It mixes everything
playing in PHP and sends it to the system's audio output, in apps of
native UI or web pages.

```php
use MyPHP\Audio\Audio;

$hoe = Audio::decodeSound(file_get_contents(__DIR__ . '/sounds/hoe.wav')); // decoded once

$hoe->play(); // as often as it sounds, over itself

$music = Audio::openStream(__DIR__ . '/sounds/spring.ogg'); // decoded as it plays
$music->setLoop(true)->setVolume(0.6)->play();
```

## Sounds and streams

A `Sound` holds decoded samples for effects that play often: `play()`
starts a new player each time, so a sound plays over itself, and
`newPlayer()` returns one paused, to set up before it plays:

```php
$hoe->newPlayer()->setPan(-0.4)->setSpeed(0.95 + mt_rand() / mt_getrandmax() * 0.1)->play();
```

`Audio::openStream` decodes a file, or a seekable stream, about a second
ahead as it plays, for music and other long audio, and
`Audio::newPlayer` does the same with any `Decoder`. Close a stream's
player when you are done with it: it closes the file. A sound's players
need no closing.

## Players

A `Player` plays, pauses (`pause()`), and goes back to the start
(`stop()`); `play()` after its end plays it again from the start.
`seek()` moves it, `position()` and `duration()` say where it is and how
long it is, in seconds, and `playing()` turns false at its end, so a
game can wait for a sound.

| setting | effect |
|---|---|
| `setVolume($v)` | a gain: 1 as decoded, 0 silent |
| `setPan($p)` | from the left speaker (-1) to the right (1): mono keeps its power, stereo both channels, as Web Audio's `StereoPannerNode` |
| `setSpeed($s)` | speed and pitch together, as a record's: 2 is twice as fast and an octave higher |
| `setLoop(true)` | starts again at the end, without a gap |
| `setLoopRange($start, $end)` | loops from `$end` back to `$start`, in seconds: a tune's intro plays once, then its loop (`$end` 0 is the end) |

The setters return the player, to chain them. Volume changes, pauses and
resumes ramp over 5 ms, so none clicks. `Audio::setVolume` sets the
volume of everything.

## Formats

| format | decoder |
|---|---|
| WAV (integer PCM of 8 to 32 bits, 32 or 64 bit floats) | PHP |
| Ogg Vorbis | PHP |
| MP3, AAC and ALAC in M4A, FLAC, AIFF, CAF | the system's (AudioToolbox) |

Prefer Ogg Vorbis for music that loops: MP3 files begin and end with the
encoder's padding, a few milliseconds of silence that a loop would play
too, unless `setLoopRange` leaves it out.

```php
// 12.5 s of intro, then bars from 12.5 s to 1 min 40 s, again and again.
$music->setLoopRange(12.5, 100)->play();
```

`Audio::registerFormat` adds a format of your own, and a `Decoder` you
write plays sound an app makes as it plays, such as a synthesizer:

```php
final class Tone implements Decoder
{
    private int $pos = 0;
    public function sampleRate(): int { return 48000; }
    public function channels(): int { return 1; }
    public function len(): int { return -1; } // endless
    public function setPosition(int $frame): void { $this->pos = $frame; }
    public function read(int $frames): array
    {
        $out = [];
        for ($i = 0; $i < $frames; $i++, $this->pos++) { $out[] = 0.2 * sin(2 * M_PI * 440 * $this->pos / 48000); }
        return $out;
    }
}

Audio::newPlayer(new Tone())->play();
```

`Audio::newSound` takes samples an app computed once.

## The output

The output opens when something first plays and closes five seconds
after everything stopped, so an app that plays nothing keeps no audio
device busy. It plays at 48 kHz in stereo, about 85 ms ahead, through an
Audio Queue on the default output, which follows it; players of other
rates are resampled as they play. PHP mixes on the main thread, as the
app runs (`App::run`): work that keeps the main thread busy for longer
than that makes the sound break up.

`Audio::setLatency` sets how far ahead it mixes, as Web Audio's
`latencyHint` (mygo's `SetLatency`):

| latency | ahead | for |
|---|---|---|
| `Latency::Interactive` (the default) | about 85 ms | games and interfaces: sounds start, stop and change as soon as asked |
| `Latency::Playback` | about 256 ms | music players: playback rides out the moments the system keeps the app waiting, as macOS does for up to 0.2 s while its appearance changes |

With `Latency::Playback`, plays, pauses, seeks and volume changes are
heard that much later. Set it before playing: an output open opens again,
which drops what it held.

Where no output opens, as on a machine with no audio device, players
still play on time, unheard, and `Audio::err()` says why.

[examples/piano](../examples/piano/main.php) is a piano of synthesized
keys that plays from the pointer and the keyboard, with a sustain pedal,
dampers that fade its strings out, and a few tunes it plays by itself.
