<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use MyPHP\Media\Https;
use RuntimeException;

/**
 * An XML-RPC server's client of a plugin's (IINA's iina.http.xmlrpc, its
 * JustXMLRPC): call() a method with its arguments, its return value given
 * (int, double, boolean, string, base64 as a string, dateTime.iso8601 as a
 * string, array, struct); a fault, or a failure, thrown as an HttpError
 * {statusCode, reason, data: {httpCode, reason, description}}.
 */
final class XmlRpc extends Api
{
    public function __construct(Instance $instance, private readonly string $location)
    {
        parent::__construct($instance);
    }

    /** @param list<mixed> $args */
    public function call(string $method, array $args = []): mixed
    {
        $this->require(Plugin::NETWORK);
        $xml = '<?xml version="1.0"?><methodCall><methodName>' . htmlspecialchars($method, ENT_XML1) . '</methodName><params>'
            . implode('', array_map(fn($a) => '<param>' . self::encode($a) . '</param>', $args)) . '</params></methodCall>';
        try {
            [$status, $body] = Https::request('POST', $this->location, ['Content-Type' => 'text/xml'], $xml, 'xmlrpc', true);
        } catch (RuntimeException $e) {
            throw self::error(0, $e->getMessage(), $e->getMessage());
        }
        if ($status < 200 || $status >= 300) { throw self::error($status, "HTTP $status", "The server answered $status"); }
        $doc = @simplexml_load_string($body);
        if ($doc === false) { throw self::error($status, 'Bad response', 'The response is no XML-RPC'); }
        if (isset($doc->fault)) {
            $fault = self::decode($doc->fault->value);
            throw self::error($status, (string)($fault['faultString'] ?? 'fault'), (string)($fault['faultCode'] ?? '') . ' ' . (string)($fault['faultString'] ?? ''));
        }
        return isset($doc->params->param->value) ? self::decode($doc->params->param->value) : null;
    }

    private static function error(int $code, string $reason, string $desc): HttpError
    {
        return new HttpError(['statusCode' => $code, 'reason' => $reason, 'data' => ['httpCode' => $code, 'reason' => $reason, 'description' => $desc], 'text' => null]);
    }

    /** $v as XML-RPC's <value>. */
    public static function encode(mixed $v): string
    {
        $x = fn(string $s): string => htmlspecialchars($s, ENT_XML1);
        return '<value>' . match (true) {
            is_bool($v) => '<boolean>' . ($v ? 1 : 0) . '</boolean>',
            is_int($v) => "<int>$v</int>",
            is_float($v) => "<double>$v</double>",
            is_string($v) => '<string>' . $x($v) . '</string>',
            $v === null => '<nil/>',
            is_array($v) && array_is_list($v) => '<array><data>' . implode('', array_map(self::encode(...), $v)) . '</data></array>',
            is_array($v) => '<struct>' . implode('', array_map(fn($k, $m) => '<member><name>' . $x((string)$k) . '</name>' . self::encode($m) . '</member>', array_keys($v), $v)) . '</struct>',
            default => '<string>' . $x((string)json_encode($v)) . '</string>',
        } . '</value>';
    }

    /** XML-RPC's <value> $v as PHP's. */
    public static function decode(\SimpleXMLElement $v): mixed
    {
        $kids = $v->children();
        if (count($kids) === 0) { return (string)$v; } // a string without its type
        $t = $kids[0];
        return match ($t->getName()) {
            'int', 'i4', 'i8' => (int)(string)$t,
            'double' => (float)(string)$t,
            'boolean' => (string)$t === '1',
            'nil' => null,
            'array' => array_map(fn($x) => self::decode($x), iterator_to_array($t->data->value, false)),
            'struct' => array_combine(array_map(fn($m) => (string)$m->name, iterator_to_array($t->member, false)), array_map(fn($m) => self::decode($m->value), iterator_to_array($t->member, false))),
            default => (string)$t, // string, base64, dateTime.iso8601
        };
    }
}
