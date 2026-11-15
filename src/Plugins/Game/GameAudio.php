<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Game;

use MyPHP\Audio\{Audio, Player, Sound};

/**
 * Game Audio Manager with BGM cross-fading and low-latency in-memory SFX pool
 * (inspired by Flutter Casual Games Toolkit & flame_audio).
 *
 * - SFX Pool: Decodes short sound effects once into memory (`Sound`), plays them
 *   with zero disk I/O and optional random pitch jitter (`pitchVariance`) to prevent
 *   ear fatigue on repetitive actions (footsteps, sword hits, coin pickups).
 * - BGM Cross-Fader: Smoothly fades out the previous background track while fading
 *   in the new track over $crossfadeDuration seconds when switching scenes/battles.
 * - Procedural Retro Synth: Built-in helper (`registerTone`) to synthesize 8-bit/16-bit
 *   retro sound effects (square/sine/noise sweeps) without external audio files.
 */
final class GameAudio
{
    /** @var array<string, Sound> */
    private array $sfx = [];

    private ?Player $bgmCurrent = null;
    private ?string $bgmCurrentPath = null;
    private ?Player $bgmFadingOut = null;

    public float $bgmVolume = 0.65;
    public float $sfxVolume = 0.90;
    public bool $muted = false;

    private float $fadeTimer = 0.0;
    private float $fadeDuration = 0.0;

    /**
     * Preloads a sound effect file into memory under $name.
     */
    public function registerSfx(string $name, string $filePath): Sound
    {
        $data = file_get_contents($filePath);
        if ($data === false) {
            throw new \RuntimeException("GameAudio: cannot read SFX file $filePath");
        }
        return $this->sfx[$name] = Audio::decodeSound($data);
    }

    /**
     * Registers an already-decoded Sound under $name.
     */
    public function registerSound(string $name, Sound $sound): Sound
    {
        return $this->sfx[$name] = $sound;
    }

    /**
     * Synthesizes a procedural retro sound effect (sine/square wave with frequency sweep
     * and exponential envelope) and registers it under $name — great for instant game feel
     * without external WAV assets.
     */
    public function registerTone(
        string $name,
        float $startFreq = 440.0,
        float $endFreq = 880.0,
        float $duration = 0.12,
        bool $square = false,
        float $gain = 0.35
    ): Sound {
        $rate = 44100;
        $frames = max(1, (int)round($duration * $rate));
        $samples = [];
        $phase = 0.0;
        for ($i = 0; $i < $frames; $i++) {
            $t = $i / $frames;
            $freq = $startFreq + ($endFreq - $startFreq) * $t;
            $phase += 2.0 * M_PI * $freq / $rate;
            $raw = sin($phase);
            if ($square) {
                $raw = $raw >= 0.0 ? 0.85 : -0.85;
            }
            // Attack + exponential decay envelope
            $env = min(1.0, $i / max(1.0, $rate * 0.004)) * pow(1.0 - $t, 1.35);
            $samples[] = $raw * $gain * $env;
        }
        return $this->sfx[$name] = Audio::newSound($rate, 1, $samples);
    }

    /**
     * Plays a registered sound effect from memory immediately.
     *
     * @param float $volume Gain multiplier relative to $this->sfxVolume
     * @param float $pitchVariance Random speed/pitch jitter (e.g. 0.06 means 0.94x..1.06x)
     * @param float $pan Stereo pan in [-1.0, 1.0]
     */
    public function playSfx(string $name, float $volume = 1.0, float $pitchVariance = 0.0, float $pan = 0.0): ?Player
    {
        if ($this->muted || !isset($this->sfx[$name])) {
            return null;
        }
        $player = $this->sfx[$name]->newPlayer();
        $player->setVolume(max(0.0, $this->sfxVolume * $volume));
        if ($pan != 0.0) {
            $player->setPan($pan);
        }
        if ($pitchVariance > 0.0) {
            $r = (mt_rand(0, 2000) / 1000.0 - 1.0) * $pitchVariance;
            $player->setSpeed(max(0.25, 1.0 + $r));
        }
        $player->play();
        return $player;
    }

    /**
     * Starts or cross-fades to a looping background music stream at $filePath.
     */
    public function playBgm(string $filePath, float $crossfadeDuration = 0.6, bool $loop = true): Player
    {
        if ($this->bgmCurrentPath === $filePath && $this->bgmCurrent !== null) {
            if (!$this->bgmCurrent->playing() && !$this->muted) {
                $this->bgmCurrent->play();
            }
            return $this->bgmCurrent;
        }

        if ($this->bgmFadingOut !== null) {
            $this->bgmFadingOut->close();
            $this->bgmFadingOut = null;
        }

        $next = Audio::openStream($filePath);
        $next->setLoop($loop);
        $this->bgmCurrentPath = $filePath;

        if ($this->bgmCurrent !== null && $crossfadeDuration > 0.01) {
            $this->bgmFadingOut = $this->bgmCurrent;
            $this->bgmCurrent = $next;
            $this->fadeDuration = $crossfadeDuration;
            $this->fadeTimer = 0.0;
            $next->setVolume(0.0);
            if (!$this->muted) {
                $next->play();
            }
        } else {
            if ($this->bgmCurrent !== null) {
                $this->bgmCurrent->close();
            }
            $this->bgmCurrent = $next;
            $this->fadeDuration = 0.0;
            $next->setVolume($this->muted ? 0.0 : $this->bgmVolume);
            if (!$this->muted) {
                $next->play();
            }
        }

        return $next;
    }

    /**
     * Stops background music (optionally fading out over $fadeOutDuration seconds).
     */
    public function stopBgm(float $fadeOutDuration = 0.0): void
    {
        if ($this->bgmCurrent === null) {
            return;
        }
        if ($fadeOutDuration > 0.01) {
            if ($this->bgmFadingOut !== null) {
                $this->bgmFadingOut->close();
            }
            $this->bgmFadingOut = $this->bgmCurrent;
            $this->bgmCurrent = null;
            $this->bgmCurrentPath = null;
            $this->fadeDuration = $fadeOutDuration;
            $this->fadeTimer = 0.0;
        } else {
            $this->bgmCurrent->close();
            $this->bgmCurrent = null;
            $this->bgmCurrentPath = null;
        }
    }

    /**
     * Advances active BGM cross-fades by $dt seconds.
     */
    public function step(float $dt): void
    {
        if ($this->fadeDuration <= 0.0) {
            return;
        }
        $this->fadeTimer += $dt;
        $t = min(1.0, $this->fadeTimer / $this->fadeDuration);
        $targetVol = $this->muted ? 0.0 : $this->bgmVolume;

        if ($this->bgmFadingOut !== null) {
            $this->bgmFadingOut->setVolume($targetVol * (1.0 - $t));
        }
        if ($this->bgmCurrent !== null) {
            $this->bgmCurrent->setVolume($targetVol * $t);
        }

        if ($t >= 1.0) {
            if ($this->bgmFadingOut !== null) {
                $this->bgmFadingOut->close();
                $this->bgmFadingOut = null;
            }
            $this->fadeDuration = 0.0;
        }
    }
}