<?php

declare(strict_types=1);

namespace App\Domain\Polaczenia;

use App\Domain\Monitoring\EpizodAlarmu;

/**
 * „Pula połączeń PostgreSQL się zapełnia" na webhook właściciela (issue #598).
 *
 * TEN SAM KANAŁ, CO BŁĘDY 500 I CZUJKA KOPII (`blad_webhook`, D-041).
 * Właściciel ma JEDNO miejsce, w które patrzy; drugie znaczyłoby drugie
 * miejsce do niepatrzenia. Wzorzec jest przepisany z `App\Domain\Kopie\AlarmKopii`
 * celowo — nie budujemy drugiego mechanizmu alarmowania.
 *
 * MASZYNA EPIZODU JEST WSPÓLNA (#972)
 * Czujka chodzi co godzinę, a stan „za dużo połączeń" trwa godzinami.
 * Ograniczenie powtórzeń, cisza tylko za dzwonek PRZYJĘTY przez kanał,
 * krótkie ponowienie po porażce, jedno odwołanie po powrocie do `spokojny`
 * i granice pamięci w cache — pełne uzasadnienie i jedyna implementacja
 * stoją w `App\Domain\Monitoring\EpizodAlarmu` (transport:
 * `KanalAlarmowy`). Ta klasa dostarcza wyłącznie klucz pamięci, listę
 * stanów, długość ciszy (`kuking.polaczenia.cisza_godzin`) i treść.
 *
 * `zadzwonJesliTrzeba()` woła wyłącznie `BudzetPolaczen` i `SprawdzKolejke`
 * — w obu po dwa razy, ale na ROZŁĄCZNYCH gałęziach, więc w jednym
 * przebiegu wykonuje się jedno. `HealthController` ma własny, niezależny
 * mechanizm (`powiadomWebhook()` z własnym odstępem).
 *
 * CO STĄD WYCHODZI — LISTA ZAMKNIĘTA
 * stan, liczba zajętych backendów, liczba dostępnych miejsc, próg i zdanie
 * mówiące, CO ZROBIĆ. Bez nazw baz, użytkowników, hostów i treści zapytań.
 */
final class AlarmPolaczen
{
    private const KLUCZ = 'kuking:polaczenia:ostatni-alarm';

    /** Stany, które są ALARMEM. `spokojny` i `nieobslugiwany` nie dzwonią. */
    private const ALARMUJACE = [
        StanPolaczenBazy::OSTRZEZENIE,
        StanPolaczenBazy::KRYTYCZNY,
        StanPolaczenBazy::NIEDOSTEPNY,
    ];

    public function __construct(private readonly EpizodAlarmu $epizod) {}

    /**
     * @param  array<string, mixed>  $wynik
     * @return bool czy kanał PRZYJĄŁ wiadomość (odpowiedź 2xx). To NIE jest
     *              to samo, co „ktoś ją zobaczył" — patrz `KanalAlarmowy`.
     */
    public function zadzwonJesliTrzeba(array $wynik): bool
    {
        return $this->epizod->zadzwonJesliTrzeba(
            klucz: self::KLUCZ,
            stan: (string) ($wynik['stan'] ?? ''),
            spokojny: StanPolaczenBazy::SPOKOJNY,
            alarmujace: self::ALARMUJACE,
            ciszaGodzin: (int) config('kuking.polaczenia.cisza_godzin'),
            trescAlarmu: fn (): string => $this->tresc($wynik),
            trescOdwolania: fn (string $poprzedni): string => sprintf('połączenia PostgreSQL wróciły do normy (poprzedni stan: %s).', $poprzedni),
        );
    }

    /** @param array<string, mixed> $wynik */
    public function tresc(array $wynik): string
    {
        $stan = (string) ($wynik['stan'] ?? '');

        // Teksty są STAŁYMI z tego pliku, dobieranymi przez `match` po
        // wartości z listy stałych — nie składamy ich z niczego, co przyszło
        // z zewnątrz. Ta sama zasada, co w `AlarmKopii` i w handlerze webhooka.
        $co = match ($stan) {
            StanPolaczenBazy::KRYTYCZNY => sprintf(
                'zajętych backendów: %d z %d dostępnych miejsc (próg krytyczny %d).',
                (int) ($wynik['zajete_serwer'] ?? 0),
                (int) ($wynik['dostepne'] ?? 0),
                (int) ($wynik['prog_krytyczny'] ?? 0),
            ),
            StanPolaczenBazy::OSTRZEZENIE => sprintf(
                'zajętych backendów: %d, a policzony budżet szczytowy tej topologii to %d (próg ostrzegawczy %d).',
                (int) ($wynik['zajete_serwer'] ?? 0),
                (int) ($wynik['budzet_szczytowy'] ?? 0),
                (int) ($wynik['prog_ostrzegawczy'] ?? 0),
            ),
            StanPolaczenBazy::NIEDOSTEPNY => 'nie udało się odczytać stanu połączeń z serwera bazy.',
            default => 'nieznany stan połączeń.',
        };

        return implode(' ', [
            'połączenia PostgreSQL:',
            $co,
            'Co zrobić: NIE dokładaj replik ani workerów, zanim nie wiadomo, co je zajmuje.',
            'Najpierw `php artisan kuking:budzet-polaczen`, potem porównaj z budżetem w docs/DATABASE.md.',
        ]);
    }
}
