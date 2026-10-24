<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Updater;

use Closure;
use DateTimeImmutable;
use MyPHP\{App, PathName, Power, Updater as Core};
use MyPHP\Native\Bundle;
use Throwable;

/**
 * The plugin's state (mygo's updater): the user's choices, kept in
 * updater.json; the automatic checks; the session under way, whose window
 * $open shows. What it asks of the app goes through closures that tests
 * replace.
 *
 * @internal
 */
final class Checker
{
    public const STATE_FILE = 'updater.json';
    /** The size of the window's content, for status views and for views with release notes (mygo's frontend.Size). */
    public const STATUS_WIDTH = 480, STATUS_HEIGHT = 148, RELEASE_WIDTH = 620, RELEASE_HEIGHT = 440;

    /** @var Closure(): bool whether the app can update itself */
    public Closure $enabled;
    /** @var Closure(Closure(): bool): ?Release checks for an update until canceled */
    public Closure $check;
    /** @var Closure(): void */
    public Closure $relaunch;
    /** @var Closure(): string */
    public Closure $appVersion;
    /** @var Closure(): string */
    public Closure $locale;
    /** @var Closure(): DateTimeImmutable */
    public Closure $now;
    /** @var Closure(): string the directory of updater.json */
    public Closure $stateDir;
    /** @var Closure(float, Closure): void runs a function seconds later */
    public Closure $after;
    /** @var Closure(Closure): void runs a function that waits, as a goroutine (App::go) */
    public Closure $go;
    public float $firstCheckWait = 10;
    /** @var Closure(Session): void creates the window of a session and shows it */
    public Closure $open;

    private ?Text $text = null;
    private bool $loaded = false;
    /** "" when the state cannot be saved. */
    private string $file = '';
    /** @var array{automaticChecks: ?bool, automaticDownloads: bool, skippedVersion: string, lastCheck: ?DateTimeImmutable} */
    public array $state = ['automaticChecks' => null, 'automaticDownloads' => false, 'skippedVersion' => '', 'lastCheck' => null];
    /** @var array<int, Closure> the functions of onChange */
    private array $changes = [];
    private int $nextChange = 0;
    /** Set once the app is ready and can update itself: only then are checks scheduled. */
    public bool $running = false;
    /** The generation of the timer of the next automatic check, null when none is armed. */
    public ?int $timer = null;
    private int $timers = 0;
    /** When the last automatic check failed, which retries sooner than the interval. */
    public ?DateTimeImmutable $failed = null;
    /** The check in progress, and the update installed while the app runs, which runs at the next launch. */
    public ?Session $session = null;
    public ?Release $installed = null;

    public readonly Options $opts;

    /** @param ?Closure(Checker, Session): void $open creates the window of a session and shows it: the web page's by default */
    public function __construct(Options $opts, ?Closure $open = null)
    {
        $this->opts = clone $opts; // the app's own stay as they are
        if ($this->opts->interval <= 0) {
            $this->opts->interval = 24 * 3600;
        }
        $this->enabled = fn(): bool => Core::enabled();
        $this->check = function (Closure $canceled): ?Release {
            $up = Core::check($canceled);
            return $up === null ? null : new Release($up->version, $up->notes, fn(?Closure $progress, Closure $canceled) => $up->install($progress, $canceled));
        };
        $this->relaunch = fn() => App::relaunch();
        $this->appVersion = fn(): string => App::version();
        $this->locale = fn(): string => App::locale();
        $this->now = fn(): DateTimeImmutable => new DateTimeImmutable;
        $this->stateDir = fn(): string => App::path(PathName::UserData);
        $this->after = fn(float $s, Closure $fn) => App::after($s, $fn);
        $this->go = fn(Closure $fn) => App::go($fn);
        $open ??= UpdateWindow::open(...);
        $this->open = fn(Session $s) => $open($this, $s);
    }

