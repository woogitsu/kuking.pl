<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use App\Models\PotwierdzenieZadaniaRodo;
use App\Models\User;
use App\Support\NumerZadaniaRodo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * JEDYNE miejsce, które pisze do `potwierdzenia_zadan_rodo`.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO JEDNO MIEJSCE, A NIE TRZY WYWOŁANIA W TRZECH KLASACH
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo reguły tego rejestru są wiązane parami i każda para da się złamać
 * z osobna: `wynik = 'wykonane'` musi mieć `zakres`, każdy inny wynik mieć go
 * nie może; `zakonczono` istnieje dokładnie wtedy, gdy wynik nie jest
 * `w_toku`. Baza tego pilnuje CHECK-ami i to jest właściwa kolejność obrony —
 * ale kod, który próbuje zapisać sprzeczność i dostaje 500 w środku obsługi
 * żądania RODO, jest usterką nawet wtedy, gdy baza go powstrzyma. Druga
 * ścieżka zapisu prędzej czy później zapomni o jednej z tych par.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  TA KLASA NIE MA METODY ODCZYTU — I TO NIE JEST PRZEOCZENIE
 * ────────────────────────────────────────────────────────────────────────
 *
 * Od decyzji właściciela z 21.09.2026 `konto_id` zostaje w wierszu także po
 * wykonaniu żądania (`docs/decyzje/PROJEKT_POTWIERDZENIA_RODO.md` §3.3
 * punkt 7). Decyzja brzmi „ma się dać odpowiedzieć regulatorowi", a nie „ma
 * być wyszukiwarka" — więc rejestr nie dostaje żadnej publicznej metody,
 * która zwracałaby wiersze po `konto_id`. Wyszukanie po koncie jest tu
 * PRYWATNE i służy wyłącznie domknięciu sprawy, którą sami wcześniej
 * otworzyliśmy. Pilnuje tego `RejestrPotwierdzenRodoNieMaEkranuTest`.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  KAŻDE DOMKNIĘCIE IDZIE W TRANSAKCJI WOŁAJĄCEGO
 * ────────────────────────────────────────────────────────────────────────
 *
 * Metody niżej NIE otwierają własnej transakcji na skutek — są wołane
 * ze środka `DB::transaction()` w `EraseAccountData` i `CancelAccountDeletion`
 * i to jest cała ich wartość. Potwierdzenie zapisane w OSOBNEJ transakcji
 * potrafi opisywać wykonanie, którego nie było (wymazanie padło po zapisie
 * potwierdzenia) albo przemilczeć wykonanie, które było (potwierdzenie padło
 * po wymazaniu). Dowodzi tego `PotwierdzenieRodoIdzieWTejSamejTransakcjiTest`.
 *
 * Jedyny `DB::transaction()` w tej klasie opakowuje POJEDYNCZY `INSERT` przy
 * losowaniu numeru — to jest SAVEPOINT, nie druga transakcja: bez niego
 * kolizja unikalności zatruwałaby całą otaczającą transakcję i drugie
 * losowanie odbijałoby się o „current transaction is aborted" (ta sama
 * pułapka i to samo lekarstwo co w `DataSettingsController::requestExport`).
 */
final class RejestrPotwierdzenRodo
{
    /**
     * Ile razy wolno powtórzyć losowanie numeru, zanim uznamy, że odbija się
     * coś innego niż kolizja. Numer ma ~59 bitów, więc trzy próby to zapas
     * nieosiągalny w praktyce — a nie pętla bez końca, gdyby indeks
     * unikalności kiedyś zaczął odrzucać z innego powodu.
     */
    private const PROB_LOSOWANIA = 3;

    /**
     * Żądanie usunięcia konta właśnie wpłynęło (`/ustawienia/twoje-dane`).
     *
     * Wiersz powstaje TERAZ, a nie przy wykonaniu, z dwóch powodów naraz:
     * człowiek dostaje numer sprawy, zanim jego dane znikną, a `otrzymano`
     * jest datą wpływu, bez której nie da się wykazać terminu z art. 12
     * ust. 3 RODO.
     *
     * `zakres` tu NIE STOI, choć człowiek już go wybrał — CHECK
     * `..._zakres_tylko_wykonane_check` go zabrania i słusznie: zakres jest
     * zapisem tego, co WYKONANO, a przez 30 dni karencji nie wykonano
     * niczego i wybór wciąż może się zmienić razem z cofnięciem żądania.
     * Wybór z chwili zgłoszenia żyje w `users.delete_scope` i we wpisie
     * `account.delete_requested` (D-022).
     */
    public function przyjmijZadanieUsunieciaKonta(User $user): PotwierdzenieZadaniaRodo
    {
        return $this->zapisz([
            'rodzaj' => SlownikPotwierdzenRodo::RODZAJE[0],
            'wynik' => PotwierdzenieZadaniaRodo::WYNIK_W_TOKU,
            'zakres' => null,
            'otrzymano' => Carbon::today(),
            'zakonczono' => null,
            'wersja_procedury' => SlownikPotwierdzenRodo::WERSJA_PROCEDURY_DZIS,
            // Czego nie usunięto, wiadomo dopiero po wykonaniu — a wpisanie
            // tego z góry byłoby obietnicą, nie sprawozdaniem.
            'wyjatki' => null,
            'konto_id' => $user->getKey(),
        ]);
    }

