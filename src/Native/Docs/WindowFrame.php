<?php
declare(strict_types=1);

namespace MyPHP\Native\Docs;

use Closure;
use MyPHP\{CloseEvent, DocumentState, Window};

/**
 * The frame of a document's Window. Its page gets the document's state
 * and reloads as the events myphp:document-state and
 * myphp:document-reload, when it is one of the app's own pages: paths are
 * none of other pages' business (mygo's trusted pages).
 *
 * @internal
 */
final class WindowFrame implements Frame
{
    public const STATE_EVENT = 'myphp:document-state', RELOAD_EVENT = 'myphp:document-reload';

    public function __construct(private readonly Window $w) {}

    public function window(): Window
    {
        return $this->w;
    }

    public function onClose(Closure $fn): void
    {
        $this->w->on('close', function (CloseEvent $e) use ($fn): void {
            if ($fn($e->canceled)) {
                $e->cancel();
            }
        });
    }

    public function onClosed(Closure $fn): void
    {
        $this->w->on('closed', fn() => $fn());
    }

    public function onFocus(Closure $fn): void
    {
        $this->w->on('focus', fn() => $fn());
    }

    public function onBlur(Closure $fn): void
    {
        $this->w->on('blur', fn() => $fn());
    }

    public function show(): void
    {
        $this->w->show();
        $this->w->focus();
    }

    public function close(): void
    {
        $this->w->close();
    }

    public function destroy(): void
    {
        $this->w->destroy();
    }

    public function isDestroyed(): bool
    {
        return $this->w->isDestroyed();
    }

    public function present(DocumentState $s): void
    {
        $this->w->setDocumentEdited($s->edited);
        $this->w->setRepresentedFile($s->path);
        $this->w->setTitle($s->name);
        if ($this->w->isOwnPage()) {
            $this->w->send(self::STATE_EVENT, $s);
        }
    }

    public function reloaded(): void
    {
        if ($this->w->isOwnPage()) {
            $this->w->send(self::RELOAD_EVENT, new \stdClass);
        }
    }
}
