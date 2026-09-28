<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Dziennik wykonanych wymazań kont — POZA BAZĄ (audyt B5, znalezisko 3).
 *
 * PO CO
 * Odtworzenie bazy z kopii (zrzut offsite z 30 dni, PITR, Volume Backup)
 * przywraca konta, które po dacie kopii już wymazaliśmy — z prawdziwym
 * e-mailem, profilem i treściami. Ślad wymazania (`potwierdzenia_zadan_rodo`,
 * `audit_log`, `users.data_erased_at`) leży w tej samej bazie, więc cofa się
 * razem z nią. Po odtworzeniu nie byłoby skąd wiedzieć, że ktoś prosił
 * o usunięcie.
 *
 * Ten dziennik leży w magazynie obiektów, nie w PostgreSQL: jeden obiekt na
 * wymazane konto, `dziennik-wymazan/<user_id>.json`. Treść to wyłącznie
 * identyfikator konta, chwila wymazania i wykonany zakres (`minimum` albo
 * `everything`) — bez e-maila, nazwy, IP. Zakres jest potrzebny, bo ponowne
 * wymazanie ma zrobić to samo, co zrobiliśmy za pierwszym razem (D-022).
 *
 * GDZIE
 * `kuking.dziennik_wymazan.dysk` — domyślnie ten sam prywatny dysk co paczki
 * eksportu (`r2_eksporty` na produkcji), pod osobnym prefiksem. Nie w buckecie
 * kopii bazy: do tamtego aplikacja z założenia nie ma prawa zapisu (D-043).
 * `CleanUpDataExports` kasuje wyłącznie klucze zapisane w `data_exports`,
 * więc tego prefiksu nie dotyka.
 *
 * ZAPIS NIE MOŻE ZATRZYMAĆ WYMAZANIA
 * Wymazanie jest ważniejsze niż jego dziennik. Nieudany zapis kończy się
 * błędem w logu, a nocne `uzupelnij()` dopisuje brakujące wpisy dla
 * kont wymazanych w oknie kopii — bez osobnej kolejki i bez kolumny w bazie.
 *
 * OKNO, KTÓREGO NOC NIE ZAMYKA (issue #2038)
 * `uzupelnij()` szuka kont po `users.data_erased_at` w BIEŻĄCEJ bazie. Gdy
 * zapis padnie, a przed najbliższym udanym uzupełnieniem ktoś odtworzy
 * kopię sprzed wymazania, znacznika już nie ma i noc nie ma czego dopisać.
 * Dlatego:
 *  - `zapisz()` próbuje `PROBY` razy z odstępem — chwilowa czkawka
 *    magazynu nie otwiera okna wcale;
 *  - po ostatniej próbie linia logu (`error`) niesie KOMPLET wpisu
 *    (identyfikator, zakres, chwila). Log wychodzi na stderr, do dziennika
 *    Railwaya, czyli poza bazę — i przeżywa jej odtworzenie. Z tej linii
 *    `kuking:dziennik-wymazan --dopisz=… --zakres=… --kiedy=…` odtwarza
 *    wpis ręcznie (`docs/infra/KOPIE_I_ODTWORZENIE.md` §3.1).
 * Log nie jest magazynem z gwarancją retencji; pełne domknięcie okna czeka
 * na decyzję właściciela (opis wariantów w §3.1).
 */
final class DziennikWymazan
{
    public const PREFIKS = 'dziennik-wymazan/';

    public function dysk(): Filesystem
    {
        return Storage::disk((string) config('kuking.dziennik_wymazan.dysk'));
    }

    /** Ile razy próbujemy zapisać wpis, zanim zostanie tylko linia logu. */
    public const PROBY = 3;

    /** Odstępy między próbami, w sekundach (wołają to wyłącznie komendy konsoli). */
    private const ODSTEPY_SEKUND = [1, 3];

    public function zapisz(string $userId, string $zakres, CarbonInterface $kiedy): bool
    {
        $wymazanoAt = $kiedy->toIso8601ZuluString();
        $tresc = (string) json_encode([
            'user_id' => $userId,
            'wymazano_at' => $wymazanoAt,
            'zakres' => $zakres,
        ]);
        $ostatni = null;

        for ($proba = 1; $proba <= self::PROBY; $proba++) {
            try {
                $this->dysk()->put(self::PREFIKS.$userId.'.json', $tresc);

                return true;
            } catch (Throwable $e) {
                $ostatni = $e;

                if ($proba < self::PROBY) {
                    Sleep::for(self::ODSTEPY_SEKUND[$proba - 1] ?? 3)->seconds();
                }
            }
        }

        // Komplet wpisu w kontekście: po odtworzeniu kopii sprzed wymazania
        // ta linia jest jedynym śladem poza bazą (issue #2038).
        Log::error('Dziennik wymazań: nie udało się zapisać wpisu. Dopisze go nocne uzupełnienie — ale jeśli przedtem odtworzysz kopię bazy, dopisz go ręcznie z tej linii (docs/infra/KOPIE_I_ODTWORZENIE.md §3.1).', [
            'user_id' => $userId,
            'zakres' => $zakres,
            'wymazano_at' => $wymazanoAt,
            'proby' => self::PROBY,
            'wyjatek' => $ostatni instanceof Throwable ? $ostatni::class : null,
        ]);

        return false;
    }

    /**
     * Wpisy od podanej chwili (włącznie), najstarsze pierwsze.
     *
     * @return list<array{user_id: string, wymazano_at: CarbonImmutable, zakres: string}>
     */
    public function wpisyOd(?CarbonInterface $od = null): array
    {
        $wpisy = [];

        foreach ($this->dysk()->files(rtrim(self::PREFIKS, '/')) as $klucz) {
            $dane = json_decode((string) $this->dysk()->get($klucz), true);

            if (! is_array($dane) || ! isset($dane['user_id'], $dane['wymazano_at'], $dane['zakres'])) {
                continue;
            }

            $kiedy = CarbonImmutable::parse($dane['wymazano_at']);

            if ($od !== null && $kiedy->lt($od)) {
                continue;
            }

            $wpisy[] = ['user_id' => (string) $dane['user_id'], 'wymazano_at' => $kiedy, 'zakres' => (string) $dane['zakres']];
        }

        usort($wpisy, fn ($a, $b) => $a['wymazano_at'] <=> $b['wymazano_at']);

        return $wpisy;
    }

    /**
     * Dopisuje wpisy kont wymazanych w ostatnich `$dni` dniach, których
     * w dzienniku brak (zapis przy wymazaniu padł albo wymazanie było
     * wcześniejsze niż ten dziennik).
     */
    public function uzupelnij(int $dni): int
    {
        $dopisane = 0;

        User::query()
            ->whereNotNull('data_erased_at')
            ->where('data_erased_at', '>=', now()->subDays($dni))
            ->orderBy('data_erased_at')
            ->each(function (User $konto) use (&$dopisane): void {
                if ($this->dysk()->exists(self::PREFIKS.$konto->getKey().'.json')) {
                    return;
                }

                $zakres = $konto->delete_scope ?? User::DELETE_SCOPE_MINIMUM;

                if ($this->zapisz((string) $konto->getKey(), $zakres, $konto->data_erased_at)) {
                    $dopisane++;
                }
            });

        return $dopisane;
    }

    /** Kasuje wpisy starsze niż `$dni` — wtedy nie ma już kopii, z której konto mogłoby wrócić. */
    public function przytnij(int $dni): int
    {
        $granica = now()->subDays($dni);
        $skasowane = 0;

        foreach ($this->wpisyOd() as $wpis) {
            if ($wpis['wymazano_at']->lt($granica)) {
                $this->dysk()->delete(self::PREFIKS.$wpis['user_id'].'.json');
                $skasowane++;
            }
        }

        return $skasowane;
    }
}
