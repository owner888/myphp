<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

/**
 * A request of a plugin's answered with an error (IINA's http promise
 * rejected): the response, as a successful one's ($response: statusCode,
 * reason, data, text).
 */
final class HttpError extends PluginError
{
    /** @param array{statusCode: int, reason: string, data: mixed, text: ?string} $response */
    public function __construct(public readonly array $response)
    {
        parent::__construct("HTTP {$response['statusCode']} {$response['reason']}");
    }
}
