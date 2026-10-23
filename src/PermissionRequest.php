<?php
declare(strict_types=1);

namespace MyPHP;

/** What a page asks for, which the window's permission handler decides (mygo's PermissionRequest). */
final class PermissionRequest
{
    /** The permissions (mygo's Permission…). */
    public const CAMERA = 'camera', MICROPHONE = 'microphone', GEOLOCATION = 'geolocation', NOTIFICATIONS = 'notifications';

    public function __construct(
        /** @var list<string> the permissions asked for together, as the camera and the microphone of a video call */
        public readonly array $permissions,
        /** The origin of the page asking, as "https://example.com". */
        public readonly string $origin,
    ) {}
}
