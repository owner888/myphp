<?php
declare(strict_types=1);

namespace MyPHP;

/** Kinds of data pages store, which Session::clearData clears (mygo's SiteData), as bits: SiteData::COOKIES | SiteData::LOCAL_STORAGE. */
final class SiteData
{
    public const COOKIES = 1;
    /** localStorage and sessionStorage. */
    public const LOCAL_STORAGE = 2;
    /** IndexedDB, and WebSQL where the engine still has it. */
    public const INDEXED_DB = 4;
    /** The HTTP cache, and Cache Storage (window.caches). */
    public const CACHES = 8;
    public const SERVICE_WORKERS = 16;
    /** All the data the engine keeps for sites: the kinds above and the others it has, such as HSTS and media keys. */
    public const ALL = 0xffff;
}
