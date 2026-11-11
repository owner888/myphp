<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use MyPHP\{App, Clipboard, Dialog, FileFilter, OpenDialogOptions};
use MyPHP\UI\{Context, Node};

/**
 * ShareLink and PhotosPicker, as SwiftUI's:
 * - ShareLink($item, title: 'Share') copies or opens the shared item/URL.
 * - PhotosPicker('Select Photo', selection: $this->photos) opens the system photo picker.
 */
final class ShareLink extends View
{
    public function __construct(
        public readonly string $item,
        public readonly string $title = 'Share',
        public readonly ?string $systemImage = 'square.and.arrow.up',
        public readonly ?View $label = null,
    ) {}

    protected function build(Context $c, Env $env): ?Node
    {
        $item = $this->item;
        $btn = new Button($this->title, action: static function () use ($item): void {
            Clipboard::writeText($item);
        }, label: $this->label, systemImage: $this->label === null ? $this->systemImage : null);
        return $btn->render($c, $env);
    }
}