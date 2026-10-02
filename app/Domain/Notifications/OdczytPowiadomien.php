<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Odczyt powiadomień odbiorcy — jedno wejście dla listy, licznika, plakietki,
 * „Oznacz wszystkie" i pojedynczego otwarcia (etap 4 issue #1687).
 *
 * PO CO. Do tej pory każde z tych miejsc samo składało
 * `$user->notifications()->visibleTo($user)…`: kontroler cztery razy,
 * `User` dwa razy. Filtr był jeden, ale „co wolno zobaczyć" nie miało
 * jednego adresu, pod którym da się je zmienić, i model `User` znał
 * szczegóły odczytu powiadomień. Ta klasa jest tym adresem: cały odczyt
 * przechodzi przez `Notification::scopeVisibleTo()` (→ `WidocznoscPowiadomien`
 * → `WidocznoscTresciSql`), a wołający nie budują filtra po swojemu.
 *
 * CZEGO TU NIE MA. Reguł widoczności (`WidocznoscPowiadomien`), adresów
 * „Zobacz" (`CelPowiadomienia`), wycinków komentarzy (`WycinkiKomentarzy`)
 * ani retencji (`Compliance\PrzedawnionePowiadomienia`) — to osobne
 * odpowiedzialności. Tu tylko: które wiersze, ile ich i jakie stany
 * (istnienie wykonania, slug przepisu) dociągnąć zbiorczo, żeby liczba
 * zapytań nie rosła z liczbą wierszy (#833, D-196).
 */
final class OdczytPowiadomien
{
    /** Ile powiadomień mieści strona listy. */
    public const NA_STRONE = 30;

    /**
     * Strona powiadomień widocznych dla odbiorcy, z awatarami nadawców i ze
     * stanem celów doładowanym JEDNYM zapytaniem na rodzaj (nie na wiersz).
     *
     * `simplePaginate()`, NIE `paginate()` (issue #2289). Lista nie pokazuje
     * liczby stron ani wszystkich powiadomień — tylko „Następna strona"
     * (`<x-show-more>`), a na to wystarczy jeden wiersz ponad stronę.
     * `paginate()` dokładał pełne `COUNT(*)` przez filtr widoczności, którego
     * szacowany koszt od ok. 600 widocznych powiadomień przekraczał
     * `jit_above_cost` — PostgreSQL kompilował je przez JIT przy każdym
     * wejściu (ok. 0,4–0,5 s przy 1000–2000 powiadomieniach, retencja trzy
     * miesiące). Pilnuje `PowiadomieniaKosztPlanuTest`.
     *
     * ZAKRES „NIEPRZECZYTANE” (#2442): ten sam odczyt, ten sam filtr
     * widoczności (`visibleTo`), tylko z dodatkowym `read_at IS NULL` PRZED
     * limitem i paginacją — nie filtrowanie w PHP i nie osobne `COUNT(*)`.
     * Odczyt strony niczego nie oznacza jako przeczytane.
     *
     * @return Paginator<int, Notification>
     */
    public function strona(User $odbiorca, int $naStrone = self::NA_STRONE, bool $tylkoNieprzeczytane = false): Paginator
    {
        $strona = $odbiorca
            ->notifications()
            ->visibleTo($odbiorca)
            ->when($tylkoNieprzeczytane, fn ($zapytanie) => $zapytanie->whereNull('notifications.read_at'))
            ->with('actor.profile.avatar')
            ->simplePaginate($naStrone);

        $wiersze = array_values($strona->items());

        $this->doladujZapisujacych($wiersze, $odbiorca);
        $this->doladujIstnienieWykonan($wiersze);
        $this->doladujSlugiPrzepisow($wiersze);
        // Pokazane przepisy (#2650): tytuł tylko przy bieżącym `readShared()`.
        app(CelPowiadomienia::class)->wczytajPrzepisyUdostepnione($wiersze, $odbiorca);

        return $strona;
    }

    /** Wszystkie nieprzeczytane, które odbiorca widzi — to samo co lista. */
    public function liczbaNieprzeczytanych(User $odbiorca): int
    {
        return $odbiorca->notifications()
            ->visibleTo($odbiorca)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * Czy odbiorca ma choć jedno widoczne nieprzeczytane — do przycisku
     * „Oznacz wszystkie" (issue #1402). `EXISTS` zatrzymuje się na pierwszym
     * wierszu, więc koszt nie rośnie z zaległościami jak pełne `COUNT(*)`
     * z `liczbaNieprzeczytanych()` (issue #2289: ten sam próg JIT co lista).
     */
    public function saNieprzeczytane(User $odbiorca): bool
    {
        return $odbiorca->notifications()
            ->visibleTo($odbiorca)
            ->whereNull('read_at')
            ->exists();
    }

    /**
     * Licznik do plakietki w belce — z sufitem (audyt B4 S1).
     *
     * Plakietka nie pokazuje więcej niż `User::PLAKIETKA_POWIADOMIEN_DO`,
     * więc liczymy najwyżej o jeden wiersz dalej (`LIMIT` w podzapytaniu)
     * i koszt przestaje rosnąć z zaległościami. Ten sam filtr co lista,
     * więc poniżej sufitu wynik jest identyczny.
     */
    public function liczbaDoPlakietki(User $odbiorca): int
    {
        // TANI PRE-CHECK (audyt wydajności W5): plakietka stoi na KAŻDEJ stronie
        // zalogowanej osoby, a filtr widoczności to ogromne zapytanie, którego
        // samo PLANOWANIE kosztuje 14–24 ms. Gdy nie ma żadnego
        // nieprzeczytanego wiersza (zwykle tak jest), widoczny podzbiór też
        // jest pusty — wynik ten sam (0), bez planowania ciężkiego zapytania.
        // `EXISTS` po `notifications_user_unread_idx` (user_id, read_at IS NULL).
        if (! Notification::query()->where('user_id', $odbiorca->getKey())->whereNull('read_at')->exists()) {
            return 0;
        }

        $nieprzeczytane = $odbiorca->notifications()
            ->visibleTo($odbiorca)
            ->whereNull('read_at')
            ->select('notifications.id')
            ->limit(User::PLAKIETKA_POWIADOMIEN_DO + 1)
            ->toBase();

        return DB::query()->fromSub($nieprzeczytane, 'nieprzeczytane')->count();
    }

    /**
     * „Oznacz wszystkie" — wyłącznie to, co odbiorca widzi (issue #969, #1401).
     * Bez tego przycisk gasił też powiadomienia ukryte blokadą albo statusem
     * sprawcy, a po odblokowaniu wracały jako przeczytane.
     *
     * @return int ile wierszy faktycznie zmieniono
     */
    public function oznaczWszystkieJakoPrzeczytane(User $odbiorca): int
    {
        return $odbiorca->notifications()
            ->visibleTo($odbiorca)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Pojedyncze powiadomienie do otwarcia — tylko spośród powiadomień TEJ
     * osoby i tylko widocznych (issue #1351). Cudzy albo ukryty identyfikator
     * daje `null`, bez ujawniania, czy taki wiersz istnieje (AGENTS.md §7).
     */
    public function widoczne(User $odbiorca, string $id): ?Notification
    {
        return $odbiorca->notifications()
            ->visibleTo($odbiorca)
            ->whereKey($id)
            ->first();
    }

    /**
     * Czy powiadomienie o komentarzu jest ukryte WYŁĄCZNIE dlatego, że
     * komentarz albo treść nad nim przestały być dostępne (issue #759).
     *
     * `visibleTo(..., false)` pomija tylko ten jeden warunek: wiersz ukryty
     * blokadą, statusem sprawcy albo typem służbowym (#1351) dalej daje
     * `false` i wołający kończy 404.
     */
    public function ukrytePrzezBrakTresciKomentarza(User $odbiorca, string $id): bool
    {
        return $odbiorca->notifications()
            ->visibleTo($odbiorca, false)
            ->whereKey($id)
            ->whereIn('type', Notification::TYPY_Z_WYCINKIEM_KOMENTARZA)
            ->exists();
    }

    /**
     * Pierwsza widoczna osoba z każdej partii zapisów — JEDNYM zapytaniem na
     * stronę, nie jednym na wiersz (audyt wydajności W2). Reguły widoczności
     * zostają w `WidocznoscPowiadomien`; tu tylko zbieramy partie strony
     * i wręczamy wynik powiadomieniom, żeby `zapisujacyDoPokazania()`
     * w widoku nie pytało bazy po raz drugi.
     *
     * @param  list<Notification>  $powiadomienia  wiersze tego odbiorcy
     */
    private function doladujZapisujacych(array $powiadomienia, User $odbiorca): void
    {
        $partie = [];
        $wiersze = [];

        foreach ($powiadomienia as $powiadomienie) {
            if ($powiadomienie->type !== Notification::TYPE_SAVED) {
                continue;
            }

            $zapisujacy = $powiadomienie->zapisujacyPartii();

            if ($zapisujacy === []) {
                $powiadomienie->ustawZapisujacyDoPokazania(null);

                continue;
            }

            $klucz = (string) $powiadomienie->getKey();
            $partie[$klucz] = $zapisujacy;
            $wiersze[$klucz] = $powiadomienie;
        }

        if ($partie === []) {
            return;
        }

        foreach (app(WidocznoscPowiadomien::class)->pierwsiWidoczniZapisujacy($partie, (string) $odbiorca->getKey()) as $klucz => $pierwszy) {
            $wiersze[$klucz]->ustawZapisujacyDoPokazania($pierwszy);
        }
    }

    /**
     * Czy wykonania z powiadomień o ugotowaniu jeszcze istnieją (issue #771)
     * — JEDNYM zapytaniem, bez pytania o każde wykonanie osobno w widoku.
     *
     * @param  list<Notification>  $powiadomienia
     */
    private function doladujIstnienieWykonan(array $powiadomienia): void
    {
        $doSprawdzenia = [];

        foreach ($powiadomienia as $powiadomienie) {
            $id = $powiadomienie->data['cooked_event_id'] ?? null;

            if (in_array($powiadomienie->type, [Notification::TYPE_COOKED, Notification::TYPE_HINT_PROPOSED], true) && is_string($id) && Str::isUuid($id)) {
                $doSprawdzenia[$id][] = $powiadomienie;
            }
        }

        if ($doSprawdzenia === []) {
            return;
        }

        $istniejace = CookedEvent::query()
            ->whereIn('id', array_keys($doSprawdzenia))
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->flip();

        foreach ($doSprawdzenia as $id => $grupa) {
            foreach ($grupa as $powiadomienie) {
                $powiadomienie->zapamietajIstnienieWykonania($istniejace->has($id));
            }
        }
    }

    /**
     * Aktualne slugi przepisów z powiadomień o zapisaniu do zeszytu
     * (issue #1034) — JEDNYM zapytaniem. Przepis usunięty miękko nie wraca
     * (`SoftDeletes`), więc jego powiadomienie dostaje `null` i traci „Zobacz".
     *
     * @param  list<Notification>  $powiadomienia
     */
    private function doladujSlugiPrzepisow(array $powiadomienia): void
    {
        $doSprawdzenia = [];

        foreach ($powiadomienia as $powiadomienie) {
            $id = $powiadomienie->data['recipe_id'] ?? null;

            if (in_array($powiadomienie->type, [Notification::TYPE_SAVED, Notification::TYPE_HINT_ACCEPTED], true) && is_string($id) && Str::isUuid($id)) {
                $doSprawdzenia[$id][] = $powiadomienie;
            }
        }

        if ($doSprawdzenia === []) {
            return;
        }

        $slugi = Recipe::query()
            ->whereIn('id', array_keys($doSprawdzenia))
            ->pluck('slug', 'id')
            ->mapWithKeys(fn (mixed $slug, mixed $id): array => [(string) $id => (string) $slug]);

        foreach ($doSprawdzenia as $id => $grupa) {
            foreach ($grupa as $powiadomienie) {
                $powiadomienie->zapamietajSlugPrzepisu($slugi->get($id));
            }
        }
    }
}
