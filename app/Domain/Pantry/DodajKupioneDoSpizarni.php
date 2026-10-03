<?php

declare(strict_types=1);

namespace App\Domain\Pantry;

use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Wybrane odhaczone pozycje listy zakupów do „Co mam w domu” (V2, #2481).
 *
 * Świadoma, jednorazowa czynność: samo odhaczenie NIGDY nie zmienia spiżarni.
 * Człowiek wybiera pozycje i dla każdej wpisuje albo poprawia NAZWĘ produktu
 * — nie parsujemy „2 szklanki mąki”, nie zgadujemy ilości, terminu, daty
 * zakupu ani bezpieczeństwa żywności. Lista zakupów (teksty, odhaczenia,
 * kolejność) zostaje nietknięta.
 *
 * Reguły produktu to `CoMamWDomu` (normalizacja, klucz, deduplikacja, limit
 * 150): produkt, który już jest na liście, zostaje bez zmian — jego ilości,
 * terminy i zamrożenie nie są nadpisywane ani zwiększane.
 *
 * ATOMOWO: nazwy sprawdzamy wszystkie przed zapisem (błędy przy polach), a
 * zapis to jedna transakcja pod blokadą konta — błąd limitu cofa wszystko.
 * Pozycja cudza, usunięta albo już nieodhaczona (stary formularz) powoduje
 * odmowę CAŁEGO zestawu: niczego nie przetwarzamy po cichu.
 */
final class DodajKupioneDoSpizarni
{
    public function __construct(private readonly CoMamWDomu $spizarnia = new CoMamWDomu) {}

    /**
     * @param  array<string, string>  $nazwyPoId  identyfikator pozycji listy => nazwa produktu
     * @return array{dodane: list<string>, juz_byly: list<string>}
     *
     * @throws ValidationException
     */
    public function handle(User $user, array $nazwyPoId, ?ShoppingList $lista = null): array
    {
        if ($nazwyPoId === []) {
            throw ValidationException::withMessages([
                'pozycje' => 'Zaznacz przynajmniej jedną pozycję, którą chcesz dodać do „Co mam w domu”.',
            ]);
        }

        // Wszystkie nazwy najpierw: błąd stoi przy właściwym polu, a poprawne
        // wpisy zostają w formularzu.
        $bledy = [];
        $sprawdzone = [];
        foreach ($nazwyPoId as $id => $nazwa) {
            try {
                $sprawdzone[(string) $id] = $this->spizarnia->sprawdzNazwe($nazwa);
            } catch (ValidationException $e) {
                $bledy['nazwy.'.$id] = (string) ($e->errors()['nazwa'][0] ?? 'Sprawdź nazwę produktu.');
            }
        }

        if ($bledy !== []) {
            throw ValidationException::withMessages($bledy);
        }

        return DB::transaction(function () use ($user, $sprawdzone, $lista): array {
            // Ta sama blokada konta co w `CoMamWDomu` (reentrantna w transakcji).
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($lista !== null && ! ShoppingList::query()
                ->whereKey($lista->getKey())->where('user_id', $user->getKey())->exists()) {
                throw ValidationException::withMessages([
                    'lista' => 'Tej listy zakupów już nie ma. Wybierz listę jeszcze raz.',
                ]);
            }

            $aktualne = ShoppingListItem::query()
                ->where('user_id', $user->getKey())
                ->when($lista === null,
                    fn ($q) => $q->whereNull('list_id'),
                    fn ($q) => $q->where('list_id', $lista?->getKey()),
                )
                ->whereIn('id', array_keys($sprawdzone))
                ->whereNotNull('checked_at')
                ->count();

            if ($aktualne !== count($sprawdzone)) {
                throw ValidationException::withMessages([
                    'pozycje' => 'Lista zakupów zmieniła się od otwarcia tego ekranu: część zaznaczonych pozycji została usunięta albo nie jest już odhaczona. Nic nie zostało dodane. Sprawdź listę i zaznacz pozycje jeszcze raz.',
                ]);
            }

            $dodane = [];
            $juzByly = [];
            foreach ($sprawdzone as $dane) {
                try {
                    $wynik = $this->spizarnia->dodaj($user, $dane['nazwa']);
                } catch (ValidationException) {
                    throw ValidationException::withMessages([
                        'pozycje' => 'W „Co mam w domu” mieści się najwyżej '.CoMamWDomu::MAKS_PRODUKTOW.' produktów, a po dodaniu zaznaczonych byłoby ich więcej. Zaznacz mniej pozycji albo usuń niepotrzebne produkty. Nic nie zostało dodane.',
                    ]);
                }

                if ($wynik['nowy']) {
                    $dodane[] = $wynik['produkt']->name;
                } else {
                    $juzByly[] = $wynik['produkt']->name;
                }
            }

            return ['dodane' => $dodane, 'juz_byly' => $juzByly];
        });
    }
}
