<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Każdy limit zapytań ma WŁASNY licznik — przeglądanie serwisu nie zjada
 * budżetu publikacji.
 *
 * TO JEST PRZYCZYNA ZGŁOSZENIA „429 PRZY PIERWSZYM DODANIU ZDJĘCIA"
 * Właściciel zgłosił: pierwsza próba dodania zdjęcia danego dnia kończyła się
 * stroną „Za dużo prób. Spróbuj ponownie za 1 min." Przyczyna nie była
 * w limicie publikacji — była w tym, że limity nie miały osobnych liczników.
 *
 * `ThrottleRequests::resolveRequestSignature()` buduje klucz licznika
 * WYŁĄCZNIE z identyfikatora zalogowanego konta (a dla gościa z domeny i IP).
 * Nazwy trasy w kluczu NIE MA. Rozróżnia je dopiero TRZECI parametr
 * middleware'u — prefiks — którego nie przekazywało żadne wywołanie
 * `throttle:` w `routes/web.php`. Skutek: wszystkie limity zalogowanej osoby
 * dzieliły JEDEN licznik, a każda trasa porównywała jego wartość ze SWOIM
 * maksimum.
 *
 * Zmierzone przed poprawką, dokładnie tym testem: 25 zapytań o warianty
 * zdjęć (limit tej trasy to 600/min, więc żadne nie odbiło) i PIERWSZA próba
 * publikacji (limit 20/10 min) dostawała 429 z `Retry-After: 59` — czyli
 * słowo w słowo to, co zobaczył właściciel.
 *
 * DLACZEGO TO WYSZŁO DOPIERO TERAZ
 * Trasa `/zdjecia/{media}/{wariant}` powstała dwa dni temu razem
 * z zamknięciem W7-02. Wcześniej zdjęcia szły prosto z CDN-u i nie przechodziły
 * przez limiter Laravela w ogóle. Poprawka bezpieczeństwa wpuściła kilkadziesiąt
 * zapytań na KAŻDE otwarcie strony do licznika, który współdzieliła z publikacją.
 */
class LicznikiLimitowNieMieszajaSieMiedzyTrasamiTest extends TestCase
{
    use RefreshDatabase;

    public function test_przegladanie_zdjec_nie_blokuje_pierwszej_publikacji(): void
    {
        $autor = $this->user('basia');
        $zdjecie = Media::factory()->create(['owner_id' => $autor->getKey()]);

        // Odtworzenie zwykłego przeglądania: strona z feedem to kilkadziesiąt
        // zapytań o warianty zdjęć. Limit tej trasy to 600/min, więc mieszczą
        // się z ogromnym zapasem. Nieistniejące UUID-y są tu bez znaczenia —
        // licznik nabija middleware, ZANIM kontroler zdąży oddać 404.
        for ($i = 0; $i < 25; $i++) {
            $this->actingAs($autor)->get(
                route('media.show', ['media' => Str::uuid()->toString(), 'wariant' => 'feed']),
            );
        }

        $odpowiedz = $this->actingAs($autor)->post(route('posts.store'), [
            'body' => 'Pierwszy sernik dnia.',
            'visibility' => 'public',
            'media_ids' => [$zdjecie->getKey()],
        ]);

        $this->assertNotSame(
            429,
            $odpowiedz->status(),
            'Pierwsza próba dodania zdjęcia odbiła się o limit, mimo że nikt '
            .'jeszcze nic nie opublikował. Zapytania o warianty zdjęć zjadły '
            .'budżet publikacji, bo oba limity dzielą jeden licznik. Tak wyglądało '
            .'zgłoszenie właściciela: „Za dużo prób. Spróbuj ponownie za 1 min." '
            .'przy PIERWSZYM zdjęciu danego dnia.',
        );

        $odpowiedz->assertRedirect();
    }

    public function test_kazda_nasza_trasa_z_limitem_ma_wlasny_prefiks_licznika(): void
    {
        // To jest REGUŁA, nie jednorazowa poprawka. Trasa dopisana jutro bez
        // prefiksu znowu wpadnie do wspólnego worka i znowu nikt tego nie
        // zauważy, bo objawia się to dopiero pod obciążeniem u konkretnej osoby.
        $bezPrefiksu = [];

        foreach (Route::getRoutes()->getRoutes() as $trasa) {
            if ($this->trasaPakietu($trasa->uri())) {
                continue;
            }

            foreach ($trasa->gatherMiddleware() as $warstwa) {
                if (! is_string($warstwa) || ! str_starts_with($warstwa, 'throttle:')) {
                    continue;
                }

                // `throttle:<ile>,<minut>,<prefiks>` — bez trzeciego członu
                // licznik nie odróżnia tej trasy od żadnej innej.
                if (substr_count($warstwa, ',') < 2) {
                    $bezPrefiksu[] = $trasa->methods()[0].' /'.$trasa->uri().'  ('.$warstwa.')';
                }
            }
        }

        $this->assertSame(
            [],
            $bezPrefiksu,
            "Trasa z limitem zapytań bez własnego prefiksu licznika:\n"
            .implode("\n", $bezPrefiksu)
            ."\n\n`ThrottleRequests` buduje klucz licznika z samego identyfikatora "
            .'konta (albo z IP dla gościa) — nazwy trasy w nim NIE MA. Bez trzeciego '
            .'parametru ta trasa dzieli licznik z każdą inną, a limity z '
            .'`config/kuking.php` przestają znaczyć to, co mówią. Dopisz prefiks '
            .'równy kluczowi limitu, np. `throttle:{$limits[\'post\']},post`.',
        );
    }

