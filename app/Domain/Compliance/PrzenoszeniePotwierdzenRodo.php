<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use App\Models\AuditLogEntry;
use App\Models\PotwierdzenieZadaniaRodo;
use App\Models\User;
use Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Przeniesienie starych wpisów `audit_log` `account.*` do
 * `potwierdzenia_zadan_rodo` (krok 5 `docs/decyzje/PROJEKT_POTWIERDZENIA_RODO.md`,
 * #2708, decyzja właściciela z 2.10.2026 przy D-233).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO ROBI
 * ────────────────────────────────────────────────────────────────────────
 *
 * Od 21.09.2026 nowe żądania zapisują potwierdzenie same
 * (`RejestrPotwierdzenRodo`). Zostają sprawy ZAMKNIĘTE wcześniej, które mają
 * wyłącznie wpisy w `audit_log`. Dla każdego zamknięcia —
 * `account.data_erased` (wynik `wykonane`, zakres z metadanych) albo
 * `account.delete_cancelled` (wynik `cofniete`) — powstaje jedno potwierdzenie,
 * o ile konta nie ma jeszcze w rejestrze w tej liczbie. `otrzymano` to data
 * najstarszego niezużytego `account.delete_requested` tego konta sprzed
 * zamknięcia (a gdy go nie ma — data zamknięcia).
 *
 * Sprawy OTWARTE (żądanie bez zamknięcia) nie są przenoszone: ma je albo
 * wiersz `w_toku` z `RejestrPotwierdzenRodo`, albo zamknie je
 * `EraseAccountData` (gałąź „sprawy w toku nie ma”, `domknij()`).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  IDEMPOTENCJA I POKRYCIE
 * ────────────────────────────────────────────────────────────────────────
 *
 * Porównujemy LICZBY na parze (konto, wynik): zamknięć w audycie i
 * potwierdzeń w rejestrze. Powstaje tylko różnica — drugi przebieg nic nie
 * dubluje, a potwierdzenia zapisane przez nowy kod nie są dublowane. Najpierw
 * odejmujemy wiersze z tą samą datą zamknięcia, żeby nowe potwierdzenie nie
 * „zjadło” miejsca starszemu przypadkowi o innej dacie. Do bramki
 * (`ileBrakuje()`) bierzemy same liczby, nie daty: wpisy audytu starzeją się
 * szybciej niż potwierdzenia (12 miesięcy wobec 36) i dopasowanie po dacie
 * rozjechałoby się, gdy `delete_requested` już zniknął, a `data_erased` jeszcze nie.
 *
 * Ta sama liczba (`ileBrakuje()`) jest bramką dla retencji audytu: dopóki
 * choć jednego zamknięcia nie ma w rejestrze, `PrzedawnioneWpisyAudytu` nie
 * kasuje wpisów `account.*`.
 *
 * Czego NIE przenosimy: adresu e-mail, IP, metadanych poza zakresem. Numer
 * sprawy jest losowany teraz — wnioskodawcy historyczni go nie mają
 * (PROJEKT §5 pkt 5, świadomie przyjęte).
 */
final class PrzenoszeniePotwierdzenRodo
{
    private const ZAMKNIECIA = [
        'account.data_erased' => PotwierdzenieZadaniaRodo::WYNIK_WYKONANE,
        'account.delete_cancelled' => PotwierdzenieZadaniaRodo::WYNIK_COFNIETE,
    ];

    private const ZADANIE = 'account.delete_requested';

    public function __construct(
        private readonly RejestrPotwierdzenRodo $rejestr = new RejestrPotwierdzenRodo,
    ) {}

    /**
     * Ile zamknięć z audytu nie ma pokrycia w rejestrze (suma różnic po parach
     * konto + wynik). 0 oznacza, że potwierdzenia przejęły rolę dowodu.
     */
    public function ileBrakuje(): int
    {
        $audyt = [];
        $zdarzenia = AuditLogEntry::query()
            ->whereIn('action', array_keys(self::ZAMKNIECIA))
            ->whereNotNull('subject_id')
            ->toBase()
            ->selectRaw('subject_id, action, count(*) as ile')
            ->groupBy('subject_id', 'action')
            ->get();

        foreach ($zdarzenia as $w) {
            $audyt[$w->subject_id.'|'.self::ZAMKNIECIA[$w->action]] = (int) $w->ile;
        }

        $istniejace = $this->istniejace();
        $brakuje = 0;
        foreach ($audyt as $klucz => $ile) {
            $brakuje += max(0, $ile - count($istniejace[$klucz] ?? []));
        }

        return $brakuje;
    }

    /**
     * @return array{zamkniecia_w_audycie: int, potwierdzenia_w_rejestrze: int, do_utworzenia: int, utworzono: int, wykonane: int, cofniete: int, otwarte_pominiete: int, zakres_domyslny: int, brak_konta: int}
     */
    public function przenies(bool $naSucho): array
    {
        $wynik = [
            'zamkniecia_w_audycie' => 0,
            'potwierdzenia_w_rejestrze' => 0,
            'do_utworzenia' => 0,
            'utworzono' => 0,
            'wykonane' => 0,
            'cofniete' => 0,
            'otwarte_pominiete' => 0,
            'zakres_domyslny' => 0,
            'brak_konta' => 0,
        ];

        $kontaIstniejace = User::query()->pluck('id')->flip();
        $istniejace = $this->istniejace();
        $przypadki = [];

        foreach ($this->zdarzeniaPoKoncie() as $kontoId => $zdarzenia) {
            $otwarte = [];
            foreach ($zdarzenia as $z) {
                if ($z->action === self::ZADANIE) {
                    $otwarte[] = $z;

                    continue;
                }

                $wynik['zamkniecia_w_audycie']++;
                $wynikSprawy = self::ZAMKNIECIA[$z->action];
                $zadanie = array_shift($otwarte);

                if (! isset($kontaIstniejace[$kontoId])) {
                    $wynik['brak_konta']++;

                    continue;
                }

                $zakres = null;
                if ($wynikSprawy === PotwierdzenieZadaniaRodo::WYNIK_WYKONANE) {
                    $zakres = $this->zakres($z) ?? ($zadanie !== null ? $this->zakres($zadanie) : null);
                    if ($zakres === null) {
                        $zakres = User::DELETE_SCOPE_MINIMUM;
                        $wynik['zakres_domyslny']++;
                    }
                }

                $zakonczono = Carbon::parse($z->created_at)->toDateString();
                $otrzymano = $zadanie !== null ? min(Carbon::parse($zadanie->created_at)->toDateString(), $zakonczono) : $zakonczono;

                $przypadki[$kontoId.'|'.$wynikSprawy][] = [
                    'konto_id' => $kontoId,
                    'wynik' => $wynikSprawy,
                    'zakres' => $zakres,
                    'otrzymano' => $otrzymano,
                    'zakonczono' => $zakonczono,
                ];
            }

            $wynik['otwarte_pominiete'] += count($otwarte);
        }

        $wynik['potwierdzenia_w_rejestrze'] = array_sum(array_map('count', $istniejace));

        $doUtworzenia = [];
        foreach ($przypadki as $klucz => $lista) {
            $maJuz = $istniejace[$klucz] ?? [];
            $niedopasowane = [];
            foreach ($lista as $przypadek) {
                $i = array_search($przypadek['zakonczono'], $maJuz, true);
                if ($i === false) {
                    $niedopasowane[] = $przypadek;
                } else {
                    unset($maJuz[$i]);
                }
            }
            $brak = max(0, count($niedopasowane) - count($maJuz));
            foreach (array_slice($niedopasowane, 0, $brak) as $przypadek) {
                $doUtworzenia[] = $przypadek;
            }
        }

        $wynik['do_utworzenia'] = count($doUtworzenia);

        if ($naSucho || $doUtworzenia === []) {
            return $wynik;
        }

        DB::transaction(function () use ($doUtworzenia, &$wynik): void {
            foreach ($doUtworzenia as $p) {
                $this->rejestr->dopiszZAudytu([
                    'rodzaj' => SlownikPotwierdzenRodo::RODZAJE[0],
                    'wynik' => $p['wynik'],
                    'zakres' => $p['zakres'],
                    'otrzymano' => Carbon::parse($p['otrzymano']),
                    'zakonczono' => Carbon::parse($p['zakonczono']),
                    'wersja_procedury' => SlownikPotwierdzenRodo::WERSJA_PROCEDURY_DZIS,
                    'wyjatki' => null,
                    'konto_id' => $p['konto_id'],
                ]);
                $wynik['utworzono']++;
                $wynik[$p['wynik'] === PotwierdzenieZadaniaRodo::WYNIK_WYKONANE ? 'wykonane' : 'cofniete']++;
            }
        });

        return $wynik;
    }

    /**
     * @return array<string, list<string>> klucz „konto|wynik” => daty zakończenia istniejących potwierdzeń
     */
    private function istniejace(): array
    {
        $wynik = [];
        $wiersze = PotwierdzenieZadaniaRodo::query()
            ->whereIn('wynik', array_values(self::ZAMKNIECIA))
            ->whereNotNull('konto_id')
            ->get(['konto_id', 'wynik', 'zakonczono']);

        foreach ($wiersze as $w) {
            $wynik[$w->konto_id.'|'.$w->wynik][] = Carbon::parse($w->zakonczono)->toDateString();
        }

        return $wynik;
    }

    /**
     * @return Generator<string, list<AuditLogEntry>>
     */
    private function zdarzeniaPoKoncie(): Generator
    {
        $akcje = [self::ZADANIE, ...array_keys(self::ZAMKNIECIA)];
        $konta = AuditLogEntry::query()
            ->whereIn('action', $akcje)
            ->whereNotNull('subject_id')
            ->distinct()
            ->orderBy('subject_id')
            ->pluck('subject_id');

        foreach ($konta as $kontoId) {
            yield (string) $kontoId => AuditLogEntry::query()
                ->where('subject_id', $kontoId)
                ->whereIn('action', $akcje)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->all();
        }
    }

    private function zakres(AuditLogEntry $wpis): ?string
    {
        $zakres = $wpis->metadata['zakres'] ?? null;

        return in_array($zakres, SlownikPotwierdzenRodo::zakresy(), true) ? $zakres : null;
    }
}
