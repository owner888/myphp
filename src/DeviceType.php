<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * The form factor of the device the app runs on: desktop (macOS, Linux,
 * Windows), mobile (an iPhone or Android phone), pad (an iPad or Android
 * tablet), or tv (Apple TV, Android TV).
 */
enum DeviceType: string
{
    case Desktop = 'desktop';
    case Mobile = 'mobile';
    case Pad = 'pad';
    case Tv = 'tv';
}