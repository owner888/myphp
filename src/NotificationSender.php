<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * The sender of an incoming direct message (NotificationOptions::$sender,
 * mygo's NotificationSender): $id a stable identifier of the person in the
 * app, not taken for an email address or a phone number; $name what shows,
 * the notification's title when empty; $avatar an encoded image, PNG or
 * JPEG, which the app fetches itself. An empty id or avatar leaves the
 * notification ordinary.
 */
final class NotificationSender
{
    public function __construct(
        public string $id = '',
        public string $name = '',
        public string $avatar = '',
    ) {}
}
