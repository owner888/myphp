<?php
declare(strict_types=1);

namespace MyPHP\Audio;

use Closure;

/**
 * Plays sound (mygo's package audio): effects decoded into memory, music
 * streamed as it plays, and sound an app makes itself, mixed in PHP and
 * sent to the system's audio output:
 *
 *     $click = Audio::decodeSound(file_get_contents('click.wav')); // decoded once
 *     $click->play(); // as often as it sounds, over itself
 *
 *     $music = Audio::openStream('music.ogg'); // decoded as it plays
 *     $music->setLoop(true)->setVolume(0.6)->play();
 *
 * WAV and Ogg Vorbis decode in PHP; MP3, AAC (M4A), ALAC, FLAC, AIFF and
 * CAF through the system's decoders. The output opens when something
 * first plays and closes a few seconds after everything stopped; it plays
 * while the app runs (App::run). Where no output can open, players still
 * play on time, unheard, and err() says why.
 */
final class Audio
{
    /** @var list<array{0: string, 1: string, 2: Closure}> name, magic and what opens a decoder, as registered */
    private static array $formats = [];
    private static bool $builtIn = false;
    private static ?\Throwable $err = null;

    /**
     * Registers a format for decodeSound, openStream and newDecoder: $magic
     * is the start of its files, where '?' matches any byte, and $open
     * returns a decoder reading the stream it is given from its start.
     * @param Closure(resource): Decoder $open
     */
    public static function registerFormat(string $name, string $magic, Closure $open): void
    {
        self::builtIn();
        self::$formats[] = [$name, $magic, $open];
    }

    /** The formats MyPHP decodes, registered before the app's. */
    private static function builtIn(): void
    {
        if (self::$builtIn) { return; }
        self::$builtIn = true;
        self::$formats[] = ['wav', 'RIFF????WAVE', fn($r) => new Wav($r)];
        self::$formats[] = ['vorbis', 'OggS', fn($r) => new Vorbis\Decoder($r)];
        foreach (SystemDecoder::FORMATS as [$name, $magic]) {
            self::$formats[] = [$name, $magic, fn($r) => new SystemDecoder($r, $name)];
        }
    }

    private static function matches(string $magic, string $head): bool
    {
        if (strlen($magic) > strlen($head)) { return false; }
        for ($i = 0, $n = strlen($magic); $i < $n; $i++) {
            if ($magic[$i] !== '?' && $magic[$i] !== $head[$i]) { return false; }
        }
        return true;
    }

    /**
     * A decoder of the stream $r's format, from where it is, and the
     * format's name; ErrFormat for none.
     * @param resource $r a seekable stream
     * @return array{0: Decoder, 1: string}
     */
    public static function newDecoder($r): array
    {
        self::builtIn();
        $start = ftell($r);
        $head = (string)fread($r, 16);
        fseek($r, (int)$start);
        foreach (self::$formats as [$name, $magic, $open]) {
            if (self::matches($magic, $head)) {
                return [$open($r), $name];
            }
        }
        throw new ErrFormat();
    }

    /** Decodes $data, of a registered format, into memory. */
    public static function decodeSound(string $data): Sound
    {
        $r = fopen('php://memory', 'w+b');
        fwrite($r, $data);
        rewind($r);
        [$d] = self::newDecoder($r);
        try {
            return self::readSound($d);
        } finally {
            if (method_exists($d, 'close')) { $d->close(); }
            fclose($r);
        }
    }

    /** Decodes everything $d has into memory. */
    public static function readSound(Decoder $d): Sound
    {
        $ch = $d->channels();
        if ($ch < 1 || $d->sampleRate() <= 0) {
            throw new \RuntimeException('audio: decoder of no channels or sample rate');
        }
        $keep = min($ch, 2);
        $out = '';
        while (($s = $d->read(4096)) !== []) {
            $n = count($s); $n -= $n % $ch;
            if ($keep !== $ch || $n !== count($s)) {
                $kept = [];
                for ($i = 0; $i < $n; $i += $ch) { for ($c = 0; $c < $keep; $c++) { $kept[] = $s[$i + $c]; } }
                $s = $kept;
            }
            $out .= pack('g*', ...$s);
        }
        return new Sound($out, $keep, $d->sampleRate());
    }

    /**
     * A sound of $samples, interleaved frames of 1 (mono) or 2 (stereo)
     * channels at $sampleRate frames per second, as an app makes them.
     * @param list<float> $samples
     */
    public static function newSound(int $sampleRate, int $channels, array $samples): Sound
    {
        if ($channels !== 1 && $channels !== 2) { throw new \InvalidArgumentException('audio: newSound takes 1 or 2 channels'); }
        if ($sampleRate <= 0) { throw new \InvalidArgumentException('audio: newSound takes a positive sample rate'); }
        $n = count($samples); $n -= $n % $channels;
        return new Sound(pack('g*', ...($n === count($samples) ? $samples : array_slice($samples, 0, $n))), $channels, $sampleRate);
    }

    /**
     * A paused player decoding the file at $file, or the seekable stream
     * $file, as it plays, for music and other long audio: it decodes about
     * a second ahead. Closing the player closes the stream.
     * @param string|resource $file
     */
    public static function openStream($file): Player
    {
        $r = is_string($file) ? fopen($file, 'rb') : $file;
        if ($r === false) { throw new \RuntimeException("audio: cannot open $file"); }
        try {
            [$d] = self::newDecoder($r);
        } catch (\Throwable $e) {
            if (is_string($file)) { fclose($r); }
            throw $e;
        }
        return self::newPlayer(new ClosingDecoder($d, $r));
    }

    /** A paused player decoding $d as it plays, about a second ahead. Closing the player closes $d if it has a close() method. */
    public static function newPlayer(Decoder $d): Player
    {
        return new Player(new Stream($d), $d->sampleRate(), min($d->channels(), 2), $d->len());
    }

    /** Sets the volume of everything played, a gain: 1 (the default) as decoded, 0 silent. */
    public static function setVolume(float $v): void
    {
        Mixer::$volume = max($v, 0.0);
    }

    public static function volume(): float
    {
        return Mixer::$volume;
    }

    /**
     * Sets how far ahead the output mixes (mygo's SetLatency):
     * Latency::Playback for music, which plays on while the system keeps
     * the app waiting, Latency::Interactive (the default) for sounds that
     * answer at once. An output open opens again, dropping what it held.
     */
    public static function setLatency(Latency $l): void
    {
        if (Output::$latency === $l) { return; }
        Output::$latency = $l;
        if (Output::open()) {
            Output::close();
            if (Mixer::$voices !== []) { Output::wake(); }
        }
    }

    public static function latency(): Latency
    {
        return Output::$latency;
    }

    /** Why the last output to open is not the system's audio output, as on a machine with none, or null. */
    public static function err(): ?\Throwable
    {
        return self::$err;
    }

    /** @internal */
    public static function setErr(?\Throwable $e): void
    {
        self::$err = $e;
    }
}