    /** The size of the window's content for a status view or a release view. @return array{0: int, 1: int} */
    public static function size(bool $release): array
    {
        return $release ? [self::RELEASE_WIDTH, self::RELEASE_HEIGHT] : [self::STATUS_WIDTH, self::STATUS_HEIGHT];
    }

    /** The texts of the window, in the language chosen once. */
    public function text(): Text
    {
        return $this->text ??= Text::for($this->opts->language !== '' ? $this->opts->language : ($this->locale)(), $this->opts->strings);
    }

    /** The PNG image of the update window: Options::$icon, else the project's icon.png among the app's resources, or null. */
    public function icon(): ?string
    {
        if ($this->opts->icon !== null) {
            return $this->opts->icon;
        }
        $app = Bundle::path();
        $png = $app === null ? false : @file_get_contents("$app/Contents/Resources/app/icon.png");
        return $png === false ? null : $png;
    }

    /** Schedules the automatic checks, once the app is ready. */
    public function start(): void
    {
        if (!($this->enabled)()) {
            return;
        }
        $this->load();
        $this->running = true;
        // Timers stop while the computer sleeps: check what is due on waking.
        Power::onResume(fn() => $this->schedule());
        $this->schedule();
    }

    /** Reads the state file, once. */
    public function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;
        try {
            $this->file = ($this->stateDir)() . '/' . self::STATE_FILE;
        } catch (Throwable) {
            return;
        }
        $data = @file_get_contents($this->file);
        $j = $data === false ? null : json_decode($data, true);
        if (!is_array($j)) {
            return; // none yet, or damaged
        }
        $this->state = [
            'automaticChecks' => is_bool($j['automaticChecks'] ?? null) ? $j['automaticChecks'] : null,
            'automaticDownloads' => ($j['automaticDownloads'] ?? false) === true,
            'skippedVersion' => is_string($j['skippedVersion'] ?? null) ? $j['skippedVersion'] : '',
            'lastCheck' => is_string($j['lastCheck'] ?? null) ? (self::time($j['lastCheck']) ?? null) : null,
        ];
    }

    private static function time(string $s): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($s);
        } catch (Throwable) {
            return null;
        }
    }

    /** Changes the state, saves it, and calls the functions of onChange when it changed. @param Closure(array): array $fn */
    public function update(Closure $fn): void
    {
        $this->load();
        $before = $this->state;
        $this->state = $fn($this->state);
        $this->save();
        if ($this->state != $before) {
            foreach ($this->changes as $f) {
                $f();
            }
        }
    }

    /** Writes the state file. */
    private function save(): void
    {
        if ($this->file === '') {
            return;
        }
        $s = $this->state;
        $out = [];
        if ($s['automaticChecks'] !== null) {
            $out['automaticChecks'] = $s['automaticChecks'];
        }
        if ($s['automaticDownloads']) {
            $out['automaticDownloads'] = true;
        }
        if ($s['skippedVersion'] !== '') {
            $out['skippedVersion'] = $s['skippedVersion'];
        }
        if ($s['lastCheck'] !== null) {
            $out['lastCheck'] = $s['lastCheck']->format(DATE_RFC3339_EXTENDED);
        }
        if (@file_put_contents("{$this->file}.tmp", json_encode((object)$out)) !== false) {
            @rename("{$this->file}.tmp", $this->file);
        }
    }

    /** @return Closure(): void that removes $fn */
    public function onChange(Closure $fn): Closure
    {
        $k = $this->nextChange++;
        $this->changes[$k] = $fn;
        return function () use ($k): void { unset($this->changes[$k]); };
    }

    public function automaticChecks(): bool
    {
        return $this->state['automaticChecks'] ?? !$this->opts->disableAutomaticChecks;
    }

    /** Arms the timer of the next automatic check. */
    public function schedule(): void
    {
        $this->timer = null;
        if (!$this->running || $this->session !== null || $this->installed !== null || !$this->automaticChecks()) {
            return;
        }
        $now = ($this->now)();
        $wait = max(self::nextCheck($this->state['lastCheck'], $this->failed, $this->opts->interval, $now) - self::seconds($now), $this->firstCheckWait);
        $gen = $this->timer = ++$this->timers;
        ($this->after)($wait, function () use ($gen): void {
            if ($this->timer === $gen) {
                ($this->go)(fn() => $this->begin(false));
            }
        });
    }

    /**
     * When the next automatic check is due, in Unix seconds: an interval
     * after the last successful one (or after Go's zero time, long ago,
     * when there was none), and when a check failed since, an hour after
     * that at most.
     */
    public static function nextCheck(?DateTimeImmutable $last, ?DateTimeImmutable $failed, float $interval, DateTimeImmutable $now): float
    {
        $at = $last === null ? self::ZERO_TIME : self::seconds($last);
        if ($at > self::seconds($now)) { // the clock was set back
            $at = self::seconds($now);
        }
        $due = $at + $interval;
        if ($failed !== null && ($retry = self::seconds($failed) + min($interval, 3600.0)) > $due) {
            $due = $retry;
        }
        return $due;
    }

    /** Go's zero time, January 1 of year 1, in Unix seconds. */
    public const ZERO_TIME = -62135596800.0;

    public static function seconds(DateTimeImmutable $t): float
    {
        return (float)$t->format('U.u');
    }

    /** Records the outcome of a check. */
    private function checked(?Throwable $err): void
    {
        if ($err !== null) {
            $this->failed = ($this->now)();
            return;
        }
        $this->failed = null;
        $t = ($this->now)();
        $this->update(fn(array $s): array => ['lastCheck' => $t] + $s);
    }

    /** Starts a check, or makes the one under way show its window when the user asked for it. Runs in a fiber of its own. */
    public function begin(bool $user): void
    {
        $this->load();
        if ($this->session !== null) {
            if ($user) {
                $this->session->promote();
            }
            return;
        }
        if (!$user && $this->installed !== null) {
            return;
        }
        $s = $this->session = new Session($this, $user);
        $this->timer = null;
        try {
            $this->run($s);
        } finally {
            $this->end($s);
        }
    }

    /** Closes the window of a session and schedules the next check. */
    private function end(Session $s): void
    {
        if ($this->session === $s) {
            $this->session = null;
        }
        $s->close();
        $this->schedule();
    }

    /** Checks for updates and drives the update window. */
    private function run(Session $s): void
    {
        $t = $this->text();
        if (!($this->enabled)()) {
            $s->set($this->unavailableView());
            $s->show();
            $s->wait();
            return;
        }
        if ($this->installed !== null) {
            $this->offerRelaunch($s, $this->installed);
            return;
        }
        $s->set(['title' => $t->s->checking, 'bar' => true, 'progress' => -1, 'buttons' => $this->cancelButton(false)]);
        if ($s->user) {
            $s->show();
        }
        $err = null;
        $r = null;
        try {
            $r = ($this->check)(fn(): bool => $s->canceled);
        } catch (Throwable $e) {
            $err = $e;
        }
        if ($s->canceled) {
            return; // canceled
        }
        $this->checked($err);
        if ($err !== null) {
            if ($s->user) {
                $s->set($this->errorView($t->s->checkError, $err));
                $s->wait();
            }
            return;
        }
        if ($r === null) {
            if ($s->user) {
                $s->set(['title' => $t->s->upToDate, 'message' => sprintf($t->s->upToDateMessage, App::name(), ($this->appVersion)()), 'buttons' => $this->okButton()]);
                $s->wait();
            }
            return;
        }
        $skipped = $this->state['skippedVersion'] === $r->version;
        $automatic = $this->state['automaticDownloads'];
        if (!$s->user) {
            if ($skipped) {
                return;
            }
            if ($automatic) {
                $this->install($s, $r);
                return;
            }
        }
        $s->set($this->availableView($r, $automatic));
        $s->show();
        [$action, $auto] = $s->wait();
        if ($action === 'close') {
            return;
        }
        $this->update(function (array $st) use ($auto, $action, $r): array {
            $st['automaticDownloads'] = $auto;
            if ($action === 'skip') {
                $st['skippedVersion'] = $r->version;
            }
            return $st;
        });
        if ($action === 'install') {
            $this->install($s, $r);
        }
    }

    /** Downloads and installs $r, showing its progress when the window shows, then offers to relaunch. */
    private function install(Session $s, Release $r): void
    {
        $t = $this->text();
        $s->set($this->downloadingView(0, 0));
        $err = null;
        try {
            ($r->install)(function (int $downloaded, int $total) use ($s, $t): void {
                if ($downloaded < $total) {
                    $s->set($this->downloadingView($downloaded, $total));
                } else {
                    $s->set(['title' => $t->s->installing, 'bar' => true, 'progress' => -1, 'buttons' => $this->cancelButton(true)]);
                }
            }, fn(): bool => $s->canceled);
        } catch (Throwable $e) {
            $err = $e;
        }
        if ($s->canceled) {
            return; // canceled
        }
        if ($err !== null) {
            if ($s->user || $s->shown) {
                $s->set($this->errorView($t->s->installError, $err));
                $s->show();
                $s->wait();
            }
            return;
        }
        $this->installed = $r;
        if ($s->user || $s->shown) {
            $this->offerRelaunch($s, $r);
        }
    }

    private function offerRelaunch(Session $s, Release $r): void
    {
        $t = $this->text();
        $s->set([
            'title' => $t->s->ready,
            'message' => sprintf($t->s->readyMessage, App::name(), $r->version),
            'buttons' => [['action' => 'later', 'label' => $t->s->later, 'cancel' => true], ['action' => 'relaunch', 'label' => $t->s->relaunch, 'default' => true]],
        ]);
        $s->show();
        if ($s->wait()[0] === 'relaunch') {
            ($this->relaunch)();
        }
    }

    /** @return list<array<string, mixed>> */
    private function okButton(): array
    {
        return [['action' => 'ok', 'label' => $this->text()->s->ok, 'default' => true, 'cancel' => true]];
    }

    /** @return list<array<string, mixed>> */
    private function cancelButton(bool $disabled): array
    {
        return [['action' => 'cancel', 'label' => $this->text()->s->cancel, 'cancel' => true] + ($disabled ? ['disabled' => true] : [])];
    }

    /** @return array<string, mixed> */
    private function unavailableView(): array
    {
        $t = $this->text();
        $msg = App::isDev() ? $t->s->developmentBuild : sprintf($t->s->unavailableMessage, App::name());
        return ['title' => $t->s->unavailable, 'message' => $msg, 'buttons' => $this->okButton()];
    }

    /** @return array<string, mixed> */
    private function errorView(string $msg, Throwable $err): array
    {
        return ['title' => $this->text()->s->error, 'message' => $msg, 'detail' => $err->getMessage(), 'buttons' => $this->okButton()];
    }

    /** @return array<string, mixed> */
    private function availableView(Release $r, bool $automaticDownloads): array
    {
        $t = $this->text();
        $name = App::name();
        $notes = Markdown::parse($r->notes);
        return [
            'release' => $notes !== [],
            'title' => sprintf($t->s->available, $name),
            'message' => sprintf($t->s->availableMessage, $name, $r->version, ($this->appVersion)()),
            'notes' => $notes,
            'checkbox' => true,
            'checked' => $automaticDownloads,
            'buttons' => [
                ['action' => 'skip', 'label' => $t->s->skip, 'aside' => true],
                ['action' => 'later', 'label' => $t->s->remindLater, 'cancel' => true],
                ['action' => 'install', 'label' => $t->s->install, 'default' => true],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function downloadingView(int $downloaded, int $total): array
    {
        $t = $this->text();
        $v = ['title' => $t->s->downloading, 'bar' => true, 'progress' => -1, 'buttons' => $this->cancelButton(false)];
        if ($total > 0) {
            $v['progress'] = $downloaded / $total;
            $v['message'] = sprintf($t->s->progress, $t->megabytes($downloaded), $t->megabytes($total));
        }
        return $v;
    }
}
