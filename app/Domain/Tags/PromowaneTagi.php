<?php

declare(strict_types=1);

namespace App\Domain\Tags;

use App\Models\Tag;
use App\Models\TagPromotion;
use Illuminate\Support\Facades\DB;

/**
 * Zmiany listy tagów promowanych (D-021) — jedna naraz (#1308).
 *
 * DLACZEGO BLOKADA, A NIE SAMA TRANSAKCJA
 * Dodanie liczy „ostatnia pozycja + 1", przesunięcie wybiera sąsiada. Obie
 * decyzje zależą od stanu CAŁEJ listy, więc dwa równoległe żądania, które
 * odczytały ten sam stan, dawały dwie promocje z tą samą pozycją albo
 * przesunięcie nadpisujące drugie. Sama transakcja w READ COMMITTED tego nie
 * zmienia. Każda zmiana najpierw bierze transakcyjną blokadę doradczą listy
 * (`TagMutationLock::forPromotions()`), a dopiero POTEM czyta aktualny stan.
 * Lista jest krótka i zmienia ją tylko gospodarz — serializacja nic nie kosztuje.
 *
 * DLACZEGO NIE `UNIQUE(position)`
 * Zamiana miejscami to dwa kolejne `UPDATE`, a na produkcji mogą już leżeć
 * remisy sprzed tej poprawki — ograniczenie wymagałoby migracji danych
 * i odroczonego sprawdzania. Zamiast tego odczyt ma stabilne drugie
 * kryterium (`TagPromotion::scopeWKolejnosci()`), a każde przesunięcie
 * przenumerowuje listę od 1, więc zastany remis znika przy pierwszej zmianie
 * kolejności.
 */
final class PromowaneTagi
{
    /** Dopisuje tag na koniec listy. `false`, gdy już na niej był. */
    public function dodaj(Tag $tag): bool
    {
        return DB::transaction(function () use ($tag): bool {
            TagMutationLock::forPromotions();

            if (TagPromotion::query()->whereKey($tag->getKey())->exists()) {
                return false;
            }

            TagPromotion::create([
                'tag_id' => $tag->getKey(),
                'position' => ((int) TagPromotion::query()->max('position')) + 1,
            ]);

            return true;
        });
    }

    /**
     * Zamienia tag z sąsiadem. `$kierunek`: -1 w górę, +1 w dół.
     *
     * `false`, gdy nic się nie zmieniło: tag jest już na skraju listy albo
     * w międzyczasie zniknął z niej — wtedy nie ma o czym meldować ani czego
     * wpisywać do dziennika.
     */
    public function przesun(Tag $tag, int $kierunek): bool
    {
        return DB::transaction(function () use ($tag, $kierunek): bool {
            TagMutationLock::forPromotions();

            $lista = TagPromotion::query()->wKolejnosci()->get()->values();
            $indeks = $lista->search(fn (TagPromotion $p): bool => $p->getKey() === $tag->getKey());

            if ($indeks === false) {
                return false;
            }

            $sasiad = $indeks + ($kierunek < 0 ? -1 : 1);

            if ($sasiad < 0 || $sasiad >= $lista->count()) {
                return false;
            }

            $kolejnosc = $lista->all();
            [$kolejnosc[$indeks], $kolejnosc[$sasiad]] = [$kolejnosc[$sasiad], $kolejnosc[$indeks]];

            foreach (array_values($kolejnosc) as $i => $promocja) {
                if ($promocja->position !== $i + 1) {
                    $promocja->forceFill(['position' => $i + 1])->save();
                }
            }

            return true;
        });
    }

    /**
     * Zdejmuje tag z listy. Tag żyje dalej jako zwykły, otwarty tag.
     * `false`, gdy już go na niej nie było.
     */
    public function usun(Tag $tag): bool
    {
        return DB::transaction(function () use ($tag): bool {
            TagMutationLock::forPromotions();

            return TagPromotion::query()->whereKey($tag->getKey())->delete() > 0;
        });
    }
}
