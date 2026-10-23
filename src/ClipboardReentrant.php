<?php
declare(strict_types=1);

namespace MyPHP;

use RuntimeException;

/** A provider of clipboard data tried to use the clipboard while the system asked it for its data (mygo's ErrClipboardReentrant). */
final class ClipboardReentrant extends RuntimeException {}
