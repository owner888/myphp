<?php
declare(strict_types=1);

namespace MyPHP;

/** What a document's command throws when the user cancels it, in a dialog it showed (mygo's ErrCanceled). */
final class DocumentCanceled extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('myphp: canceled');
    }
}
