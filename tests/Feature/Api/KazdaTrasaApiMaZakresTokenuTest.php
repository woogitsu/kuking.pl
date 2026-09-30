<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Http\Api\ZakresyTokenu;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as TrasaLaravela;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Zakresy tokenu są EGZEKWOWANE na prawdziwych trasach `/api/v1`
 * (#2232, audyt 30.09.2026 S-02).
 *
 * Do tej poprawki `routes/api.php` obiecywał w komentarzu `ability:<zakres>`
 * na każdej trasie mutującej, a nie miała go żadna: token wydany z samym
 * `tresc:czytaj` publikował wpis (201). `ZakresyTokenuTest` sprawdzał tylko
 * trasę sztuczną, zarejestrowaną w teście — dlatego tutaj są dwie połowy:
 *
 *  - strażnik tras: KAŻDA trasa `api/v1/*` (poza `api.tokeny.*`) ma
 *    `ability:` z właściwym zakresem, więc nowa trasa bez zakresu oblewa
 *    od razu, zanim ktoś wyda węższy token;
 *  - zachowanie: token bez `tresc:pisz` dostaje 403 na każdej z sześciu tras
 *    zapisu i nic nie powstaje; token z samym zapisem nie czyta treści.
 */
class KazdaTrasaApiMaZakresTokenuTest extends TestCase
{
    use RefreshDatabase;

    /** Wydanie i odwołanie własnego tokenu działa każdym tokenem (albo bez niego). */
    private const BEZ_ZAKRESU = ['api.tokeny.'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['kuking.api.wlaczone' => true]);
        config(['kuking.api.limity.na_adres' => '1000,1', 'kuking.api.limity.na_token' => '1000,1']);
        Storage::fake('public');
    }

    public function test_kazda_trasa_api_v1_ma_ability_z_wlasciwym_zakresem(): void
    {
        $sprawdzone = 0;
        $braki = [];

        foreach (Route::getRoutes()->getRoutes() as $trasa) {
            /** @var TrasaLaravela $trasa */
            if (! str_starts_with($trasa->uri(), 'api/v1/')) {
                continue;
            }

            $nazwa = (string) $trasa->getName();
            foreach (self::BEZ_ZAKRESU as $prefiks) {
                if (str_starts_with($nazwa, $prefiks)) {
                    continue 2;
                }
            }

            $sprawdzone++;
            $zakresy = $this->zakresyTrasy($trasa);
            $pisze = array_diff($trasa->methods(), ['GET', 'HEAD']) !== [];

            if ($pisze && ! in_array(ZakresyTokenu::TRESC_PISZ, $zakresy, true)) {
                $braki[] = sprintf('%s %s (%s) — trasa zapisu bez ability:%s', implode('|', $trasa->methods()), $trasa->uri(), $nazwa, ZakresyTokenu::TRESC_PISZ);
            }

            if (! $pisze && array_intersect($zakresy, [ZakresyTokenu::PROFIL_CZYTAJ, ZakresyTokenu::TRESC_CZYTAJ]) === []) {
                $braki[] = sprintf('%s %s (%s) — trasa odczytu bez ability: z zakresem czytania', implode('|', $trasa->methods()), $trasa->uri(), $nazwa);
            }
        }

        // Pułapka §2 (PULAPKI_TESTOW.md): pusty skan byłby zielony.
        // Dziś: /ja, 8 odczytów i 6 zapisów.
        $this->assertGreaterThanOrEqual(15, $sprawdzone, 'Strażnik nie znalazł tras /api/v1 — sprawdza pustą listę.');
        $this->assertSame([], $braki, "Trasy API bez egzekwowanego zakresu tokenu (#2232):\n".implode("\n", $braki));
    }

    /** @return array<string, array{0: string}> */
    public static function trasyZapisu(): array
    {
        return [
            'nowy wpis' => ['api.wpisy.store'],
            'ugotowałem' => ['api.przepisy.ugotowalem'],
            'komentarz pod wpisem' => ['api.wpisy.komentarze.store'],
            'komentarz pod przepisem' => ['api.przepisy.komentarze.store'],
            'obserwuj' => ['api.osoby.obserwuj'],
            'przestań obserwować' => ['api.osoby.przestan'],
        ];
    }

    #[DataProvider('trasyZapisu')]
    public function test_token_tylko_do_czytania_nie_zapisuje_niczego(string $nazwaTrasy): void
    {
        [$basia, $adam, $wpis, $przepis] = $this->scena();
        DB::table('follows')->insert(['follower_id' => $basia->getKey(), 'followed_id' => $adam->getKey(), 'created_at' => now()]);
        $przed = $this->stan();

        $czytelnik = $basia->createToken('Czytnik', [ZakresyTokenu::PROFIL_CZYTAJ, ZakresyTokenu::TRESC_CZYTAJ])->plainTextToken;
        [$metoda, $adres, $dane] = $this->zadanie($nazwaTrasy, $adam, $wpis, $przepis);

        $this->withHeader('Authorization', 'Bearer '.$czytelnik)
            ->json($metoda, $adres, $dane)
            ->assertForbidden()
            ->assertJsonPath('code', 'brak_zakresu')
            ->assertJsonPath('message', 'Ta aplikacja nie ma uprawnienia do tej operacji. Wyloguj się w aplikacji i zaloguj ponownie.');

        $this->assertSame($przed, $this->stan(), 'Token bez tresc:pisz coś zapisał.');
    }

    /**
     * Kontrola dodatnia na TYCH SAMYCH trasach i danych: token z `tresc:pisz`
     * przechodzi — 403 wyżej nie znaczy „trasa zepsuta dla każdego”.
     */
    #[DataProvider('trasyZapisu')]
    public function test_token_z_zapisem_przechodzi_na_tej_samej_trasie(string $nazwaTrasy): void
    {
        [$basia, $adam, $wpis, $przepis] = $this->scena();
        DB::table('follows')->insert(['follower_id' => $basia->getKey(), 'followed_id' => $adam->getKey(), 'created_at' => now()]);
        $przed = $this->stan();

        $pisarz = $basia->createToken('Pisarz', [ZakresyTokenu::TRESC_PISZ])->plainTextToken;
        [$metoda, $adres, $dane] = $this->zadanie($nazwaTrasy, $adam, $wpis, $przepis);

        $odpowiedz = $this->withHeader('Authorization', 'Bearer '.$pisarz)->json($metoda, $adres, $dane);

        $this->assertContains($odpowiedz->status(), [200, 201], $odpowiedz->getContent());
        $this->assertNotSame($przed, $this->stan(), 'Token z tresc:pisz nic nie zapisał — kontrola dodatnia nie działa.');
    }

    public function test_zakres_czytania_rozdziela_profil_i_tresc(): void
    {
        [$basia] = $this->scena();
        $pisarz = $basia->createToken('Pisarz', [ZakresyTokenu::TRESC_PISZ])->plainTextToken;
        $profil = $basia->createToken('Profil', [ZakresyTokenu::PROFIL_CZYTAJ])->plainTextToken;
        $tresc = $basia->createToken('Treść', [ZakresyTokenu::TRESC_CZYTAJ])->plainTextToken;

        $this->zTokenem($pisarz)->getJson('/api/v1/feed')
            ->assertForbidden()->assertJsonPath('code', 'brak_zakresu');
        $this->zTokenem($profil)->getJson('/api/v1/feed')->assertForbidden();
        $this->zTokenem($tresc)->getJson('/api/v1/feed')->assertOk();

        $this->zTokenem($tresc)->getJson('/api/v1/ja')->assertForbidden();
        $this->zTokenem($profil)->getJson('/api/v1/ja')->assertOk();
    }

    /**
     * Kolejne żądanie innym tokenem w tym samym teście: strażnik `sanctum`
     * pamięta osobę z poprzedniego żądania, więc go zapominamy.
     */
    private function zTokenem(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    public function test_wylogowanie_dziala_tokenem_o_kazdym_zakresie(): void
    {
        [$basia] = $this->scena();
        $czytelnik = $basia->createToken('Czytnik', [ZakresyTokenu::TRESC_CZYTAJ]);

        $this->withHeader('Authorization', 'Bearer '.$czytelnik->plainTextToken)
            ->deleteJson('/api/v1/tokeny/biezacy')
            ->assertSuccessful();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $czytelnik->accessToken->getKey()]);
    }

    /** @return array{0: User, 1: User, 2: Post, 3: Recipe} */
    private function scena(): array
    {
        $basia = $this->user('basia');
        $adam = $this->user('adam');
        $wpis = Post::factory()->create(['author_id' => $adam->getKey()]);
        $przepis = Recipe::factory()->create(['author_id' => $adam->getKey()]);

        return [$basia, $adam, $wpis, $przepis];
    }

    /** @return array{0: string, 1: string, 2: array<string, mixed>} */
    private function zadanie(string $nazwaTrasy, User $adam, Post $wpis, Recipe $przepis): array
    {
        return match ($nazwaTrasy) {
            'api.wpisy.store' => ['POST', route($nazwaTrasy), [
                'photos' => [UploadedFile::fake()->image('obiad.jpg', 800, 600)],
                'body' => 'Dziś pierogi ruskie.',
                'visibility' => 'public',
            ]],
            'api.przepisy.ugotowalem' => ['POST', route($nazwaTrasy, $przepis->getKey()), []],
            'api.wpisy.komentarze.store' => ['POST', route($nazwaTrasy, $wpis->getKey()), ['body' => 'Smacznie!']],
            'api.przepisy.komentarze.store' => ['POST', route($nazwaTrasy, $przepis->getKey()), ['body' => 'Zrobię.']],
            // Basia już obserwuje Adama — więc „obserwuj” drugiej osoby.
            'api.osoby.obserwuj' => ['POST', route($nazwaTrasy, $this->user('celina')->getKey()), []],
            'api.osoby.przestan' => ['DELETE', route($nazwaTrasy, $adam->getKey()), []],
            default => $this->fail('Trasa bez zadania w tym teście — dopisz ją do zadanie(): '.$nazwaTrasy),
        };
    }

    /** @return array<string, int> */
    private function stan(): array
    {
        return [
            'wpisy' => Post::query()->count(),
            'ugotowania' => CookedEvent::query()->count(),
            'komentarze' => Comment::query()->count(),
            'obserwowania' => DB::table('follows')->count(),
        ];
    }

    /** @return list<string> */
    private function zakresyTrasy(TrasaLaravela $trasa): array
    {
        $zakresy = [];
        foreach ($trasa->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && preg_match('/^abilit(?:y|ies):(.+)$/', $middleware, $m) === 1) {
                array_push($zakresy, ...explode(',', $m[1]));
            }
        }

        return $zakresy;
    }
}
