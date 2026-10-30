<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;

/**
 * Where Updater gets updates from instead of the feed that myphp.json
 * configures (Updater::setSource, mygo's UpdateSource): an update service
 * the app already has, an endpoint that needs the user's credentials, or a
 * channel the app chooses while it runs. It serves what `myphp build`
 * publishes, from wherever the app keeps it, and Updater checks it as it
 * checks the feed's: the archive and deltas must be signed with the key of
 * updates.publicKey the app was built with, or they are not installed.
 *
 * Its methods may wait for the network as Updater's do, inside App::go();
 * $canceled, when set, returns true once the check or the install is
 * canceled.
 */
interface UpdateSource
{
    /**
     * The manifest of the running platform: the JSON `myphp build` writes as
     * update-<target>.json, as update-darwin-arm64.json, of at most 1 MiB.
     * $prereleases says whether the app offers them (Updater::setPrereleases).
     *
     * @param ?Closure(): bool $canceled
     */
    public function manifest(bool $prereleases, ?Closure $canceled): string;

    /**
     * The file at $url, which the manifest names, the archive of the update
     * or one of its deltas, as a readable stream, which Updater reads to its
     * end and closes. The URL is the manifest's, so it may be anything the
     * source understands, such as an id of its own.
     *
     * @param ?Closure(): bool $canceled
     * @return resource
     */
    public function open(string $url, ?Closure $canceled);
}