    public function test_jeden_prefiks_to_zawsze_ten_sam_limit(): void
    {
        // Druga połowa reguły, i ta jest ważniejsza od pierwszej.
        //
        // Prefiks „ma być" nie wystarcza — dwie trasy o RÓŻNYCH limitach, które
        // dostaną ten sam prefiks, znowu dzielą budżet, tylko trudniej to
        // zauważyć niż przy braku prefiksu. Ten test przyjmuje więc także trasę
        // pakietu (Livewire), która prefiksu nie ma wcale: jej „pusty" prefiks
        // jest osobnym workiem i dopóki jest w nim sama, niczego nie psuje.
        // Gdyby dołączyła do niej druga trasa z innym limitem — ten test oblei.
        $wgKlucza = [];

        foreach (Route::getRoutes()->getRoutes() as $trasa) {
            foreach ($trasa->gatherMiddleware() as $warstwa) {
                if (! is_string($warstwa) || ! str_starts_with($warstwa, 'throttle:')) {
                    continue;
                }

                $czesci = explode(',', substr($warstwa, strlen('throttle:')));
                $prefiks = $czesci[2] ?? '(bez prefiksu)';
                $limit = ($czesci[0] ?? '?').','.($czesci[1] ?? '?');

                $wgKlucza[$prefiks][$limit][] = $trasa->methods()[0].' /'.$trasa->uri();
            }
        }

        $sprzeczne = [];

        foreach ($wgKlucza as $prefiks => $limity) {
            if (count($limity) > 1) {
                $sprzeczne[] = $prefiks.' => '.implode(' ORAZ ', array_keys($limity));
            }
        }

        $this->assertSame(
            [],
            $sprzeczne,
            "Ten sam prefiks licznika obsługuje dwa różne limity:\n"
            .implode("\n", $sprzeczne)
            ."\n\nTrasy o tym samym prefiksie liczą się do JEDNEGO wiadra, ale każda "
            .'porównuje jego stan ze SWOIM maksimum. Ta o niższym limicie zacznie '
            .'odbijać ludzi, którzy jej nawet nie użyli — dokładnie tak wyglądało '
            .'zgłoszenie „429 przy pierwszym dodaniu zdjęcia".',
        );
    }

    /**
     * Trasa dostarczona przez pakiet, nie napisana w `routes/web.php`.
     *
     * Livewire rejestruje własny endpoint wgrywania plików z `throttle:60,1`
     * i nie da się mu dopisać prefiksu z naszego pliku tras. Nie jest to dziś
     * problem WŁAŚNIE dlatego, że wszystkie nasze trasy prefiks mają: pusty
     * worek zostaje mu na wyłączność. Pilnuje tego test wyżej, nie ten wyjątek.
     */
    private function trasaPakietu(string $uri): bool
    {
        return str_starts_with($uri, 'livewire');
    }

    public function test_trasy_dzielace_swiadomie_ten_sam_limit_dalej_go_dziela(): void
    {
        // Prefiks to KLUCZ LIMITU, nie nazwa trasy — i to jest celowe.
        // `routes/web.php` mówi wprost, że publikacja wpisu, przepisu
        // i „Ugotowałem" mają dzielić jeden budżet `post`. Gdyby prefiks szedł
        // od nazwy trasy, ta decyzja zniknęłaby po cichu przy okazji poprawki
        // czegoś zupełnie innego.
        $prefiksy = [];

        foreach (Route::getRoutes()->getRoutes() as $trasa) {
            foreach ($trasa->gatherMiddleware() as $warstwa) {
                if (is_string($warstwa) && str_starts_with($warstwa, 'throttle:')) {
                    $prefiksy[$trasa->getName() ?? $trasa->uri()] = substr($warstwa, strrpos($warstwa, ',') + 1);
                }
            }
        }

        $this->assertSame('post', $prefiksy['posts.store'] ?? null);
        $this->assertSame('post', $prefiksy['recipes.store'] ?? null);
        $this->assertSame('comment', $prefiksy['posts.comment'] ?? null);
        $this->assertSame('zdjecie', $prefiksy['media.show'] ?? null);
    }
}
