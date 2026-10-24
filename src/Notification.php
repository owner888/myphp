<?php
declare(strict_types=1);

namespace MyPHP;

use MyPHP\Native\ObjC;
use RuntimeException;

/**
 * A desktop notification through UNUserNotificationCenter, as mygo's
 * Notification. The center only serves apps running from a bundle with an
 * identifier (`myphp build`, not `myphp dev` nor the exec launcher): ask
 * supported() first. The first show() asks the user for permission; what
 * is shown before they answer is dropped by the system.
 */
final class Notification
{
    private const ASK_BADGE_SOUND_ALERT = 1 | 2 | 4;
    private static bool $asked = false;
    /** @var array<string, Notification> those shown, whose clicks reach their onClick listeners */
    private static array $byId = [];
    public readonly string $id;
    /** @var array<int, \Closure> */
    private array $onClick = [];
    private int $next = 0;

    public function __construct(public readonly NotificationOptions $options)
    {
        $this->id = $options->id !== '' ? $options->id : 'myphp-' . bin2hex(random_bytes(8));
    }

    /** Whether notifications can be shown: the app runs from a bundle with an identifier. */
    public static function supported(): bool
    {
        $bundle = ObjC::msg('p:', ObjC::cls('NSBundle'), 'mainBundle');
        return ObjC::msg('p:', $bundle, 'bundleIdentifier') !== null && ObjC::$c->objc_getClass('UNUserNotificationCenter') !== null;
    }

    private static function center()
    {
        if (!self::supported()) {
            throw new RuntimeException('notifications need the app packaged in a bundle (myphp build)');
        }
        return ObjC::msg('p:', ObjC::cls('UNUserNotificationCenter'), 'currentNotificationCenter');
    }

    public function show(): void
    {
        $center = self::center();
        if (!self::$asked) {
            self::$asked = true;
            // The answer arrives on a background queue, where PHP cannot run: a no-op block takes it.
            ObjC::msg('v:Lp', $center, 'requestAuthorizationWithOptions:completionHandler:', self::ASK_BADGE_SOUND_ALERT, ObjC::noopBlock());
        }
        $o = $this->options;
        $content = ObjC::msg('p:', ObjC::alloc('UNMutableNotificationContent'), 'init');
        ObjC::msg('v:p', $content, 'setTitle:', ObjC::str($o->title));
        if ($o->subtitle !== '') {
            ObjC::msg('v:p', $content, 'setSubtitle:', ObjC::str($o->subtitle));
        }
        ObjC::msg('v:p', $content, 'setBody:', ObjC::str($o->body));
        if (!$o->silent) {
            ObjC::msg('v:p', $content, 'setSound:', ObjC::msg('p:', ObjC::cls('UNNotificationSound'), 'defaultSound'));
        }
        if ($o->group !== '') {
            ObjC::msg('v:p', $content, 'setThreadIdentifier:', ObjC::str($o->group));
        }
        $request = ObjC::msg('p:ppp', ObjC::cls('UNNotificationRequest'), 'requestWithIdentifier:content:trigger:', ObjC::str($this->id), $content, null);
        ObjC::msg('v:pp', $center, 'addNotificationRequest:withCompletionHandler:', $request, ObjC::noopBlock());
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

    /** Takes the notification out of Notification Center. */
    public function close(): void
    {
        $ids = ObjC::msg('p:', ObjC::cls('NSMutableArray'), 'array');
        ObjC::msg('v:p', $ids, 'addObject:', ObjC::str($this->id));
        unset(self::$byId[$this->id]);
        $center = self::center();
        ObjC::msg('v:p', $center, 'removeDeliveredNotificationsWithIdentifiers:', $ids);
        ObjC::msg('v:p', $center, 'removePendingNotificationRequestsWithIdentifiers:', $ids);
    }

    /**
     * Takes every notification of the app out of Notification Center, those
     * of earlier runs too (mygo's ClearNotifications): an app calls it as it
     * comes to the front, so that what the user has seen in the app does
     * not wait there:
     *
     *     App::onDidBecomeActive(fn() => Notification::clear());
     */
    public static function clear(): void
    {
        self::$byId = [];
        $center = self::center();
        ObjC::msg('v:', $center, 'removeAllDeliveredNotifications');
        ObjC::msg('v:', $center, 'removeAllPendingNotificationRequests');
    }

    /** The delegate that shows banners while the app is frontmost and reports clicks; App attaches it at launch. */
    public static function attachDelegate(): void
    {
        if (!self::supported()) {
            return;
        }
        ObjC::defineClass('MyPHPNotificationDelegate', 'NSObject', [
            'userNotificationCenter:willPresentNotification:withCompletionHandler:' => ['v@:@@@?', 'v(ppppp)', function ($self, $cmd, $center, $note, $handler) {
                ObjC::callBlock('v:L', $handler, 2 | 8 | 16); // sound, list, banner: show while the app is frontmost too
            }],
            'userNotificationCenter:didReceiveNotificationResponse:withCompletionHandler:' => ['v@:@@@?', 'v(ppppp)', function ($self, $cmd, $center, $response, $handler) {
                $id = ObjC::phpStr(ObjC::msg('p:', ObjC::msg('p:', ObjC::msg('p:', $response, 'notification'), 'request'), 'identifier'));
                self::clicked($id);
                ObjC::callBlock('v:', $handler);
            }],
        ], ['UNUserNotificationCenterDelegate']);
        ObjC::msg('v:p', self::center(), 'setDelegate:', ObjC::msg('p:', ObjC::alloc('MyPHPNotificationDelegate'), 'init'));
    }
}
