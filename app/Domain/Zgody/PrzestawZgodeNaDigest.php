<?php

declare(strict_types=1);

namespace App\Domain\Zgody;

use App\Models\User;
use App\Models\WpisZgody;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Jedyne miejsce, które przestawia zgodę na tygodniowy digest — i jedyne,
 * które dopisuje wiersz do `dziennik_zgod` (D-072, audyt DB1).
 *
 * DLACZEGO JEDNA KLASA, A NIE `forceFill()` W TRZECH KONTROLERACH
 * Zgoda zmienia się dziś w czterech miejscach: haczyk na
 * `/ustawienia/prywatnosc`, odnośnik ze stopki listu, przycisk powrotny na
 * ekranie po wypisaniu i anonimizacja konta. Do 10 września każde z nich
 * robiło to samodzielnie, jedną linijką `forceFill(['wants_weekly_digest' =>
 * …])`. Dokładnie tak powstaje dziennik z dziurą: piąte miejsce dopisane
 * kiedyś w przyszłości (import, komenda administracyjna, panel) przestawi
 * boolean i NIE zapisze dowodu, a nikt tego nie zauważy, bo wysyłka będzie
 * dalej działać. Reguła domenowa mieszka więc tutaj, zgodnie z AGENTS.md §4,
 * i nie da się jej obejść dodaniem drugiego endpointu.
 *
 * ZDARZENIE POWSTAJE TYLKO PRZY REALNEJ ZMIANIE
 * Formularz prywatności wysyła stan OBU haczyków przy każdym zapisie, a na
 * odnośnik wypisania wchodzi się dwa razy (odświeżenie strony, skaner
 * odnośników w firmowej poczcie — patrz `PodsumowanieTygodniaController`).
 * Gdyby każdy taki zapis dopisywał wiersz, dziennik po miesiącu opowiadałby
 * o dziesiątkach „wycofań", których nikt nie wykonał — czyli byłby gorszym
 * dowodem niż brak dziennika. `handle()` zwraca więc `true` tylko wtedy, gdy
 * stan faktycznie się zmienił, a wołający używa tego również do decyzji, czy
 * zapisać sygnał produktowy.
 *
 * ASYMETRIA MIĘDZY UDZIELENIEM A WYCOFANIEM — NAJWAŻNIEJSZA RZECZ W TYM
 * PLIKU
 *
 *  - **UDZIELENIE jest atomowe: flaga i dowód albo razem, albo wcale.**
 *    Gdy zapis dowodu padnie, cała operacja się wycofuje i człowiek widzi
 *    błąd. Wysyłka z włączoną flagą, ale bez wiersza w dzienniku, to
 *    dokładnie stan, którego D-072 zabrania: mailing bez dowodu podstawy
 *    prawnej. Lepiej nie zapisać kogoś na listy, niż zapisać go bez dowodu.
 *
 *  - **WYCOFANIE dzieje się ZAWSZE, nawet gdy dziennika nie da się zapisać.**
 *    Odwrotnie niż wyżej, i to jest wybór, nie niedopatrzenie: RODO art. 7
 *    ust. 3 mówi, że wycofanie zgody ma być tak łatwe jak jej udzielenie,
 *    a `PodsumowanieTygodniaController` nie odmawia wypisania nawet kontu
 *    zawieszonemu. Awaria bazy po naszej stronie nie może być powodem, przez
 *    który człowiek dalej dostaje listy — więc flaga gaśnie pierwsza, a
 *    nieudany zapis dowodu trafia do dziennika aplikacji jako `error`.
 *    Kierunek pomyłki jest wtedy wybrany świadomie: brak wiersza o wycofaniu
 *    działa PRZECIWKO nam w sporze (to my mamy wykazać podstawę), a wysyłka
 *    po „nie" działałaby przeciwko człowiekowi.
 *
 * `DB::transaction()` WOKÓŁ SAMEGO ZAPISU DOWODU PRZY WYCOFANIU, choć wynik
 * i tak jest połykany: bez tego nieudany `INSERT` zatruwa CAŁĄ otaczającą
 * transakcję (Postgres odrzuca wtedy każde następne zapytanie tym samym
 * połączeniem), a wycofanie zgody wołane jest m.in. ze środka wielkiej
 * transakcji `EraseAccountData`. Laravel otwiera wewnątrz transakcji
 * SAVEPOINT, więc cofa się wyłącznie ten jeden zapis. Ten sam mechanizm
 * i to samo uzasadnienie co w `App\Domain\Analytics\ZapiszSygnal`.
 *
 * `forceFill()`, NIE `update()`: ta klasa jest wołana także bez żądania HTTP
 * (anonimizacja konta) i ma działać niezależnie od tego, czy
 * `wants_weekly_digest` jest kiedykolwiek w `$fillable`.
 */
