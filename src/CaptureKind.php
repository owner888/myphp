<?php
declare(strict_types=1);

namespace MyPHP;

/** A kind of device a page captures with the web's camera and microphone APIs, getUserMedia() and getDisplayMedia() (mygo's CaptureKind). */
enum CaptureKind: int
{
    case Camera = 0;
    case Microphone = 1;
    /** The capture of a screen or a window, which a page starts with getDisplayMedia(). */
    case Display = 2;
}
