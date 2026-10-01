<?php

declare(strict_types=1);

namespace App\Domain\Users;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Dawne nazwy profilu: `/@stara-nazwa` → 301 na aktualny profil
 * (decyzja właściciela z 1.10.2026, wiersz w D-333; tabela
 * `profile_username_redirects`, odpowiednik `recipe_slug_redirects`).
 *
 * ZASADY, KTÓRE TA KLASA TRZYMA W JEDNYM MIEJSCU
 *
 *  1. ŻYWY PROFIL MA PIERWSZEŃSTWO. Przekierowania szukamy dopiero wtedy,
 *     gdy pod nazwą nie ma żadnego profilu. Dodatkowo `zajmij()` kasuje
 *     cudzy wiersz w chwili, gdy ktoś bierze tę nazwę (zmiana nazwy
 *     i rejestracja) — inaczej po kolejnej zmianie nazwy przez nowego
 *     właściciela dawny adres „ożyłby" i wskazał poprzednią osobę.
 *  2. WIERSZ WSKAZUJE OSOBĘ, NIE NAZWĘ. Łańcuch A → B → C kończy się na C bez
 *     pętli; powrót do dawnej nazwy kasuje własny wiersz (`zajmij()`).
 *  3. PRZEKIEROWANIE NIE MA WIĘCEJ PRAW NIŻ PROFIL. Przed 301 pytamy tę samą
 *     Policy (`viewProfile`) co pod nowym adresem; odmowa to 404, nie 403 —
 *     sam nagłówek `Location` zdradzałby nową nazwę osoby, której profilu
 *     widz nie ma prawa zobaczyć (zbanowana, w trakcie usuwania, zablokowana
 *     w którąkolwiek stronę). Tak samo robi `RecipeController::show()`.
 *  4. WYMAZANIE KONTA KASUJE DAWNE NAZWY (RODO art. 17, `EraseAccountData`).
 */
final class DawneNazwyProfilu
{
    /**
     * Nazwa w postaci klucza: małe litery. Adres profilu nie rozróżnia
     * wielkości liter, a CHECK w tabeli wymaga małych.
     */
    public static function klucz(string $nazwa): string
    {
        return mb_strtolower(trim($nazwa));
    }

    /**
     * Osoba zmieniła nazwę z $dawna na $nowa: zapamiętaj dawną i zwolnij
     * nową. Wołać W TEJ SAMEJ TRANSAKCJI co zapis profilu, PO nim.
     * Zmiana samej wielkości liter („Basia" → „basia") to ta sama nazwa
     * z punktu widzenia adresu — nic nie zapamiętujemy.
     */
    public function zmieniono(User $user, string $dawna, string $nowa): void
    {
        $kluczDawnej = self::klucz($dawna);
        $kluczNowej = self::klucz($nowa);

        if ($kluczDawnej === $kluczNowej) {
            return;
        }

        $this->zajmij($user, $kluczNowej);

        // Upsert, bo przy wyścigu wiersz mógł już powstać; ostatni zapis
        // wskazuje osobę, która nazwę faktycznie opuściła.
        DB::table('profile_username_redirects')->upsert(
            [['username' => $kluczDawnej, 'user_id' => $user->getKey(), 'created_at' => now()]],
            ['username'],
            ['user_id', 'created_at'],
        );
    }

    /**
     * Ktoś bierze nazwę (rejestracja albo zmiana): jej wiersz przekierowania,
     * własny lub cudzy, znika. Wolno zająć cudzą dawną nazwę od razu
     * (decyzja właściciela z 1.10.2026, wiersz w D-333).
     */
    public function zajmij(User $user, string $nazwa): void
    {
        DB::table('profile_username_redirects')
            ->where('username', self::klucz($nazwa))
            ->delete();
    }

    /** Wymazanie / usunięcie konta: wszystkie dawne nazwy tej osoby. */
    public function usunDlaOsoby(User $user): void
    {
        DB::table('profile_username_redirects')->where('user_id', $user->getKey())->delete();
    }

    /**
     * Profil, do którego prowadzi dawna nazwa, albo `null`. Wołać wyłącznie
     * wtedy, gdy pod `$nazwa` nie ma żywego profilu.
     */
    public function profilPoDawnejNazwie(string $nazwa): ?Profile
    {
        $userId = DB::table('profile_username_redirects')
            ->where('username', self::klucz($nazwa))
            ->value('user_id');

        if ($userId === null) {
            return null;
        }

        return Profile::query()->with('user')->whereKey($userId)->first();
    }

    /**
     * 301 na ten sam ekran pod aktualną nazwą (ta sama trasa, te same
     * parametry zapytania) albo `null` — wtedy wołający odpowiada 404.
     * `$widz` to osoba oglądająca; dla kanału Atom zawsze gość.
     *
     * @param  string  $trasa  nazwa trasy z parametrem `username`
     */
    public function przekierowanie(Request $request, ?User $widz, string $nazwa, string $trasa): ?RedirectResponse
    {
        $profil = $this->profilPoDawnejNazwie($nazwa);
        $wlasciciel = $profil?->user;

        if ($profil === null || $wlasciciel === null) {
            return null;
        }

        $wlasciciel->setRelation('profile', $profil);

        if (! Gate::forUser($widz)->allows('viewProfile', $wlasciciel)) {
            return null;
        }

        // Bez jawnego zakazu cache przeglądarka (a czytnik kanałów, który
        // omija warstwę `web`) mogłaby zapamiętać 301 na stałe. Po powrocie
        // osoby do dawnej nazwy dwa zapamiętane przekierowania (A → B i B → A)
        // dałyby u tego odwiedzającego pętlę. Profil i podstrony dostają
        // to samo z `PreventSharedSessionCache`; kanał Atom stoi poza nim.
        $odpowiedz = redirect()->route($trasa, [...$request->query(), 'username' => $profil->username], 301);
        $odpowiedz->headers->set('Cache-Control', 'private, no-store');

        return $odpowiedz;
    }
}