final class PrzestawZgodeNaDigest
{
    /**
     * @param  string  $zrodlo  jedna ze stałych `WpisZgody::ZRODLO_*`
     * @return bool czy stan zgody FAKTYCZNIE się zmienił
     */
    public function handle(User $osoba, bool $chce, string $zrodlo): bool
    {
        return DB::transaction(function () use ($osoba, $chce, $zrodlo): bool {
            // Każda droga zgody czyta aktualny stan pod tą samą blokadą
            // co formularz prywatności, także gdy dostała stary model.
            $current = User::query()->lockForUpdate()->findOrFail($osoba->getKey());
            $changed = $this->apply($current, $chce, $zrodlo);
            $osoba->setRawAttributes($current->getAttributes(), true);

            return $changed;
        });
    }

    private function apply(User $osoba, bool $chce, string $zrodlo): bool
    {
        if ((bool) $osoba->wants_weekly_digest === $chce) {
            return false;
        }

        if ($chce) {
            DB::transaction(function () use ($osoba, $zrodlo): void {
                $osoba->forceFill(['wants_weekly_digest' => true])->save();

                $this->zapisz($osoba, WpisZgody::UDZIELONA, $zrodlo);
            });

            return true;
        }

        $osoba->forceFill(['wants_weekly_digest' => false])->save();

        try {
            DB::transaction(fn (): WpisZgody => $this->zapisz($osoba, WpisZgody::WYCOFANA, $zrodlo));
        } catch (Throwable $awaria) {
            // BEZ danych osobowych w dzienniku aplikacji (AGENTS.md §7) —
            // identyfikator konta wystarcza, żeby dopisać wiersz ręcznie.
            // NAZWA KLASY I SQLSTATE, NIGDY `getMessage()`.
            //
            // Wyjątek leci z `DB::transaction()`, więc jest to najczęściej
            // `QueryException` — a jego komunikat buduje STEROWNIK i wkłada
            // w niego SQL RAZEM Z WARTOŚCIAMI („DETAIL: Key (email)=(…)",
            // „insert into … values (…)"). Deklaracja „BEZ danych osobowych"
            // dwie linijki wyżej była więc nieprawdziwa dokładnie w tym
            // wierszu. Ten sam wzorzec, co w `App\Domain\Analytics\
            // ZapiszSygnal` i `ZanotujOstatniaWizyte`; pełne uzasadnienie
            // w `App\Logging\WebhookBleduHandler` (audyt A6-01).
            Log::error('Nie udało się zapisać wycofania zgody na tygodniowy digest.', [
                'user_id' => (string) $osoba->getKey(),
                'zrodlo' => $zrodlo,
                'wyjatek' => $awaria::class,
                'sqlstate' => $awaria instanceof QueryException ? (string) $awaria->getCode() : null,
            ]);

            return true;
        }

        return true;
    }

    private function zapisz(User $osoba, string $czynnosc, string $zrodlo): WpisZgody
    {
        return WpisZgody::create([
            'user_id' => $osoba->getKey(),
            'cel' => WpisZgody::CEL_TYGODNIOWY_DIGEST,
            'czynnosc' => $czynnosc,
            'zrodlo' => $zrodlo,
            // Czas z aplikacji, nie z `DEFAULT now()` w bazie — po to, żeby
            // test przesuwający zegar (`$this->travel()`) opisywał ten sam
            // świat co reszta serwisu. `useCurrent()` w migracji zostaje
            // jako zabezpieczenie wiersza wstawionego ręcznie w `psql`.
            'wystapilo_at' => now(),
            'wersja_polityki' => (string) config('kuking.zgody.wersja_polityki'),
        ]);
    }
}
