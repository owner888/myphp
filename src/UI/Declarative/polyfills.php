<?php
declare(strict_types=1);

if (!function_exists('array_first')) {
    function array_first(array $array): mixed { return $array === [] ? null : $array[array_key_first($array)]; }
}
if (!function_exists('array_last')) {
    function array_last(array $array): mixed { return $array === [] ? null : $array[array_key_last($array)]; }
}
