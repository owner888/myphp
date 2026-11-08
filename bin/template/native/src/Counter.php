<?php
// The app's state and its actions: view.php shows it and calls them, as $this.
// Keep the view out of here, and changes to the state out of the view.
declare(strict_types=1);

final class Counter
{
    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }

    public function reset(): void
    {
        $this->count = 0;
    }
}
