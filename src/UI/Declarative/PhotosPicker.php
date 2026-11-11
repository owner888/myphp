<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\{App, Dialog, FileFilter, OpenDialogOptions};
use MyPHP\UI\{Context, Node};

/**
 * PhotosPicker, as SwiftUI's PhotosUI PhotosPicker: opens the system photo
 * picker and stores the chosen file path(s) into $selection (a ?string for a
 * single photo, or an array for multiple).
 */
final class PhotosPicker extends View
{
    private mixed $selection;

    /** @param list<string> $matching file extensions (jpg, png, heic, mp4…) */
    public function __construct(
        public readonly string $title,
        mixed &$selection,
        public readonly ?int $maxSelectionCount = null,
        public readonly array $matching = ['jpg', 'jpeg', 'png', 'heic', 'webp'],
        public readonly ?string $systemImage = 'photo.on.rectangle',
        public readonly ?View $label = null,
    ) {
        $this->selection = &$selection;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $multiple = is_array($this->selection) && ($this->maxSelectionCount === null || $this->maxSelectionCount > 1);
        $exts = $this->matching;
        $rt = $c->runtime;
        $btn = new Button($this->title, action: function () use ($multiple, $exts, $rt): void {
            App::go(function () use ($multiple, $exts, $rt): void {
                $files = Dialog::open(new OpenDialogOptions(
                    filters: [new FileFilter('Photos', $exts)],
                    multiple: $multiple,
                ));
                if ($files !== []) {
                    $this->selection = is_array($this->selection) ? $files : $files[0];
                    $rt?->requestFrame?->__invoke();
                }
            });
        }, label: $this->label, systemImage: $this->label === null ? $this->systemImage : null);
        return $btn->render($c, $env);
    }
}