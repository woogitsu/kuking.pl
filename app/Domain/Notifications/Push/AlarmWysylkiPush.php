<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Push;

use App\Domain\Monitoring\EpizodAlarmu;

/**
 * „Push utknął i nikt go nie rozliczy” na webhook właściciela (#2053).
 *
 * Ten sam kanał i ta sama maszyna epizodu co `AlarmKolejki` (#599, #972):
 * jeden alarm na epizod, powtórka najwcześniej po
 * `push_alarm_cisza_godzin`, jedno odwołanie, gdy grupy zostaną rozliczone.
 * Klasa dostarcza klucz pamięci, stany i treść.
 *
 * TREŚĆ TO WYŁĄCZNIE LICZBY I KODY Z ZAMKNIĘTEJ LISTY — żadnego UUID
 * powiadomienia, odbiorcy, adresu urządzenia, tytułu przepisu ani imienia
 * aktora. Szczegóły ogląda się zapytaniem z runbooka, nie w kanale.
 */
final class AlarmWysylkiPush
{
    private const KLUCZ = 'kuking:push:ostatni-alarm';

    private const ALARMUJACE = [
        StanWysylkiPush::NIEROZLICZONE,
        StanWysylkiPush::NIEDOSTEPNY,
    ];

    public function __construct(private readonly EpizodAlarmu $epizod) {}

    /** @param array<string, mixed> $wynik */
    public function zadzwonJesliTrzeba(array $wynik): bool
    {
        return $this->epizod->zadzwonJesliTrzeba(
            klucz: self::KLUCZ,
            stan: (string) ($wynik['stan'] ?? ''),
            spokojny: StanWysylkiPush::SPOKOJNY,
            alarmujace: self::ALARMUJACE,
            ciszaGodzin: (int) config('kuking.notifications.zewnetrzne.push_alarm_cisza_godzin', 24),
            trescAlarmu: fn (): string => $this->tresc($wynik),
            trescOdwolania: fn (string $poprzedni): string => sprintf(
                'Web Push: wszystkie grupy bez wysyłki są rozliczone (poprzedni stan: %s).',
                $poprzedni,
            ),
        );
    }

    /** @param array<string, mixed> $wynik */
    public function tresc(array $wynik): string
    {
        if (($wynik['stan'] ?? '') === StanWysylkiPush::NIEDOSTEPNY) {
            return 'Web Push: nie udało się odczytać stanu rezerwacji w `notifications`. '
                .'Co zrobić: `php artisan kuking:sprawdz-push --bez-alarmu`.';
        }

        $kody = array_values(array_filter(
            is_array($wynik['kody'] ?? null) ? $wynik['kody'] : [],
            static fn (mixed $kod): bool => in_array($kod, [KodZamknieciaPush::PorazkaTransportu->value, StanWysylkiPush::KOD_UTRACONE_PONOWIENIE], true),
        ));

        return sprintf(
            'Web Push: %d grup z trwałą porażką transportu (najstarsza %s), %d grup z utraconym ponowieniem '
            .'(najstarsza %s, próg %d min). Kody: %s. Powiadomienia w serwisie są na miejscu — nie dotarło tylko szturchnięcie. '
            .'Co zrobić: docs/infra/WEB_PUSH_TRWALE_PORAZKI_2053.md. NIE zeruj zbiorczo `push_proba_at` — '
            .'część urządzeń mogła już dostać ten push.',
            (int) ($wynik['trwale_porazki'] ?? 0),
            $this->wiek($wynik['najstarsza_porazka_sekundy'] ?? null),
            (int) ($wynik['utracone_ponowienia'] ?? 0),
            $this->wiek($wynik['najstarsze_utracone_sekundy'] ?? null),
            (int) ($wynik['prog_osierocenia_minut'] ?? 0),
            $kody === [] ? 'brak' : implode(', ', $kody),
        );
    }

    private function wiek(mixed $sekundy): string
    {
        if (! is_int($sekundy)) {
            return '—';
        }

        return $sekundy >= 7200
            ? sprintf('%d h', intdiv($sekundy, 3600))
            : sprintf('%d min', intdiv($sekundy, 60));
    }
}
