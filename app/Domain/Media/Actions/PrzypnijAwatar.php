<?php

declare(strict_types=1);

namespace App\Domain\Media\Actions;

use App\Domain\Media\ZdjeciaDoPrzypiecia;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Media;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Zdjęcie profilowe: przypięcie i odpięcie — obie strony jednej kolumny
 * `profiles.avatar_media_id` (D-103, dokończenie D-083).
 *
 * ══════════════════════════════════════════════════════════════════════
 *  PO CO TO WYSZŁO Z KONTROLERA
 * ══════════════════════════════════════════════════════════════════════
 *
 * `AvatarSettingsController::update()` robił to jedną linijką:
 *
 *     $profile->update(['avatar_media_id' => $zdjecie->getKey()]);
 *
 * bez transakcji i bez blokady wiersza `media` — czyli dokładnie tak, jak
 * `PublishPost` przed D-083. Reguła „zdjęcie przypina się pod blokadą"
 * należy do domeny, nie do kontrolera (`AGENTS.md` §4): dopóki stała
 * w kontrolerze, drugi endpoint na to samo pole zaczynałby od zera.
 *
 * ══════════════════════════════════════════════════════════════════════
 *  CO TU JEST INNE NIŻ W TRZECH POZOSTAŁYCH DROGACH
 * ══════════════════════════════════════════════════════════════════════
 *
 * Awatar ZASTĘPUJE poprzednie zdjęcie, a nie dokłada kolejne. Wpis może
 * mieć dziesięć zdjęć, przepis ma jedno główne i jeden skan, ale tam nowe
 * zdjęcie nie wypycha starego z tej samej kolumny w tej samej sekundzie.
 * Wynikają z tego dwie rzeczy — i tylko druga jest usterką.
 *
 * 1. STARE ZDJĘCIE PRZESTAJE BYĆ UŻYWANE W CHWILI COMMITU. To NIE łamie
 *    wzorca z D-083. Sprzątacz pyta „czy używane" pod blokadą TEGO starego
 *    wiersza i czyta `profiles` ponownie, więc przed naszym commitem widzi
 *    „używane" (pomija), a po commicie „nieużywane" (przejmuje). Obie
 *    odpowiedzi są prawdziwe w chwili, w której padają, i żadna z nich nie
 *    kasuje zdjęcia, które ktoś właśnie przypiął. Stare zdjęcie MA zniknąć
 *    — po to człowiek wgrał nowe.
 *
 * 2. ODPIĘCIE („Usuń zdjęcie") DZIAŁAŁO NA MODELU SPRZED ODCZYTU. To jest
 *    usterka, i wzorzec z D-083 jej nie łapie, bo D-083 opisuje
 *    PRZYPINANIE, a tu zdjęcie ginie przez ODPIĘCIE. Kontroler czytał
 *    `$profile->avatar`, a potem zerował kolumnę bezwarunkowo:
 *
 *      1. karta A otwiera „Zdjęcie profilowe" i czyta awatar = X;
 *      2. karta B wgrywa nowe zdjęcie Y — kolumna wskazuje już Y;
 *      3. karta A klika „Usuń zdjęcie" i zapisuje `avatar_media_id = NULL`,
 *         odpinając Y, o którym nic nie wie.
 *
 *    Y zostaje bez odwołania, więc po dobie karencji zabiera je sprzątacz
 *    osieroconych zdjęć — razem z plikami. Człowiek traci zdjęcie, którego
 *    nie usuwał, i nie dostaje o tym ani jednego zdania. W grupie 50+ dwie
 *    otwarte karty tej samej strony to nie przypadek brzegowy, tylko sposób
 *    obsługi komputera.
 *
 *    ZAMYKA TO JEDNO ZDANIE SQL, NIE BLOKADA. `odepnij()` zeruje kolumnę
 *    warunkiem `avatar_media_id = <to zdjęcie>` i patrzy, ile wierszy
 *    zmieniła. Gwarancję daje wtedy atomowy `UPDATE … WHERE`, a nie
 *    `exists()` w PHP (D-079 §4 i `docs/PULAPKI_TESTOW.md`) — i nie dokłada
 *    blokady, której inne drogi na `profiles` nie biorą, więc nie ma jak
 *    ustawić się w kolejce w innej kolejności niż one.
 *
 * ══════════════════════════════════════════════════════════════════════
 *  KOLEJNOŚĆ BLOKAD: `media`, I TYLKO `media`
 * ══════════════════════════════════════════════════════════════════════
 *
 * `ZamekKonta` (D-075/D-079, „konto najpierw") TU NIE WCHODZI i to jest
 * decyzja, nie przeoczenie. Zmierzone na PostgreSQL 18 dwiema sesjami psql
 * (pomiar opisany w D-103):
 *
 *   sesja 1 trzyma `users FOR UPDATE`  → UPDATE profiles PRZECHODZI
 *   sesja 1 trzyma `media FOR UPDATE`  → UPDATE profiles CZEKA
 *
 * Czyli `UPDATE profiles SET avatar_media_id = …` NIE bierze wiersza
 * `users` (klucz obcy `profiles.user_id` się nie zmienia, więc silnik
 * pomija jego sprawdzenie), a bierze wiersz `media` — dokładnie tam, gdzie
 * czeka blokada założona przez `ZdjeciaDoPrzypiecia`.
 *
 * Gdyby awatar wchodził przez `ZamekKonta`, brałby `users FOR UPDATE`
 * PRZED wierszem `media`. `PublishPost` i `PublishRecipe` biorą je
 * w kolejności odwrotnej (`media FOR UPDATE`, potem `users` przy wstawianiu
 * wiersza z `author_id` — też zmierzone). Dwie kolejności w jednym
 * repozytorium to zakleszczenie, nie zabezpieczenie (D-079 §1) — i to
 * dokładnie ta rodzina usterek, którą naprawiały D-093 i PR #311.
 */
final class PrzypnijAwatar
{
    /**
     * Ustawia zdjęcie profilowe — pod blokadą wiersza `media`.
     *
     * ODMOWA JEST TU WYJĄTKIEM, NIE CICHYM POMINIĘCIEM, i tym różni się od
     * trzech pozostałych dróg. Przy wpisie i przy przepisie zniknięcie
     * jednego zdjęcia zostawia całą treść, którą człowiek wpisał, więc
     * lepiej zapisać wpis bez zdjęcia niż nie zapisać nic. Ten ekran ma
     * jedno pole i jedną czynność: „Zdjęcie zapisane." przy niezmienionym
     * zdjęciu byłoby kłamstwem — tym samym, które ten formularz już raz
     * naprawiał (`required` przy pustym pliku, patrz
     * `AvatarSettingsController::update()`).
     *
     * @throws BladDlaCzlowieka gdy tego zdjęcia nie wolno już przypiąć
     */
    public function handle(User $wlasciciel, Media $zdjecie): void
    {
        DB::transaction(function () use ($wlasciciel, $zdjecie): void {
            $zablokowane = ZdjeciaDoPrzypiecia::zablokuj(
                (string) $wlasciciel->getKey(),
                [(string) $zdjecie->getKey()],
            );

            if ($zablokowane === []) {
                throw new BladDlaCzlowieka(
                    'Nie udało się zapisać tego zdjęcia. Wybierz je jeszcze raz — '
                    .'poprzednie zdjęcie zostaje bez zmian.',
                );
            }

            // Wiersz profilu bierzemy zapytaniem, a nie z modelu podanego
            // z zewnątrz: tamten mógł zostać odczytany przed sekundą albo
            // przed godziną, a zapisać ma się profil TEJ osoby, nie ten,
            // który akurat wisi w pamięci (D-079 §3).
            //
            // `updated_at` wpisujemy ręcznie, bo `update()` na builderze nie
            // przechodzi przez model i sam znacznika czasu nie ruszy.
            Profile::query()
                ->where('user_id', $wlasciciel->getKey())
                ->update([
                    'avatar_media_id' => $zablokowane[0],
                    'updated_at' => now(),
                ]);
        });
    }

    /**
     * Odpina zdjęcie profilowe — ale TYLKO jeśli profil nadal wskazuje
     * właśnie na nie.
     *
     * @return bool czy to zdjęcie faktycznie zostało odpięte; `false` znaczy
     *              „profil wskazuje już na coś innego" i wtedy nie wolno
     *              kasować ani wiersza `media`, ani plików
     */
    public function odepnij(User $wlasciciel, Media $zdjecie): bool
    {
        $zmienione = Profile::query()
            ->where('user_id', $wlasciciel->getKey())
            ->where('avatar_media_id', $zdjecie->getKey())
            ->update([
                'avatar_media_id' => null,
                'updated_at' => now(),
            ]);

        return $zmienione > 0;
    }
}
