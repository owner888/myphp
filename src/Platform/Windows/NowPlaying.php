<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use Closure;
use FFI;
use FFI\CData;
use MyPHP\{App, Logger, MediaCommand};
use MyPHP\Native\Com;
use MyPHP\Platform;
use MyPHP\Platform\AppHandler;

/**
 * The SystemMediaTransportControls of the app's window, through
 * ISystemMediaTransportControlsInterop as desktop apps get them (mygo's
 * windows nowplaying.go): the media controls of the volume flyout and the
 * lock screen, which the media keys drive. The Windows Runtime's classes
 * are activated by their names (HSTRINGs of combase's). The controls call
 * an agile delegate on a thread of their own, where PHP may not run: the
 * handlers of their events are not agile (WebView::handler refuses
 * IAgileObject and IMarshal), so COM brings each call to this apartment's
 * thread, the main one, as it handles its messages.
 */
final class NowPlaying implements Platform\NowPlaying
{
    private const IID_INTEROP = 'ddb0472d-c911-4a1f-86d9-dc3d71a95f5a', IID_CONTROLS = '99fa3ff4-1742-42a6-902e-087d41f965ec',
        IID_CONTROLS2 = 'ea98d2f6-7f3c-4af2-a586-72889808efb1', IID_MUSIC2 = '00368462-97d3-44b9-b00f-008afcefaf18',
        IID_TIMELINE = '5125316a-c3a2-475b-8507-93534dc88f15', IID_STREAM = '905a0fe1-bc53-11df-8c49-001e4fc686da',
        IID_STREAM_REF = '857309dc-3fbf-4e7d-986f-ef3b1a07a964';
    /** TypedEventHandler<SystemMediaTransportControls, …ButtonPressedEventArgs> and <…, PlaybackPositionChangeRequestedEventArgs>. */
    private const BUTTON_PRESSED = '0557e996-7b23-5bae-aa81-ea0d671143a4', POSITION_REQUESTED = '44e34f15-bdc0-50a7-ace4-39e91fb753f1';
    /** MediaPlaybackStatus: Closed, Stopped, Playing, Paused; MediaPlaybackType Music. */
    private const CLOSED = 0, STOPPED = 2, PLAYING = 3, PAUSED = 4, MUSIC = 1;
    /** MediaCommand's bit of a SystemMediaTransportControlsButton: Play, Pause, Stop, Next, Previous. */
    private const BUTTONS = [0 => MediaCommand::PLAY, 1 => MediaCommand::PAUSE, 2 => MediaCommand::STOP, 6 => MediaCommand::NEXT, 7 => MediaCommand::PREVIOUS];

    private static ?FFI $f = null;
    /** The controls, made on first use; whether they could not be. */
    private ?CData $controls = null, $controls2 = null;
    private bool $failed = false;
    /** Where the commands go (cmd, position) while a player's state shows (show()), else the app's MyPHP\NowPlaying (AppHandler::mediaCommand). */
    private ?Closure $command = null;

    public function __construct(private readonly Backend $b, private readonly AppHandler $h) {}

    public function set(?array $info): void
    {
        $this->show($info, null);
    }

