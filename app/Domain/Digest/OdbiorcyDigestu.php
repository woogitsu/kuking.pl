<?php

declare(strict_types=1);

namespace App\Domain\Digest;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Kto w ogóle może dostać tygodniowe podsumowanie i kto dostanie je DZIŚ
 * (issue #11, `docs/DECISIONS.md` D-057).
 *
 * TO JEST KLASA O JEDNYM OGRANICZENIU: 300 LISTÓW NA DOBĘ
 * Konto pocztowe Kuking (EmailLabs STARTUP, `docs/decyzje/POCZTA.md` §1) ma
 * twardy limit **300 wiadomości na dobę na całe konto** — wspólny
 * z potwierdzeniami adresu, resetami haseł, ostrzeżeniami o zmianie adresu
 * i powiadomieniami moderacyjnymi. Tygodniowe podsumowanie do 500 osób
 * **nie mieści się w jednej dobie** i żadne sprytniejsze zapytanie tego nie
 * zmieni.
 *
 * ROZWIĄZANIE: JEDNA KOLEJKA, DOBOWY SUFIT, „KTO CZEKA NAJDŁUŻEJ"
 * Zadanie chodzi CODZIENNIE i za każdym razem bierze najwyżej
 * `config('kuking.digest.dzienny_limit')` osób — domyślnie 60. Nie połowę
 * wiadra i nie „ile się zmieści": 120 listów jest już zajętych przez
 * logowanie linkiem (issue #25), a 100 zostawiamy wolnych na potwierdzenia
 * rejestracji i przypomnienia haseł, których nie da się przełożyć na jutro.
 * Cały rachunek stoi w `config/kuking.php`, sekcja `poczta`. Sufitu pilnuje
 * `App\Domain\Security\DziennyBudzetListow` — ta sama klasa co przy
 * logowaniu linkiem, żeby nie było dwóch liczników jednego wiadra.
 *
 * Kolejność to `weekly_digest_sent_at ASC NULLS FIRST`: najpierw ci, którzy
 * nie dostali nigdy, potem ci, którzy czekają najdłużej.
 *
 * CO SIĘ PRZEZ TO TRACI, ŻEBY BYŁO JASNE: WSPÓLNY PIĄTEK.
 * `docs/product/RETENTION_LOOPS.md` §4 chciał jednej wysyłki w piątek. Przy
 * 60 listach dziennie „wszyscy w piątek" kończy się na 60 kontach — powyżej
 * tego liczba nie kłamie tylko wtedy, gdy wysyłka trwa kilka dni. Wybieramy
 * więc obietnicę, którą da się dotrzymać („jeden list na tydzień"), a nie tę,
 * której nie da się („zawsze w piątek"). Dzień tygodnia ustala się dla każdej
 * osoby sam i potem jest stały, bo kolejność jest stabilna.
 *
 * PRZEPUSTOWOŚĆ: 60 × 7 = **420 osób tygodniowo**. Powyżej tego progu plan
 * darmowy przestaje wystarczać — nie „zwalnia", tylko przestaje: przy 500
 * kontach z pełną zgodą i przy 2 000 kont część ludzi dostawałaby list co
 * drugi tydzień, więc obietnica z ekranu ustawień przestałaby być prawdą.
 * Rachunek dla 100, 500 i 2 000 kont oraz moment przejścia na plan płatny:
 * `docs/DECISIONS.md` D-057. Że kolejka nie schodzi do zera, widać
 * w dzienniku: komenda zapisuje wtedy `ileCzeka()`.
 */
final class OdbiorcyDigestu
{
    /**
     * Wszyscy, którzy w ogóle kwalifikują się do wysyłki — bez limitu dobowego
     * i bez sprawdzania odstępu. Do rachunków i do diagnostyki.
     *
     * @return Builder<User>
     */
    public function kwalifikujacySie(): Builder
    {
        return User::query()
            // ZGODA — pierwsza i bezwarunkowa. To nie jest poczta
            // transakcyjna: podstawą jest art. 6 ust. 1 lit. a RODO, czyli
            // zgoda, którą człowiek wyraził haczykiem na
            // `/ustawienia/prywatnosc` (`docs/DATABASE.md`,
            // `wants_weekly_digest`).
            ->where('wants_weekly_digest', true)
            // TYLKO KONTO CZYNNE. Issue #11 wymienia `suspended`, `banned`
            // i `pending_delete`; dochodzi `erased` (D-022), bo tam adres
            // e-mail jest już zanonimizowany i list poszedłby w próżnię albo,
            // gorzej, pod cudzy adres. Prościej i bezpieczniej jest wymienić
            // JEDYNY status, który wolno, niż listę tych, których nie wolno:
            // piąty status dodany kiedyś w przyszłości domyślnie NIE dostanie
            // poczty, zamiast dostać ją przez przeoczenie.
            ->where('status', User::STATUS_ACTIVE)
            // POTWIERDZONY ADRES. Adres niepotwierdzony może należeć do kogoś
            // innego (literówka przy rejestracji) — a list opowiada, kto
            // ugotował z czyjego przepisu i kto kogo obserwuje. To są cudze
            // dane i nie mają prawa trafić pod niesprawdzoną skrzynkę.
            ->whereNotNull('email_verified_at')
            // KONTA Z TREŚCI ZALĄŻKOWEJ (D-025) NIE SĄ LUDŹMI. Nikt tych
            // skrzynek nie czyta, a każdy taki list zjada listę z dobowego
            // limitu prawdziwej osobie.
            ->where(function (Builder $q): void {
                $q->where('is_seeded', false)->orWhereNull('is_seeded');
            });
    }

    /**
     * Kto dostanie list w TYM przebiegu.
     *
     * `$limit` domyślnie z konfiguracji; komenda potrafi go zawęzić flagą,
     * żeby dało się wysłać jeden list próbny bez ruszania konfiguracji.
     *
     * @return Collection<int, User>
     */
    public function naDzis(?int $limit = null): Collection
    {
        $limit ??= (int) config('kuking.digest.dzienny_limit');
        $odstep = (int) config('kuking.digest.odstep_dni');

        return $this->kwalifikujacySie()
            // ODSTĘP — tu mieszka obietnica „jeden e-mail tygodniowo, nigdy
            // więcej" (issue #11 pkt 4) i tu mieszka odporność na powtórne
            // uruchomienie zadania tego samego dnia. To jest ten sam warunek,
            // bo to jest to samo pytanie: „czy ta osoba dostała już list
            // w ciągu ostatnich siedmiu dni".
            ->where(function (Builder $q) use ($odstep): void {
                $q->whereNull('weekly_digest_sent_at')
                    ->orWhere('weekly_digest_sent_at', '<=', now()->subDays($odstep));
            })
            ->with('profile')
            ->orderByRaw('weekly_digest_sent_at ASC NULLS FIRST')
            // Drugi klucz, żeby kolejność była POWTARZALNA. Bez niego dwie
            // osoby z tym samym znacznikiem (a po pierwszym przebiegu mają go
            // setki: `NULL`) wracają w kolejności, którą Postgres wybiera
            // sam — i ta sama osoba mogłaby przez tygodnie lądować na końcu
            // każdej paczki, czyli nie dostawać nic.
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(max(0, $limit))
            ->get();
    }

    /**
     * Ile osób stoi w kolejce po dzisiejszej wysyłce.
     *
     * Liczba, nie lista — jedyny jej odbiorca to zdanie w dzienniku i na
     * ekranie komendy. `count()` zamiast `naDzis(PHP_INT_MAX)->count()`, bo
     * tamto ściągałoby całą kolejkę do pamięci PHP wyłącznie po to, żeby ją
     * policzyć.
     *
     * UWAGA PRZY CZYTANIU TEJ LICZBY: są w niej także osoby, dla których nie
     * ma dziś o czym pisać. One będą tu wisieć do końca tygodnia i to jest
     * w porządku — kolejka ma je sprawdzać codziennie, bo treść może się
     * pojawić jutro. Liczba mówi więc „tylu czeka w kolejce", a nie „tylu
     * nie zmieściło się w limicie".
     */
    public function ileCzeka(): int
    {
        return $this->kwalifikujacySie()
            ->where(function (Builder $q): void {
                $q->whereNull('weekly_digest_sent_at')
                    ->orWhere('weekly_digest_sent_at', '<=', now()->subDays((int) config('kuking.digest.odstep_dni')));
            })
            ->count();
    }

    /**
     * Zapisuje, że list do tej osoby POSZEDŁ DO WYSYŁKI.
     *
     * ZNACZNIK STAWIAMY PRZY WŁOŻENIU DO KOLEJKI, NIE PO DORĘCZENIU — i to
     * jest świadomy wybór strony, po której wolno się pomylić.
     *
     * Kuking nie wie, czy list doszedł: dostawca nie odsyła potwierdzeń
     * doręczenia i nikt tego jeszcze nie obsługuje (`docs/decyzje/POCZTA.md`
     * §5 pkt 6). Gdyby znacznik czekał na sukces wysyłki, każde uruchomienie
     * zadania przed opróżnieniem kolejki wkładałoby te same listy DRUGI RAZ —
     * czyli obietnica „nigdy więcej niż jeden w tygodniu" padłaby dokładnie
     * w dniu, w którym kolejka się zatka. Cena tego wyboru: list, który
     * przepadł w kolejce, nie zostanie ponowiony i ta osoba czeka tydzień.
     * Lepiej, żeby ktoś dostał o jeden list za mało, niż żeby dostał trzy.
     *
     * `update()` na zapytaniu, nie `save()` na modelu: `weekly_digest_sent_at`
     * jest poza `$fillable` (AGENTS.md §7 — znacznik stanu konta, nie
     * preferencja), a jedno zapytanie na całą paczkę zamiast stu.
     *
     * @param  Collection<int, User>|list<User>  $osoby
     */
    public function oznaczWyslane(iterable $osoby): void
    {
        $identyfikatory = collect($osoby)
            ->map(fn (User $u): string => (string) $u->getKey())
            ->values()
            ->all();

        if ($identyfikatory === []) {
            return;
        }

        User::query()->whereIn('id', $identyfikatory)->update([
            'weekly_digest_sent_at' => now(),
        ]);
    }
}
