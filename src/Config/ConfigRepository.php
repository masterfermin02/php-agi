<?php

declare(strict_types=1);

namespace Fperdomo\PhpAgi\Config;

/**
 * Loads and normalizes PHPAGI configuration.
 *
 * Goal: keep a backward-compatible array shape (the legacy `$agi->config`) while
 * centralizing defaults and typing internally.
 */
final readonly class ConfigRepository
{
    public function __construct(
        private ?string $configPath = null,
    ) {}

    /**
     * Builds the legacy AGI config array.
     *
     * @param  array<string, mixed>  $overrides  Overrides for [phpagi] section (same as old $optconfig)
     * @param  callable|null  $which  Optional resolver for binaries (text2wave/swift)
     * @return array<string, array<string, mixed>>
     */
    public function buildAgiConfig(array $overrides = [], ?callable $which = null): array
    {
        $base = IniConfigLoader::load($this->configPath);

        $phpagi = PhpAgiConfig::fromArray(array_merge($base['phpagi'] ?? [], $overrides));
        $ttsConfig = TtsConfig::fromSections($base['festival'] ?? [], $base['cepstral'] ?? []);

        // Backward-compat section arrays
        $config = $base;
        $config['phpagi'] = $phpagi->toArray();

        // Resolve missing binaries via $which (which is Agi::which)
        if (($ttsConfig->festivalText2Wave ?? '') === '' && $which !== null) {
            $config['festival']['text2wave'] = (string) $which('text2wave');
        } elseif (isset($base['festival'])) {
            $config['festival']['text2wave'] = $ttsConfig->festivalText2Wave;
        } else {
            $config['festival'] = ['text2wave' => $ttsConfig->festivalText2Wave];
        }

        if (($ttsConfig->cepstralSwift ?? '') === '' && $which !== null) {
            $config['cepstral']['swift'] = (string) $which('swift');
        } elseif (isset($base['cepstral'])) {
            $config['cepstral']['swift'] = $ttsConfig->cepstralSwift;
        } else {
            $config['cepstral'] = ['swift' => $ttsConfig->cepstralSwift];
        }

        return $config;
    }

    /**
     * Builds the legacy AMI config array.
     *
     * @param  array<string, mixed>  $overrides  Overrides for [asmanager] section (same as old $optconfig)
     * @return array<string, array<string, mixed>>
     */
    public function buildAsManagerConfig(array $overrides = []): array
    {
        $base = IniConfigLoader::load($this->configPath);

        $asManagerConfig = AsManagerConfig::fromArray(array_merge($base['asmanager'] ?? [], $overrides));

        $config = $base;
        $config['asmanager'] = $asManagerConfig->toArray();

        return $config;
    }
}
