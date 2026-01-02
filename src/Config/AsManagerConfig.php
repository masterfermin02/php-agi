<?php

declare(strict_types=1);

namespace Fperdomo\PhpAgi\Config;

/**
 * Typed config for the [asmanager] section.
 */
final readonly class AsManagerConfig
{
    public function __construct(
        public string $server = 'localhost',
        public int $port = 5038,
        public string $username = 'phpagi',
        public string $secret = 'phpagi',
        public bool $writeLog = false,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        $server = isset($values['server']) ? (string) $values['server'] : 'localhost';
        $port = isset($values['port']) ? (int) $values['port'] : 5038;
        $username = isset($values['username']) ? (string) $values['username'] : 'phpagi';
        $secret = isset($values['secret']) ? (string) $values['secret'] : 'phpagi';
        $writeLog = self::toBool($values['write_log'] ?? false);

        return new self(
            server: $server,
            port: $port,
            username: $username,
            secret: $secret,
            writeLog: $writeLog,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'server' => $this->server,
            'port' => $this->port,
            'username' => $this->username,
            'secret' => $this->secret,
            'write_log' => $this->writeLog,
        ];
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            $v = strtolower(trim($value));

            return in_array($v, ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }
}
