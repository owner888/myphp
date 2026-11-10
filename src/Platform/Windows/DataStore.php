<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use MyPHP\Platform;

/**
 * A session's store (mygo's windows dataStore): a WebView2 profile of the
 * app's environment, named for the session, or an InPrivate one of a name
 * of its own for an ephemeral session, whose data WebView2 keeps in memory
 * until the last web view of the profile closes. A web view gets its
 * profile from the options of its controller (WebView::createController);
 * cookies come from a web view of the profile (WebsiteData).
 */
final class DataStore implements Platform\DataStore
{
    private bool $closed = false;

    public function __construct(public readonly string $profile, public readonly bool $private) {}

    /** Lets the profile go: the hidden web view of its cookie calls closes once none needs it, and an InPrivate profile's data goes with its last web view (mygo's Close). */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        WebsiteData::closeStore($this);
    }
}
