<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * Given to a page's 'contextMenu' listeners as its context menu is about to
 * open (mygo's ContextMenuEvent of Page.OnContextMenu, as Electron's
 * context-menu event): what was right-clicked, and $menu, the menu the web
 * view is about to show, which listeners change or replace.
 * preventDefault() shows no menu.
 *
 * $menu holds the web view's own items, each with its id (the constants
 * below, the same on every platform that has the item; WebKit's identifier
 * for the others, as "WKMenuItemIdentifierTranslateMenu"; "" for items it
 * names none of), its label and its state: hide, rename, disable or move
 * them, remove them with Menu::remove, and add the app's own with append
 * and insert. The web view's items do what the web view does, unless the
 * app gives one a click of its own, which runs in its place. Setting $menu
 * to a menu of the app's own shows that instead, and null shows none.
 */
final class ContextMenuEvent
{
    public const BACK = 'back', FORWARD = 'forward', RELOAD = 'reload', STOP = 'stop';
    /** Inspect Element, with the developer tools. */
    public const INSPECT = 'inspect';
    public const UNDO = 'undo', REDO = 'redo', CUT = 'cut', COPY = 'copy', PASTE = 'paste', PASTE_AND_MATCH_STYLE = 'pasteAndMatchStyle', DELETE = 'delete', SELECT_ALL = 'selectAll';
    /** Each of the words suggested for a misspelled one. */
    public const SPELLING_GUESS = 'spellingGuess';
    public const OPEN_LINK = 'openLink', OPEN_LINK_IN_NEW_WINDOW = 'openLinkInNewWindow', DOWNLOAD_LINK = 'downloadLink', COPY_LINK = 'copyLink';
    public const OPEN_IMAGE_IN_NEW_WINDOW = 'openImageInNewWindow', DOWNLOAD_IMAGE = 'downloadImage', COPY_IMAGE = 'copyImage', COPY_IMAGE_URL = 'copyImageURL';
    public const OPEN_FRAME_IN_NEW_WINDOW = 'openFrameInNewWindow';
    public const OPEN_MEDIA_IN_NEW_WINDOW = 'openMediaInNewWindow', DOWNLOAD_MEDIA = 'downloadMedia', COPY_MEDIA_URL = 'copyMediaURL';
    /** Show or hide a video's controls. */
    public const MEDIA_CONTROLS = 'mediaControls';
    public const MEDIA_LOOP = 'mediaLoop', MEDIA_FULL_SCREEN = 'mediaFullScreen', PICTURE_IN_PICTURE = 'pictureInPicture';
    public const LOOK_UP = 'lookUp', SEARCH_WEB = 'searchWeb', SHARE = 'share', EMOJI = 'emoji', PRINT = 'print';

    public bool $prevented = false;

    public function __construct(
        /** The link clicked, and its text. */
        public readonly string $linkURL = '',
        public readonly string $linkText = '',
        /** The image clicked. */
        public readonly string $imageURL = '',
        /** The video or audio clicked. */
        public readonly string $mediaURL = '',
        /** The text selected where the menu opened. */
        public readonly string $selectionText = '',
        /** Over a text field or editable content, whose menu has the web view's editing items. */
        public readonly bool $editable = false,
        /** The URL of the frame clicked; "" on macOS, whose WebKit tells none, as mygo's. */
        public readonly string $frameURL = '',
        public ?Menu $menu = null,
    ) {}

    public function preventDefault(): void
    {
        $this->prevented = true;
    }
}
