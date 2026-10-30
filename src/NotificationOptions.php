<?php
declare(strict_types=1);

namespace MyPHP;

/** What a Notification is made of, as mygo's NotificationOptions. */
final class NotificationOptions
{
    public function __construct(
        public string $title = '',
        public string $body = '',
        public string $subtitle = '',
        /** Identifies it to App::onNotificationClick(); showing one with the id of one still shown replaces that. Empty makes one. */
        public string $id = '',
        /** No sound. */
        public bool $silent = false,
        /** Notifications sharing a group stack together in Notification Center: a conversation's. */
        public string $group = '',
        /**
         * The sender of an incoming direct message: on macOS 12 and later its
         * avatar shows, with the app's small icon, when the signed app has
         * the Communication Notifications capability; otherwise, or when
         * preparing the message fails, the ordinary notification shows.
         */
        public ?NotificationSender $sender = null,
    ) {}
}