    /**
     * Shows $info as set() does, its commands sent to $command (MediaCommand's
     * bit and a seek's position) rather than the app's MyPHP\NowPlaying:
     * the video player's (Windows\Media::showNowPlaying), the last to show
     * taking them, as on macOS and Linux.
     * @internal Windows\Media
     * @param ?array<string, mixed> $info
     */
    public function show(?array $info, ?Closure $command): void
    {
        $this->command = $info === null ? null : $command;
        $c = $this->controls();
        if ($c === null) {
            return;
        }
        $f = self::f();
        $out = $f->new('void *');
        ($c->v->get_DisplayUpdater)($c, FFI::addr($out));
        $updater = FFI::isNull($out) ? null : Com::typed($f, $out, 'Updater *');
        try {
            if ($info === null) {
                if ($updater !== null) {
                    ($updater->v->ClearAll)($updater);
                    ($updater->v->Update)($updater);
                }
                ($c->v->put_PlaybackStatus)($c, self::CLOSED);
                ($c->v->put_IsEnabled)($c, 0);
                return;
            }
            $cmds = (int)$info['commands'];
            ($c->v->put_IsEnabled)($c, 1);
            ($c->v->put_PlaybackStatus)($c, ['playing' => self::PLAYING, 'paused' => self::PAUSED, 'stopped' => self::STOPPED][$info['state']] ?? self::STOPPED);
            ($c->v->put_IsPlayEnabled)($c, ($cmds & MediaCommand::PLAY) !== 0 ? 1 : 0);
            ($c->v->put_IsPauseEnabled)($c, ($cmds & MediaCommand::PAUSE) !== 0 ? 1 : 0);
            ($c->v->put_IsStopEnabled)($c, ($cmds & MediaCommand::STOP) !== 0 ? 1 : 0);
            ($c->v->put_IsNextEnabled)($c, ($cmds & MediaCommand::NEXT) !== 0 ? 1 : 0);
            ($c->v->put_IsPreviousEnabled)($c, ($cmds & MediaCommand::PREVIOUS) !== 0 ? 1 : 0);
            if ($updater !== null) {
                ($updater->v->put_Type)($updater, self::MUSIC);
                $mo = $f->new('void *');
                if (($updater->v->get_MusicProperties)($updater, FFI::addr($mo)) >= 0 && !FFI::isNull($mo)) {
                    $music = Com::typed($f, $mo, 'Music *');
                    self::withString((string)$info['title'], fn($h) => ($music->v->put_Title)($music, $h));
                    self::withString((string)$info['artist'], fn($h) => ($music->v->put_Artist)($music, $h));
                    if (($m2 = Com::query($music, self::IID_MUSIC2)) !== null) {
                        $music2 = Com::typed($f, $m2, 'Music2 *');
                        self::withString((string)$info['album'], fn($h) => ($music2->v->put_AlbumTitle)($music2, $h));
                        Com::release($m2);
                    }
                    Com::release($mo);
                }
                $thumb = self::thumbnail((string)($info['artwork'] ?? ''));
                ($updater->v->put_Thumbnail)($updater, $thumb);
                if ($thumb !== null) { Com::release($thumb); }
                ($updater->v->Update)($updater);
            }
            if (($c2 = $this->controls2) !== null) {
                if (($tl = self::activate('Windows.Media.SystemMediaTransportControlsTimelineProperties')) !== null) {
                    if (($t = Com::query($tl, self::IID_TIMELINE)) !== null) {
                        $timeline = Com::typed($f, $t, 'Timeline *');
                        // TimeSpans, of 100 ns, from a start of 0.
                        [$end, $pos] = [(int)round($info['duration'] * 1e7), (int)round($info['position'] * 1e7)];
                        ($timeline->v->put_EndTime)($timeline, $end);
                        if ($cmds & MediaCommand::SEEK) { ($timeline->v->put_MaxSeekTime)($timeline, $end); }
                        ($timeline->v->put_Position)($timeline, $pos);
                        ($c2->v->UpdateTimelineProperties)($c2, $t);
                        Com::release($t);
                    }
                    Com::release($tl);
                }
                ($c2->v->put_PlaybackRate)($c2, (float)$info['rate']);
            }
        } finally {
            if ($updater !== null) { Com::release($out); }
        }
    }

    /** The controls of the app's window, made and hooked up on first use, or null (mygo's mediaControls). */
    private function controls(): ?CData
    {
        if ($this->controls !== null || $this->failed) {
            return $this->controls;
        }
        $this->failed = true;
        $f = self::f();
        $interop = self::factory('Windows.Media.SystemMediaTransportControls', self::IID_INTEROP);
        if ($interop === null) {
            Logger::warn('no SystemMediaTransportControls: the media controls show nothing', 'windows');
            return null;
        }
        $i = Com::typed($f, $interop, 'Interop *');
        $iid = Com::guid(self::IID_CONTROLS);
        $out = $f->new('void *');
        $hr = ($i->v->GetForWindow)($i, $this->b->hwnd(), FFI::addr($iid), FFI::addr($out));
        Com::release($interop);
        if ($hr < 0 || FFI::isNull($out)) {
            Logger::warn(sprintf('no media controls of the app\'s window (HRESULT 0x%08X)', $hr & 0xFFFFFFFF), 'windows');
            return null;
        }
        $c = Com::typed($f, $out, 'Controls *');
        $token = $f->new('int64_t');
        $self = \WeakReference::create($this);
        ($c->v->add_ButtonPressed)($c, WebView::handler(static function (int $sender, $args) use ($self, $f): void {
            $a = Com::typed($f, $args, 'Args *');
            $button = $f->new('int32_t');
            if (($a->v->get_Value)($a, FFI::addr($button)) >= 0 && isset(self::BUTTONS[$button->cdata])) {
                $self->get()?->report(self::BUTTONS[$button->cdata], 0.0);
            }
        }, [self::BUTTON_PRESSED]), FFI::addr($token));
        if (($c2 = Com::query($c, self::IID_CONTROLS2)) !== null) {
            $this->controls2 = Com::typed($f, $c2, 'Controls2 *');
            ($this->controls2->v->add_PlaybackPositionChangeRequested)($this->controls2, WebView::handler(static function (int $sender, $args) use ($self, $f): void {
                $a = Com::typed($f, $args, 'PositionArgs *');
                $t = $f->new('int64_t'); // a TimeSpan, of 100 ns
                if (($a->v->get_Value)($a, FFI::addr($t)) >= 0) {
                    $self->get()?->report(MediaCommand::SEEK, $t->cdata / 1e7);
                }
            }, [self::POSITION_REQUESTED]), FFI::addr($token));
        }
        [$this->controls, $this->failed] = [$c, false];
        return $c;
    }

