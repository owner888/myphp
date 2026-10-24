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
     * The files the app ships with: the project's resources directory,
     * which `myphp build` copies with the app, as
     * Contents/Resources/app/resources of the bundle; next to the script
     * the app runs, unbundled.
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
