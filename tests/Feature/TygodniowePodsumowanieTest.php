<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\ZapiszSygnal;
use App\Domain\Digest\OdnosnikWypisania;
use App\Domain\Digest\ZbierzTresciDigestu;
use App\Mail\PodsumowanieTygodnia;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\ProductSignal;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Tygodniowe podsumowanie — reguły, których nie wolno złamać (issue #11,
 * `docs/DECISIONS.md` D-057).
 *
 * Ten plik pilnuje SIEDMIU rzeczy i każda z nich jest obietnicą złożoną
 * człowiekowi albo dostawcy poczty:
 *
 *  1. bez zgody nie idzie nic;
 *  2. pusty tydzień = brak listu („lepiej nic niż e-mail o niczym");
 *  3. konto niebędące czynnym nie dostaje poczty;
 *  4. drugie uruchomienie tego samego dnia nie wysyła drugi raz;
 *  5. dzienny limit listów jest respektowany i liczony na dobę, nie na przebieg;
 *  6. listy wychodzą rozłożone w czasie, nie wszystkie w jednej minucie;
 *  7. wyłącznik `KUKING_DIGEST_WLACZONY` naprawdę wyłącza.
 *
 * Wypisanie się ma własny plik (`WypisanieZPodsumowaniaTest`), prywatność
 * treści też (`PodsumowanieSzanujePrywatnoscTest`).
 */
class TygodniowePodsumowanieTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('kuking.digest.wlaczony', true);
        config()->set('kuking.digest.dzienny_limit', 60);
        config()->set('kuking.digest.odstep_dni', 7);
        config()->set('kuking.digest.okno_dni', 7);
        config()->set('kuking.digest.odstep_sekund', 20);
        config()->set('kuking.digest.max_pozycji', 3);
    }

    /**
     * Osoba, której PRZEPIS ktoś w tym tygodniu ugotował — czyli ktoś, kto
     * ma realny powód dostać list.
     */
    private function autorZWykonaniem(string $username, array $atrybuty = []): User
    {
        $autor = $this->user($username, $atrybuty);
        $kucharz = $this->user($username.'_kucharz');

        $przepis = Recipe::factory()->for($autor, 'author')->create();
        CookedEvent::factory()->for($kucharz, 'user')->for($przepis, 'recipe')->create([
            'cooked_at' => now()->subDay(),
        ]);

        return $autor;
    }

    // -----------------------------------------------------------------
    // 1. Zgoda
    // -----------------------------------------------------------------

    public function test_bez_zgody_nie_idzie_nic(): void
    {
        Mail::fake();

        $this->autorZWykonaminemBezZgody();

        Artisan::call('kuking:wyslij-podsumowania');

        Mail::assertNothingQueued();
    }

    private function autorZWykonaminemBezZgody(): User
    {
        return $this->autorZWykonaniem('bez_zgody', ['wants_weekly_digest' => false]);
    }

    public function test_ze_zgoda_i_z_trescia_list_wychodzi(): void
    {
        Mail::fake();

        $autor = $this->autorZWykonaniem('ze_zgoda');

        Artisan::call('kuking:wyslij-podsumowania');

        Mail::assertQueued(
            PodsumowanieTygodnia::class,
            fn (PodsumowanieTygodnia $list) => $list->hasTo($autor->email),
        );
    }

    // -----------------------------------------------------------------
    // 2. Pusty tydzień
    // -----------------------------------------------------------------

    public function test_pusty_tydzien_nie_konczy_sie_zadnym_listem(): void
    {
        Mail::fake();

        // Zgoda jest, konto czynne, adres potwierdzony — brakuje tylko
        // jednego: żeby się cokolwiek wydarzyło.
        $this->user('cisza');

        Artisan::call('kuking:wyslij-podsumowania');

        Mail::assertNothingQueued();
    }

    public function test_zdarzenie_sprzed_okna_nie_jest_powodem_do_listu(): void
    {
        Mail::fake();

        $autor = $this->user('stare_wykonanie');
        $kucharz = $this->user('stary_kucharz');
        $przepis = Recipe::factory()->for($autor, 'author')->create();

        // Dwa tygodnie temu — poza oknem `okno_dni`. Ten list byłby
        // opowieścią o czymś, o czym autor dawno wie z powiadomienia.
        CookedEvent::factory()->for($kucharz, 'user')->for($przepis, 'recipe')->create([
            'cooked_at' => now()->subDays(14),
        ]);

        Artisan::call('kuking:wyslij-podsumowania');

        Mail::assertNothingQueued();
    }

    public function test_samo_pytanie_gospodarza_nie_wystarcza_do_wyslania(): void
    {
        Mail::fake();

        config()->set('kuking.digest.pytanie', 'Co kisisz w tym tygodniu?');

        $this->user('tylko_pytanie');

        Artisan::call('kuking:wyslij-podsumowania');

        // Pytanie jest jedno dla wszystkich i takie samo co tydzień. Gdyby
        // wystarczało, serwis rozsyłałby pięciuset osobom to samo zdanie
        // i nazywał je podsumowaniem.
        Mail::assertNothingQueued();
    }

    public function test_sami_nowi_obserwujacy_wystarczaja_do_wyslania(): void
    {
        Mail::fake();

        $autor = $this->user('obserwowany');
        $nowy = $this->user('nowy_obserwujacy');
        $nowy->following()->attach($autor->getKey(), ['created_at' => now()->subDay()]);

        Artisan::call('kuking:wyslij-podsumowania');

        Mail::assertQueued(
            PodsumowanieTygodnia::class,
            fn (PodsumowanieTygodnia $list) => $list->hasTo($autor->email),
        );
    }

    // -----------------------------------------------------------------
    // 3. Stan konta
    // -----------------------------------------------------------------

    /**
     * Issue #11 wymienia trzy statusy; `erased` dochodzi z D-022, bo tam
     * adres e-mail jest już zanonimizowany.
     */
    public function test_konta_niebedace_czynnymi_nie_dostaja_poczty(): void
    {
        Mail::fake();

        foreach ([User::STATUS_SUSPENDED, User::STATUS_BANNED, User::STATUS_PENDING_DELETE] as $i => $status) {
            $this->autorZWykonaniem('status_'.$i, ['status' => $status]);
        }

        // `erased` osobno: baza wymusza równoważność
        // `(data_erased_at IS NOT NULL) = (status = 'erased')` (D-022), więc
        // tego stanu nie da się ustawić samym statusem — i dobrze.
        $this->autorZWykonaniem('status_erased', [
            'status' => User::STATUS_ERASED,
            'data_erased_at' => now(),
        ]);

        Artisan::call('kuking:wyslij-podsumowania');

        Mail::assertNothingQueued();
    }

    public function test_niepotwierdzony_adres_nie_dostaje_poczty(): void
    {
        Mail::fake();

        $this->autorZWykonaniem('niepotwierdzony', ['email_verified_at' => null]);

        Artisan::call('kuking:wyslij-podsumowania');

        Mail::assertNothingQueued();
    }

    // -----------------------------------------------------------------
    // 4. Powtórne uruchomienie
    // -----------------------------------------------------------------

    public function test_drugie_uruchomienie_tego_samego_dnia_nie_wysyla_drugi_raz(): void
    {
        Mail::fake();

        $autor = $this->autorZWykonaniem('raz_na_tydzien');

        Artisan::call('kuking:wyslij-podsumowania');
        Artisan::call('kuking:wyslij-podsumowania');
        Artisan::call('kuking:wyslij-podsumowania');

        Mail::assertQueuedCount(1);
        $this->assertNotNull($autor->fresh()->weekly_digest_sent_at);
    }

    public function test_po_tygodniu_list_idzie_znowu(): void
    {
        Mail::fake();

        $autor = $this->autorZWykonaniem('za_tydzien');

        Artisan::call('kuking:wyslij-podsumowania');
        Mail::assertQueuedCount(1);

        // Ósmy dzień: odstęp minął, a wykonanie jest świeże.
        $this->travel(8)->days();
        CookedEvent::query()->update(['cooked_at' => now()->subDay()]);

        Artisan::call('kuking:wyslij-podsumowania');
        Mail::assertQueuedCount(2);
    }

    // -----------------------------------------------------------------
    // 5. Limit tempa
    // -----------------------------------------------------------------

    public function test_dzienny_limit_zatrzymuje_wysylke(): void
    {
        Mail::fake();

        config()->set('kuking.digest.dzienny_limit', 2);

        for ($i = 0; $i < 5; $i++) {
            $this->autorZWykonaniem('limit_'.$i);
        }

        Artisan::call('kuking:wyslij-podsumowania');

        Mail::assertQueuedCount(2);

        // Asercja kontrolna: pozostała trójka NIE dostała znacznika, więc
        // jutro jest pierwsza w kolejce, a nie zapomniana.
        $this->assertSame(
            2,
            User::query()->whereNotNull('weekly_digest_sent_at')->count(),
            'Znacznik dostał ktoś, komu nie wysłano listu — albo odwrotnie.',
        );
    }

    /**
     * Limit jest DOBOWY, nie „na przebieg" — inaczej dwa uruchomienia
     * (ręczne po awarii, restart kontenera) podwoiłyby dzienną wysyłkę
     * i przebiłyby limit dostawcy.
     */
    public function test_limit_liczy_sie_na_dobe_a_nie_na_przebieg(): void
    {
        Mail::fake();

        config()->set('kuking.digest.dzienny_limit', 2);

        for ($i = 0; $i < 6; $i++) {
            $this->autorZWykonaniem('doba_'.$i);
        }

        Artisan::call('kuking:wyslij-podsumowania');
        Artisan::call('kuking:wyslij-podsumowania');
        Artisan::call('kuking:wyslij-podsumowania');

        Mail::assertQueuedCount(2);

        // Nazajutrz budżet wraca.
        $this->travel(1)->day();
        CookedEvent::query()->update(['cooked_at' => now()->subHours(2)]);

        Artisan::call('kuking:wyslij-podsumowania');

        Mail::assertQueuedCount(4);
    }

    public function test_listy_wychodza_rozlozone_w_czasie(): void
    {
        Mail::fake();

        config()->set('kuking.digest.odstep_sekund', 20);

        for ($i = 0; $i < 3; $i++) {
            $this->autorZWykonaniem('tempo_'.$i);
        }

        Artisan::call('kuking:wyslij-podsumowania');

        $opoznienia = [];

        Mail::assertQueued(PodsumowanieTygodnia::class, function (PodsumowanieTygodnia $list) use (&$opoznienia): bool {
            $opoznienia[] = $list->delay;

            return true;
        });

        $this->assertCount(3, $opoznienia);

        // Trzy listy w jednej minucie to dla dostawcy sygnał spamowy
        // (`docs/decyzje/POCZTA.md` §5 pkt 5). Ostatni ma wyjść co najmniej
        // czterdzieści sekund po pierwszym.
        $najwieksze = collect($opoznienia)->max();
        $this->assertNotNull($najwieksze, 'Listy poszły bez żadnego opóźnienia — limit tempa nie działa.');
        $this->assertTrue(
            $najwieksze->greaterThanOrEqualTo(now()->addSeconds(39)),
            'Ostatni list z paczki nie jest odsunięty w czasie — cała paczka poszłaby w jednej minucie.',
        );
    }

    // -----------------------------------------------------------------
    // 6. Wyłącznik i sygnały
    // -----------------------------------------------------------------

    public function test_jedna_zmienna_wylacza_calosc(): void
    {
        Mail::fake();

        config()->set('kuking.digest.wlaczony', false);

        $this->autorZWykonaniem('wylaczone');

        Artisan::call('kuking:wyslij-podsumowania');

        Mail::assertNothingQueued();
        $this->assertNull(User::query()->whereNotNull('weekly_digest_sent_at')->first());
    }

    public function test_wyslanie_zostawia_sygnal_bez_danych_osobowych(): void
    {
        Mail::fake();

        $this->autorZWykonaniem('sygnal');

        Artisan::call('kuking:wyslij-podsumowania');

        $sygnal = ProductSignal::query()
            ->where('signal_name', ZapiszSygnal::WEEKLY_DIGEST_SENT)
            ->firstOrFail();

        $this->assertEqualsCanonicalizing(
            ['wykonania' => 1, 'nowi_obserwujacy' => 0, 'wpisy' => 0],
            $sygnal->properties,
        );

        // Żadnej treści, żadnego adresu, żadnej nazwy — AGENTS.md §7.
        $zapis = json_encode($sygnal->properties, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('@', $zapis);
    }

    public function test_na_sucho_nic_nie_wysyla_i_nic_nie_zapisuje(): void
    {
        Mail::fake();

        $autor = $this->autorZWykonaniem('na_sucho');

        Artisan::call('kuking:wyslij-podsumowania', ['--na-sucho' => true]);

        Mail::assertNothingQueued();
        $this->assertNull($autor->fresh()->weekly_digest_sent_at);
        $this->assertSame(0, ProductSignal::query()->count());
    }

    // -----------------------------------------------------------------
    // 7. Treść listu
    // -----------------------------------------------------------------

    public function test_temat_niesie_imie_i_nazwe_potrawy_bez_formy_zaleznej_od_rodzaju(): void
    {
        Mail::fake();

        $autor = $this->user('temat_autor');
        $kucharz = $this->user('temat_kucharz', ['display_name' => 'Halina']);
        $przepis = Recipe::factory()->for($autor, 'author')->create(['title' => 'Rosół na niedzielę']);
        CookedEvent::factory()->for($kucharz, 'user')->for($przepis, 'recipe')->create([
            'cooked_at' => now()->subHour(),
        ]);

        Artisan::call('kuking:wyslij-podsumowania');

        Mail::assertQueued(PodsumowanieTygodnia::class, function (PodsumowanieTygodnia $list): bool {
            $temat = $list->envelope()->subject;

            $this->assertStringContainsString('Halina', (string) $temat);
            $this->assertStringContainsString('Rosół na niedzielę', (string) $temat);

            // Bez „ugotowała"/„ugotował" — serwis nie zna płci kucharza
            // i nigdy jej nie pozna (`polityka-prywatnosci.md` §2).
            $this->assertStringNotContainsString('ugotował', (string) $temat);

            return true;
        });
    }

    public function test_list_niesie_naglowek_wypisania_dla_gmaila_i_outlooka(): void
    {
        Mail::fake();

        $this->autorZWykonaniem('naglowek');

        Artisan::call('kuking:wyslij-podsumowania');

        Mail::assertQueued(PodsumowanieTygodnia::class, function (PodsumowanieTygodnia $list): bool {
            $naglowki = $list->headers()->text;

            $this->assertArrayHasKey('List-Unsubscribe', $naglowki);
            $this->assertStringContainsString('podsumowanie/wypisz', $naglowki['List-Unsubscribe']);
            $this->assertStringContainsString('signature=', $naglowki['List-Unsubscribe']);
            $this->assertSame('List-Unsubscribe=One-Click', $naglowki['List-Unsubscribe-Post']);

            return true;
        });
    }

    public function test_list_ma_wersje_tekstowa_i_odnosnik_wypisania_w_obu(): void
    {
        $autor = $this->autorZWykonaniem('obie_wersje');

        $tresc = app(ZbierzTresciDigestu::class)->dlaJednej($autor->fresh());
        $list = new PodsumowanieTygodnia($tresc);

        $html = $list->render();

        // `render()` oddaje wariant HTML; wersję tekstową składamy osobno,
        // przez ten sam widok, którego używa `Content::text`.
        $tekst = view('mail.podsumowanie-tygodnia-tekst', [
            'tresc' => $tresc,
            'imie' => $autor->displayName(),
            'gospodarz' => config('kuking.community.host_name'),
            'wypisz' => OdnosnikWypisania::dla($autor),
        ])->render();

        foreach ([$html, $tekst] as $wariant) {
            $this->assertStringContainsString('podsumowanie/wypisz', $wariant);
        }

        // Wersja tekstowa musi nieść PEŁNY adres, nie słowo z odnośnikiem —
        // w zwykłym tekście nie ma czego kliknąć poza tym, co widać.
        $this->assertStringContainsString('signature=', $tekst);
    }

    public function test_list_pokazuje_co_pokazali_obserwowani(): void
    {
        Mail::fake();

        $odbiorca = $this->user('czytelnik_feedu');
        $obserwowany = $this->user('gotujaca');
        $odbiorca->following()->attach($obserwowany->getKey(), ['created_at' => now()->subMonth()]);

        Post::factory()->for($obserwowany, 'author')->create([
            'body' => 'Placki ziemniaczane z sosem grzybowym',
            'published_at' => now()->subDay(),
        ]);

        Artisan::call('kuking:wyslij-podsumowania');

        Mail::assertQueued(PodsumowanieTygodnia::class, function (PodsumowanieTygodnia $list) use ($odbiorca): bool {
            if (! $list->hasTo($odbiorca->email)) {
                return false;
            }

            $this->assertStringContainsString('Placki ziemniaczane', $list->render());

            return true;
        });
    }
}