    /** A command of the controls, to the player showing or the app, from the queue (as mygo posts it). */
    private function report(int $cmd, float $position): void
    {
        $this->b->timer(0, function () use ($cmd, $position): void {
            $this->command !== null ? ($this->command)($cmd, $position) : $this->h->mediaCommand($cmd, $position);
        });
    }

    // The Windows Runtime.

    /** The activation factory of class $class as $iid, held, or null. */
    private static function factory(string $class, string $iid): ?CData
    {
        $f = self::f();
        $f->CoInitializeEx(null, 2);
        $g = Com::guid($iid);
        $out = $f->new('void *');
        $hr = self::withString($class, fn($h) => $f->RoGetActivationFactory($h, FFI::addr($g), FFI::addr($out)));
        return $hr < 0 || FFI::isNull($out) ? null : Com::typed($f, $out, 'void *');
    }

    /** An instance of class $class, its IInspectable held, or null. */
    private static function activate(string $class): ?CData
    {
        $f = self::f();
        $out = $f->new('void *');
        $hr = self::withString($class, fn($h) => $f->RoActivateInstance($h, FFI::addr($out)));
        return $hr < 0 || FFI::isNull($out) ? null : Com::typed($f, $out, 'void *');
    }

    /** $call's result with an HSTRING of $s, deleted after (null for ''). */
    private static function withString(string $s, Closure $call): mixed
    {
        $f = self::f();
        $h = $f->new('void *');
        $u = $s === '' ? '' : (string)iconv('UTF-8', 'UTF-16LE', $s);
        if ($u !== '') {
            $buf = $f->new('uint16_t[' . (strlen($u) >> 1) . ']');
            FFI::memcpy($buf, $u, strlen($u));
            $f->WindowsCreateString($buf, strlen($u) >> 1, FFI::addr($h));
        }
        try {
            return $call($h);
        } finally {
            if (!FFI::isNull($h)) { $f->WindowsDeleteString($h); }
        }
    }

    /** A RandomAccessStreamReference of the image $bytes, held, or null (mygo's thumbnail). */
    private static function thumbnail(string $bytes): ?CData
    {
        if ($bytes === '') {
            return null;
        }
        $f = self::f();
        static $shlwapi = null;
        $shlwapi ??= FFI::cdef('void *SHCreateMemStream(const void *init, uint32_t size);', 'shlwapi.dll');
        $data = $f->new('uint8_t[' . strlen($bytes) . ']');
        FFI::memcpy($data, $bytes, strlen($bytes));
        $stream = $shlwapi->SHCreateMemStream($data, strlen($bytes));
        if ($stream === null) {
            return null;
        }
        $iid = Com::guid(self::IID_STREAM);
        $ras = $f->new('void *');
        static $shcore = null;
        $shcore ??= FFI::cdef('int32_t CreateRandomAccessStreamOverStream(void *stream, int32_t options, const void *iid, void **out);', 'shcore.dll');
        $hr = $shcore->CreateRandomAccessStreamOverStream($stream, 0, FFI::addr($iid), FFI::addr($ras)); // BSOS_DEFAULT
        Com::release($stream);
        if ($hr < 0 || FFI::isNull($ras)) {
            return null;
        }
        $ref = null;
        if (($statics = self::factory('Windows.Storage.Streams.RandomAccessStreamReference', self::IID_STREAM_REF)) !== null) {
            $s = Com::typed($f, $statics, 'StreamRefStatics *');
            $out = $f->new('void *');
            if (($s->v->CreateFromStream)($s, $ras, FFI::addr($out)) >= 0 && !FFI::isNull($out)) {
                $ref = Com::typed($f, $out, 'void *');
            }
            Com::release($statics);
        }
        Com::release($ras);
        return $ref;
    }

