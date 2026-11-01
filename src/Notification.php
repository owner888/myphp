<?php
declare(strict_types=1);

namespace MyPHP;

use RuntimeException;

/**
 * A desktop notification, as mygo's Notification. On macOS the center only
 * serves apps running from a bundle with an identifier (`myphp build`, not
 * `myphp dev` nor the exec launcher): ask supported() first. The first
 * show() asks the user for permission; what is shown before they answer is
 * dropped by the system.
 */
final class Notification
{
    /** @var array<string, Notification> those shown, whose clicks reach their onClick listeners */
    private static array $byId = [];
    public readonly string $id;
    /** @var array<int, \Closure> */
    private array $onClick = [];
    private int $next = 0;

    public readonly NotificationOptions $options;

    /** A notification of $options, which it copies, a sender with them, its name the title when it has none (mygo's NewNotification). */
    public function __construct(NotificationOptions $options)
    {
        $this->options = clone $options;
        if ($options->sender !== null) {
            $this->options->sender = clone $options->sender;
            if ($this->options->sender->name === '') { $this->options->sender->name = $options->title; }
        }
        $this->id = $options->id !== '' ? $options->id : 'myphp-' . bin2hex(random_bytes(8));
    }

    /** Whether notifications can be shown: on macOS, the app runs from a bundle with an identifier. */
    public static function supported(): bool
    {
        return App::backend()->notificationsSupported();
    }

    public function show(): void
    {
        App::backend()->showNotification($this);
        self::$byId[$this->id] = $this;
    }

    /** Calls $fn when the user clicks the notification, before App::onNotificationClick's listeners (mygo's OnClick). Returns a function that removes it. */
    public function onClick(\Closure $fn): \Closure
    {
        $k = $this->next++;
        $this->onClick[$k] = $fn;
        return function () use ($k): void { unset($this->onClick[$k]); };
    }

    /** @internal the user clicked the notification of $id: its onClick listeners, then the app's */
    public static function clicked(string $id): void
    {
        foreach ((self::$byId[$id] ?? null)?->onClick ?? [] as $fn) {
            $fn();
        }
        App::notificationClicked($id);
    }

    /** Takes the notification out of the system's notifications. */
    public function close(): void
    {
        unset(self::$byId[$this->id]);
        App::backend()->removeNotification($this->id);
    }

    /**
     * Takes every notification of the app out of the system's notifications,
     * on macOS those of earlier runs too (mygo's ClearNotifications): an app
     * calls it as it comes to the front, so that what the user has seen in
     * the app does not wait there:
     *
     *     App::onDidBecomeActive(fn() => Notification::clear());
     */
    public static function clear(): void
    {
        self::$byId = [];
        App::backend()->removeAllNotifications();
    }
}
