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

    public readonly NotificationOptions $options;
    /** Whether the app is signed with the Communication Notifications entitlement and the system has what a sender's notification needs; asked once. */
    private static ?bool $communication = null;

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
        if ($o->sender !== null) {
            $content = $this->communicationContent($content);
        }
        $request = ObjC::msg('p:ppp', ObjC::cls('UNNotificationRequest'), 'requestWithIdentifier:content:trigger:', ObjC::str($this->id), $content, null);
        ObjC::msg('v:pp', $center, 'addNotificationRequest:withCompletionHandler:', $request, ObjC::noopBlock());
        self::$byId[$this->id] = $this;
    }

    /**
     * $content made a direct message from the sender (mygo's
     * addCommunicationNotification): an incoming INSendMessageIntent of the
     * sender, as an INPerson of the app's id with the avatar, the subtitle
     * above the body, the group the conversation, donated to SiriKit, and
     * the content updated from it, which shows the avatar. Without the
     * capability, with an incomplete sender or an avatar no image, or when
     * the update fails, $content as it is.
     */
    /**
     * The avatar's bytes as the Intents image service takes them (mygo's
     * communicationAvatar): what NSImage reads, as a PNG, since NSImage
     * reads SVG, which the service refuses; null for what it can't read,
     * which keeps the ordinary notification.
     */
    private static function communicationAvatar(string $avatar)
    {
        $data = ObjC::msg('p:sL', ObjC::cls('NSData'), 'dataWithBytes:length:', $avatar, strlen($avatar));
        $decoded = ObjC::msg('p:p', ObjC::alloc('NSImage'), 'initWithData:', $data);
        if ($decoded === null) {
            return null;
        }
        $rep = ObjC::msg('p:p', ObjC::cls('NSBitmapImageRep'), 'imageRepWithData:', ObjC::msg('p:', $decoded, 'TIFFRepresentation'));
        $png = $rep === null ? null : ObjC::msg('p:Lp', $rep, 'representationUsingType:properties:', 4, ObjC::msg('p:', ObjC::cls('NSDictionary'), 'dictionary')); // NSBitmapImageFileTypePNG
        ObjC::msg('v:', $decoded, 'release');
        return $png !== null && ObjC::msg('L:', $png, 'length') > 0 ? $png : null;
    }

    private function communicationContent($content)
    {
        $o = $this->options; $s = $o->sender;
        if ($s->id === '' || $s->avatar === '' || !self::communicationAuthorized()) {
            return $content;
        }
        $data = self::communicationAvatar($s->avatar);
        if ($data === null) {
            return $content;
        }
        $handle = ObjC::msg('p:pl', ObjC::alloc('INPersonHandle'), 'initWithValue:type:', ObjC::str($s->id), 0); // unknown: the app's own id, no contact detail
        $person = ObjC::msg('p:ppppppbl', ObjC::alloc('INPerson'), 'initWithPersonHandle:nameComponents:displayName:image:contactIdentifier:customIdentifier:isMe:suggestionType:',
            $handle, null, ObjC::str($s->name), ObjC::msg('p:p', ObjC::cls('INImage'), 'imageWithImageData:', $data), null, ObjC::str($s->id), false, 0);
        $body = $o->subtitle === '' ? $o->body : $o->subtitle . ($o->body !== '' ? "\n" . $o->body : '');
        $intent = ObjC::msg('p:plpppppp', ObjC::alloc('INSendMessageIntent'), 'initWithRecipients:outgoingMessageType:content:speakableGroupName:conversationIdentifier:serviceName:sender:attachments:',
            null, 1, ObjC::str($body), null, ObjC::str($o->group !== '' ? $o->group : $this->id), null, $person, null); // 1: a text message
        $interaction = ObjC::msg('p:pp', ObjC::alloc('INInteraction'), 'initWithIntent:response:', $intent, null);
        ObjC::msg('v:l', $interaction, 'setDirection:', 2); // incoming
        // Its completion comes on another queue, where PHP cannot run: the content is updated from the intent at once, which needs no answer of the donation.
        ObjC::msg('v:p', $interaction, 'donateInteractionWithCompletion:', ObjC::noopBlock());
        $prepared = ObjC::msg('p:', $content, 'mutableCopy');
        ObjC::msg('v:p', $prepared, 'setBody:', ObjC::str($body));
        $err = ObjC::$c->new('void*[1]');
        $updated = ObjC::msg('p:pp', $prepared, 'contentByUpdatingWithProvider:error:', $intent, ObjC::$c->cast('void*', \FFI::addr($err)));
        foreach ([$handle, $person, $intent, $interaction] as $obj) { ObjC::msg('v:', $obj, 'release'); }
        return $err[0] === null && $updated !== null ? $updated : $content;
    }

    /** Whether the app may show senders: Intents has what it needs, and the app is signed with com.apple.developer.usernotifications.communication (mygo's communicationAuthorized), asked once. */
    private static function communicationAuthorized(): bool
    {
        if (self::$communication !== null) {
            return self::$communication;
        }
        self::$communication = false;
        if (ObjC::$c->dlopen('/System/Library/Frameworks/Intents.framework/Intents', 2 | 8) === null || ObjC::$c->objc_getClass('INSendMessageIntent') === null
            || !ObjC::msg('b:p', ObjC::cls('UNMutableNotificationContent'), 'instancesRespondToSelector:', ObjC::sel('contentByUpdatingWithProvider:error:'))) {
            return false;
        }
        $sec = \FFI::cdef('void* SecTaskCreateFromSelf(void*); void* SecTaskCopyValueForEntitlement(void*, void*, void*); void CFRelease(void*);', '/System/Library/Frameworks/Security.framework/Security');
        $task = $sec->SecTaskCreateFromSelf(null);
        if ($task === null) {
            return false;
        }
        $value = $sec->SecTaskCopyValueForEntitlement($task, ObjC::str('com.apple.developer.usernotifications.communication'), null);
        $sec->CFRelease($task);
        if ($value === null) {
            return false;
        }
        self::$communication = ObjC::msg('b:', $value, 'boolValue');
        $sec->CFRelease($value);
        return self::$communication;
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
