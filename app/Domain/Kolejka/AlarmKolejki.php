<?php

declare(strict_types=1);

namespace App\Domain\Kolejka;

use App\Domain\Monitoring\EpizodAlarmu;

/**
 * „Kolejka stanęła" albo „coś padło w nocy" na webhook właściciela (#599).
 *
 * TEN SAM KANAŁ, CO BŁĘDY 500, CZUJKA KOPII I BUDŻET POŁĄCZEŃ
 * (`blad_webhook`, D-041) — jedno miejsce, w które właściciel patrzy.
 * Nie dokładamy drugiej platformy monitoringu; to jest wymóg #599.
 *
 * MASZYNA EPIZODU JEST WSPÓLNA (#972)
 * Ograniczenie powtórzeń, cisza tylko za dzwonek PRZYJĘTY przez kanał,
 * krótkie ponowienie po porażce, jedno odwołanie po powrocie do spokoju
 * i znane ograniczenie pamięci w cache — wszystko to żyje w jednym miejscu:
 * `App\Domain\Monitoring\EpizodAlarmu` (transport: `KanalAlarmowy`).
 * Ta klasa dostarcza wyłącznie klucz pamięci, listę stanów, długość ciszy
 * (`kuking.kolejka.cisza_godzin`) i treść wiadomości. SAMA OCENA stanu jest
 * bezstanowa (`StanKolejki` liczy z okna czasowego), więc restart nie
 * generuje fałszywej awarii — tylko ewentualne powtórzenie prawdziwej.
 *
 * CISZA NALEŻY SIĘ ZA DZWONEK, KTÓRY KANAŁ PRZYJĄŁ — NIE ZA SAMĄ PRÓBĘ
 * (poprawka do #599; usterkę zmierzył odbiór #676; pełne uzasadnienie
 * i historia usterki stoją teraz w `EpizodAlarmu`, bo tam mieszka algorytm).
 *
 * ZNANE OGRANICZENIE PAMIĘCI
 * Pamięć stanu mieszka w cache. Start kontenera już jej nie czyści (do
 * 25.09.2026 `docker/entrypoint.sh` wołał `cache:clear`, audyt B10-03), ale
 * wpis może zniknąć przy ręcznym czyszczeniu cache albo wygaśnięciu. Wtedy
 * trwający alarm zadzwoni raz dodatkowo, a niewysłane odwołanie przepadnie —
 * to jest świadomie zaakceptowane, bo SAMA OCENA stanu jest bezstanowa.
 */
final class AlarmKolejki
{
    private const KLUCZ = 'kuking:kolejka:ostatni-alarm';

    /** Stany, które są ALARMEM. `spokojna` nie dzwoni. */
    private const ALARMUJACE = [
        StanKolejki::ZALEGLOSC,
        StanKolejki::NOWE_NIEUDANE,
        StanKolejki::NIEDOSTEPNA,
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
            spokojny: StanKolejki::SPOKOJNA,
            alarmujace: self::ALARMUJACE,
            ciszaGodzin: (int) config('kuking.kolejka.cisza_godzin'),
            trescAlarmu: fn (): string => $this->tresc($wynik),
            trescOdwolania: fn (string $poprzedni): string => sprintf('kolejka wróciła do normy (poprzedni stan: %s).', $poprzedni),
        );
    }

    /** @param array<string, mixed> $wynik */
    public function tresc(array $wynik): string
    {
        $stan = (string) ($wynik['stan'] ?? '');

        // Teksty są STAŁYMI z tego pliku, dobieranymi przez `match` po
        // wartości z listy stałych. Nic, co przyszło z `payload` albo
        // `exception`, nie ma prawa się tu znaleźć.
        $co = match ($stan) {
            StanKolejki::ZALEGLOSC => sprintf(
                'najstarsze gotowe zadanie czeka %d s (próg %d s)%s, oczekujących %d, zawieszonych %d.%s '
                .'To wygląda na workera, który NIE PRACUJE — a worker, który nie chodzi, nie zgłasza żadnego błędu.',
                (int) ($wynik['zaleglosc_sekundy'] ?? 0),
                (int) ($wynik['prog_zaleglosci_sekundy'] ?? 0),
                is_string($wynik['najstarsza_kolejka'] ?? null) ? ' w kolejce `'.$wynik['najstarsza_kolejka'].'`' : '',
                (int) ($wynik['oczekujace'] ?? 0),
                (int) ($wynik['zawieszone'] ?? 0),
                $this->wedlugKolejek($wynik['kolejki'] ?? []),
            ),
            StanKolejki::NOWE_NIEUDANE => sprintf(
                'w ostatnich %d h padło %d zadań (w tabeli razem: %d).',
                (int) ($wynik['okno_godzin'] ?? 0),
                (int) ($wynik['nieudane_w_oknie'] ?? 0),
                (int) ($wynik['nieudane_razem'] ?? 0),
            ),
            StanKolejki::NIEDOSTEPNA => 'nie udało się odczytać stanu tabel kolejki.',
            default => 'nieznany stan kolejki.',
        };

        return implode(' ', [
            'kolejka:',
            $co,
            'Co zrobić: `php artisan kuking:sprawdz-kolejke`, potem `php artisan kuking:martwe-zadania`',
            '(niczego nie kasuje bez `--skasuj`). NIE ponawiaj zbiorczo starych zadań —',
            'żeton resetu hasła wygasa i ponowienie wysyła człowiekowi martwy link.',
        ]);
    }

    /**
     * „Według kolejek: default 3 (12 s), media 40 (900 s).” — nazwy
     * przeszły już filtr w `StanKolejki::wedlugKolejek()`.
     */
    private function wedlugKolejek(mixed $kolejki): string
    {
        if (! is_array($kolejki) || $kolejki === []) {
            return '';
        }

        $czesci = [];
        foreach ($kolejki as $nazwa => $liczby) {
            $czesci[] = sprintf(
                '%s %d (%d s)',
                (string) $nazwa,
                (int) ($liczby['oczekujace'] ?? 0),
                (int) ($liczby['zaleglosc_sekundy'] ?? 0),
            );
        }

        return ' Według kolejek: '.implode(', ', $czesci).'.';
    }
}
