<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

/** How a paste a program reads itself goes (Options::$onPasteStatus, mygo's terminal.PasteStatus). */
enum PasteStatus: int
{
    /** The program was told what the paste holds. */
    case PasteOffered = 0;
    /** The program reads it, and Sent of Total went so far. */
    case PasteSending = 1;
    /** All of it went. */
    case PasteSent = 2;
    /** The program has not read it 5 seconds after it was offered. It may still, as long as no other paste comes. */
    case PasteNotTaken = 3;
    /** The paste had no text, as only an image, and the program does not read the clipboard, so nothing was pasted. */
    case PasteNoText = 4;
}
