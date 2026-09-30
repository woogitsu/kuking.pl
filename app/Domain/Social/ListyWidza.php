<?php

declare(strict_types=1);

namespace App\Domain\Social;

use App\Models\Block;
use App\Models\Tag;
use App\Models\User;
use App\Support\PamiecZadania;

/**
 * Listy widza, z których korzysta jeden ekran wiele razy: kogo obserwuje,
 * jakie tematy obserwuje i z kim jest w blokadzie (W7, audyt wydajności).
 *
 * PO CO
 * Start (`GET /`) pytał o te same listy z kilku miejsc: obserwowane osoby
 * cztery razy (feed — wybór źródła i strona, tablica dnia, skróty w menu
 * kart), aktywne tematy dwa razy, blokady trzy razy. Teraz każda lista jest
 * czytana raz na żądanie i odpowiada z pamięci (`PamiecZadania`, w HTTP
 * atrybuty `Request`).
 *
 * CO TU NIE JEST PAMIĘTANE
 * Bramki widoczności i blokad w SQL (`Post::widoczneDla()`, #2026) nadal
 * liczy baza W CHWILI zapytania o treść — to są podzapytania, nie listy.
 * Pamięć obejmuje wyłącznie wejścia, którymi zawężamy źródła i podpisujemy
 * karty.
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

    /** @return list<string> identyfikatory obserwowanych osób */
    public function osoby(User $widz): array
    {
        return $this->lista($widz, 'osoby', fn (): array => $widz->following()->pluck('users.id')->all());
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
     * Tematy, które mają zasilać Start: tylko AKTYWNE (#853) — obserwowany
     * temat scalony prowadzi do celu, jeśli ten jest aktywny (ta sama
     * semantyka co w `MergeTags::przepnijObserwacje()`).
     *
     * @return list<string>
     */
    public function tagiAktywne(User $widz): array
    {
        return $this->lista($widz, 'tagi_aktywne', function () use ($widz): array {
            $surowe = $this->tagiSurowe($widz);

            if ($surowe === []) {
                return [];
            }

            return Tag::query()->aktywne()
                ->where(fn ($q) => $q->whereIn('id', $surowe)->orWhereIn(
                    'id',
                    Tag::query()->select('merged_into_tag_id')
                        ->where('status', Tag::STATUS_MERGED)
                        ->whereIn('id', $surowe),
                ))
                ->pluck('id')
                ->all();
        });
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
