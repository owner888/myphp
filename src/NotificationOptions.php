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
        /** Notifications sharing a group stack together in Notification Center. */
        public string $group = '',
    ) {}
}
