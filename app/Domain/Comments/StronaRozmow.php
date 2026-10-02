<?php

declare(strict_types=1);

namespace App\Domain\Comments;

/**
 * Porcja listy „Moje rozmowy” (#2432) z kursorem do następnej. Ma tylko to,
 * czego używa `<x-show-more>`: nazwę parametru, informację o dalszej porcji
 * i jej adres.
 */
final class StronaRozmow
{
    public const PARAMETR = 'po';

    /** @param list<PozycjaRozmowy> $pozycje */
    public function __construct(
        private readonly array $pozycje,
        private readonly ?string $nastepnyKursor,
    ) {}

    /** @return list<PozycjaRozmowy> */
    public function items(): array
    {
        return $this->pozycje;
    }

    public function hasMorePages(): bool
    {
        return $this->nastepnyKursor !== null;
    }

    public function nextPageUrl(): ?string
    {
        return $this->nastepnyKursor === null
            ? null
            : route('collections.own-conversations', [self::PARAMETR => $this->nastepnyKursor]);
    }

    public function getCursorName(): string
    {
        return self::PARAMETR;
    }
}