    /**
     * Dane zostały wymazane — domknięcie sprawy w TEJ SAMEJ transakcji co
     * `EraseAccountData`.
     *
     * `$zakres` bierzemy od wołającego, bo tylko on wie, co NAPRAWDĘ zrobił:
     * `EraseAccountData` czyta `users.delete_scope` pod blokadą, a ten sam
     * odczyt powtórzony tutaj mógłby trafić na wiersz już zanonimizowany.
     * Zakres z domysłu („pewnie minimum, bo taki jest domyślny") byłby
     * zapisem nieprawdy w dowodzie.
     *
     * `konto_id` ZOSTAJE — decyzja właściciela z 21.09.2026.
     */
    public function domknijJakoWykonane(User $user, string $zakres): PotwierdzenieZadaniaRodo
    {
        if (! in_array($zakres, SlownikPotwierdzenRodo::zakresy(), true)) {
            throw new RuntimeException(
                'Nieznany zakres wykonania żądania RODO: `'.$zakres.'`. Dozwolone: '
                .implode(', ', SlownikPotwierdzenRodo::zakresy()).'. Potwierdzenie z zakresem '
                .'spoza tej listy odbiłoby się od CHECK-a w bazie w środku wymazywania danych.',
            );
        }

        return $this->domknij($user, PotwierdzenieZadaniaRodo::WYNIK_WYKONANE, $zakres, $this->wyjatki($zakres));
    }

    /**
     * Człowiek cofnął żądanie w karencji — domknięcie w TEJ SAMEJ transakcji
     * co `CancelAccountDeletion`.
     *
     * To jest ten wynik, o który toczy się spór „nigdy nie prosiłem
     * o usunięcie konta" (ADR_RETENCJE.md §3.1): `User::cancelDeletion()`
     * ZERUJE `delete_requested_at`, więc poza tym wierszem i dziennikiem
     * audytu nie zostaje po żądaniu żaden ślad.
     */
    public function domknijJakoCofniete(User $user): PotwierdzenieZadaniaRodo
    {
        return $this->domknij($user, PotwierdzenieZadaniaRodo::WYNIK_COFNIETE, null, null);
    }

    /**
     * Wspólne domknięcie: znajdź sprawę w toku tego konta i zamknij ją.
     *
     * GDY SPRAWY W TOKU NIE MA, wiersz powstaje od razu zamknięty — i to nie
     * jest backfill (ten jest osobnym, niezleconym krokiem właściciela,
     * §5 punkt 5 projektu). To jest ta jedna sytuacja, w której ścieżka
     * zapisu musi być TOTALNA: konto, które zgłosiło usunięcie ZANIM ten kod
     * wszedł na produkcję, nie ma wiersza `w_toku`, a mimo to zostanie
     * wymazane po swojej karencji. Bez tej gałęzi jego wymazanie nie miałoby
     * ŻADNEGO potwierdzenia — czyli dokładnie ten brak dowodu, przed którym
     * cała ta tabela ma bronić. `otrzymano` bierzemy wtedy z
     * `users.delete_requested_at`, bo to jest prawdziwa data wpływu.
     */
    private function domknij(User $user, string $wynik, ?string $zakres, ?string $wyjatki): PotwierdzenieZadaniaRodo
    {
        $wToku = PotwierdzenieZadaniaRodo::query()
            ->where('konto_id', $user->getKey())
            ->where('wynik', PotwierdzenieZadaniaRodo::WYNIK_W_TOKU)
            ->orderByDesc('otrzymano')
            ->orderByDesc('created_at')
            ->first();

        $otrzymano = $wToku?->otrzymano?->toDateString()
            ?? $user->delete_requested_at?->toDateString()
            ?? Carbon::today()->toDateString();

        $zakonczono = $this->niePrzedOtrzymaniem($otrzymano);

        if ($wToku === null) {
            return $this->zapisz([
                'rodzaj' => SlownikPotwierdzenRodo::RODZAJE[0],
                'wynik' => $wynik,
                'zakres' => $zakres,
                'otrzymano' => $otrzymano,
                'zakonczono' => $zakonczono,
                'wersja_procedury' => SlownikPotwierdzenRodo::WERSJA_PROCEDURY_DZIS,
                'wyjatki' => $wyjatki,
                'konto_id' => $user->getKey(),
            ]);
        }

        // Przypisanie WPROST, nie `update([...])` — `wynik`, `zakres`
        // i `zakonczono` są poza `$fillable` z powodów wypisanych przy modelu.
        $wToku->wynik = $wynik;
        $wToku->zakres = $zakres;
        $wToku->zakonczono = $zakonczono;
        $wToku->wyjatki = $wyjatki;
        $wToku->save();

        return $wToku;
    }

