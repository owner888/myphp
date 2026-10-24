<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** A side of a diff (mygo's editor.DiffSide); its value indexes what each side has. */
enum DiffSide: int
{
    case DiffOld = 0;
    case DiffNew = 1;
}
