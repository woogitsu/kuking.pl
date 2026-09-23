<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Odmiana;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;

/**
 * Przepisuje kolumny z castem `encrypted` z poprzedniego `APP_KEY` na bieżący
 * (issue #1043, procedura: `docs/infra/DEPLOYMENT_RUNBOOK.md`, „Rotacja
 * `APP_KEY`").
 *
 * PO CO TO ISTNIEJE
 * Po rotacji Laravel odczytuje stare szyfrogramy kluczami z
 * `APP_PREVIOUS_KEYS`, ale ich NIE przepisuje — sam odczyt przez model nie
 * robi atrybutu „brudnym", więc zwykły zapis modelu też nic nie zmieni.
 * Dopóki w bazie leży choć jeden szyfrogram starym kluczem, starego klucza
 * nie wolno usunąć z `APP_PREVIOUS_KEYS`: sekret 2FA tego konta stałby się
 * nieczytelny i osoba nie zalogowałaby się już nigdy (poza
 * `kuking:2fa-wylacz`).
 *
 * JAK DZIAŁA
 * Dla każdej wartości: jeśli daje się odczytać SAMYM bieżącym kluczem —
 * pomija (dlatego komenda jest idempotentna i można ją przerwać i puścić
 * ponownie). Jeśli daje się odczytać dopiero kluczem poprzednim — szyfruje
 * te same bajty jawne bieżącym kluczem. Na poziomie ciągu znaków, bez
 * interpretowania treści: cast `encrypted` i `encrypted:array` to oba
 * `encryptString()` (drugi z JSON-em w środku), więc wynik jest dokładnie
 * tym, co zapisałby sam model.
 *
 * Zapis jest warunkowy (`WHERE kolumna = stary szyfrogram`): jeśli ktoś
 * w tej samej chwili wyłączył albo włączył 2FA, jego nowa wartość jest już
 * zaszyfrowana bieżącym kluczem i nie zostaje nadpisana. Zapis idzie z
 * pominięciem `updated_at` i zdarzeń modelu — to nie jest zmiana konta.
 *
 * Wartość nieczytelna ŻADNYM kluczem nie jest ruszana; komenda kończy się
 * wtedy błędem, bo to znaczy, że któregoś klucza brakuje w
 * `APP_PREVIOUS_KEYS` — i to jest sygnał, żeby NIE usuwać niczego dalej.
 */
class PrzeszyfrujKlucz extends Command
{
    /**
     * Każda kolumna z castem `encrypted*` w modelach. Nowa taka kolumna
     * musi tu trafić, inaczej rotacja po cichu ją zgubi — pilnuje tego
     * `PrzeszyfrowanieKluczaTest`.
     */
    public const KOLUMNY = ['two_factor_secret', 'two_factor_backup_codes'];

    protected $signature = 'kuking:przeszyfruj-klucz
                            {--na-sucho : Policz, co trzeba przepisać, ale niczego nie zapisuj}
                            {--partia=500 : Ile kont wziąć naraz}';

    protected $description = 'Przepisuje zaszyfrowane kolumny (sekret 2FA, kody zapasowe) z poprzedniego APP_KEY na bieżący';

    public function handle(): int
    {
        $naSucho = (bool) $this->option('na-sucho');
        $partia = max(1, (int) $this->option('partia'));

        // Szyfrator znający WYŁĄCZNIE bieżący klucz — `Crypt` zna także
        // poprzednie, więc sam nie powie, którym kluczem coś zaszyfrowano.
        $tylkoBiezacy = new Encrypter(Crypt::getKey(), (string) config('app.cipher'));

        $aktualne = 0;
        $doPrzepisania = 0;
        $przepisane = 0;
        $nieczytelne = 0;

        User::query()
            ->select(['id', ...self::KOLUMNY])
            ->where(function ($zapytanie): void {
                foreach (self::KOLUMNY as $kolumna) {
                    $zapytanie->orWhereNotNull($kolumna);
                }
            })
            ->chunkById($partia, function (Collection $konta) use ($tylkoBiezacy, $naSucho, &$aktualne, &$doPrzepisania, &$przepisane, &$nieczytelne): void {
                foreach ($konta as $konto) {
                    foreach (self::KOLUMNY as $kolumna) {
                        $szyfrogram = $konto->getRawOriginal($kolumna);

                        if ($szyfrogram === null) {
                            continue;
                        }

                        try {
                            $tylkoBiezacy->decryptString($szyfrogram);
                            $aktualne++;

                            continue;
                        } catch (DecryptException) {
                            // Nie bieżącym kluczem — sprawdzamy poprzednie.
                        }

                        try {
                            $jawne = Crypt::decryptString($szyfrogram);
                        } catch (DecryptException) {
                            $nieczytelne++;
                            $this->warn("Konto {$konto->getKey()}: {$kolumna} nie daje się odczytać żadnym kluczem — zostawione bez zmian.");

                            continue;
                        }

                        $doPrzepisania++;

                        if ($naSucho) {
                            continue;
                        }

                        $przepisane += User::query()
                            ->whereKey($konto->getKey())
                            ->where($kolumna, $szyfrogram)
                            ->toBase()
                            ->update([$kolumna => Crypt::encryptString($jawne)]);
                    }
                }
            });

        $this->info($naSucho
            ? 'Do przepisania: '.self::ile($doPrzepisania).'. Nic nie zapisano.'
            : 'Przepisano '.self::ile($przepisane).' na bieżący klucz.');
        $this->line('Już zaszyfrowane bieżącym kluczem: '.self::ile($aktualne).'.');

        if ($nieczytelne > 0) {
            $this->error('Nieczytelne żadnym kluczem: '.self::ile($nieczytelne).'. Sprawdź APP_PREVIOUS_KEYS i NIE usuwaj z niej starego klucza.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private static function ile(int $ile): string
    {
        return $ile.' '.Odmiana::rzeczownik($ile, 'wartość', 'wartości', 'wartości');
    }
}
