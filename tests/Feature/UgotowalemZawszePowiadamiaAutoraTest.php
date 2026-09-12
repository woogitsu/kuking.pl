<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Domain\Social\Actions\BlockUser;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * „«Ugotowałem» jest ważniejsze niż lajk i ZAWSZE powiadamia autora przepisu"
 * (`AGENTS.md` §1).
 *
 * Słowo „zawsze" jest tu całą treścią obietnicy, a obietnica działająca
 * w większości ścieżek nie działa. Ten plik pilnuje jej na KAŻDEJ ścieżce,
 * którą w tym serwisie powstaje wykonanie przepisu, i rozstrzyga przypadki
 * brzegowe, w których „zawsze" ma świadomy, nazwany wyjątek.
 *
 * ŚCIEŻKI POWSTAWANIA WYKONANIA — PEŁNA LISTA (zmierzona, nie założona)
 * ---------------------------------------------------------------------
 * 1. HTTP: `POST /przepisy/{slug}/ugotowalem` → `CookedEventController::store`.
 * 2. Domenowa: `RecordCookedEvent::handle()` wołane wprost — tędy pójdzie
 *    każde przyszłe polecenie konsolowe, zadanie w kolejce albo import.
 * 3. `Database\Seeders\DemoSeeder` — dane demonstracyjne do pracy lokalnej
 *    i do automatu dostępności.
 * 4. `Database\Factories\CookedEventFactory` — wyłącznie testy; fabryka
 *    celowo NIE powiadamia nikogo, bo test ma sam decydować, co istnieje.
 *
 * DLACZEGO WYJĄTKI OD „ZAWSZE" SĄ WYPISANE TUTAJ, A NIE TYLKO W KODZIE
 * `NotifyUser` ma trzy powody, dla których powiadomienie nie powstaje
 * (własna akcja, blokada, konto zamknięte). Każdy z nich jest słuszny
 * i każdy jest zwężeniem słowa „zawsze" — więc każdy ma tu własny test
 * z KONTROLĄ DODATNIĄ obok (pułapka 4 z `docs/PULAPKI_TESTOW.md`):
 * asercja „powiadomienia nie ma" przechodzi także wtedy, gdy powiadomień
 * nie ma nigdzie, więc sama w sobie nie dowodzi niczego.
 */