    /**
     * Data zakończenia nigdy wcześniejsza niż data wpływu
     * (`..._kolejnosc_dat_check`).
     *
     * W normalnym biegu rzeczy karencja trwa 30 dni, więc „dziś" jest
     * z ogromnym zapasem później. Ta linijka broni przypadku, w którym
     * żądanie wpłynęło i zostało cofnięte tego samego dnia przy zegarze
     * przestawionym w teście albo po zmianie strefy — wtedy `zakonczono`
     * równe `otrzymano` jest prawdą, a odmowa bazy w środku transakcji
     * cofającej usunięcie konta byłaby awarią widoczną dla człowieka.
     */
    private function niePrzedOtrzymaniem(string $otrzymano): Carbon
    {
        $wplyw = Carbon::parse($otrzymano)->startOfDay();
        $dzis = Carbon::today();

        return $dzis->lessThan($wplyw) ? $wplyw : $dzis;
    }

    /**
     * Czego usunięcie konta NIE OBEJMUJE — tekst wskazuje REGUŁĘ, nie opowiada
     * o człowieku (wymóg z komentarza migracji i z oceny §C).
     */
    private function wyjatki(string $zakres): string
    {
        $wspolne = 'Dokumentacja sprawy moderacyjnej (zgłoszenia, decyzje, odwołania) ma własny '
            .'okres 36 miesięcy od zamknięcia sprawy — docs/decyzje/ADR_RETENCJE.md §5.3–5.5.';

        if ($zakres === User::DELETE_SCOPE_EVERYTHING) {
            return 'Treści usunięte razem z kontem, zgodnie z wyborem wnioskodawcy (D-022). '.$wspolne;
        }

        return 'Treści (przepisy, wpisy, komentarze, wykonania) zostały w serwisie bez danych '
            .'osobowych autora, pod podpisem „Użytkownik usunięty” — docs/legal/COMPLIANCE.md §2. '
            .$wspolne;
    }

    /**
     * Wstawia wiersz, losując numer aż do skutku.
     *
     * Unikalności pilnuje INDEKS W BAZIE, nie sprawdzenie przed wstawką:
     * dwa żądania mogą wylosować ten sam numer w tej samej chwili i oba
     * zobaczyłyby „wolne".
     *
     * @param  array<string, mixed>  $wiersz
     */
    private function zapisz(array $wiersz): PotwierdzenieZadaniaRodo
    {
        for ($proba = 1; $proba <= self::PROB_LOSOWANIA; $proba++) {
            $potwierdzenie = new PotwierdzenieZadaniaRodo;

            // Przypisanie WPROST zamiast `create([...])`: poza `$fillable`
            // stoi tu wszystko, co decyduje o treści dowodu i o zegarze
            // retencji (uzasadnienie przy modelu).
            $potwierdzenie->numer = NumerZadaniaRodo::wygeneruj();
            $potwierdzenie->rodzaj = $wiersz['rodzaj'];
            $potwierdzenie->wynik = $wiersz['wynik'];
            $potwierdzenie->zakres = $wiersz['zakres'];
            $potwierdzenie->otrzymano = $wiersz['otrzymano'];
            $potwierdzenie->zakonczono = $wiersz['zakonczono'];
            $potwierdzenie->wersja_procedury = $wiersz['wersja_procedury'];
            $potwierdzenie->wyjatki = $wiersz['wyjatki'];
            $potwierdzenie->konto_id = $wiersz['konto_id'];

            try {
                // SAVEPOINT, nie druga transakcja — uzasadnienie w komentarzu
                // klasy.
                DB::transaction(static fn () => $potwierdzenie->save());

                return $potwierdzenie;
            } catch (UniqueConstraintViolationException $e) {
                // Druga otwarta sprawa tego konta (#1346) to nie kolizja
                // numeru — losowanie od nowa nic tu nie zmieni.
                if ($proba === self::PROB_LOSOWANIA
                    || str_contains($e->getMessage(), 'potwierdzenia_zadan_rodo_jedna_w_toku_na_konto')) {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('Nie udało się wylosować wolnego numeru sprawy RODO.');
    }
}
