<?php
declare(strict_types=1);

namespace MyPHP;

/** Whether what the app shows in Now Playing plays (mygo's PlaybackState). */
enum PlaybackState
{
    /** The default: an app usually shows what it plays as it starts playing. */
    case Playing;
    case Paused;
    case Stopped;
}
