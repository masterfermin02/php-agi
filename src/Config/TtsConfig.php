<?php

declare(strict_types=1);

namespace Fperdomo\PhpAgi\Config;

/**
 * Typed config for the [festival] and [cepstral] sections.
 */
final readonly class TtsConfig
{
    public function __construct(
        public ?string $festivalText2Wave = null,
        public ?string $cepstralSwift = null,
    ) {}

    /**
     * @param  array<string, mixed>  $festival
     * @param  array<string, mixed>  $cepstral
     */
    public static function fromSections(array $festival, array $cepstral): self
    {
        $text2wave = isset($festival['text2wave']) ? (string) $festival['text2wave'] : null;
        $swift = isset($cepstral['swift']) ? (string) $cepstral['swift'] : null;

        return new self(
            festivalText2Wave: $text2wave,
            cepstralSwift: $swift,
        );
    }

    /**
     * @return array{festival: array<string,mixed>, cepstral: array<string,mixed>}
     */
    public function toArray(): array
    {
        return [
            'festival' => ['text2wave' => $this->festivalText2Wave],
            'cepstral' => ['swift' => $this->cepstralSwift],
        ];
    }
}
