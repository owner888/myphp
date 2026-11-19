<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * Foundation's Progress, the part a transfer reports: how many units there are (bytes), how many are done, as a
 * fraction for a ProgressView(value:total:) (`ProgressView('Uploading', value: $p->completedUnitCount,
 * total: $p->totalUnitCount)`), and whether it was cancelled.
 */
final class Progress
{
    public function __construct(public int $totalUnitCount = 0, public int $completedUnitCount = 0)
    {
    }

    private bool $cancelled = false;

    /** The part done, 0 to 1: 0 while the total is not known (Foundation's -1 for "indeterminate" is not used). */
    public float $fractionCompleted {
        get => $this->totalUnitCount > 0 ? min(1.0, max(0.0, $this->completedUnitCount / $this->totalUnitCount)) : 0.0;
    }

    public bool $isCancelled {
        get => $this->cancelled;
    }

    /** Asks that the work ends: a transfer ends at its next report (URLError cancelled). */
    public function cancel(): void
    {
        $this->cancelled = true;
    }
}
