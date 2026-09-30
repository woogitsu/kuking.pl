<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Domain\Monitoring\EpizodAlarmu;
use App\Notifications\PotwierdzenieZgloszeniaNielegalnejTresci;
use Illuminate\Support\Facades\Log;

/**
 * „Potwierdzenie przyjęcia zgłoszenia DSA stoi na suficie prób" na webhook
 * właściciela (#2218, sufit 3 prób zaakceptowany w D-333).
 *
 * PO CO
 * Po `PotwierdzenieZgloszeniaNielegalnejTresci::LIMIT_PORAZEK_LISTU`
 * ostatecznych porażkach listu dosyłka przestaje sama ponawiać list na adres
 * zgłaszającego spoza serwisu. To słuszne, ale od tej chwili nikt już nic
 * nie robi: sprawa bez potwierdzenia (DSA art. 16 ust. 4) czeka na człowieka,
 * który sprawdzi adres. Do tej poprawki mówił o tym tylko `warn()` w wyniku
 * komendy z kodem 0 — a `App\Support\Harmonogram` czyta wyjście komendy
 * wyłącznie przy kodzie różnym od zera, więc z harmonogramu nie zostawało
 * nic poza „DONE" (ten sam wzorzec co IN-05 w audycie z 30.09).
 *
 * DLACZEGO NIE KOD WYJŚCIA ≠ 0
 * Sufit to stan TRWAŁY — trwa, dopóki ktoś nie poprawi sprawy ręcznie.
 * Dosyłka chodzi co godzinę, a kod ≠ 0 zamienia się w `Harmonogram` na
 * wyjątek zgłaszany przy KAŻDYM przebiegu, z pominięciem ciszy — czyli
 * wiadomość co godzinę przez 30 dni pamięci licznika. Ten sam powód i ten
 * sam wybór co w `kuking:sprawdz-push` (#2053). Kod ≠ 0 zostaje dla
 * prawdziwej awarii przebiegu (wyjątek przy dosyłaniu).
 *
 * CO ROBI ZAMIAST TEGO
 *  - `Log::warning` przy każdym przebiegu ze sprawami na suficie — ślad
 *    w dzienniku serwera, jak w poprawce IN-05 (`SprawdzKopieBazy`).
 *    `warning`, nie `error`: `blad_webhook` przyjmuje dopiero `error`, więc
 *    ten wpis nie dzwoni co godzinę;
 *  - `EpizodAlarmu` na kanale alarmowym (D-041, #599, #972): jeden alarm na
 *    epizod, powtórka najwcześniej po `CISZA_GODZIN`, zmiana liczby spraw
 *    dzwoni od razu, JEDNO odwołanie, gdy na suficie nie zostanie żadna.
 *
 * W WIADOMOŚCI NA KANALE SĄ WYŁĄCZNIE LICZBY I INSTRUKCJA — bez numeru
 * sprawy i adresu: kanał wychodzi do usługi, nad którą nie mamy kontroli
 * (AGENTS.md §7). Numery spraw (nie adresy) są w dzienniku serwera.
 */
final class AlarmSufituPotwierdzen
{
    public const KLUCZ = 'kuking:dosylka-potwierdzen:sufit-alarm';

    /** Raz na dobę o trwającym stanie wystarczy — to sprawa dla człowieka, nie pożar. */
    public const CISZA_GODZIN = 24;

    private const SPOKOJNY = 'spokojny';

    public function __construct(private readonly EpizodAlarmu $epizod) {}

    /**
     * @param  list<string>  $numerySpraw  numery spraw na suficie (do dziennika serwera, NIE na kanał)
     * @return bool czy kanał PRZYJĄŁ wiadomość (alarm albo odwołanie)
     */
    public function zglos(array $numerySpraw): bool
    {
        $naSuficie = count($numerySpraw);

        if ($naSuficie > 0) {
            Log::warning('Potwierdzenia przyjęcia zgłoszeń DSA na suficie prób — dosyłka ich już nie ponawia.', [
                'stage' => 'dosylka_potwierdzen_sufit',
                'na_suficie' => $naSuficie,
                'numery_spraw' => array_slice($numerySpraw, 0, 20),
            ]);
        }

        return $this->epizod->zadzwonJesliTrzeba(
            klucz: self::KLUCZ,
            // Stan niesie liczbę spraw: nowa sprawa na suficie to nowa
            // informacja i ma dzwonić od razu, a nie po ciszy.
            stan: $naSuficie > 0 ? 'na_suficie:'.$naSuficie : self::SPOKOJNY,
            spokojny: self::SPOKOJNY,
            alarmujace: $naSuficie > 0 ? ['na_suficie:'.$naSuficie] : [],
            ciszaGodzin: self::CISZA_GODZIN,
            trescAlarmu: fn (): string => $this->tresc($naSuficie),
            trescOdwolania: static fn (string $poprzedni): string => 'potwierdzenia zgłoszeń DSA: żadna sprawa nie stoi już na suficie prób listu.',
        );
    }

    public function tresc(int $naSuficie): string
    {
        return sprintf(
            'potwierdzenia zgłoszeń DSA: %d zgłoszeń prawnych bez konta nie dostało potwierdzenia przyjęcia '
            .'(DSA art. 16 ust. 4), bo list na podany adres padł %d razy i dosyłka przestała go ponawiać. '
            .'Co zrobić: numery spraw są w dzienniku serwera przy wpisie „na suficie prób”; sprawdź adres '
            .'zgłaszającego w panelu moderacji i potwierdź przyjęcie inną drogą. Dosyłka sama spróbuje '
            .'ponownie dopiero po wygaśnięciu licznika porażek (30 dni).',
            $naSuficie,
            PotwierdzenieZgloszeniaNielegalnejTresci::LIMIT_PORAZEK_LISTU,
        );
    }
}
