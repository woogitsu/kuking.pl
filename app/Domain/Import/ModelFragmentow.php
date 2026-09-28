<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Models\User;

/** Model wskazuje tylko granice; tekst szkicu składa PHP z oryginału. */
final class ModelFragmentow implements WyznaczaczFragmentow
{
    public function __construct(private readonly PlatnyOdczytImportu $odczyt) {}

    public function fragmenty(array $wiersze, ?User $osoba = null, bool $chceZgody = false, ?string $probaId = null): ?array
    {
        if ($osoba === null || $probaId === null || ! $chceZgody) {
            return null;
        }

        $zadanie = ZadanieFragmentow::tresc($wiersze);
        $dane = $this->odczyt->odczytaj(
            $osoba,
            $chceZgody,
            $probaId,
            KlientLuna::ZADANIE_TEKST,
            ZadanieFragmentow::INSTRUKCJA,
            [['type' => 'input_text', 'text' => $zadanie['input']]],
            'fragmenty_przepisu',
            $zadanie['text']['format']['schema'],
        );

        return is_array($dane['fragmenty'] ?? null) ? $dane['fragmenty'] : null;
    }
}
