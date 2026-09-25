<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Models\ModerationAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Zapytania ekranu „Konta użytkowników" w panelu moderacji
 * (`/admin/uzytkownicy`).
 *
 * Wyjęte z `App\Http\Controllers\Admin\UzytkownicyController` bez zmiany
 * zachowania (issue #970): te same zapytania, ta sama kolejność, ta sama
 * liczba zapytań. Wejście z adresu — filtry i sortowanie sprowadzone do
 * wartości, których nie da się podrobić — przygotowuje
 * `App\Http\Requests\Admin\ListaKontRequest`. Ta klasa nie zna żądania HTTP:
 * dostaje gotowe filtry, więc tę samą listę można zapytać z testu albo
 * z komendy bez udawania przeglądarki.
 *
 * Dlaczego ekran jest tylko do patrzenia i czego NIE logujemy do
 * `audit_log` — nagłówek kontrolera. Tutaj są wyłącznie zapytania.
 *
 * ZERO N+1. Profil idzie przez `with()`, liczba wpisów przez `withCount()`
 * (podzapytanie w tym samym `SELECT`), liczniki filtrów jednym `GROUP BY`.
 * Liczba zapytań NIE ROŚNIE z liczbą kont i pilnuje tego test
 * (`PanelUzytkownicyTest::test_liczba_zapytan_nie_rosnie_z_liczba_kont`).
 */
final class ListaKont
{
    /**
     * Ile kont na stronie.
     *
     * Ta sama liczba co w kolejce odwołań i wiadomości — jeden rytm
     * stronicowania w całym panelu. Przy 25 wierszach tabela mieści się
     * na ekranie razem z nagłówkiem, a osoba z powiększonym tekstem nie
     * przewija pół dnia do stronicowania na dole.
     */
    public const NA_STRONIE = 25;

    /**
     * Po czym wolno sortować — BIAŁA LISTA, nie nazwa kolumny z adresu.
     *
     * `?sortuj=` trafia do `ORDER BY`, więc ta lista jest jedyną rzeczą,
     * która dzieli ten ekran od wstrzyknięcia SQL. `ListaKontRequest`
     * przepuszcza wyłącznie klucze z tej listy, a `klauzulaSortowania()`
     * i tak bierze kolumnę stąd, nie z żądania. Klucze są po polsku, bo
     * widać je w pasku adresu.
     *
     * @var array<string, string>
     */
    public const SORTOWANIA = [
        'rejestracja' => 'users.created_at',
        'aktywnosc' => 'users.ostatnio_widziany_at',
        'wpisy' => 'wpisow_count',
    ];

    /**
     * DOMYŚLNIE: NAJNOWSZE KONTA NA GÓRZE. Nigdy „najwięcej wpisów".
     *
     * AGENTS.md §12 zakazuje publicznych rankingów użytkowników. Lista
     * posortowana domyślnie po liczbie wpisów malejąco JEST takim rankingiem
     * — wystarczy zrzut ekranu, żeby wyszła z niej „czołówka najaktywniejszych"
     * pokazana komukolwiek poza moderatorem. Sortowanie po wpisach zostaje
     * dostępne, bo bywa potrzebne przy koncie zakładanym pod spam, ale trzeba
     * je włączyć świadomie, klikając w nagłówek kolumny.
     *
     * Data rejestracji malejąco odpowiada na pytanie, które przy tej liście
     * pada najczęściej, a przy fali z Garnek.pl będzie padać codziennie:
     * „kto przyszedł dzisiaj".
     */
    public const SORTOWANIE_DOMYSLNE = 'rejestracja';

    /**
     * Jedna strona listy kont.
     *
     * @param  array{status: string, od: ?CarbonImmutable, do: ?CarbonImmutable, bez_wpisow: bool, szukaj: string, fraza: string}  $filtry
     * @return LengthAwarePaginator<int, User>
     */
    public function strona(array $filtry, string $sortuj, string $kierunek): LengthAwarePaginator
    {
        $zapytanie = User::query()
            /*
             * PROFIL PRZEZ `with()`, NIE W PĘTLI. Bez tego każdy z 25 wierszy
             * dokładałby własne zapytanie o nazwę — czyli 26 zamiast 2.
             *
             * AWATARA ŚWIADOMIE TU NIE MA (`profile.avatar` nie jest
             * doładowywane). To jest tabela do czytania, nie galeria: dodanie
             * zdjęć dołożyłoby trzecie zapytanie i wariantów pliku na każdy
             * wiersz, a tożsamość na tym ekranie niesie nazwa z `@username`.
             * Awatar jest tam, gdzie ma znaczenie — na karcie konta.
             */
            ->with('profile')
            /*
             * `withCount` = PODZAPYTANIE W TYM SAMYM `SELECT`, zero dodatkowych
             * zapytań niezależnie od liczby wierszy. Idzie po istniejącym
             * indeksie `posts_author_published_idx (author_id, …) WHERE
             * deleted_at IS NULL`.
             *
             * Liczy WPISY, nie „aktywność" w ogóle — moderatorowi chodzi o to,
             * czy konto w ogóle czegokolwiek tu dodało. Soft delete sprawia,
             * że usunięte wpisy się nie liczą, i tak ma być: pytamy o to, co
             * dziś stoi w serwisie.
             */
            ->withCount(['posts as wpisow_count']);

        if ($filtry['status'] !== 'wszystkie') {
            $zapytanie->where('users.status', $filtry['status']);
        }

        if ($filtry['od'] instanceof CarbonImmutable) {
            $zapytanie->where('users.created_at', '>=', $filtry['od']);
        }

        if ($filtry['do'] instanceof CarbonImmutable) {
            $zapytanie->where('users.created_at', '<', $filtry['do']);
        }

        if ($filtry['bez_wpisow']) {
            // `NOT EXISTS` po tym samym indeksie co `withCount` wyżej —
            // nie liczy wpisów, tylko sprawdza, czy jest choć jeden.
            $zapytanie->whereDoesntHave('posts');
        }

        if ($filtry['fraza'] !== '') {
            $wzorzec = '%'.$this->doLike($filtry['fraza']).'%';

            $zapytanie->where(function ($szukaj) use ($wzorzec): void {
                // Adres e-mail leży w bazie już małymi literami (mutator
                // `User::email`), ale przepuszczamy go przez tę samą funkcję
                // co nazwy — inaczej „Michał@…" wpisane w pole szukania nie
                // znalazłoby niczego, bo fraza jest znormalizowana, a kolumna
                // nie. Indeks `users_email_trgm_idx` stoi na dokładnie tym
                // wyrażeniu.
                $szukaj->whereRaw('public.kuking_normalize(users.email) LIKE ?', [$wzorzec])
                    ->orWhereHas('profile', function ($profil) use ($wzorzec): void {
                        // KOLUMNY `*_search`, nie `kuking_normalize(kolumna)`.
                        // To są kolumny generowane z issue #116, z gotowymi
                        // indeksami trigramowymi — liczenie normalizacji od
                        // nowa przy każdym wierszu było dokładnie tym kosztem,
                        // który tamta migracja usunęła.
                        $profil->where('profiles.username_search', 'like', $wzorzec)
                            ->orWhere('profiles.display_name_search', 'like', $wzorzec);
                    });
            });
        }

        return $zapytanie
            ->orderByRaw($this->klauzulaSortowania($sortuj, $kierunek))
            /*
             * DRUGI WARUNEK ROZSTRZYGA REMIS — ten sam powód co w
             * `AppealController::index()`. Bez niego PostgreSQL oddaje wiersze
             * o równej wartości w porządku fizycznym, a ten przestawia każdy
             * UPDATE (choćby zapis `ostatnio_widziany_at`). Przy stronicowaniu
             * po 25 znaczy to inny podział na strony między jednym kliknięciem
             * a drugim: konto pokazane dwa razy albo pominięte. Przy sortowaniu
             * po liczbie wpisów remisy są regułą, nie wyjątkiem — zero wpisów
             * ma większość kont, a przy tysiącach kont to są całe strony
             * nierozróżnialnych wierszy.
             *
             * `id` jest UUID-em v7, więc rozstrzyga tak samo jak czas założenia
             * konta: starsze niżej przy DESC.
             */
            ->orderByDesc('users.id')
            ->paginate(self::NA_STRONIE)
            ->withQueryString();
    }

    /**
     * Historia decyzji moderacyjnych DOTYCZĄCYCH tego konta.
     *
     * Po `subject_user_id`, nie po `moderator_id` — pytamy „co się z tą osobą
     * działo", nie „co ta osoba rozstrzygnęła". Idzie po istniejącym indeksie
     * `moderation_actions_subject_idx (subject_user_id, created_at DESC)`,
     * więc nie potrzeba tu żadnej zmiany schematu.
     *
     * Bez stronicowania, za to z twardym limitem: to jest kontekst do sprawy,
     * a nie druga kolejka do pracy. Konto z pięćdziesięcioma decyzjami jest
     * i tak rozstrzygnięte na pierwszy rzut oka.
     *
     * @return EloquentCollection<int, ModerationAction>
     */
    public function historiaDecyzji(User $user): EloquentCollection
    {
        return ModerationAction::query()
            ->where('subject_user_id', $user->getKey())
            ->with('moderator.profile')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    /**
     * Ile kont w każdym stanie — liczby przy zakładkach filtra.
     *
     * JEDNO zapytanie grupujące, nie pięć `count()`. To nie jest panel
     * statystyk: te liczby istnieją po to, żeby moderator wiedział, czy
     * zakładka „Zawieszone" jest pusta, ZANIM w nią kliknie — przy tysiącach
     * kont wejście na pustą listę to strata, której da się uniknąć.
     *
     * @return array<string, int>
     */
    public function liczniki(): array
    {
        $wiersze = User::query()
            ->selectRaw('status, count(*) as ile')
            ->groupBy('status')
            ->pluck('ile', 'status');

        $liczniki = ['wszystkie' => 0];

        foreach (array_keys(User::ETYKIETY_STATUSU) as $status) {
            $liczniki[$status] = (int) ($wiersze[$status] ?? 0);
            $liczniki['wszystkie'] += $liczniki[$status];
        }

        return $liczniki;
    }

    /**
     * Fraza bezpieczna do wstawienia w `LIKE`.
     *
     * `%` i `_` są w `LIKE` znakami wieloznacznymi. Bez tego kroku wpisanie
     * samego „%" oddawałoby WSZYSTKIE konta w serwisie jednym znakiem —
     * a „_" cicho psułby szukanie nazw z podkreśleniem (`profiles.username`
     * dopuszcza je wprost, CHECK `^[a-zA-Z0-9_]{3,40}$`). Backslash uciekamy
     * jako pierwszy, inaczej uciekalibyśmy własne ucieczki.
     */
    private function doLike(string $fraza): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $fraza);
    }

    /**
     * Klauzula `ORDER BY` — kolumna wyłącznie z białej listy wyżej, kierunek
     * wyłącznie `ASC` albo `DESC`, żadnego fragmentu z żądania.
     *
     * Klucz spoza listy i kierunek inny niż `asc` sprowadzamy do wartości
     * domyślnych TAKŻE TUTAJ, choć `ListaKontRequest` już to zrobił. Ta klasa
     * jest publiczna i ktoś kiedyś wywoła ją z innego miejsca — a ta metoda
     * skleja SQL, więc nie może polegać na tym, że wejście przyszło przez
     * właściwe drzwi.
     *
     * `NULLS LAST` W OBIE STRONY I TO NIE JEST KOSMETYKA. PostgreSQL domyślnie
     * stawia NULL-e na końcu przy `ASC` i na POCZĄTKU przy `DESC`. Kolumna
     * `ostatnio_widziany_at` jest pusta u kont, które nigdy nie weszły
     * (i u zanonimizowanych — `EraseAccountData` ją zeruje), więc sortowanie
     * „ostatnio widziani, najnowsi na górze" zaczynałoby się od kilkudziesięciu
     * wierszy z pustym polem. Pierwsza strona pokazywałaby dokładnie te konta,
     * o które nikt nie pytał.
     */
    private function klauzulaSortowania(string $sortuj, string $kierunek): string
    {
        $kolumna = self::SORTOWANIA[$sortuj] ?? self::SORTOWANIA[self::SORTOWANIE_DOMYSLNE];

        return $kolumna.' '.($kierunek === 'asc' ? 'ASC' : 'DESC').' NULLS LAST';
    }
}
