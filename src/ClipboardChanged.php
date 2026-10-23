<?php
declare(strict_types=1);

namespace MyPHP;

use RuntimeException;

/** The clipboard changed hands while it was read or flushed (mygo's ErrClipboardChanged). */
final class ClipboardChanged extends RuntimeException {}