    private static function f(): FFI
    {
        // Vtable indices of windows.media.h: IInspectable takes the first six.
        return self::$f ??= FFI::cdef(<<<'C'
            typedef struct { void *r0[6]; int32_t (*GetForWindow)(void *self, void *hwnd, const void *iid, void **controls); } InteropVtbl;
            typedef struct { InteropVtbl *v; } Interop;
            typedef struct {
                void *r0[7];
                int32_t (*put_PlaybackStatus)(void *self, int32_t status);
                int32_t (*get_DisplayUpdater)(void *self, void **updater);
                void *r9[2];
                int32_t (*put_IsEnabled)(void *self, uint8_t on);
                void *r12;
                int32_t (*put_IsPlayEnabled)(void *self, uint8_t on);
                void *r14;
                int32_t (*put_IsStopEnabled)(void *self, uint8_t on);
                void *r16;
                int32_t (*put_IsPauseEnabled)(void *self, uint8_t on);
                void *r18[7];
                int32_t (*put_IsPreviousEnabled)(void *self, uint8_t on);
                void *r26;
                int32_t (*put_IsNextEnabled)(void *self, uint8_t on);
                void *r28[4];
                int32_t (*add_ButtonPressed)(void *self, void *handler, int64_t *token);
            } ControlsVtbl;
            typedef struct { ControlsVtbl *v; } Controls;
            typedef struct {
                void *r0[11];
                int32_t (*put_PlaybackRate)(void *self, double rate);
                int32_t (*UpdateTimelineProperties)(void *self, void *timeline);
                int32_t (*add_PlaybackPositionChangeRequested)(void *self, void *handler, int64_t *token);
            } Controls2Vtbl;
            typedef struct { Controls2Vtbl *v; } Controls2;
            typedef struct { void *r0[7]; int32_t (*put_Type)(void *self, int32_t type); void *r8[3]; int32_t (*put_Thumbnail)(void *self, void *ref); int32_t (*get_MusicProperties)(void *self, void **music); void *r13[3]; int32_t (*ClearAll)(void *self); int32_t (*Update)(void *self); } UpdaterVtbl;
            typedef struct { UpdaterVtbl *v; } Updater;
            typedef struct { void *r0[7]; int32_t (*put_Title)(void *self, void *s); void *r8[3]; int32_t (*put_Artist)(void *self, void *s); } MusicVtbl;
            typedef struct { MusicVtbl *v; } Music;
            typedef struct { void *r0[7]; int32_t (*put_AlbumTitle)(void *self, void *s); } Music2Vtbl;
            typedef struct { Music2Vtbl *v; } Music2;
            typedef struct { void *r0[9]; int32_t (*put_EndTime)(void *self, int64_t t); void *r10[3]; int32_t (*put_MaxSeekTime)(void *self, int64_t t); void *r14; int32_t (*put_Position)(void *self, int64_t t); } TimelineVtbl;
            typedef struct { TimelineVtbl *v; } Timeline;
            typedef struct { void *r0[8]; int32_t (*CreateFromStream)(void *self, void *stream, void **ref); } StreamRefStaticsVtbl;
            typedef struct { StreamRefStaticsVtbl *v; } StreamRefStatics;
            typedef struct { void *r0[6]; int32_t (*get_Value)(void *self, int32_t *button); } ArgsVtbl;
            typedef struct { ArgsVtbl *v; } Args;
            typedef struct { void *r0[6]; int32_t (*get_Value)(void *self, int64_t *t); } PositionArgsVtbl;
            typedef struct { PositionArgsVtbl *v; } PositionArgs;
            int32_t CoInitializeEx(void *reserved, uint32_t model);
            int32_t RoGetActivationFactory(void *classId, const void *iid, void **factory);
            int32_t RoActivateInstance(void *classId, void **instance);
            int32_t WindowsCreateString(const void *s, uint32_t length, void **string);
            int32_t WindowsDeleteString(void *string);
            C, 'combase.dll');
    }
}
