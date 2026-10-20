<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * A run of a menu's function (mygo's menuRun): the one collecting what
 * the menu shows, or the one after an item was chosen, which finds it by
 * its place among the items and its label.
 */
final class MenuRun
{
    public int $seq = 0;
    /** @var list<string> the items' labels by place, when collecting */
    public array $labels = [];
    /** @var list<string> the editing commands of editItems()' items by place, '' for the others, when collecting */
    public array $commands = [];
    /** That an item reported the choice. */
    public bool $chosen = false;

    public function __construct(public bool $collect = false, public int $choice = 0, public string $label = '') {}
}
