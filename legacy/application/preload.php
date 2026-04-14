<?php

require_once __DIR__ . '/configs/constants.php';

require_once VENDOR_PATH . '/autoload.php';

require_once CONFIG_PATH . '/conf.php';

Logging::setLogPath(LIBRETIME_LOG_FILEPATH);

/**
 * Drop-in replacement for the deprecated strftime() (removed in PHP 9).
 * Uses IntlDateFormatter to preserve locale-aware formatting.
 */
if (!function_exists('_strftime_compat')) {
    function _strftime_compat(string $format, int|string $timestamp): string
    {
        static $map = [
            '%%' => "'%'",
            '%Y' => 'yyyy', '%y' => 'yy',
            '%m' => 'MM',   '%d' => 'dd',  '%e' => 'd',
            '%H' => 'HH',   '%I' => 'hh',
            '%M' => 'mm',   '%S' => 'ss',
            '%p' => 'a',
            '%A' => 'EEEE', '%a' => 'EEE',
            '%B' => 'MMMM', '%b' => 'MMM',
            '%T' => 'HH:mm:ss',
            '%F' => 'yyyy-MM-dd',
            '%D' => 'MM/dd/yy',
            '%x' => 'yyyy-MM-dd',
            '%X' => 'HH:mm:ss',
            '%c' => 'yyyy-MM-dd HH:mm:ss',
            '%r' => 'hh:mm:ss a',
            '%R' => 'HH:mm',
            '%Z' => 'zzz',  '%z' => 'Z',
            '%n' => "\n",    '%t' => "\t",
        ];

        $icuPattern = strtr($format, $map);
        $fmt = new IntlDateFormatter(
            null,
            IntlDateFormatter::FULL,
            IntlDateFormatter::FULL,
        );
        $fmt->setPattern($icuPattern);

        return $fmt->format((int) $timestamp);
    }
}
