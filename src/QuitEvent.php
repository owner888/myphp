<?php
declare(strict_types=1);

namespace MyPHP;

/** Given to App::onBeforeQuit's listeners as the app is asked to quit (mygo's QuitEvent); cancel() keeps it running. */
final class QuitEvent
{
    public bool $canceled = false;

    public function cancel(): void
    {
        $this->canceled = true;
    }
}
