<?php
declare(strict_types=1);

namespace MyPHP;

/** Whether a page captures a kind of device (Page::captureState, mygo's CaptureState): WKMediaCaptureState's values. */
enum CaptureState: int
{
    /** The page captures no such device. */
    case None = 0;
    /** A capture going on: the page gets the device's sound or picture. */
    case Active = 1;
    /** A capture muted, by Page::setCaptureState or the system: the page keeps its tracks and gets silence or black frames. */
    case Muted = 2;
}