class UgotowalemZawszePowiadamiaAutoraTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Powiadomienia o ugotowaniu, jakie dostał ten człowiek.
     *
     * @return Collection<int, Notification>
     */
    private function powiadomieniaOUgotowaniu(User $odbiorca)
    {
        return Notification::query()
            ->where('user_id', $odbiorca->getKey())
            ->where('type', Notification::TYPE_COOKED)
            ->get();
    }

    // -----------------------------------------------------------------
    // ŚCIEŻKA 1: formularz „Ugotowałem"
    // -----------------------------------------------------------------

    public function test_sciezka_http_powiadamia_autora_przepisu(): void
    {
        $autor = $this->user('autor_http');
        $kucharz = $this->user('kucharz_http');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $this->actingAs($kucharz)
            ->post(route('cooked.store', $recipe->slug), ['note' => 'Wyszło.'])
            ->assertRedirect();

        $powiadomienia = $this->powiadomieniaOUgotowaniu($autor);

        $this->assertCount(1, $powiadomienia, 'Formularz „Ugotowałem" nie powiadomił autora przepisu.');
        $this->assertSame($kucharz->getKey(), $powiadomienia->first()->actor_id);
        $this->assertSame(1, CookedEvent::query()->count());
    }

    // -----------------------------------------------------------------
    // ŚCIEŻKA 2: akcja domenowa wołana wprost
    // -----------------------------------------------------------------

    public function test_sciezka_domenowa_powiadamia_autora_przepisu(): void
    {
        $autor = $this->user('autor_akcja');
        $kucharz = $this->user('kucharz_akcja');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        app(RecordCookedEvent::class)->handle($kucharz, $recipe);

        $this->assertCount(
            1,
            $this->powiadomieniaOUgotowaniu($autor),
            'Akcja domenowa zapisała wykonanie bez powiadomienia autora.',
        );
    }

    /**
     * Powiadomienie nie jest odłożone do kolejki — powstaje w tej samej
     * transakcji, co wykonanie. Gdyby szło zadaniem w tle, „zawsze" zależałoby
     * od tego, czy ktoś uruchomił workera; przy `QUEUE_CONNECTION=database`
     * (stack z `AGENTS.md` §3) niedziałająca kolejka cicho zjadałaby
     * najcenniejsze powiadomienie w serwisie.
     */
    public function test_powiadomienie_nie_czeka_na_kolejke(): void
    {
        Queue::fake();

        $autor = $this->user('autor_kolejka');
        $kucharz = $this->user('kucharz_kolejka');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        app(RecordCookedEvent::class)->handle($kucharz, $recipe);

        Queue::assertNothingPushed();
        $this->assertCount(
            1,
            $this->powiadomieniaOUgotowaniu($autor),
            'Powiadomienie nie powstało bez uruchomionej kolejki.',
        );
    }

    // -----------------------------------------------------------------
    // ŚCIEŻKA 3: dane demonstracyjne
    // -----------------------------------------------------------------

    /**
     * Każde wykonanie z danych demonstracyjnych ma swoje powiadomienie.
     *
     * DLACZEGO TO NIE JEST CZEPIANIE SIĘ SEEDERA. Na danych demo chodzi
     * automat dostępności i na nich pracuje się lokalnie — to jest obraz
     * serwisu, który człowiek ogląda, zanim zobaczy produkcję. Seeder, który
     * zapisuje wykonanie bez powiadomienia, pokazuje serwis, w którym
     * obietnica z `AGENTS.md` §1 nie obowiązuje, i uczy tego każdego, kto
     * z tych danych korzysta.
     *
     * Asercja jest na PARZE (wykonanie → powiadomienie), nie na samej liczbie
     * powiadomień: liczba przechodzi też wtedy, gdy powiadomienia są, ale nie
     * te.
     */
    public function test_dane_demonstracyjne_nie_zostawiaja_wykonania_bez_powiadomienia(): void
    {
        Storage::fake('public');

        $this->seed(DemoSeeder::class);

        $wykonania = CookedEvent::with('recipe')->get();

        $this->assertGreaterThan(
            0,
            $wykonania->count(),
            'Seeder nie zapisał ani jednego wykonania — test nie mierzy niczego.',
        );

        foreach ($wykonania as $wykonanie) {
            // Własne wykonanie autora nie ma powiadamiać — patrz test niżej.
            if ($wykonanie->user_id === $wykonanie->recipe->author_id) {
                continue;
            }

            $this->assertTrue(
                Notification::query()
                    ->where('user_id', $wykonanie->recipe->author_id)
                    ->where('actor_id', $wykonanie->user_id)
                    ->where('type', Notification::TYPE_COOKED)
                    ->where('data->cooked_event_id', $wykonanie->getKey())
                    ->exists(),
                'Wykonanie z danych demonstracyjnych nie powiadomiło autora przepisu: '.$wykonanie->getKey(),
            );
        }
    }

    // -----------------------------------------------------------------
    // PRZYPADKI BRZEGOWE
    // -----------------------------------------------------------------

    /**
     * WŁASNY PRZEPIS: powiadomienia nie ma i tak ma być.
     *
     * `RecipePolicy::cook()` celowo pozwala ugotować własny przepis („ludzie
     * realnie gotują swoje przepisy i chcą mieć ślad"). Powiadomienie
     * o własnej akcji nie niesie jednak żadnej informacji — `NotifyUser`
     * odcina je pierwszą regułą. To jedyne zwężenie słowa „zawsze", które nie
     * wynika ze stanu konta ani z blokady.
     *
     * KONTROLA DODATNIA W TYM SAMYM TEŚCIE: ten sam przepis ugotowany przez
     * KOGOŚ INNEGO powiadomienie daje. Bez niej asercja przeszłaby również
     * wtedy, gdyby powiadomienia nie powstawały w ogóle.
     */
    public function test_wlasne_wykonanie_nie_powiadamia_a_cudze_powiadamia(): void
    {
        $autor = $this->user('autor_wlasne');
        $ktos_inny = $this->user('ktos_inny');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        app(RecordCookedEvent::class)->handle($autor, $recipe);

        $this->assertCount(
            0,
            $this->powiadomieniaOUgotowaniu($autor),
            'Autor dostał powiadomienie o własnym wykonaniu.',
        );

        app(RecordCookedEvent::class)->handle($ktos_inny, $recipe);

        $this->assertCount(
            1,
            $this->powiadomieniaOUgotowaniu($autor),
            'Kontrola dodatnia: cudze wykonanie tego samego przepisu powiadomienia nie dało.',
        );
        $this->assertSame(2, CookedEvent::query()->count(), 'Oba wykonania mają zostać zapisane.');
    }

    /**
     * BLOKADA: wykonanie w ogóle nie powstaje, więc nie ma czego powiadamiać.
     *
     * Blokada liczy się w obie strony (`RecipePolicy::view()` →
     * `User::hasBlockRelationWith()`), więc test sprawdza OBIE, osobno.
     * Każdy kierunek ma własny sabotaż i własną kontrolę: pułapka 3b
     * („każda gałąź warunku potrzebuje własnego testu").
     *
     * KONTROLA DODATNIA: trzecia osoba, bez blokady, gotuje ten sam przepis
     * i powiadomienie dostaje.
     */
    public function test_blokada_w_obie_strony_nie_dopuszcza_wykonania(): void
    {
        $autor = $this->user('autor_blokada');
        $kucharz_zablokowany = $this->user('kucharz_zablokowany');
        $kucharz_blokujacy = $this->user('kucharz_blokujacy');
        $kucharz_bez_blokady = $this->user('kucharz_bez_blokady');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        // Kierunek 1: autor zablokował kucharza.
        app(BlockUser::class)->handle($autor, $kucharz_zablokowany);

        try {
            app(RecordCookedEvent::class)->handle($kucharz_zablokowany->fresh(), $recipe->fresh());
            $this->fail('Zablokowana osoba zapisała wykonanie cudzego przepisu.');
        } catch (BladDlaCzlowieka) {
            // Tak ma być.
        }

        // Kierunek 2: kucharz zablokował autora.
        app(BlockUser::class)->handle($kucharz_blokujacy, $autor);

        try {
            app(RecordCookedEvent::class)->handle($kucharz_blokujacy->fresh(), $recipe->fresh());
            $this->fail('Osoba, która zablokowała autora, zapisała wykonanie jego przepisu.');
        } catch (BladDlaCzlowieka) {
            // Tak ma być.
        }

        $this->assertSame(0, CookedEvent::query()->count());
        $this->assertCount(0, $this->powiadomieniaOUgotowaniu($autor));

        // KONTROLA DODATNIA.
        app(RecordCookedEvent::class)->handle($kucharz_bez_blokady, $recipe->fresh());

        $this->assertSame(1, CookedEvent::query()->count());
        $this->assertCount(
            1,
            $this->powiadomieniaOUgotowaniu($autor),
            'Kontrola dodatnia: wykonanie bez blokady też nie dało powiadomienia.',
        );
    }

    /**
     * AUTOR ZAWIESZONY: powiadomienie JEST.
     *
     * Zawieszenie to kara za PISANIE, nie odcięcie od serwisu
     * (`User::mozeCzytac()`, `EnsureAccountIsActive` — „dostęp tylko do
     * odczytu"). Człowiek odsiadujący karę dalej czyta serwis i dalej ma
     * prawo wiedzieć, że ktoś ugotował z jego przepisu. To jest dokładnie ta
     * wiadomość, dla której warto wrócić.
     */
    public function test_autor_zawieszony_dostaje_powiadomienie(): void
    {
        $autor = $this->user('autor_zawieszony');
        $kucharz = $this->user('kucharz_przy_zawieszonym');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $autor->suspend(now()->addDays(7));

        app(RecordCookedEvent::class)->handle($kucharz, $recipe->fresh());

        $this->assertCount(
            1,
            $this->powiadomieniaOUgotowaniu($autor),
            'Zawieszenie odcina od pisania, nie od wiadomości „ktoś ugotował Twój przepis".',
        );
    }

    /**
     * AUTOR ZBANOWANY albo OZNACZONY DO USUNIĘCIA: przepisu nie da się już
     * ugotować, więc nie powstaje ani wykonanie, ani powiadomienie.
     *
     * `User::jestDostepnyJakoAutor()` ukrywa treść takiego konta
     * (`RecipePolicy::view()`), a `NotifyUser` i tak nie wysłałby wiadomości
     * do konta, którego nie da się otworzyć. Obie warstwy mówią to samo i obie
     * są tu zmierzone: najpierw brak wykonania, potem kontrola dodatnia.
     */
    public function test_autor_zbanowany_albo_do_usuniecia_nie_ma_przepisu_do_ugotowania(): void
    {
        $zbanowany = $this->user('autor_zbanowany');
        $do_usuniecia = $this->user('autor_do_usuniecia');
        $zywy = $this->user('autor_zywy');
        $kucharz = $this->user('kucharz_przy_zamknietych');

        $przepis_zbanowanego = Recipe::factory()->create(['author_id' => $zbanowany->getKey()]);
        $przepis_do_usuniecia = Recipe::factory()->create(['author_id' => $do_usuniecia->getKey()]);
        $przepis_zywego = Recipe::factory()->create(['author_id' => $zywy->getKey()]);

        $zbanowany->ban();
        $do_usuniecia->markForDeletion();

        foreach ([$przepis_zbanowanego, $przepis_do_usuniecia] as $przepis) {
            try {
                app(RecordCookedEvent::class)->handle($kucharz->fresh(), $przepis->fresh());
                $this->fail('Zapisało się wykonanie przepisu konta zamkniętego: '.$przepis->getKey());
            } catch (BladDlaCzlowieka) {
                // Tak ma być.
            }
        }

        $this->assertSame(0, CookedEvent::query()->count());
        $this->assertCount(0, $this->powiadomieniaOUgotowaniu($zbanowany));
        $this->assertCount(0, $this->powiadomieniaOUgotowaniu($do_usuniecia));

        // KONTROLA DODATNIA: ten sam kucharz, przepis żywego autora.
        app(RecordCookedEvent::class)->handle($kucharz->fresh(), $przepis_zywego);

        $this->assertCount(
            1,
            $this->powiadomieniaOUgotowaniu($zywy),
            'Kontrola dodatnia: wykonanie przepisu aktywnego autora też nie dało powiadomienia.',
        );
    }

    /**
     * AUTOR Z WYMAZANYM KONTEM (`erased`): wykonanie zapisujemy, powiadomienia
     * nie ma i to jest jedyny właściwy wynik.
     *
     * D-022 rozdziela dwie rzeczy: tekst wymazanego konta ZOSTAJE widoczny
     * (`jestDostepnyJakoAutor()` przepuszcza `erased`), a samego człowieka już
     * nie ma (`mozeCzytac()` — nie). Przepis da się więc ugotować, ale nie ma
     * komu wysłać wiadomości: konta nie da się otworzyć, odzyskać ani
     * przeczytać. Powiadomienie byłoby wierszem w bazie, którego nikt nigdy
     * nie zobaczy — a kolumna `notifications.user_id` prowadziłaby do konta
     * po wymazaniu danych.
     *
     * WYKONANIE ZOSTAJE ZAPISANE, i to jest ta połowa, o którą tu chodzi:
     * to dorobek kucharza, nie autora.
     */
    public function test_wymazane_konto_autora_nie_dostaje_powiadomienia_a_wykonanie_zostaje(): void
    {
        $wymazany = $this->user('autor_wymazany');
        $zywy = $this->user('autor_zywy_2');
        $kucharz = $this->user('kucharz_przy_wymazanym');

        $przepis_wymazanego = Recipe::factory()->create(['author_id' => $wymazany->getKey()]);
        $przepis_zywego = Recipe::factory()->create(['author_id' => $zywy->getKey()]);

        $wymazany->markDataErased();

        app(RecordCookedEvent::class)->handle($kucharz->fresh(), $przepis_wymazanego->fresh());

        $this->assertSame(
            1,
            $przepis_wymazanego->cookedEvents()->count(),
            'Wykonanie przepisu wymazanego konta ma zostać zapisane — to dorobek kucharza.',
        );
        $this->assertCount(0, $this->powiadomieniaOUgotowaniu($wymazany));

        // KONTROLA DODATNIA.
        app(RecordCookedEvent::class)->handle($kucharz->fresh(), $przepis_zywego);

        $this->assertCount(
            1,
            $this->powiadomieniaOUgotowaniu($zywy),
            'Kontrola dodatnia: wykonanie przepisu żywego autora też nie dało powiadomienia.',
        );
    }

    /**
     * PRZEPIS SCHOWANY PRZEZ MODERACJĘ: wykonania nie ma i powiadomienia też.
     *
     * `RecordCookedEvent` sprawdza `isPublished()` osobno i PRZED Policy, bo
     * Policy wpuszcza autora na jego własny szkic. Przepis w stanie `hidden`
     * albo `removed` jest poza obiegiem: dopisywanie mu wykonań znaczyłoby,
     * że decyzja moderatora zatrzymuje wyświetlanie, ale nie zatrzymuje
     * powiadomień, które ta treść generuje.
     */
    public function test_przepis_schowany_przez_moderacje_nie_przyjmuje_wykonania(): void
    {
        $autor = $this->user('autor_schowany');
        $kucharz = $this->user('kucharz_przy_schowanym');

        $schowany = Recipe::factory()->create(['author_id' => $autor->getKey()]);
        $widoczny = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $schowany->update(['status' => Recipe::STATUS_HIDDEN]);

        try {
            app(RecordCookedEvent::class)->handle($kucharz, $schowany->fresh());
            $this->fail('Zapisało się wykonanie przepisu schowanego przez moderację.');
        } catch (BladDlaCzlowieka) {
            // Tak ma być.
        }

        $this->assertSame(0, CookedEvent::query()->count());
        $this->assertCount(0, $this->powiadomieniaOUgotowaniu($autor));

        // KONTROLA DODATNIA: drugi przepis tego samego autora, nieschowany.
        app(RecordCookedEvent::class)->handle($kucharz, $widoczny);

        $this->assertCount(
            1,
            $this->powiadomieniaOUgotowaniu($autor),
            'Kontrola dodatnia: wykonanie widocznego przepisu też nie dało powiadomienia.',
        );
    }

    /**
     * WYKONANIE COFNIĘTE I ZROBIONE PONOWNIE: autor dostaje DRUGIE
     * powiadomienie.
     *
     * „Ugotowałem" to ZDARZENIE, nie STAN — a `NotifyUser` wycisza w oknie
     * doby wyłącznie STANY (`TYPY_WYCISZANE_W_OKNIE`, dziś sam
     * `TYPE_FOLLOW`). Drugie gotowanie tego samego przepisu jest nowym
     * faktem i nową wiadomością; wyciszenie go zamieniłoby najcenniejszy
     * sygnał w serwisie w licznik „pierwszy raz".
     *
     * Powiadomienie po cofniętym wykonaniu ZOSTAJE w bazie i to jest
     * świadome: wiadomości nie da się odwołać po tym, jak człowiek ją
     * przeczytał. Znika natomiast z listy razem z wykonaniem — pilnuje tego
     * `Notification::scopeVisibleTo()` i osobne testy widoczności.
     */
    public function test_cofniete_i_powtorzone_wykonanie_daje_drugie_powiadomienie(): void
    {
        $autor = $this->user('autor_cofniecie');
        $kucharz = $this->user('kucharz_cofniecie');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $pierwsze = app(RecordCookedEvent::class)->handle($kucharz, $recipe);
        $this->assertCount(1, $this->powiadomieniaOUgotowaniu($autor));

        $this->actingAs($kucharz)
            ->delete(route('cooked.destroy', $pierwsze))
            ->assertRedirect();

        $this->assertSame(0, CookedEvent::query()->count(), 'Wykonanie miało zostać cofnięte.');

        app(RecordCookedEvent::class)->handle($kucharz, $recipe->fresh());

        $this->assertCount(
            2,
            $this->powiadomieniaOUgotowaniu($autor),
            'Ponowne ugotowanie po cofnięciu nie dało drugiego powiadomienia.',
        );
    }

    /**
     * TA SAMA OSOBA GOTUJE TEN SAM PRZEPIS DWA RAZY: dwa wykonania, dwa
     * powiadomienia.
     *
     * `AGENTS.md` §6 zakazuje `UNIQUE (user_id, recipe_id)` na
     * `cooked_events`, bo „ta sama osoba może gotować ten sam przepis
     * dziesiątki razy i każde takie wykonanie jest osobnym wydarzeniem".
     * Zakaz na poziomie schematu byłby pusty, gdyby powiadomienie i tak
     * przychodziło raz — to jest ta sama reguła, tylko w warstwie wyżej.
     *
     * Ograniczenie jest wąskie i nazwane: jedno WYSŁANIE FORMULARZA daje
     * jedno powiadomienie (`klucz_wyslania`, ADR idempotencji). Pilnuje tego
     * `IdempotencjaUgotowalemTest`; tu wykonania przychodzą z dwóch osobnych
     * formularzy.
     */
    public function test_ta_sama_osoba_dwa_razy_daje_dwa_powiadomienia(): void
    {
        $autor = $this->user('autor_dwa_razy');
        $kucharz = $this->user('kucharz_dwa_razy');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $akcja = app(RecordCookedEvent::class);
        $akcja->handle($kucharz, $recipe, kluczWyslania: (string) Str::uuid7());
        $akcja->handle($kucharz, $recipe, kluczWyslania: (string) Str::uuid7());

        $this->assertSame(2, $recipe->cookedEvents()->count());
        $this->assertCount(
            2,
            $this->powiadomieniaOUgotowaniu($autor),
            'Drugie gotowanie tego samego przepisu przez tę samą osobę nie dało drugiego powiadomienia.',
        );
    }

    /**
     * USTAWIENIA AUTORA: wyłącznika powiadomień w serwisie NIE MA i ten test
     * jest zapisem tego stanu.
     *
     * Jedyna zgoda, jaką człowiek tu przestawia, dotyczy TYGODNIOWEGO LISTU
     * (`users.wants_weekly_digest`, domyślnie wyłączonego — DB2). To jest
     * zgoda na POCZTĘ, nie na wiedzę o własnym przepisie: lista powiadomień
     * w serwisie jest tym, po co człowiek tu wraca. Gdyby ten przełącznik
     * kiedykolwiek zaczął wyciszać „ktoś ugotował Twój przepis", ten test
     * oblewa i decyzja musi zostać podjęta jawnie.
     */
    public function test_wylaczony_list_tygodniowy_nie_wycisza_powiadomienia_w_serwisie(): void
    {
        $autor = $this->user('autor_bez_listu');
        $kucharz = $this->user('kucharz_bez_listu');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $autor->forceFill(['wants_weekly_digest' => false])->save();

        app(RecordCookedEvent::class)->handle($kucharz, $recipe);

        $this->assertCount(
            1,
            $this->powiadomieniaOUgotowaniu($autor->fresh()),
            'Wyłączony list tygodniowy wyciszył powiadomienie w serwisie.',
        );

        // KONTROLA DODATNIA dla drugiej strony przełącznika: z listem
        // włączonym powiadomienie też jest dokładnie jedno, nie dwa.
        $drugi_autor = $this->user('autor_z_listem');
        $drugi_przepis = Recipe::factory()->create(['author_id' => $drugi_autor->getKey()]);
        $drugi_autor->forceFill(['wants_weekly_digest' => true])->save();

        app(RecordCookedEvent::class)->handle($kucharz, $drugi_przepis);

        $this->assertCount(1, $this->powiadomieniaOUgotowaniu($drugi_autor->fresh()));
    }

    /**
     * Kontrola konstrukcyjna: użyte tu konto `erased` naprawdę jest
     * niedostępne do czytania, a `suspended` naprawdę dostępne.
     *
     * Bez tego dwa testy wyżej mogłyby mierzyć nie to, co myślą — pułapka 3b
     * („sabotaż może trafiać nie tam, gdzie myślisz"), tylko od strony danych
     * wejściowych.
     */
    public function test_stany_kont_uzyte_w_tym_pliku_znacza_to_co_trzeba(): void
    {
        $zawieszony = $this->user('kontrola_zawieszony');
        $zawieszony->suspend(now()->addDay());
        $this->assertTrue($zawieszony->fresh()->mozeCzytac());

        $wymazany = $this->user('kontrola_wymazany');
        $wymazany->markDataErased();
        $this->assertFalse($wymazany->fresh()->mozeCzytac());
        $this->assertTrue($wymazany->fresh()->jestDostepnyJakoAutor());

        $zbanowany = $this->user('kontrola_zbanowany');
        $zbanowany->ban();
        $this->assertFalse($zbanowany->fresh()->jestDostepnyJakoAutor());

        $do_usuniecia = $this->user('kontrola_do_usuniecia');
        $do_usuniecia->markForDeletion();
        $this->assertFalse($do_usuniecia->fresh()->jestDostepnyJakoAutor());

        $this->assertSame(User::STATUS_ERASED, $wymazany->fresh()->status);
    }
}
