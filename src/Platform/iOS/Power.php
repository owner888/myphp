<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use MyPHP\Native\ObjC;
use MyPHP\Platform;

final class Power implements Platform\Power
{
    private int $keepAwakeCount = 0;

    public function keepAwake(bool $keep): void
    {
        if ($keep) {
            $this->keepAwakeCount++;
        } else {
            $this->keepAwakeCount = max(0, $this->keepAwakeCount - 1);
        }
        $app = ObjC::cls('UIApplication')?->msg('sharedApplication');
        if ($app !== null) {
            $app->msg('setIdleTimerDisabled:', $this->keepAwakeCount > 0);
        }
    }
}
