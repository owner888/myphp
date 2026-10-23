<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * Gives the pages of document windows their documents (mygo's
 * DocumentService), through the generated TypeScript client: bind it
 * under the name the client calls it by,
 *
 *     Ipc::bind($notes->service(), 'Note');
 *
 * and a page reads and replaces the content of the document its window
 * shows, and saves it:
 *
 *     const text = await Note.content();
 *     await Note.setContent(text + "!");
 *     window.myphp.on("myphp:document-state", (state) => { title.textContent = state.name });
 *
 * Its methods fail for windows that show no document of its kind.
 */
final class DocumentService
{
    /** @internal Documents::service() makes it */
    public function __construct(private readonly Documents $docs) {}

    private function doc(CallContext $ctx): Document
    {
        return $this->docs->forWindow($ctx->window()) ?? throw new \RuntimeException('myphp: the window shows no document of this kind');
    }

    /** The content of the window's document. */
    public function content(CallContext $ctx): mixed
    {
        return $this->doc($ctx)->content();
    }

    /** Replaces the content of the window's document, which edits it. */
    public function setContent(CallContext $ctx, mixed $content): void
    {
        $this->doc($ctx)->set($content);
    }

    /** Describes the window's document; the event myphp:document-state tells its changes. */
    public function state(CallContext $ctx): DocumentState
    {
        return $this->doc($ctx)->state();
    }

    /** Saves the window's document, as File > Save does. */
    public function save(CallContext $ctx): void
    {
        $this->doc($ctx)->save();
    }

    /** Asks where to save the window's document, and saves it there. */
    public function saveAs(CallContext $ctx): void
    {
        $this->doc($ctx)->saveAs();
    }

    /** Opens a copy of the window's document in a new window. */
    public function duplicate(CallContext $ctx): void
    {
        $this->doc($ctx)->duplicate();
    }

    /** Asks for a new name of the window's document's file. */
    public function rename(CallContext $ctx): void
    {
        $this->doc($ctx)->rename();
    }

    /** Asks where to move the window's document's file. */
    public function moveTo(CallContext $ctx): void
    {
        $this->doc($ctx)->moveTo();
    }

    /** Asks whether to discard the unsaved changes of the window's document, and reads its file again. */
    public function revert(CallContext $ctx): void
    {
        $this->doc($ctx)->revertAsking();
    }
}
