<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Gotowanie\Wspolne;

use App\Domain\Social\ZamekPary;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\CookingSession;
use App\Models\CookingSessionInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Link do wspólnego gotowania (wielorazowy, do trzech osób): utworzenie,
 * odwołanie, przyjęcie (#2385). Projekt i uzasadnienia: `docs/product/PROJEKT_WSPOLNE_GOTOWANIE_2385.md`.
 *
 * TOKEN: 40 losowych znaków, jawny istnieje wyłącznie w wyniku `utworz()`.
 * W bazie leży jego SHA-256. Tokenu nie wolno logować ani wkładać do wyjątków.
 *
 * WIELU POMOCNIKÓW (decyzja właściciela z 1.10.2026): gospodarz i do trzech
 * pomocników (`kuking.wspolne_gotowanie.max_pomocnikow`). LINK JEST
 * WIELORAZOWY (decyzja właściciela z 1.10.2026, zastępuje „jeden link = jedna
 * osoba”): jeden żywy link (`pending`, niewygasły, nieodwołany) przyjmuje
 * kolejne osoby, aż w sesji jest komplet pomocników. Przyjęcie NIE zużywa
 * linku i nie zmienia wiersza zaproszenia; kończy go wygaśnięcie
 * (`link_godziny`), odwołanie albo nowy link gospodarza (nowy unieważnia
 * stary — w sesji jest najwyżej jeden żywy, częściowy unikalny indeks).
 * Kto dostanie link, może dołączyć, więc to gospodarz decyduje, komu go daje.
 *
 * PRZYJĘCIE (`dolacz`) ma być nie do obejścia, więc wszystkie warunki są
 * sprawdzane POD ZAMKIEM PARY KONT (osoba + gospodarz, ta sama kolejność
 * blokad co przy zaproszeniu do zeszytu, D-080/D-302), a potem pod blokadą
 * wiersza SESJI — na świeżych odczytach. Blokada wiersza sesji jest
 * JEDYNYM miejscem, które szereguje limit pomocników, tworzenie i odwołanie
 * linku oraz przyjęcie; wiersz zaproszenia czytamy dopiero po niej, więc
 * wszystkie te operacje biorą blokady w tej samej kolejności (konta → sesja)
 * i nie zakleszczają się. Zamek pary sam by nie wystarczył do limitu, który
 * ma nie zależeć od tego, że gospodarz jest wspólny dla każdej pary:
 *  - konto osoby aktywne, gospodarz nie zamknięty, brak blokady w żadną stronę
 *    ORAZ brak blokady wobec któregokolwiek z obecnych pomocników;
 *  - OSOBA WIDZI PRZEPIS wg `RecipePolicy::view` — link nie otwiera treści
 *    komuś bez uprawnień (przepis prywatny, „dla obserwujących”, blokada
 *    wobec autora, konto autora zbanowane);
 *  - sesja trwa, jest wolne miejsce, link czeka (nieodwołany, niewygasły).
 * KAŻDA odmowa ma to samo zdanie (`NIEAKTUALNE`): adres linku nie jest
 * wyrocznią — nie zdradza, czy token istniał, kto kogo zablokował ani jaki
 * jest przepis.
 */
final class ZaproszenieDoGotowania
{
    public const NIEAKTUALNE = 'Nie możesz dołączyć do tej sesji. Link mógł wygasnąć, zostać odwołany albo w sesji nie ma już wolnych miejsc. Poproś gospodarza o nowy.';

    /**
     * @return array{0: CookingSessionInvitation, 1: string} zaproszenie i JAWNY token — jedyny raz, kiedy istnieje
     */
    public function utworz(User $gospodarz, CookingSession $sesja): array
    {
        Gate::forUser($gospodarz)->authorize('manage', $sesja);

        return DB::transaction(function () use ($gospodarz, $sesja): array {
            $swieza = $this->zablokujSesje($sesja);

            if (! $swieza->maGospodarza($gospodarz)) {
                throw new BladDlaCzlowieka(SesjaWspolnegoGotowania::NIE_MA_SESJI);
            }

            if ($swieza->pomocnicy()->count() >= $this->maxPomocnikow()) {
                throw new BladDlaCzlowieka('W tej sesji jest już komplet pomocników, więc nie ma miejsca na kolejną osobę. Możesz kogoś usunąć z sesji, żeby zaprosić inną osobę.');
            }

            // Nowy link unieważnia poprzedni: w sesji jest najwyżej jeden żywy.
            $this->uniewaznijOczekujace($swieza);

            $token = Str::random(40);
            $zaproszenie = new CookingSessionInvitation;
            $zaproszenie->forceFill([
                'id' => (string) Str::uuid(),
                'session_id' => $swieza->getKey(),
                'token_hash' => CookingSessionInvitation::skrotTokenu($token),
                'status' => CookingSessionInvitation::STATUS_PENDING,
                'expires_at' => min(
                    now()->addHours(max(1, (int) config('kuking.wspolne_gotowanie.link_godziny', 24))),
                    $swieza->expires_at,
                ),
            ])->save();

            return [$zaproszenie, $token];
        });
    }

    /** Odwołanie żywego linku przez gospodarza. Idempotentne. */
    public function odwolaj(User $gospodarz, CookingSession $sesja): void
    {
        Gate::forUser($gospodarz)->authorize('manage', $sesja);

        DB::transaction(function () use ($sesja): void {
            $swieza = CookingSession::query()->whereKey($sesja->getKey())->lockForUpdate()->first();

            if ($swieza !== null) {
                $this->uniewaznijOczekujace($swieza);
            }
        });
    }

    /** Czy token wskazuje link, który jeszcze czeka (bez żadnych skutków). */
    public function poTokenie(string $token): ?CookingSessionInvitation
    {
        if (mb_strlen($token) !== 40) {
            return null;
        }

        $zaproszenie = CookingSessionInvitation::query()
            ->where('token_hash', CookingSessionInvitation::skrotTokenu($token))
            ->first();

        return $zaproszenie !== null && $zaproszenie->czeka() ? $zaproszenie : null;
    }

    /**
     * Przyjęcie linku. Zwraca sesję, do której osoba ma teraz dostęp.
     * Link jest wielorazowy: kolejne osoby wchodzą tym samym linkiem, dopóki
     * jest wolne miejsce. Drugie przyjęcie przez osobę, która już jest w
     * sesji (podwójne kliknięcie), jest sukcesem bez skutku.
     *
     * @throws BladDlaCzlowieka
     */
    public function dolacz(User $osoba, string $token): CookingSession
    {
        if (mb_strlen($token) !== 40) {
            throw new BladDlaCzlowieka(self::NIEAKTUALNE);
        }

        $skrot = CookingSessionInvitation::skrotTokenu($token);
        $wstepne = CookingSessionInvitation::query()->where('token_hash', $skrot)->first();
        $sesjaWstepna = $wstepne?->session;
        $gospodarz = $sesjaWstepna?->host;

        if ($wstepne === null || $sesjaWstepna === null || $gospodarz === null) {
            throw new BladDlaCzlowieka(self::NIEAKTUALNE);
        }

        if ($gospodarz->getKey() === $osoba->getKey()) {
            throw new BladDlaCzlowieka('To jest Twoja sesja — nie musisz do niej dołączać. Link wyślij osobie, którą zapraszasz.');
        }

        return ZamekPary::zablokuj($osoba, $gospodarz, function (?User $swiezaOsoba, ?User $swiezyGospodarz) use ($skrot, $wstepne): CookingSession {
            if ($swiezaOsoba === null || $swiezyGospodarz === null) {
                throw new BladDlaCzlowieka(self::NIEAKTUALNE);
            }

            // KOLEJNOŚĆ: najpierw wiersz SESJI, potem zaproszenie. Tworzenie i
            // odwoływanie linku też najpierw blokuje sesję, więc odwrotna
            // kolejność (zaproszenie, potem sesja) zakleszczałaby się z nimi.
            // Zaproszenie czytamy po blokadzie sesji, czyli na świeżym stanie.
            $sesja = CookingSession::query()->whereKey($wstepne->session_id)->lockForUpdate()->first();

            if ($sesja === null || ! $sesja->trwa() || $sesja->host_id !== $swiezyGospodarz->getKey()) {
                throw new BladDlaCzlowieka(self::NIEAKTUALNE);
            }

            $zaproszenie = CookingSessionInvitation::query()->where('token_hash', $skrot)->first();

            if ($zaproszenie === null || $zaproszenie->session_id !== $sesja->getKey()) {
                throw new BladDlaCzlowieka(self::NIEAKTUALNE);
            }

            // Osoba już jest pomocnikiem — drugie kliknięcie, nic nie robimy.
            // Odwołany albo wygasły link nie wpuszcza nikogo, także jej.
            if ($zaproszenie->czeka() && $sesja->maPomocnika($swiezaOsoba)) {
                return $sesja;
            }

            if (! $zaproszenie->czeka()) {
                throw new BladDlaCzlowieka(self::NIEAKTUALNE);
            }

            // Stan kont i blokada — na wierszach odczytanych pod zamkami.
            // Aktywne konto: dołączenie to udział w zapisie postępu.
            if (! $swiezaOsoba->isActive() || ! $swiezyGospodarz->mozeCzytac()
                || $swiezaOsoba->hasBlockRelationWith($swiezyGospodarz)) {
                throw new BladDlaCzlowieka(self::NIEAKTUALNE);
            }

            // LINK NIE DAJE DOSTĘPU DO TREŚCI, KTÓREJ OSOBA NIE MOGŁABY ZOBACZYĆ.
            // `RecipePolicy::view` obejmuje widoczność przepisu, blokadę wobec
            // jego autora i stan konta autora; dla przepisu prywatnego
            // gospodarza nikt poza nim nie przejdzie tej bramki.
            $przepis = $sesja->recipe;

            if ($przepis === null || $przepis->trashed() || ! Gate::forUser($swiezaOsoba)->allows('view', $przepis)) {
                throw new BladDlaCzlowieka(self::NIEAKTUALNE);
            }

            // POMOCNICY MIĘDZY SOBĄ: osoby, z których któraś zablokowała drugą,
            // nie siedzą w jednej sesji. Odmowa jest taka sama jak każda inna,
            // więc nie zdradza, kogo osoba zablokowała ani kto ją.
            $obecni = $sesja->pomocnicy()->get();

            foreach ($obecni as $obecny) {
                if ($swiezaOsoba->hasBlockRelationWith($obecny)) {
                    throw new BladDlaCzlowieka(self::NIEAKTUALNE);
                }
            }

            // LIMIT pod blokadą wiersza sesji, na świeżym odczycie.
            if ($obecni->count() >= $this->maxPomocnikow()) {
                throw new BladDlaCzlowieka(self::NIEAKTUALNE);
            }

            DB::table('cooking_session_participants')->insertOrIgnore([
                'session_id' => $sesja->getKey(),
                'user_id' => $swiezaOsoba->getKey(),
                'role' => 'helper',
                'joined_at' => now(),
            ]);

            // Wiersza zaproszenia NIE ruszamy: link zostaje żywy dla kolejnych osób.

            // Gospodarz zobaczy zmianę (kto jest w sesji) po odświeżeniu.
            $sesja->forceFill(['revision' => $sesja->revision + 1])->save();

            return $sesja;
        });
    }

    private function zablokujSesje(CookingSession $sesja): CookingSession
    {
        $swieza = CookingSession::query()->whereKey($sesja->getKey())->lockForUpdate()->first();

        if ($swieza === null || ! $swieza->trwa()) {
            throw new BladDlaCzlowieka(SesjaWspolnegoGotowania::NIE_MA_SESJI);
        }

        return $swieza;
    }

    private function uniewaznijOczekujace(CookingSession $sesja): void
    {
        CookingSessionInvitation::uniewaznijZywe((string) $sesja->getKey());
    }

    private function maxPomocnikow(): int
    {
        return max(1, (int) config('kuking.wspolne_gotowanie.max_pomocnikow', 3));
    }
}
