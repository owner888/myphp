<?php
declare(strict_types=1);

namespace MyPHP;

/** What saving throws, without asking, when another app changed the document's file since it was read or saved (mygo's ErrDocumentChanged); Document::saveAnyway() writes it all the same. */
final class DocumentChanged extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('myphp: the file was changed by another application');
    }
}
