<?php

declare(strict_types=1);

namespace App\Domain\Social;

use App\Models\Block;
use App\Models\User;
use App\Support\PamiecZadania;

/**
 * Listy widza, z których korzysta jeden ekran wiele razy: kogo obserwuje,
 * jakie tematy obserwuje i z kim jest w blokadzie (W7, audyt wydajności).
 *
 * PO CO
 * Start (`GET /`) pytał o te same listy z kilku miejsc: obserwowane osoby
 * cztery razy (feed — wybór źródła i strona, tablica dnia, skróty w menu
 * kart), blokady w obie strony trzy razy. Teraz tablica dnia i skróty w menu
 * czytają listy z pamięci żądania (`PamiecZadania`, w HTTP atrybuty
 * `Request`); blokady idą z bazy raz, obserwowane osoby dwa razy (oba
 * odczyty feedu, patrz niżej).
 *
 * CZEGO TU CELOWO NIE PAMIĘTAMY
 * `FollowingFeed` czyta obserwowane osoby i tematy ŚWIEŻO przy każdym
 * odczycie (#983, `StartNieZostajePustyPoZmianieZrodlaTest`): wybór źródła
 * i strona feedu to osobne odczyty, a obserwowanie cofnięte albo temat
 * ukryty między nimi ma zmienić wynik. Feed więc pamięci nie używa, tylko ją
 * zasila (`osobyNaNowo()`). Bramki widoczności i blokad w SQL
 * (`Post::widoczneDla()`, #2026) też liczy baza w chwili zapytania.
 *
 * ŚWIEŻOŚĆ
 * Każda akcja zmieniająca obserwowanie, blokadę albo obserwowane tematy
 * woła `uniewaznij()` (FollowUser, UnfollowUser, BlockUser, UnblockUser,
 * UpdateTagFollows), więc odczyt w TYM SAMYM żądaniu po zmianie liczy od
 * nowa. Pamięć jest też osobna dla każdego widza.
 *
 * KOLEJKA I KOMENDY
 * Adapter trzyma pamięć w atrybutach jednego `Request`, a proces kolejki
 * albo komendy ma jeden `Request` na wszystkie zadania. Poza HTTP (konsola
 * niebędąca testem) nic więc nie jest pamiętane — lista czytana z bazy za
 * każdym razem, bez przecieku między zadaniami.
 */
final class ListyWidza
{
    private const KLUCZ = 'kuking.listy_widza';

    /**
     * Pamięć, którą ktoś podał wprost (`zPamiecia()`). Bez niej każde użycie
     * bierze ją z kontenera, czyli z BIEŻĄCEGO `Request`. Celowo NIE jest to
     * parametr konstruktora: kontener wstrzyknąłby go sam, a `FollowingFeed`
     * i `DailyBoard` bywają utrzymane dłużej niż jedno żądanie (kontroler
     * zapamiętany w trasie w teście, długi proces) — pamięć trzymana w polu
     * należałaby do żądania, które już minęło.
     */
    private ?PamiecZadania $wlasna = null;

    public static function zPamiecia(PamiecZadania $pamiec): self
    {
        $listy = new self;
        $listy->wlasna = $pamiec;

        return $listy;
    }

    private function pamiec(): PamiecZadania
    {
        return $this->wlasna ?? app(PamiecZadania::class);
    }

    /** Zapomina pamięć bieżącego żądania — woła to każda akcja zmieniająca te listy. */
    public static function uniewaznij(): void
    {
        $pamiec = app(PamiecZadania::class);
        $pamiec->zapisz(self::KLUCZ, null);
        $pamiec->zapisz(SkrotyObserwowania::KLUCZ, null);
    }

    /** @return list<string> identyfikatory obserwowanych osób — z pamięci żądania, jeśli już czytane */
    public function osoby(User $widz): array
    {
        return $this->lista($widz, 'osoby', fn (): array => $this->wczytajOsoby($widz));
    }

    /**
     * Obserwowane osoby ZAWSZE z bazy — i odświeżają pamięć żądania.
     *
     * Dla `FollowingFeed` (#983): wybór źródła, strona feedu i ponowne
     * sprawdzenie po stronie to osobne odczyty, a przy Read Committed każdy
     * ma widzieć to, co zatwierdzono do tej chwili. Dlatego feed nie korzysta
     * z pamięci, tylko ją zasila — kto czyta później w tym żądaniu (tablica
     * dnia, skróty w menu kart), dostaje stan z ostatniego odczytu feedu.
     *
     * @return list<string>
     */
    public function osobyNaNowo(User $widz): array
    {
        $osoby = $this->wczytajOsoby($widz);

        if ($this->mogeZapamietac()) {
            $zapamietane = $this->zapamietane($widz);
            $zapamietane['listy']['osoby'] = $osoby;
            $this->pamiec()->zapisz(self::KLUCZ, $zapamietane);
        }

        return $osoby;
    }

    /** @return list<string> */
    private function wczytajOsoby(User $widz): array
    {
        return $widz->following()->pluck('users.id')->all();
    }

    /**
     * Wszystkie tematy obserwowane przez widza, także ukryte i scalone
     * (ekran ustawień i skróty w menu muszą je znać).
     *
     * @return list<string>
     */
    public function tagiSurowe(User $widz): array
    {
        return $this->lista($widz, 'tagi_surowe', fn (): array => $widz->followedTags()->pluck('tags.id')->all());
    }

    /**
     * Osoby w blokadzie z widzem w OBIE strony: zablokowane przez niego
     * i te, które zablokowały jego.
     *
     * @return list<string>
     */
    public function blokady(User $widz): array
    {
        return $this->lista($widz, 'blokady', fn (): array => array_values(array_unique([
            ...Block::query()->where('blocker_id', $widz->getKey())->pluck('blocked_id')->all(),
            ...Block::query()->where('blocked_id', $widz->getKey())->pluck('blocker_id')->all(),
        ])));
    }

    /**
     * @param  callable(): list<string>  $wczytaj
     * @return list<string>
     */
    private function lista(User $widz, string $nazwa, callable $wczytaj): array
    {
        if (! $this->mogeZapamietac()) {
            return $wczytaj();
        }

        $zapamietane = $this->zapamietane($widz);

        if (array_key_exists($nazwa, $zapamietane['listy'])) {
            return $zapamietane['listy'][$nazwa];
        }

        $wczytane = $wczytaj();

        // Ponowny odczyt: `$wczytaj` mogło samo dopisać inną listę.
        $zapamietane = $this->zapamietane($widz);
        $zapamietane['listy'][$nazwa] = $wczytane;
        $this->pamiec()->zapisz(self::KLUCZ, $zapamietane);

        return $wczytane;
    }

    /** @return array{widz: string, listy: array<string, list<string>>} */
    private function zapamietane(User $widz): array
    {
        $pamiec = $this->pamiec()->pobierz(self::KLUCZ);

        return is_array($pamiec) && ($pamiec['widz'] ?? null) === $widz->getKey()
            ? $pamiec
            : ['widz' => $widz->getKey(), 'listy' => []];
    }

    /** Konsola (kolejka, komendy) ma jeden `Request` na wszystkie zadania — tam bez pamięci. */
    private function mogeZapamietac(): bool
    {
        return ! app()->runningInConsole() || app()->runningUnitTests();
    }
}
