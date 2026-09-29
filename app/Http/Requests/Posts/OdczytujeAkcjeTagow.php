<?php

declare(strict_types=1);

namespace App\Http\Requests\Posts;

use App\Domain\Posts\AkcjeTagowRoboczych;

/**
 * Odczyt pól tagów z formularza wpisu — wspólny dla zapisu i edycji
 * (issue #970). Sama logika „Dodaj"/„Usuń" jest w `AkcjeTagowRoboczych`.
 */
trait OdczytujeAkcjeTagow
{
    /**
     * Czy to żądanie to krok POŚREDNI („Szukaj tagów"/„Dodaj"/„Usuń"), a nie
     * próba publikacji/zapisu — formularz bez JS potrzebuje tego rozróżnienia,
     * żeby kliknięcie „Dodaj" nie próbowało opublikować niedokończonego wpisu.
     */
    public function toAkcjaTagow(): bool
    {
        return $this->has('szukaj_tagu') || $this->filled('dodaj_tag') || $this->filled('usun_tag');
    }

    /**
     * Tagi wpisane do tej pory — z ukrytych pól `tag_names[]`, w kolejności
     * dodania. To WOLNY TEKST od klienta: prawdziwa walidacja i tworzenie
     * tagów dzieje się w `ResolveTagsForPost`, w chwili publikacji/zapisu.
     *
     * @return list<string>
     */
    public function tagiZFormularza(): array
    {
        return array_values(array_filter(
            (array) $this->input('tag_names', []),
            static fn ($nazwa): bool => is_string($nazwa) && trim($nazwa) !== '',
        ));
    }

    /**
     * @param  list<string>  $tagNames
     * @return array{0: list<string>, 1: string|null} nowa lista i komunikat błędu (albo null)
     */
    public function zastosujAkcjeTagow(array $tagNames, bool $pytanie): array
    {
        return app(AkcjeTagowRoboczych::class)->zastosuj(
            $tagNames,
            $this->filled('usun_tag') ? (string) $this->input('usun_tag') : null,
            $this->filled('dodaj_tag') ? (string) $this->input('dodaj_tag') : null,
            $pytanie,
        );
    }
}
