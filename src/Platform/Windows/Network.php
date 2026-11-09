<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use FFI;
use MyPHP\NetworkStatus;
use MyPHP\Platform;
use MyPHP\Platform\AppHandler;

/**
 * The network's state (mygo's windows network.go): the system's
 * connectivity hint, what the Network Connectivity Status Indicator found
 * out on every interface (Windows 10 2004 and later; an older Windows does
 * not tell, so the connection is taken to work, unmetered, as the browsers
 * do). Its changes are watched by reading it again every two seconds:
 * NotifyNetworkConnectivityHintChange calls back on a thread of the
 * system's, where PHP cannot run.
 */
final class Network implements Platform\Network
{
    private static ?FFI $f = null;
    private static ?bool $has = null;
    private bool $watching = false;
    private ?NetworkStatus $last = null;

    public function __construct(private readonly Backend $b, private readonly AppHandler $h) {}

    private static function f(): ?FFI
    {
        if (self::$has === null) {
            try {
                self::$f = FFI::cdef(<<<'C'
                    typedef struct { int32_t Level, Cost; uint8_t ApproachingLimit, OverLimit, Roaming; } NL_NETWORK_CONNECTIVITY_HINT;
                    uint32_t GetNetworkConnectivityHint(NL_NETWORK_CONNECTIVITY_HINT *hint);
                    C, 'iphlpapi.dll');
                self::$has = true;
            } catch (\FFI\Exception) {
                self::$has = false; // before Windows 10 2004
            }
        }
        return self::$f;
    }

    /**
     * The hint as macOS and Linux report theirs (mygo's networkStatus):
     * online beyond the computer, which local access, internet access and
     * one behind a captive portal are, and an unknown level is taken to be,
     * no connectivity and a hidden network not; metered as Microsoft's
     * guidance for metered connections says, any cost but unrestricted, or
     * roaming, over the data limit or approaching it.
     */
    public function status(): NetworkStatus
    {
        $f = self::f();
        if ($f === null) {
            return new NetworkStatus(online: true);
        }
        $h = $f->new('NL_NETWORK_CONNECTIVITY_HINT');
        if ($f->GetNetworkConnectivityHint(FFI::addr($h)) !== 0) {
            return new NetworkStatus(online: true);
        }
        $online = $h->Level !== 1 && $h->Level !== 5; // NetworkConnectivityLevelHintNone, Hidden
        return new NetworkStatus(
            online: $online,
            type: $online ? 'other' : 'none', // the hint does not tell the interface
            metered: $h->Cost === 2 || $h->Cost === 3 || $h->Roaming !== 0 || $h->OverLimit !== 0 || $h->ApproachingLimit !== 0, // Fixed, Variable
        );
    }

    /** Reports AppHandler::networkChanged as the status changes, read again every two seconds. */
    public function watch(): void
    {
        if ($this->watching || self::f() === null) {
            return;
        }
        $this->watching = true;
        $this->last = $this->status();
        $tick = function () use (&$tick): void {
            $now = $this->status();
            if ($now->online !== $this->last?->online || $now->metered !== $this->last?->metered) {
                $this->last = $now;
                $this->h->networkChanged($now);
            }
            $this->b->timer(2.0, $tick);
        };
        $this->b->timer(2.0, $tick);
    }
}
