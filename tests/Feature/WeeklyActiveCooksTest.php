<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\CookRetentionCohorts;
use App\Domain\Analytics\WeeklyActiveCooks;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Weekly Active Cooks liczone z Postgresa, z wykluczeniami (issue #114).
 *
 * `docs/seo/ANALYTICS.md` §1.3-1.4 (referencja do brakującego w repozytorium
 * `docs/research/ANALITYKA.md`) znalazła lukę w zapytaniu z §2.2: liczyło
 * WSZYSTKICH — łącznie z gospodarzem, kontami zbanowanymi/`pending_delete`
 * i testowymi. Ten plik pilnuje, żeby ta luka nie wróciła: każdy test poniżej
 * MUSI oblać bez `App\Domain\Analytics\CookEligibility` (sprawdzone ręcznie
 * przed dopisaniem poprawki).
 *
 * Tydzień w tych testach to tydzień POLSKI — `WeeklyActiveCooks` obcina go
 * przez `Czas::wStrefieCzlowieka()`. Kolumny są `timestamptz`, więc momenty
 * poniżej zapisujemy w UTC, ale granica tygodnia biegnie polską północą.
 *
 * TEN AKAPIT MÓWIŁ WCZEŚNIEJ COŚ INNEGO I BYŁO TO NIEPRAWDĄ. Twierdził,
 * że tydzień jest „ZAWSZE UTC, patrz `config/database.php`". `config/
 * database.php` nie ma klucza `timezone` dla `pgsql` i nigdy nie miał —
 * sesja brała domyślną strefę SERWERA bazy, czyli ustawienie spoza tego
 * repozytorium. Daty w testach były dobrane tak, żeby omijać granicę
 * tygodnia, więc nikt tego nie zauważył. Patrz
 * `WacLiczyTydzienWStrefieCzlowiekaTest`, który pilnuje jednego i drugiego.
 */
class WeeklyActiveCooksTest extends TestCase
{
    use RefreshDatabase;

    /** Poniedziałek. Cały tydzień testowy: 2026-08-31 – 2026-09-06. */
    private const TYDZIEN_START = '2026-08-31';

    private const TYDZIEN_KONIEC = '2026-09-06';

    /** Środek tygodnia testowego — bezpieczny moment „w tym tygodniu". */
    private function wTygodniu(string $godzina = '12:00:00'): CarbonImmutable
    {
        return CarbonImmutable::parse(self::TYDZIEN_START.' '.$godzina, 'UTC');
    }

    /**
     * Przepis do podpięcia pod „Ugotowałem", CELOWO jako szkic.
     *
     * Domyślna fabryka publikuje przepis od razu (`published_at = now()`),
     * co samo w sobie liczyłoby się jako druga akcja tego autora w tym samym
     * tygodniu testowym i fałszowałoby liczby, które te testy sprawdzają.
     */
    private function przepis(User $autor): Recipe
    {
        return Recipe::factory()->draft()->create(['author_id' => $autor->getKey()]);
    }

    private function tydzienZWyniku(Collection $tygodnie, string $weekStart): ?object
    {
        return $tygodnie->first(fn ($t) => (string) $t->week_start === $weekStart);
    }

    private function wac(): WeeklyActiveCooks
    {
        return app(WeeklyActiveCooks::class);
    }

    // ---------------------------------------------------------------
    // Kontrola: zwykłe konto się liczy
    // ---------------------------------------------------------------

    public function test_zwykle_konto_z_publikacja_w_tygodniu_liczy_sie(): void
    {
        $basia = $this->user('basia');
        Post::factory()->create([
            'author_id' => $basia->getKey(),
            'published_at' => $this->wTygodniu(),
        ]);

        $tydzien = $this->tydzienZWyniku($this->wac()->weekly(), self::TYDZIEN_START);

        $this->assertNotNull($tydzien, 'Tydzień z publikacją w ogóle nie pojawił się w wyniku.');
        $this->assertSame(1, (int) $tydzien->weekly_active_cooks);
    }

    // ---------------------------------------------------------------
    // Gospodarz
    // ---------------------------------------------------------------

    public function test_gospodarz_z_realna_publikacja_nie_podnosi_wac(): void
    {
        $basia = $this->user('basia');
        Post::factory()->create([
            'author_id' => $basia->getKey(),
            'published_at' => $this->wTygodniu(),
        ]);

        $gospodarz = $this->user('woogitsu');
        config(['kuking.community.host_username' => 'woogitsu']);
        Post::factory()->create([
            'author_id' => $gospodarz->getKey(),
            'published_at' => $this->wTygodniu('13:00:00'),
        ]);

        $tydzien = $this->tydzienZWyniku($this->wac()->weekly(), self::TYDZIEN_START);

        // Bez poprawki ten tydzień pokazuje 2 (Basia + gospodarz). To jest
        // dokładnie zniekształcenie z opisu issue #114: gospodarz publikuje
        // co tydzień z definicji, więc podnosiłby WAC co tydzień gwarantowanie.
        $this->assertSame(1, (int) $tydzien->weekly_active_cooks);
    }

    public function test_kuking_wac_w_wyjsciu_komendy_nie_liczy_gospodarza(): void
    {
        $basia = $this->user('basia');
        Post::factory()->create([
            'author_id' => $basia->getKey(),
            'published_at' => $this->wTygodniu(),
        ]);

        $gospodarz = $this->user('woogitsu');
        config(['kuking.community.host_username' => 'woogitsu']);
        Post::factory()->create([
            'author_id' => $gospodarz->getKey(),
            'published_at' => $this->wTygodniu('13:00:00'),
        ]);

        $this->artisan('kuking:wac')
            ->assertSuccessful()
            ->expectsOutputToContain(self::TYDZIEN_START.' – '.self::TYDZIEN_KONIEC.': 1');
    }

    // ---------------------------------------------------------------
    // Statusy konta
    // ---------------------------------------------------------------

    public function test_zbanowane_konto_z_aktywnoscia_sprzed_bana_w_tym_samym_tygodniu_jest_wykluczone(): void
    {
        $basia = $this->user('basia');
        Post::factory()->create([
            'author_id' => $basia->getKey(),
            'published_at' => $this->wTygodniu(),
        ]);

        $marek = $this->user('marek');
        Post::factory()->create([
            'author_id' => $marek->getKey(),
            // Aktywność PRZED banem, ale w tym samym tygodniu kalendarzowym —
            // status jest bieżący (bez znacznika czasu bana), więc to, KIEDY
            // w tygodniu opublikował, nie ma znaczenia: liczy się to, że
            // KONTO jest dziś zbanowane.
            'published_at' => $this->wTygodniu('08:00:00'),
        ]);
        $marek->status = User::STATUS_BANNED;
        $marek->save();

        $tydzien = $this->tydzienZWyniku($this->wac()->weekly(), self::TYDZIEN_START);

        $this->assertSame(1, (int) $tydzien->weekly_active_cooks);
    }

    public function test_konto_pending_delete_jest_wykluczone(): void
    {
        $basia = $this->user('basia');
        Post::factory()->create([
            'author_id' => $basia->getKey(),
            'published_at' => $this->wTygodniu(),
        ]);

        $ania = $this->user('ania', ['status' => User::STATUS_PENDING_DELETE]);
        Post::factory()->create([
            'author_id' => $ania->getKey(),
            'published_at' => $this->wTygodniu('09:00:00'),
        ]);

        $tydzien = $this->tydzienZWyniku($this->wac()->weekly(), self::TYDZIEN_START);

        $this->assertSame(1, (int) $tydzien->weekly_active_cooks);
    }

    // ---------------------------------------------------------------
    // Konta testowe (config, nie kolumna — patrz config/kuking.php)
    // ---------------------------------------------------------------

    public function test_konto_z_listy_testowej_jest_wykluczone(): void
    {
        $basia = $this->user('basia');
        Post::factory()->create([
            'author_id' => $basia->getKey(),
            'published_at' => $this->wTygodniu(),
        ]);

        $testowe = $this->user('qa_wewnetrzne');
        config(['kuking.account.test_usernames' => ['qa_wewnetrzne']]);
        Post::factory()->create([
            'author_id' => $testowe->getKey(),
            'published_at' => $this->wTygodniu('10:00:00'),
        ]);

        $tydzien = $this->tydzienZWyniku($this->wac()->weekly(), self::TYDZIEN_START);

        $this->assertSame(1, (int) $tydzien->weekly_active_cooks);
    }

    // ---------------------------------------------------------------
    // Konta zalążkowe (`users.is_seeded`, D-025) — po kolumnie, nie po
    // liście nazw w configu jak konta testowe wyżej.
    // ---------------------------------------------------------------

    public function test_konto_zalazkowe_jest_wykluczone(): void
    {
        $basia = $this->user('basia');
        Post::factory()->create([
            'author_id' => $basia->getKey(),
            'published_at' => $this->wTygodniu(),
        ]);

        $persona = $this->user('halina', ['is_seeded' => true]);
        Post::factory()->create([
            'author_id' => $persona->getKey(),
            'published_at' => $this->wTygodniu('11:00:00'),
        ]);

        $tydzien = $this->tydzienZWyniku($this->wac()->weekly(), self::TYDZIEN_START);

        // Bez wykluczenia ten tydzień pokazuje 2 (Basia + persona) — dokładnie
        // to zniekształcenie, przed którym `CookEligibility` już chroni dla
        // gospodarza i kont testowych (issue #114).
        $this->assertSame(1, (int) $tydzien->weekly_active_cooks);
    }

    // ---------------------------------------------------------------
    // Definicja WAC (kontrola: poprawka nie psuje tego, co już działało)
    // ---------------------------------------------------------------

    public function test_kazda_z_trzech_akcji_kwalifikuje_osobno(): void
    {
        $autorkaPostu = $this->user('od_postu');
        Post::factory()->create([
            'author_id' => $autorkaPostu->getKey(),
            'published_at' => $this->wTygodniu('08:00:00'),
        ]);

        $autorkaPrzepisu = $this->user('od_przepisu');
        Recipe::factory()->create([
            'author_id' => $autorkaPrzepisu->getKey(),
            'published_at' => $this->wTygodniu('09:00:00'),
        ]);

        $gotujaca = $this->user('od_ugotowalem');
        $przepis = $this->przepis($this->user('autor_cudzego_przepisu'));
        CookedEvent::factory()->create([
            'user_id' => $gotujaca->getKey(),
            'recipe_id' => $przepis->getKey(),
            'cooked_at' => $this->wTygodniu('10:00:00'),
        ]);

        $tydzien = $this->tydzienZWyniku($this->wac()->weekly(), self::TYDZIEN_START);

        $this->assertSame(3, (int) $tydzien->weekly_active_cooks);
    }

    public function test_soft_delete_owany_post_nie_liczy_sie(): void
    {
        $basia = $this->user('basia');
        $post = Post::factory()->create([
            'author_id' => $basia->getKey(),
            'published_at' => $this->wTygodniu(),
        ]);
        $post->delete();

        $tydzien = $this->tydzienZWyniku($this->wac()->weekly(), self::TYDZIEN_START);

        $this->assertNull($tydzien, 'Tydzień z wyłącznie skasowanym wpisem nie powinien w ogóle wystąpić.');
    }

    public function test_ta_sama_osoba_z_trzema_akcjami_w_tygodniu_liczy_sie_raz(): void
    {
        $basia = $this->user('basia');

        Post::factory()->create([
            'author_id' => $basia->getKey(),
            'published_at' => $this->wTygodniu('08:00:00'),
        ]);
        Recipe::factory()->create([
            'author_id' => $basia->getKey(),
            'published_at' => $this->wTygodniu('09:00:00'),
        ]);
        $przepis = $this->przepis($this->user('ktos_inny'));
        CookedEvent::factory()->create([
            'user_id' => $basia->getKey(),
            'recipe_id' => $przepis->getKey(),
            'cooked_at' => $this->wTygodniu('10:00:00'),
        ]);

        $tydzien = $this->tydzienZWyniku($this->wac()->weekly(), self::TYDZIEN_START);

        $this->assertSame(1, (int) $tydzien->weekly_active_cooks);
    }

    // ---------------------------------------------------------------
    // Granica tygodnia
    // ---------------------------------------------------------------

    public function test_granica_tygodnia_niedziela_i_poniedzialek_licza_sie_osobno(): void
    {
        // Granica biegnie polską północą, więc momenty są dobrane w POLSKIEJ
        // strefie i dopiero przeliczone na UTC. Wrzesień to czas letni (CEST,
        // UTC+2), stąd przesunięcie o dwie godziny wstecz.
        $wNiedziele = $this->user('w_niedziele');
        Post::factory()->create([
            'author_id' => $wNiedziele->getKey(),
            // Niedziela 6 września, 23:59:59 czasu polskiego.
            'published_at' => CarbonImmutable::parse('2026-09-06 21:59:59', 'UTC'),
        ]);

        $wPoniedzialek = $this->user('w_poniedzialek');
        Post::factory()->create([
            'author_id' => $wPoniedzialek->getKey(),
            // Poniedziałek 7 września, 00:00:00 czasu polskiego — sekundę
            // później, a już w kolejnym tygodniu.
            'published_at' => CarbonImmutable::parse('2026-09-06 22:00:00', 'UTC'),
        ]);

        $tygodnie = $this->wac()->weekly();

        $tenTydzien = $this->tydzienZWyniku($tygodnie, self::TYDZIEN_START);
        $nastepnyTydzien = $this->tydzienZWyniku($tygodnie, '2026-09-07');

        $this->assertNotNull($tenTydzien);
        $this->assertNotNull($nastepnyTydzien);
        $this->assertSame(1, (int) $tenTydzien->weekly_active_cooks);
        $this->assertSame(1, (int) $nastepnyTydzien->weekly_active_cooks);
        $this->assertNotSame((string) $tenTydzien->week_start, (string) $nastepnyTydzien->week_start);
    }

    // ---------------------------------------------------------------
    // Kohorta retencji (§3.2) — to samo wykluczenie
    // ---------------------------------------------------------------

    public function test_wykluczenie_dziala_takze_w_zapytaniu_kohortowym(): void
    {
        $basia = $this->user('basia', ['created_at' => $this->wTygodniu('06:00:00')]);
        Post::factory()->create([
            'author_id' => $basia->getKey(),
            'published_at' => $this->wTygodniu('12:00:00'),
        ]);

        $marek = $this->user('marek', ['created_at' => $this->wTygodniu('06:00:00')]);
        Post::factory()->create([
            'author_id' => $marek->getKey(),
            'published_at' => $this->wTygodniu('12:30:00'),
        ]);
        $marek->status = User::STATUS_BANNED;
        $marek->save();

        $kohorty = app(CookRetentionCohorts::class)->weekly();

        $tydzienZero = $kohorty->first(
            fn ($w) => (string) $w->signup_week === self::TYDZIEN_START && (int) $w->week_offset === 0,
        );

        $this->assertNotNull($tydzienZero, 'Kohorta tygodnia rejestracji w ogóle nie wystąpiła.');
        $this->assertSame(1, (int) $tydzienZero->active_users);
    }
}
