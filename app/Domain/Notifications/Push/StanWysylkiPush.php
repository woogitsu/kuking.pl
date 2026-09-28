<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Push;

use App\Jobs\WyslijPowiadomieniePush;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Czy jakaś grupa Web Push utknęła bez wysyłki i bez rozliczenia (#2053).
 *
 * DLACZEGO CZUJKA KOLEJKI TEGO NIE WIDZI
 * Trwała porażka transportu kończy `WyslijPowiadomieniePush` SUKCESEM (push
 * jest szturchnięciem, zadanie nie ma czego ponawiać), a utracone ponowienie
 * (#1992: kolizja zamka unikalności) nie zostawia zadania wcale. W obu
 * przypadkach `jobs` i `failed_jobs` są czyste, `/health` też. Jedyny ślad
 * zostaje w `notifications` — i tu go liczymy.
 *
 * CZTERY STANY REZERWACJI (`push_proba_at IS NOT NULL AND push_wyslano_at IS NULL`):
 *
 *  - W TOKU: `push_zakonczono_at` puste, rezerwacja świeższa niż próg ALBO
 *    w `jobs` czeka (lub trwa) ponowienie niosące `push_grupa_id` TEJ
 *    grupy (`grupaId`, od #2021) albo — w dawnym formacie — ID TEGO
 *    powiadomienia (`notificationIds`). Samo zadanie tego odbiorcy NIE
 *    osłania: retry z #1992 ginie właśnie przez zamek świeżego zadania tego odbiorcy, a to
 *    przy limicie liczy sierotę jako zajęty slot, dostaje ODLOZ i wraca do
 *    `jobs` przez 48 h — dopasowanie po `user_id` zasłaniałoby sierotę
 *    na cały ten czas. Opóźniony retry
 *    i zaległa kolejka NIE są tutejszym alarmem — zaległość zgłasza
 *    `kuking:sprawdz-kolejke`;
 *  - UTRACONE PONOWIENIE: `push_zakonczono_at` puste, rezerwacja starsza niż
 *    `push_osierocenie_minut` i żadnego ponowienia z jej ID w `jobs`.
 *    Nikt już jej nie dokończy ani nie zamknie;
 *  - TRWAŁA PORAŻKA: `push_wynik = porazka_transportu` — wyczerpane próby;
 *  - ANULOWANE / ROZLICZONE: `anulowano`, `zamknieto_recznie` albo zamknięcie
 *    sprzed #2053 bez kodu. NIE alarmują.
 *
 * Porażka i utracone ponowienie alarmują, DOPÓKI człowiek ich nie rozliczy
 * (`docs/infra/WEB_PUSH_TRWALE_PORAZKI_2053.md`). Okna czasowego tu nie ma
 * celowo: grupa, której nikt nie obejrzał, nie przestaje być awarią dlatego,
 * że minęła doba. Powtórzeń pilnuje `EpizodAlarmu`.
 *
 * CZEGO TA KLASA NIE ROBI: nie ponawia, nie zeruje `push_proba_at`, nie
 * zamyka grup. Do wyniku wchodzą same liczby i wiek najstarszej grupy —
 * bez UUID, adresów urządzeń, treści i danych aktora. `payload` z `jobs`
 * jest tylko przeszukiwany w SQL, nigdy nie wraca do PHP.
 */
final class StanWysylkiPush
{
    public const SPOKOJNY = 'spokojny';

    /** Jest trwała porażka albo utracone ponowienie bez rozliczenia. */
    public const NIEROZLICZONE = 'nierozliczone';

    public const NIEDOSTEPNY = 'niedostepny';

    /** Bezpieczny kod przyczyny dla grup, których nikt nie dokończy. */
    public const KOD_UTRACONE_PONOWIENIE = 'utracone_ponowienie';

    /**
     * @return array{
     *     stan: string,
     *     trwale_porazki: int|null,
     *     najstarsza_porazka_sekundy: int|null,
     *     utracone_ponowienia: int|null,
     *     najstarsze_utracone_sekundy: int|null,
     *     prog_osierocenia_minut: int,
     *     kody: list<string>,
     *     kanal_wylaczony: bool
     * }
     */
    public function sprawdz(?Carbon $teraz = null): array
    {
        $teraz ??= Carbon::now();
        $prog = $this->progOsierocenia();

        $wynik = [
            'stan' => self::NIEDOSTEPNY,
            'trwale_porazki' => null,
            'najstarsza_porazka_sekundy' => null,
            'utracone_ponowienia' => null,
            'najstarsze_utracone_sekundy' => null,
            'prog_osierocenia_minut' => $prog,
            'kody' => [],
            'kanal_wylaczony' => ! (bool) config('kuking.notifications.zewnetrzne.wlaczone', true),
        ];

        try {
            // Grupa = jeden `push_grupa_id`; wiersze sprzed #1992 bez UUID
            // liczą się każdy osobno — tak samo jak w rachunku limitu.
            // Kod jako literał, nie parametr: tylko wtedy planista dopasuje
            // częściowy indeks `notifications_push_nierozliczone_idx`.
            $kodPorazki = KodZamknieciaPush::PorazkaTransportu->value;
            $porazki = DB::selectOne(<<<SQL
                SELECT count(DISTINCT COALESCE(push_grupa_id::text, id::text)) AS grupy,
                       min(push_zakonczono_at) AS najstarsza
                FROM notifications
                WHERE push_proba_at IS NOT NULL
                  AND push_wyslano_at IS NULL
                  AND push_wynik = '{$kodPorazki}'
                SQL);

            $tabelaZadan = (string) config('queue.connections.database.table', 'jobs');
            // AWARYJNY WYŁĄCZNIK (`wlaczone = false`) to świadoma decyzja, nie
            // awaria: zadanie wraca wtedy bez wysyłki i bez zamknięcia, więc
            // każda otwarta rezerwacja po 30 min wyglądałaby na utraconą.
            // Nie liczymy ich, póki kanał jest wyłączony; po włączeniu te,
            // których ponowienie przepadło, zgłoszą się jako utracone — bo
            // naprawdę są. Brak klucza prywatnego przy włączonym kanale NIE
            // jest tu wyjątkiem: to błąd konfiguracji i ma alarmować.
            $kanalWlaczony = (bool) config('kuking.notifications.zewnetrzne.wlaczone', true);
            $utracone = ! $kanalWlaczony ? (object) ['grupy' => 0, 'najstarsza' => null] : DB::selectOne(<<<SQL
                SELECT count(DISTINCT COALESCE(n.push_grupa_id::text, n.id::text)) AS grupy,
                       min(n.push_proba_at) AS najstarsza
                FROM notifications n
                WHERE n.push_proba_at IS NOT NULL
                  AND n.push_wyslano_at IS NULL
                  AND n.push_zakonczono_at IS NULL
                  AND n.push_proba_at < ?
                  AND NOT EXISTS (
                      SELECT 1 FROM {$tabelaZadan} j
                      WHERE strpos(j.payload, ?) > 0
                        AND (strpos(j.payload, n.id::text) > 0
                             OR (n.push_grupa_id IS NOT NULL
                                 AND strpos(j.payload, n.push_grupa_id::text) > 0))
                  )
                SQL, [$teraz->copy()->subMinutes($prog), class_basename(WyslijPowiadomieniePush::class)]);
        } catch (Throwable) {
            return $wynik;
        }

        $wynik['trwale_porazki'] = (int) $porazki->grupy;
        $wynik['najstarsza_porazka_sekundy'] = $this->wiek($porazki->najstarsza, $teraz);
        $wynik['utracone_ponowienia'] = (int) $utracone->grupy;
        $wynik['najstarsze_utracone_sekundy'] = $this->wiek($utracone->najstarsza, $teraz);

        if ($wynik['trwale_porazki'] > 0) {
            $wynik['kody'][] = KodZamknieciaPush::PorazkaTransportu->value;
        }
        if ($wynik['utracone_ponowienia'] > 0) {
            $wynik['kody'][] = self::KOD_UTRACONE_PONOWIENIE;
        }

        $wynik['stan'] = $wynik['kody'] === [] ? self::SPOKOJNY : self::NIEROZLICZONE;

        return $wynik;
    }

    /**
     * Ile minut bez zadania w kolejce wolno czekać rezerwacji. Musi być
     * dłuższe niż najdłuższa zdrowa przerwa: kilka prób po `$timeout`
     * i `push_ponowienie_sekund` każda, plus zwolnienie porzuconego zadania
     * po `retry_after` (960 s). Stąd dolna granica 10 minut.
     */
    private function progOsierocenia(): int
    {
        return max(10, (int) config('kuking.notifications.zewnetrzne.push_osierocenie_minut', 30));
    }

    private function wiek(mixed $od, Carbon $teraz): ?int
    {
        if ($od === null) {
            return null;
        }

        return max(0, (int) Carbon::parse((string) $od)->diffInSeconds($teraz));
    }
}
