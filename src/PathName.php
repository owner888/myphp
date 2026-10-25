<?php
declare(strict_types=1);

namespace MyPHP;

/** A well-known directory, for App::path (mygo's PathName). */
enum PathName: string
{
    case Home = 'home';
    /** The user's application data: ~/Library/Application Support. */
    case AppData = 'appData';
    /** AppData joined with the app's name, made on first use. */
    case UserData = 'userData';
    /** The app's caches: ~/Library/Caches/<name>, made on first use. */
    case Cache = 'cache';
    /** Where the app writes logs: ~/Library/Logs/<name>, made on first use. */
    case Logs = 'logs';
    /**
     * The files the app ships with: the contents of the project's resources
     * directory, with those of its directories for the app's platform (as
     * resources/darwin-arm64) merged in, and the resources listed in
     * myphp.json, which `myphp dev` and `myphp build` copy there (mygo's
     * PathResources). It is Contents/Resources of the app's bundle;
     * unbundled, the resources directory next to the script the app runs.
     */
    case Resources = 'resources';
    case Temp = 'temp';
    /** The executable that runs the app: the bundle's, else PHP's. */
    case Exe = 'exe';
    case Desktop = 'desktop';
    case Documents = 'documents';
    case Downloads = 'downloads';
    case Music = 'music';
    case Pictures = 'pictures';
    /** ~/Movies, as the Mac names it. */
    case Videos = 'videos';
}
