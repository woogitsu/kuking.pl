<?php

declare(strict_types=1);

namespace App\Domain\Monitoring;

use DateInterval;
use Illuminate\Support\Facades\Log;

/**
 * „Wymazania kont stoją, bo dziennik wymazań nie przyjmuje wpisów" na webhook
 * właściciela (issue #2038, wariant A, etap 3).
 *
 * PO CO
 * Od etapu 2 wymazanie konta czeka na zapis wpisu w dzienniku poza bazą; gdy
 * magazyn nie odpowiada, anonimizacja się cofa, a egzekutor ponawia ją co noc.
 * To bezpieczne, ale ciche: obietnica „po 30 dniach usuwamy dane" spóźnia się
 * o czas awarii i nikt o tym nie wie. Jedna noc to czkawka magazynu, kilka
 * nocy z rzędu to awaria, przy której ktoś musi coś zrobić.
 *
 * LICZNIK NOCY
 * Jedna NOC = jeden dzień kalendarza z co najmniej jednym kontem, któremu
 * wymazanie cofnęło się z powodu dziennika (`DziennikWymazanNiedostepny`).
 * Drugi przebieg tego samego dnia (ręczne uruchomienie) nie nabija licznika.
 * Przebieg BEZ takiej porażki zeruje licznik — także przebieg z pustą kolejką,
 * bo wtedy nic nie czeka. Przebieg próbny (`--dry-run`) niczego nie zmienia.
 *
 * ALARM: TA SAMA MASZYNA CO KOLEJKA I POŁĄCZENIA (`EpizodAlarmu`, #599, #972)
 * Kanał `blad_webhook` (D-041), kontrakt „przyjęty = 2xx", cisza wyłącznie za
 * wiadomość przyjętą przez kanał, JEDNO odwołanie po powrocie do normy.
 * Próg to `kuking.dziennik_wymazan.alarm_po_nocach`, cisza —
 * `kuking.dziennik_wymazan.alarm_cisza_godzin`. Niezależnie od kanału
 * po przekroczeniu progu idzie `Log::error` (dziennik serwera).
 *
 * W WIADOMOŚCI SĄ WYŁĄCZNIE LICZBY I INSTRUKCJA. Żadnego identyfikatora
 * konta, e-maila ani komunikatu wyjątku: kanał wychodzi do usługi, nad którą
 * nie mamy kontroli (AGENTS.md §7).
 *
 * ZNANE OGRANICZENIE PAMIĘCI
 * Licznik i pamięć alarmu mieszkają w cache (`AlarmMemory`). Ręczne
 * wyczyszczenie cache zeruje licznik — alarm przyjdzie o kilka nocy później,
 * ale przyjdzie, bo porażki trwają. Sama zaległość jest w bazie i w logu
 * przebiegu (`kuking:usun-wygasle-konta`).
 */
final class AlarmDziennikaWymazan
{
    public const KLUCZ_LICZNIKA = 'kuking:wymazanie:noce-bez-dziennika';

    public const KLUCZ_ALARMU = 'kuking:wymazanie:dziennik-alarm';

    private const SPOKOJNY = 'spokojny';

    private const ALARM = 'alarm';

    public function __construct(
        private readonly EpizodAlarmu $epizod,
        private readonly AlarmMemory $pamiec,
    ) {}

    /**
     * Zapisuje wynik nocnego przebiegu i dzwoni, jeśli trzeba.
     *
     * @param  int  $nieudaneZPowoduDziennika  konta cofnięte przez dziennik w tym przebiegu
     * @param  int  $czekajace  konta po karencji, które nadal czekają na wymazanie
     * @return bool czy kanał PRZYJĄŁ wiadomość (alarm albo odwołanie)
     */
    public function zapiszPrzebieg(int $nieudaneZPowoduDziennika, int $czekajace): bool
    {
        $noce = $this->policzNoc($nieudaneZPowoduDziennika > 0);
        $prog = max(1, (int) config('kuking.dziennik_wymazan.alarm_po_nocach'));
        $alarm = $noce >= $prog;

        if ($alarm) {
            Log::error('Wymazywanie kont po karencji: dziennik wymazań nie przyjmuje wpisów, wymazania stoją.', [
                'noce_z_rzedu' => $noce,
                'prog_nocy' => $prog,
                'nieudane_dzis' => $nieudaneZPowoduDziennika,
                'czekajace' => $czekajace,
            ]);
        }

        return $this->epizod->zadzwonJesliTrzeba(
            klucz: self::KLUCZ_ALARMU,
            stan: $alarm ? self::ALARM : self::SPOKOJNY,
            spokojny: self::SPOKOJNY,
            alarmujace: [self::ALARM],
            ciszaGodzin: (int) config('kuking.dziennik_wymazan.alarm_cisza_godzin'),
            trescAlarmu: fn (): string => $this->tresc($noce, $czekajace),
            trescOdwolania: static fn (string $poprzedni): string => 'wymazywanie kont: dziennik wymazań znów przyjmuje wpisy — kolejka wymazań rusza.',
        );
    }

    /** Liczba kolejnych nocy z porażką dziennika po tym przebiegu. */
    private function policzNoc(bool $porazka): int
    {
        if (! $porazka) {
            $this->pamiec->forget(self::KLUCZ_LICZNIKA);

            return 0;
        }

        $dzis = now()->toDateString();
        $zapis = $this->pamiec->get(self::KLUCZ_LICZNIKA);
        $noce = is_array($zapis) ? (int) ($zapis['noce'] ?? 0) : 0;

        if (! is_array($zapis) || ($zapis['dzien'] ?? null) !== $dzis) {
            $noce++;
        }

        $this->pamiec->put(self::KLUCZ_LICZNIKA, ['noce' => $noce, 'dzien' => $dzis], new DateInterval('P30D'));

        return $noce;
    }

    public function tresc(int $noce, int $czekajace): string
    {
        return sprintf(
            'wymazywanie kont: przez %d noce z rzędu nie przeszło wymazanie konta, bo dziennik wymazań poza bazą '
            .'nie przyjmuje wpisów; czeka %d kont po karencji. Nic nie ginie — konta zostają nietknięte i egzekutor '
            .'ponawia je co noc, ale obietnica usunięcia danych po 30 dniach się spóźnia. Co zrobić: sprawdź dostęp '
            .'do magazynu dziennika (dysk `r2_eksporty`, prefiks `dziennik-wymazan/`) w dzienniku serwera po wpisach '
            .'„Dziennik wymazań”, napraw go i uruchom `php artisan kuking:usun-wygasle-konta`. '
            .'Nie odtwarzaj kopii bazy, zanim magazyn wróci (docs/infra/KOPIE_I_ODTWORZENIE.md §3.1).',
            $noce,
            $czekajace,
        );
    }
}
