<?php
// Now Playing and the media keys (mygo's nowplaying_test.go): what it
// shows, by a backend of the test's; the commands, toggled, offered or
// not, with where playback is; Control driving a player and hearing its
// end; on macOS, MPNowPlayingInfoCenter and MPRemoteCommandCenter.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\{MediaCommand, MediaPlayer, NowPlaying, NowPlayingInfo, PlaybackState};

(function () {
    $shown = []; // what each apply showed
    NowPlaying::$show = function (\Closure $info) use (&$shown): void { $shown[] = $info(); };
    $last = function () use (&$shown) { return $shown === [] ? false : $shown[array_key_last($shown)]; };

    NowPlaying::set(new NowPlayingInfo(title: 'Spring', artist: 'Vivaldi', album: 'The Four Seasons', artwork: "\x89PNG", duration: 180, position: 60, state: PlaybackState::Paused));
    $got = $last();
    same(['Spring', 'Vivaldi', 'The Four Seasons', "\x89PNG", 'paused', 60.0, 180.0, 1.0], [$got['title'], $got['artist'], $got['album'], $got['artwork'], $got['state'], $got['position'], $got['duration'], $got['rate']], 'Now Playing: shown');
    same(MediaCommand::PLAY | MediaCommand::PAUSE | MediaCommand::STOP, $got['commands'], 'Now Playing: the default commands');

    $cmds = [];
    $off = NowPlaying::onCommand(function (int $cmd, float $pos) use (&$cmds): void { $cmds[] = [$cmd, $pos]; });
    NowPlaying::command(MediaCommand::TOGGLE, 0); // paused: play
    NowPlaying::command(MediaCommand::NEXT, 0); // not offered
    NowPlaying::command(MediaCommand::STOP, 0);
    NowPlaying::set(new NowPlayingInfo(title: 'Summer', duration: 60, commands: MediaCommand::PAUSE | MediaCommand::NEXT | MediaCommand::SEEK));
    $got = $last();
    same(['playing', MediaCommand::PAUSE | MediaCommand::NEXT | MediaCommand::SEEK], [$got['state'], $got['commands']], 'Now Playing: another track, playing, its commands');
    NowPlaying::command(MediaCommand::TOGGLE, 0); // playing: pause
    NowPlaying::command(MediaCommand::NEXT, 0);
    NowPlaying::command(MediaCommand::SEEK, 120); // past the end
    NowPlaying::command(MediaCommand::PLAY, 0); // not offered
    // Commands other than seeking get where playback is: a minute into Spring, paused, and somewhere in Summer, which plays.
    same([MediaCommand::PLAY, MediaCommand::STOP, MediaCommand::PAUSE, MediaCommand::NEXT, MediaCommand::SEEK], array_column($cmds, 0), 'Now Playing: the commands that reach onCommand');
    same([60.0, 60.0, 60.0], [$cmds[0][1], $cmds[1][1], $cmds[4][1]], 'Now Playing: where Spring was, and the seek within Summer');
    check($cmds[2][1] >= 0 && $cmds[2][1] < 60 && $cmds[3][1] >= $cmds[2][1], 'Now Playing: where Summer plays');

    NowPlaying::setPlayback(PlaybackState::Stopped, 0);
    same('stopped', $last()['state'], 'Now Playing: setPlayback');
    NowPlaying::clear();
    same(null, $last(), 'Now Playing: cleared');
    same(null, NowPlaying::info(), 'Now Playing: nothing shown');
    $n = count($cmds);
    NowPlaying::command(MediaCommand::PAUSE, 0);
    same($n, count($cmds), 'Now Playing: no command with nothing shown');
    $off();

    // control() drives a player (a minute long), and hears of its end.
    $p = new class implements MediaPlayer {
        public bool $on = false;
        public float $pos = 10;
        public ?\Closure $end = null;
        public function play(): void { $this->on = true; }
        public function pause(): void { $this->on = false; }
        public function stop(): void { [$this->on, $this->pos] = [false, 0.0]; }
        public function playing(): bool { return $this->on; }
        public function seek(float $seconds): void { $this->pos = $seconds; }
        public function position(): float { return $this->pos; }
        public function duration(): float { return 60; }
        public function onEnd(\Closure $fn): \Closure { $this->end = $fn; return function (): void { $this->end = null; }; }
    };
    $release = NowPlaying::control($p);
    NowPlaying::set(new NowPlayingInfo(title: 'Autumn', commands: MediaCommand::NEXT));
    $got = $last();
    same(['paused', 10.0, 60.0], [$got['state'], $got['position'], $got['duration']], 'control: the player shown');
    same(MediaCommand::PLAY | MediaCommand::PAUSE | MediaCommand::STOP | MediaCommand::NEXT | MediaCommand::SEEK, $got['commands'], 'control: its commands on those offered');
    $next = 0;
    $off = NowPlaying::onCommand(function (int $cmd) use (&$next): void { if ($cmd === MediaCommand::NEXT) { $next++; } });
    NowPlaying::command(MediaCommand::TOGGLE, 0);
    NowPlaying::command(MediaCommand::SEEK, 30);
    NowPlaying::command(MediaCommand::NEXT, 0);
    same([true, 30.0, 1, 'playing'], [$p->on, $p->pos, $next, $last()['state']], 'control: played, seeked, next for the app');
    [$p->on, $p->pos] = [false, 60.0];
    ($p->end)();
    same('stopped', $last()['state'], 'control: at the end');
    $release();
    same(null, $p->end, 'control: released, its end not listened to');
    NowPlaying::command(MediaCommand::PLAY, 0);
    check(!$p->on, 'control: released, no command reaches it');
    $off();
    NowPlaying::clear();

    // On macOS: the system's Now Playing, and the commands enabled as offered.
    if (PHP_OS_FAMILY === 'Darwin') {
        $O = \MyPHP\Native\ObjC::class;
        \MyPHP\Native\NowPlaying::media(['title' => 'Winter', 'artist' => 'Vivaldi', 'album' => '', 'artwork' => '', 'duration' => 0.0, 'position' => 5.0, 'rate' => 1.0, 'state' => 'playing', 'commands' => MediaCommand::PLAY | MediaCommand::PAUSE | MediaCommand::SEEK], function (): void {});
        $center = $O::msg('p:', $O::cls('MPNowPlayingInfoCenter'), 'defaultCenter');
        $info = $O::msg('p:', $center, 'nowPlayingInfo');
        $value = fn(string $k) => $O::phpStr($O::msg('p:', $O::msg('p:p', $info, 'objectForKey:', $O::str($k)), 'description'));
        $cc = $O::msg('p:', $O::cls('MPRemoteCommandCenter'), 'sharedCommandCenter');
        $on = fn(string $getter): bool => (bool)$O::msg('b:', $O::msg('p:', $cc, $getter), 'isEnabled');
        same(['Winter', 'Vivaldi', '1', 1], [$value('title'), $value('artist'), $value('MPNowPlayingInfoPropertyPlaybackRate'), $O::msg('L:', $center, 'playbackState')], 'macOS: shown, playing');
        same([true, true, true, false, false], [$on('playCommand'), $on('togglePlayPauseCommand'), $on('pauseCommand'), $on('nextTrackCommand'), $on('changePlaybackPositionCommand')], 'macOS: play and pause enabled, the toggle with them, not next, nor seeking without a duration');
        \MyPHP\Native\NowPlaying::media(null, function (): void {});
        same([null, 3], [$O::msg('p:', $center, 'nowPlayingInfo'), $O::msg('L:', $center, 'playbackState')], 'macOS: shown no more');
    }
    NowPlaying::$show = null;
})();
