<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Moderation\ZaleglePotwierdzeniaZgloszen;
use App\Models\AuditLogEntry;
use App\Models\Report;
use App\Models\User;
use App\Notifications\PotwierdzenieZgloszeniaNielegalnejTresci;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Ręczne oznaczenie zgłoszenia jako potwierdzonego INNĄ DROGĄ niż list
 * z serwisu (#2218, kryterium 3; DSA art. 16 ust. 4).
 *
 * PO CO. Po `PotwierdzenieZgloszeniaNielegalnejTresci::LIMIT_PORAZEK_LISTU`
 * porażkach dosyłka przestaje ponawiać list na adres spoza serwisu, a sprawa
 * zostaje zaległością bez końca — alarm (`AlarmSufituPotwierdzen`) mówi
 * człowiekowi, że ma coś zrobić, ale bez tej akcji nie ma jak zamknąć sprawy
 * po tym, jak potwierdzono ją telefonem, pocztą albo z innego adresu.
 *
 * CO ROBI. Ustawia `receipt_sent_at` (znacznik znaczy „potwierdziliśmy",
 * nie „zleciliśmy" — patrz nagłówek listu) i zapisuje w dzienniku audytu
 * KTO, KIEDY i JAKĄ DROGĄ. Wpis stoi w TEJ SAMEJ transakcji co znacznik
 * (D-249, klasa 1): nie ma znacznika bez śladu i śladu bez znacznika.
 *
 * ZAMEK. Warunkowy odczyt pod blokadą wiersza z tego samego zapytania, które
 * definiuje zaległość dla dosyłki (`ZaleglePotwierdzeniaZgloszen`) — sprawa,
 * której dosyłka nie uważa za zaległą (potwierdzona, po decyzji, bez
 * adresata), jest odrzucana z komunikatem, a równoległa dosyłka przegrywa
 * albo wygrywa na `UPDATE ... WHERE receipt_sent_at IS NULL`, nie na PHP.
 *
 * W dzienniku NIE MA adresu ani treści zgłoszenia: numer sprawy, droga i to,
 * czy sprawa stała na suficie (AGENTS.md §7). Droga to zamknięta lista —
 * wolny tekst wciągałby do dziennika dane osobowe zgłaszającego.
 */
final class PotwierdzZgloszenieInnaDroga
{
    public const AKCJA_AUDYTU = 'moderation.receipt_confirmed_manually';

    public const DROGA_TELEFON = 'telefon';

    public const DROGA_POCZTA_PAPIEROWA = 'poczta_papierowa';

    public const DROGA_INNY_EMAIL = 'inny_email';

    public const DROGA_OSOBISCIE = 'osobiscie';

    /** @var list<string> */
    public const DROGI = [
        self::DROGA_TELEFON,
        self::DROGA_POCZTA_PAPIEROWA,
        self::DROGA_INNY_EMAIL,
        self::DROGA_OSOBISCIE,
    ];

    public const KOMUNIKAT_BRAK_UPRAWNIEN = 'Tylko czynne konto moderatora albo administratora może oznaczyć sprawę jako potwierdzoną. Podaj login takiego konta w opcji --operator.';

    private const KOMUNIKAT_PUSTY_NUMER = 'Podaj numer sprawy, na przykład KU-ABCD-2345.';

    public static function komunikatNieznanejDrogi(string $droga): string
    {
        return 'Nieznana droga potwierdzenia „'.$droga.'”. Dozwolone: '.implode(', ', self::DROGI).'.';
    }

    /**
     * Wstępne, NIEBLOKUJĄCE sprawdzenie dla komendy: czy sprawa jest i czy
     * wciąż czeka na potwierdzenie — żeby operator nie dostał pytania, na które
     * akcja i tak odmówi. To tylko odczyt: rozstrzyga `handle()` pod blokadą.
     *
     * @throws DomainException komunikat po polsku, gotowy do pokazania operatorowi
     */
    public function sprawaDoPotwierdzenia(string $numerSprawy): Report
    {
        $numer = mb_strtoupper(trim($numerSprawy));

        if ($numer === '') {
            throw new DomainException(self::KOMUNIKAT_PUSTY_NUMER);
        }

        $sprawa = Report::query()->where('numer_sprawy', $numer)->first();

        if ($sprawa === null) {
            throw new DomainException($this->komunikatBrakuSprawy($numer));
        }

        if (! ZaleglePotwierdzeniaZgloszen::zapytanie()->whereKey($sprawa->getKey())->exists()) {
            throw new DomainException($this->dlaczegoNieZalegla($sprawa));
        }

        return $sprawa;
    }

    /** Czy sprawa bez konta zgłaszającego stoi na suficie prób listu. */
    public function naSuficie(Report $sprawa): bool
    {
        return $sprawa->reporter_id === null
            && PotwierdzenieZgloszeniaNielegalnejTresci::ponawianieWstrzymane((string) $sprawa->getKey());
    }

    private function komunikatBrakuSprawy(string $numer): string
    {
        return "Nie ma sprawy o numerze {$numer}. Sprawdź numer w panelu moderacji (wygląda tak: KU-ABCD-2345).";
    }

    /**
     * @throws DomainException komunikat po polsku, gotowy do pokazania operatorowi
     */
    public function handle(string $numerSprawy, User $operator, string $droga): Report
    {
        if (! $operator->isModerator()) {
            throw new DomainException(self::KOMUNIKAT_BRAK_UPRAWNIEN);
        }

        if (! in_array($droga, self::DROGI, true)) {
            throw new DomainException(self::komunikatNieznanejDrogi($droga));
        }

        $numer = mb_strtoupper(trim($numerSprawy));

        if ($numer === '') {
            throw new DomainException(self::KOMUNIKAT_PUSTY_NUMER);
        }

        $sprawa = DB::transaction(function () use ($numer, $operator, $droga): Report {
            $istniejaca = Report::query()->where('numer_sprawy', $numer)->first();

            if ($istniejaca === null) {
                throw new DomainException($this->komunikatBrakuSprawy($numer));
            }

            $zaleglaSprawa = ZaleglePotwierdzeniaZgloszen::zapytanie()
                ->whereKey($istniejaca->getKey())
                ->lockForUpdate()
                ->first();

            if ($zaleglaSprawa === null) {
                throw new DomainException($this->dlaczegoNieZalegla($istniejaca));
            }

            $naSuficie = $this->naSuficie($zaleglaSprawa);

            $zajete = Report::query()
                ->whereKey($zaleglaSprawa->getKey())
                ->whereNull('receipt_sent_at')
                ->update(['receipt_sent_at' => now()]);

            if ($zajete === 0) {
                throw new DomainException("Sprawa {$numer} została potwierdzona w tej samej chwili przez kogoś innego — nic nie zmieniono.");
            }

            AuditLogEntry::record(
                self::AKCJA_AUDYTU,
                $operator,
                $zaleglaSprawa,
                [
                    'numer_sprawy' => $numer,
                    'droga' => $droga,
                    'na_suficie' => $naSuficie,
                ],
            );

            return $zaleglaSprawa->refresh();
        });

        // Licznik porażek to stan roboczy dosyłki (cache) — po zamknięciu
        // sprawy nie ma czego liczyć. Poza transakcją: to nie dane sprawy.
        Cache::forget(PotwierdzenieZgloszeniaNielegalnejTresci::kluczPorazek((string) $sprawa->getKey()));

        return $sprawa;
    }

    private function dlaczegoNieZalegla(Report $sprawa): string
    {
        $numer = $sprawa->numer_sprawy;

        if ($sprawa->receipt_sent_at !== null) {
            return "Sprawa {$numer} ma już potwierdzenie przyjęcia (z ".$sprawa->receipt_sent_at->toDateTimeString().') — nic do oznaczenia.';
        }

        if ($sprawa->decision_sent_at !== null) {
            return "W sprawie {$numer} zgłaszający dostał już informację o rozstrzygnięciu, więc potwierdzenie przyjęcia straciło sens — nic do oznaczenia.";
        }

        return "Sprawa {$numer} nie ma adresata potwierdzenia (zgłoszenie bez konta i bez adresu e-mail albo konto usunięte), więc nie jest zaległa — nic do oznaczenia.";
    }
}
