<?php

declare(strict_types=1);

namespace Fperdomo\PhpAgi\Config;

use Fperdomo\PhpAgi\Constants;

final class IniConfigLoader
{
    /**
     * Loads an INI file (if it exists) and returns an associative array.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function load(?string $path = null): array
    {
        $path = self::resolvePath($path);
        if ($path === null) {
            return [];
        }

        /** @var array<string, array<string, mixed>>|false $parsed */
        $parsed = parse_ini_file($path, true, INI_SCANNER_TYPED);

        return $parsed === false ? [] : $parsed;
    }

    public static function resolvePath(?string $path = null): ?string
    {
        if (is_string($path) && $path !== '' && file_exists($path)) {
            return $path;
        }

        if (file_exists(Constants::DEFAULT_PHPAGI_CONFIG)) {
            return Constants::DEFAULT_PHPAGI_CONFIG;
        }

        return null;
    }
}
