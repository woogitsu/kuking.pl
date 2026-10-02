<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Udostepnienia;

use App\Domain\Social\ZamekPary;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\RecipeShare;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Udostępnienie jednego własnego przepisu wskazanej osobie (#2650).
 *
 * WZÓR: zaproszenie do wspólnego zeszytu po nazwie konta
 * (`ZaprosDoZeszytu::poNazwie()`), z trzema różnicami — nie ma linku, nie ma
 * oczekiwania na odpowiedź i nie ma powiadomienia:
 *  - BEZ LINKU, bo issue wyklucza „publiczny link okaziciela";
 *  - BEZ ODPOWIEDZI, bo to sam odczyt, nie członkostwo — odbiorca może
 *    z dostępu zrezygnować w każdej chwili („Udostępnione mi");
 *  - BEZ POWIADOMIENIA (decyzja „do potwierdzenia" w D-333): powiadomienie
 *    niesie tytuł prywatnego przepisu do Web Push i listu, a pętla
 *    udostępnij → odbierz budziłaby komuś telefon. Autor mówi bliskiej
 *    osobie sam; przepis czeka na nią w „Moje".
 *
 * ODBIORCA PO NAZWIE KONTA, NIE PO E-MAILU. Nazwa konta jest publiczna
 * (stoi na profilu), więc odpowiedź „taka osoba jest" nie zdradza niczego,
 * czego nie widać bez logowania — e-mail zdradzałby, kto ma konto.
 * Blokada w którąkolwiek stronę, konto zamknięte, zawieszone
 * i nieistniejąca nazwa dają JEDNO zdanie (`NIE_DA_SIE`): nie zdradzamy, że
 * ktoś autora zablokował ani że konto jest ukarane.
 *
 * KONTROLA POD ZAMKIEM PARY. Ten sam `ZamekPary` co `BlockUser`: blokada,
 * która zdąży pierwsza, odmawia udostępnienia; ta, która przyjdzie po nim,
 * kasuje je (`ZerwijUdostepnieniaPrzepisow::miedzy()`). Kolejność blokad:
 * konta, potem przepis (D-079 §1) — wiersz `recipes` bierze tylko klucz
 * obcy wstawianego wiersza, a limit osób pilnuje blokada doradcza na
 * przepis, nie `FOR UPDATE` na `recipes`.
 *
 * IDEMPOTENTNIE. Drugie udostępnienie tej samej osobie oddaje istniejący
 * wiersz (`ON CONFLICT DO NOTHING` na unikalnym indeksie także przy wyścigu).
 */
final class UdostepnijPrzepis
{
    public const NIE_DA_SIE = 'Nie możemy udostępnić przepisu tej osobie. Sprawdź nazwę konta — jest na stronie profilu, po znaku @.';

    public const PELNY = 'Ten przepis jest już udostępniony tylu osobom, ilu się da. Odbierz komuś dostęp i spróbuj jeszcze raz.';

    public const SAM_SOBIE = 'To jest Twój przepis — zawsze go widzisz. Wpisz nazwę konta osoby, której chcesz go pokazać.';

    private const PRZESTRZEN_BLOKAD = 2650;

    /**
     * Kogo wskazuje nazwa — do ekranu potwierdzenia, PRZED zapisem.
     * Te same odmowy co przy zapisie, bez blokad wierszy (zapis i tak
     * sprawdza wszystko od nowa pod zamkiem).
     */
    public function odbiorca(User $autor, Recipe $przepis, string $nazwa): User
    {
        Gate::forUser($autor)->authorize('share', $przepis);

        $odbiorca = Profile::poNazwie(ltrim(trim($nazwa), '@'))?->user;

        if ($odbiorca !== null && $odbiorca->getKey() === $autor->getKey()) {
            throw new BladDlaCzlowieka(self::SAM_SOBIE);
        }

        if (! $this->moznaPokazac($autor, $odbiorca)) {
            throw new BladDlaCzlowieka(self::NIE_DA_SIE);
        }

        /** @var User $odbiorca */
        return $odbiorca;
    }

    /**
     * @return array{0: RecipeShare, 1: bool} udostępnienie i to, czy powstało teraz
     */
    public function poNazwie(User $autor, Recipe $przepis, string $nazwa): array
    {
        $odbiorcaPrzedZamkiem = $this->odbiorca($autor, $przepis, $nazwa);

        return ZamekPary::zablokuj($autor, $odbiorcaPrzedZamkiem, function (?User $swiezyAutor, ?User $odbiorca) use ($przepis): array {
            // Konto mogło zniknąć, zmienić stan albo założyć blokadę między
            // odczytem a zamkiem. Jedno zdanie także tutaj.
            if ($swiezyAutor === null || ! $this->moznaPokazac($swiezyAutor, $odbiorca)) {
                throw new BladDlaCzlowieka(self::NIE_DA_SIE);
            }

            /** @var User $odbiorca */
            DB::selectOne('SELECT pg_advisory_xact_lock(?, hashtext(?))', [self::PRZESTRZEN_BLOKAD, (string) $przepis->getKey()]);

            // Świeży przepis: autor mógł go w innym oknie usunąć albo
            // przełączyć na „Wszyscy", moderacja — ukryć.
            $swiezy = Recipe::query()->whereKey($przepis->getKey())->first();

            if ($swiezy === null || ! Gate::forUser($swiezyAutor)->allows('share', $swiezy)) {
                throw new BladDlaCzlowieka('Tego przepisu nie da się teraz udostępnić — mógł zostać usunięty, ukryty albo pokazany wszystkim w innym oknie. Odśwież stronę.');
            }

            $istniejace = $swiezy->shares()->where('recipient_id', $odbiorca->getKey())->first();

            if ($istniejace !== null) {
                return [$istniejace, false];
            }

            if ($swiezy->shares()->count() >= (int) config('kuking.udostepnienia.max_osob')) {
                throw new BladDlaCzlowieka(self::PELNY);
            }

            $wstawione = DB::table('recipe_shares')->insertOrIgnore([
                'recipe_id' => $swiezy->getKey(),
                'recipient_id' => $odbiorca->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $wiersz = $swiezy->shares()->where('recipient_id', $odbiorca->getKey())->firstOrFail();

            return [$wiersz, $wstawione > 0];
        });
    }

    /**
     * Czy tej osobie wolno w ogóle coś pokazać: konto istnieje, jest
     * AKTYWNE (zawieszone i zamknięte — nie) i nie ma blokady w żadną stronę.
     */
    private function moznaPokazac(User $autor, ?User $odbiorca): bool
    {
        return $odbiorca !== null
            && $odbiorca->getKey() !== $autor->getKey()
            && $odbiorca->isActive()
            && ! $autor->hasBlockRelationWith($odbiorca);
    }
}
