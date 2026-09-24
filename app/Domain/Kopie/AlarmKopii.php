<?php

declare(strict_types=1);

namespace App\Domain\Kopie;

use App\Domain\Monitoring\KanalAlarmowy;

/**
 * „Kopia bazy przestała powstawać" na webhook właściciela.
 *
 * TEN SAM KANAŁ, CO BŁĘDY 500 (`blad_webhook`, D-041), i to jest celowe:
 * właściciel ma JEDNO miejsce, w które patrzy. Drugi kanał znaczyłby drugie
 * miejsce do niepatrzenia.
 *
 * CO STĄD WYCHODZI — LISTA ZAMKNIĘTA
 *   stan z listy stałych `StanKopiiBazy`, wiek w godzinach, próg w godzinach
 *   i zdanie mówiące, CO ZROBIĆ.
 *
 * CZEGO NIE WYCHODZI NIGDY
 *   nazwy bucketu, klucza obiektu, poświadczenia, komunikatu wyjątku.
 *   Powód jest ten sam, co w `App\Logging\WebhookBleduHandler` po audycie
 *   A6-01: ten kanał wychodzi do Discorda albo Slacka, czyli do usługi, nad
 *   którą nie mamy żadnej kontroli, a komunikat biblioteki potrafi wnieść
 *   w siebie wartości, o których nikt nie pomyślał.
 *
 * Nazwa bucketu nie jest daną osobową, ale jest podpowiedzią dla kogoś, kto
 * przejął ten kanał — a nie kupuje nam ani jednej minuty przy diagnozie,
 * bo i tak stoi w zmiennych środowiskowych.
 *
 * DLACZEGO `error()`, SKORO TO NIE JEST WYJĄTEK
 * Bo kanał ma w `config/logging.php` poziom ustawiony na sztywno na `error`
 * i wpis niższej wagi zostałby po cichu odrzucony przez Monologa. Poziom
 * jest cechą KANAŁU, nie oceną zdarzenia (ta sama uwaga, co w
 * `App\Domain\Contact\DzwonekOperatora`).
 */
final class AlarmKopii
{
    /**
     * Stany, które są ALARMEM. `wylaczona` i `aktualna` nie dzwonią:
     * pierwsza to brak konfiguracji (bucket jeszcze nie istnieje), druga
     * to stan pożądany.
     */
    private const ALARMUJACE = [
        StanKopiiBazy::BRAK_KOPII,
        StanKopiiBazy::PRZESTARZALA,
        StanKopiiBazy::NIEDOSTEPNY,
    ];

    /**
     * Wspólny transport z `AlarmKolejki` i `AlarmPolaczen` (#972). Bez maszyny
     * epizodu: ta czujka chodzi raz na dobę i nie ma wyciszania ani odwołań.
     * Domyślny egzemplarz, bo testy #690 budują klasę przez `new`.
     */
    public function __construct(private readonly KanalAlarmowy $kanal = new KanalAlarmowy) {}

    /**
     * @param  array{stan: string, wiek_godzin: int|null, liczba: int, prog_godzin: int}  $wynik
     */
    public function zadzwonJesliTrzeba(array $wynik): bool
    {
        if (! in_array($wynik['stan'], self::ALARMUJACE, true)) {
            return false;
        }

        if (! $this->kanal->wlaczony()) {
            // Kanał wyłączony — tak jest dziś na produkcji. Ten sam warunek
            // stoi w `bootstrap/app.php` i w `DzwonekOperatora`.
            return false;
        }

        // Kontrakt 2xx i historia #690 („brak wyjątku to nie przyjęcie"):
        // `KanalAlarmowy::przyjal()`.
        return $this->kanal->przyjal($this->tresc($wynik));
    }

    /**
     * Metoda publiczna, bo to ONA jest przedmiotem testu: sprawdzenie
     * „czego tu nie ma" musi dać się zrobić bez stawiania kanału logowania.
     *
     * @param  array{stan: string, wiek_godzin: int|null, liczba: int, prog_godzin: int}  $wynik
     */
    public function tresc(array $wynik): string
    {
        $prog = (int) $wynik['prog_godzin'];

        // Teksty są STAŁYMI z tego pliku, dobieranymi przez `match` po
        // wartości z listy stałych — nie składamy ich z niczego, co przyszło
        // z zewnątrz. To jest ta sama zasada „lista dozwolonych, nie
        // filtrowanie zakazanych", co w handlerze webhooka.
        $co = match ($wynik['stan']) {
            StanKopiiBazy::BRAK_KOPII => 'W buckecie kopii nie ma ANI JEDNEGO zrzutu bazy.',
            StanKopiiBazy::PRZESTARZALA => sprintf(
                'Najnowsza kopia bazy ma %d h, a próg to %d h.',
                (int) $wynik['wiek_godzin'],
                $prog,
            ),
            StanKopiiBazy::NIEDOSTEPNY => 'Nie udało się odpytać bucketu z kopiami bazy.',
            default => 'Nieznany stan kopii bazy.',
        };

        // BEZ NAGŁÓWKA `[nazwa/środowisko]`. Dokleja go sam kanał
        // (`WebhookBleduHandler::tresc()`), więc wpisany tutaj drugi raz
        // dochodził do odbiornika jako „[Kuking/production] [Kuking/production]
        // kopia bazy: …". Zmierzone na prawdziwym odbiorniku webhooka 17.09.2026.
        return implode(' ', [
            'kopia bazy:',
            $co,
            'To znaczy, że serwis `kopia-bazy` prawdopodobnie przestał chodzić —',
            'sprawdź jego ostatnie uruchomienie w Railway (Deployments → Cron).',
            'Procedura: docs/infra/KOPIE_I_ODTWORZENIE.md sekcja 7.',
        ]);
    }
}
