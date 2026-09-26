<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
 * ostrzeżeniem w logu, a nocne `uzupelnij()` dopisuje brakujące wpisy dla
 * kont wymazanych w oknie kopii — bez osobnej kolejki i bez kolumny w bazie.
 */
final class DziennikWymazan
{
    public const PREFIKS = 'dziennik-wymazan/';

    public function dysk(): Filesystem
    {
        return Storage::disk((string) config('kuking.dziennik_wymazan.dysk'));
    }

    public function zapisz(string $userId, string $zakres, CarbonInterface $kiedy): bool
    {
        try {
            $this->dysk()->put(self::PREFIKS.$userId.'.json', (string) json_encode([
                'user_id' => $userId,
                'wymazano_at' => $kiedy->toIso8601ZuluString(),
                'zakres' => $zakres,
            ]));

            return true;
        } catch (Throwable $e) {
            Log::warning('Dziennik wymazań: nie udało się zapisać wpisu; dopisze go nocne uzupełnienie.', [
                'user_id' => $userId,
                'wyjatek' => $e::class,
            ]);

            return false;
        }
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
