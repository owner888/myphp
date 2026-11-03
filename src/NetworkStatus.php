<?php
declare(strict_types=1);

namespace MyPHP;

/** The state of the computer's network connection (mygo's NetworkStatus). Pages get it as {"online": …, "metered": …, "type": …}. */
final class NetworkStatus implements \JsonSerializable
{
    public function __construct(
        /** The system has a route out to a network beyond the computer (Network.framework's satisfied path): a network behind a captive portal, or whose way to the internet is down, counts, as for the browsers' navigator.onLine. */
        public readonly bool $online = false,
        /** The connection costs by the amount of data or is limited, so the app should send less and ask before downloading something large: an expensive path (cellular, a phone's hotspot) or Low Data Mode. */
        public readonly bool $metered = false,
        /** The interface type: 'wifi', 'cellular', 'wired', 'other', or 'none'. */
        public readonly string $type = 'other',
    ) {}

    public function isWifi(): bool
    {
        return $this->type === 'wifi';
    }

    public function isCellular(): bool
    {
        return $this->type === 'cellular';
    }

    public function isWired(): bool
    {
        return $this->type === 'wired';
    }

    /** @return array{online: bool, metered: bool, type: string} */
    public function jsonSerialize(): array
    {
        return ['online' => $this->online, 'metered' => $this->metered, 'type' => $this->type];
    }
}
