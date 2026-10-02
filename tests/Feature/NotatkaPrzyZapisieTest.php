<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\Actions\UpdateCollectionItemNote;
use App\Domain\Collections\Wspoldzielenie\OdpowiedzNaZaproszenie;
use App\Domain\Collections\Wspoldzielenie\ZaprosDoZeszytu;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\Collection;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Prywatna notatka przy zapisanej pozycji zeszytu (issue #978).
 */
final class NotatkaPrzyZapisieTest extends TestCase
{
    use RefreshDatabase;

    public function test_wlasciciel_dodaje_notatke_przy_jednej_parze_zeszyt_przepis(): void
    {
        [$wlasciciel, $autor] = [$this->user('wlasciciel'), $this->user('autor')];
        $obiady = $this->zeszyt($wlasciciel, 'Obiady');
        $urodziny = $this->zeszyt($wlasciciel, 'Urodziny');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'visibility' => 'public']);
        $obiady->recipes()->attach($przepis->id);
        $urodziny->recipes()->attach($przepis->id);
        $zapisano = DB::table('collection_items')->where('collection_id', $obiady->id)->value('created_at');
        $powiadomienia = DB::table('notifications')->count();

        // Zapis bez notatki zostaje NULL, a formularz jest opcją, nie krokiem.
        $html = $this->strona($wlasciciel, $obiady);
        $this->assertStringContainsString('Dodaj notatkę dla siebie', $html);
        $this->assertNull(DB::table('collection_items')->where('collection_id', $obiady->id)->value('note'));

        $this->actingAs($wlasciciel)->from(route('collections.show', $obiady))
            ->patch($this->adres($obiady, 'przepis', $przepis->id), $this->pola($obiady, 'przepis', $przepis->id, '  Na urodziny taty — mniej soli.  '))
            ->assertRedirect(route('collections.show', $obiady))
            ->assertSessionHas('status', 'Notatka zapisana. Widzisz ją tylko Ty.');

        $this->assertSame('Na urodziny taty — mniej soli.', DB::table('collection_items')->where('collection_id', $obiady->id)->value('note'));
        // Drugi zeszyt z tym samym przepisem ma własny (pusty) dopisek.
        $this->assertNull(DB::table('collection_items')->where('collection_id', $urodziny->id)->value('note'));
        // Kolejność w zeszycie i powiadomienia bez zmian.
        $this->assertSame($zapisano, DB::table('collection_items')->where('collection_id', $obiady->id)->value('created_at'));
        $this->assertSame($powiadomienia, DB::table('notifications')->count());

        $html = $this->strona($wlasciciel, $obiady);
        $this->assertStringContainsString('Twoja notatka (widzisz ją tylko Ty): Na urodziny taty — mniej soli.', $html);
        $this->assertStringContainsString('Zmień notatkę', $html);
        $this->assertStringNotContainsString('mniej soli', $this->strona($wlasciciel, $urodziny));
    }

    public function test_puste_pole_czysci_notatke_takze_w_eksporcie(): void
    {
        [$wlasciciel, $autor] = [$this->user('wlasciciel'), $this->user('autor')];
        $zeszyt = $this->zeszyt($wlasciciel, 'Obiady');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        $zeszyt->posts()->attach($wpis->id, ['note' => 'Stara notatka']);

        $this->assertSame('Stara notatka', $this->eksport($wlasciciel)['wpisy'][0]['moja_notatka']);

        $this->actingAs($wlasciciel)
            ->patch($this->adres($zeszyt, 'wpis', $wpis->id), $this->pola($zeszyt, 'wpis', $wpis->id, "   \n  "))
            ->assertSessionHas('status', 'Notatka usunięta. Zapis został w zeszycie.');

        $this->assertDatabaseHas('collection_items', ['collection_id' => $zeszyt->id, 'post_id' => $wpis->id, 'note' => null]);
        $this->assertNull($this->eksport($wlasciciel)['wpisy'][0]['moja_notatka']);
        $this->assertStringNotContainsString('Stara notatka', $this->strona($wlasciciel, $zeszyt));
    }

    public function test_za_dluga_notatka_wraca_z_bledem_i_tekstem_a_stara_zostaje(): void
    {
        [$wlasciciel, $autor] = [$this->user('wlasciciel'), $this->user('autor')];
        $zeszyt = $this->zeszyt($wlasciciel, 'Obiady');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'visibility' => 'public']);
        $zeszyt->recipes()->attach($przepis->id, ['note' => 'Poprzednia']);

        $zaDluga = str_repeat('ż', 501);
        $html = (string) $this->actingAs($wlasciciel)->from(route('collections.show', $zeszyt))->followingRedirects()
            ->patch($this->adres($zeszyt, 'przepis', $przepis->id), $this->pola($zeszyt, 'przepis', $przepis->id, $zaDluga) + [
                '_wiersz' => 'notatka-przepis-'.$przepis->id,
            ])->assertOk()->getContent();

        $blad = 'Notatka może mieć najwyżej 500 znaków. Skróć ją o 1 znak i zapisz jeszcze raz — Twój tekst jest nadal w polu.';
        $this->assertStringContainsString('error-summary', $html);
        $this->assertStringContainsString('href="#f-note-notatka-przepis-'.$przepis->id.'"', $html);
        $this->assertSame(2, substr_count($html, $blad), 'Błąd ma stać w podsumowaniu i przy polu.');
        $this->assertStringContainsString($zaDluga, $html);
        $this->assertMatchesRegularExpression('/<details class="mt-2"\s+open\s*>/', $html);
        $this->assertSame('Poprzednia', DB::table('collection_items')->where('collection_id', $zeszyt->id)->value('note'));

        // Granica: dokładnie 500 znaków (polskich) przechodzi.
        $this->actingAs($wlasciciel)
            ->patch($this->adres($zeszyt, 'przepis', $przepis->id), $this->pola($zeszyt, 'przepis', $przepis->id, str_repeat('ż', 500)))
            ->assertSessionHasNoErrors();
        $this->assertSame(str_repeat('ż', 500), DB::table('collection_items')->where('collection_id', $zeszyt->id)->value('note'));
    }

    public function test_akcja_domenowa_sama_pilnuje_limitu(): void
    {
        [$wlasciciel, $autor] = [$this->user('wlasciciel'), $this->user('autor')];
        $zeszyt = $this->zeszyt($wlasciciel, 'Obiady');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'visibility' => 'public']);
        $zeszyt->recipes()->attach($przepis->id, ['note' => 'Poprzednia']);

        try {
            app(UpdateCollectionItemNote::class)->handle($wlasciciel, $zeszyt, 'przepis', $przepis->id, str_repeat('a', 503), null);
            $this->fail('Za długa notatka przeszła przez akcję domenową.');
        } catch (\Throwable $e) {
            // Konkretny błąd domenowy, nie dowolny wyjątek (np. z limitu kolumny w bazie).
            $this->assertInstanceOf(ValidationException::class, $e);
            $this->assertSame(UpdateCollectionItemNote::WOREK_BLEDOW, $e->errorBag);
            $this->assertStringContainsString('Skróć ją o 3 znaki', $e->errors()['note'][0]);
        }

        $this->assertSame('Poprzednia', DB::table('collection_items')->where('collection_id', $zeszyt->id)->value('note'));
    }

    public function test_publiczny_zeszyt_nie_pokazuje_notatki_nikomu_poza_wlascicielem(): void
    {
        [$wlasciciel, $autor, $obcy] = [$this->user('wlasciciel'), $this->user('autor'), $this->user('obcy')];
        $zeszyt = $this->zeszyt($wlasciciel, 'Publiczny', 'public');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'visibility' => 'public']);
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        $zeszyt->recipes()->attach($przepis->id, ['note' => 'Tajny dopisek przy przepisie']);
        $zeszyt->posts()->attach($wpis->id, ['note' => 'Tajny dopisek przy wpisie']);

        $this->assertStringContainsString('Tajny dopisek przy przepisie', $this->strona($wlasciciel, $zeszyt));
        $this->assertStringContainsString('Tajny dopisek przy wpisie', $this->strona($wlasciciel, $zeszyt));

        foreach ([$obcy, $autor] as $kto) {
            $html = $this->strona($kto, $zeszyt);
            $this->assertStringContainsString($przepis->title, $html, 'Kontrola dodatnia: pozycja jest widoczna.');
            $this->assertStringNotContainsString('Tajny dopisek', $html);
            $this->assertStringNotContainsString('Dodaj notatkę', $html);
            $this->assertStringNotContainsString('Zmień notatkę', $html);
        }

        // Publiczny zeszyt otwiera się gościowi (issue #965) — bez notatki.
        auth()->logout();
        $gosc = $this->get(route('collections.show', $zeszyt))->assertOk();
        $this->assertStringNotContainsString('Tajny dopisek', (string) $gosc->getContent());
    }

    /**
     * Kryterium #978: notatka nie wychodzi „w eksporcie właściciela treści".
     * Autor przepisu i wpisu, który ktoś zapisał do zeszytu z dopiskiem,
     * dostaje w swojej paczce własne dane — ani notatkę, ani nazwę cudzego
     * zeszytu. (Widok zeszytu pilnuje wyżej `strona()`; tu paczka RODO.)
     */
    public function test_paczka_autora_tresci_nie_zawiera_cudzej_notatki(): void
    {
        [$wlasciciel, $autor] = [$this->user('wlasciciel'), $this->user('autor')];
        $zeszyt = $this->zeszyt($wlasciciel, 'Obiady', 'public');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'visibility' => 'public']);
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        $zeszyt->recipes()->attach($przepis->id, ['note' => 'Tajny dopisek przy przepisie']);
        $zeszyt->posts()->attach($wpis->id, ['note' => 'Tajny dopisek przy wpisie']);

        // Kontrola dodatnia: właściciel zeszytu widzi obie notatki w swojej paczce.
        $paczkaWlasciciela = json_encode(
            app(CollectUserExportData::class)->handle($wlasciciel, new ExportPhotoPlan($wlasciciel), now()),
            JSON_UNESCAPED_UNICODE,
        );
        $this->assertStringContainsString('Tajny dopisek przy przepisie', $paczkaWlasciciela);
        $this->assertStringContainsString('Tajny dopisek przy wpisie', $paczkaWlasciciela);

        $dane = app(CollectUserExportData::class)->handle($autor, new ExportPhotoPlan($autor), now());
        $paczkaAutora = json_encode($dane, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('Tajny dopisek', $paczkaAutora);
        $this->assertSame([], collect($dane['kolekcje'])->firstWhere('nazwa', 'Obiady') ?? [], 'Cudzy zeszyt nie należy do paczki autora treści.');
    }

    public function test_cudzy_zeszyt_i_nieistniejaca_pozycja_odmawiaja(): void
    {
        [$wlasciciel, $autor, $obcy] = [$this->user('wlasciciel'), $this->user('autor'), $this->user('obcy')];
        $zeszyt = $this->zeszyt($wlasciciel, 'Obiady', 'public');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'visibility' => 'public']);
        $niezapisany = Recipe::factory()->create(['author_id' => $autor->getKey(), 'visibility' => 'public']);
        $zeszyt->recipes()->attach($przepis->id);

        $this->actingAs($obcy)->patch($this->adres($zeszyt, 'przepis', $przepis->id), ['note' => 'Cudza'])->assertForbidden();
        $this->actingAs($wlasciciel)->patch($this->adres($zeszyt, 'przepis', $niezapisany->id), ['note' => 'X'])->assertNotFound();
        $this->actingAs($wlasciciel)->patch($this->adres($zeszyt, 'wpis', $przepis->id), ['note' => 'X'])->assertNotFound();
        $this->actingAs($wlasciciel)->patch(route('collections.note', ['collection' => $zeszyt, 'typ' => 'przepis', 'pozycja' => 'nie-uuid']), ['note' => 'X'])->assertNotFound();

        $this->assertNull(DB::table('collection_items')->where('collection_id', $zeszyt->id)->value('note'));
        $this->assertDatabaseMissing('collection_items', ['recipe_id' => $niezapisany->id]);
    }

    public function test_stara_karta_nie_nadpisuje_nowszej_notatki_a_tekst_wraca_do_formularza(): void
    {
        [$wlasciciel, $autor] = [$this->user('wlasciciel'), $this->user('autor')];
        $zeszyt = $this->zeszyt($wlasciciel, 'Obiady');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'visibility' => 'public']);
        $zeszyt->recipes()->attach($przepis->id, ['note' => 'A']);

        // Dwie karty otwarte na notatce „A": obie niosą jej odcisk.
        $karta1 = $this->pola($zeszyt, 'przepis', $przepis->id, 'B');
        $karta2 = $this->pola($zeszyt, 'przepis', $przepis->id, 'C');

        $this->actingAs($wlasciciel)->patch($this->adres($zeszyt, 'przepis', $przepis->id), $karta1)
            ->assertSessionHas('status');
        $this->assertSame('B', $this->notatka($zeszyt));

        $html = (string) $this->actingAs($wlasciciel)->from(route('collections.show', $zeszyt))->followingRedirects()
            ->patch($this->adres($zeszyt, 'przepis', $przepis->id), $karta2 + ['_wiersz' => 'notatka-przepis-'.$przepis->id])
            ->assertOk()->getContent();

        $this->assertSame('B', $this->notatka($zeszyt), 'Stara karta nie może nadpisać notatki B.');
        $this->assertStringContainsString('została zmieniona przed Twoim zapisem', $html);
        $this->assertStringContainsString('Aktualna notatka brzmi: „B”', $html);
        $this->assertMatchesRegularExpression('/<textarea[^>]*>\s*C\s*<\/textarea>/', $html, 'Tekst C zostaje w polu.');
        $this->assertMatchesRegularExpression('/<details class="mt-2"\s+open\s*>/', $html);
        // Formularz po konflikcie niesie odcisk AKTUALNEJ notatki „B".
        $this->assertStringContainsString('name="notatka_przed" value="'.UpdateCollectionItemNote::odcisk('B').'"', $html);

        // Świadome zastąpienie: ponowne „Zapisz" z nowym odciskiem przechodzi.
        $this->actingAs($wlasciciel)->patch($this->adres($zeszyt, 'przepis', $przepis->id), $this->pola($zeszyt, 'przepis', $przepis->id, 'C'))
            ->assertSessionHas('status');
        $this->assertSame('C', $this->notatka($zeszyt));
    }

    public function test_zapis_z_aktualnym_odciskiem_przechodzi_takze_dla_pustej_notatki(): void
    {
        [$wlasciciel, $autor] = [$this->user('wlasciciel'), $this->user('autor')];
        $zeszyt = $this->zeszyt($wlasciciel, 'Obiady');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'visibility' => 'public']);
        $zeszyt->recipes()->attach($przepis->id);

        // Pusta notatka też ma odcisk.
        $this->actingAs($wlasciciel)->patch($this->adres($zeszyt, 'przepis', $przepis->id), $this->pola($zeszyt, 'przepis', $przepis->id, 'Pierwsza'))
            ->assertSessionHas('status');
        $this->assertSame('Pierwsza', $this->notatka($zeszyt));

        // Wyczyszczenie ze starej karty, gdy notatka już się zmieniła, jest konfliktem.
        $stara = $this->pola($zeszyt, 'przepis', $przepis->id, '');
        DB::table('collection_items')->where('collection_id', $zeszyt->id)->update(['note' => 'Nowsza']);
        $this->actingAs($wlasciciel)->patch($this->adres($zeszyt, 'przepis', $przepis->id), $stara)
            ->assertSessionHasErrors('note', null, UpdateCollectionItemNote::WOREK_BLEDOW);
        $this->assertSame('Nowsza', $this->notatka($zeszyt));

        // Notatka usunięta w drugiej karcie, a ta próbuje zapisać tekst: konflikt, pusta zostaje.
        $zTekstem = $this->pola($zeszyt, 'przepis', $przepis->id, 'Moja');
        DB::table('collection_items')->where('collection_id', $zeszyt->id)->update(['note' => null]);
        $this->actingAs($wlasciciel)->patch($this->adres($zeszyt, 'przepis', $przepis->id), $zTekstem)
            ->assertSessionHasErrors('note', null, UpdateCollectionItemNote::WOREK_BLEDOW);
        $this->assertNull($this->notatka($zeszyt));
    }

    public function test_brak_odcisku_w_formularzu_jest_konfliktem_a_nie_cichym_zapisem(): void
    {
        [$wlasciciel, $autor] = [$this->user('wlasciciel'), $this->user('autor')];
        $zeszyt = $this->zeszyt($wlasciciel, 'Obiady');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'visibility' => 'public']);
        $zeszyt->recipes()->attach($przepis->id, ['note' => 'Cudza']);

        $this->actingAs($wlasciciel)->patch($this->adres($zeszyt, 'przepis', $przepis->id), ['note' => 'Z dawnego formularza'])
            ->assertSessionHasErrors('note', null, UpdateCollectionItemNote::WOREK_BLEDOW)
            ->assertSessionHasInput('note', 'Z dawnego formularza');
        $this->assertSame('Cudza', $this->notatka($zeszyt));
    }

    public function test_wspoltworca_wspolnego_zeszytu_ma_ta_sama_regule(): void
    {
        [$wlasciciel, $gosc, $autor] = [$this->user('wlasciciel'), $this->user('gosc'), $this->user('autor')];
        $zeszyt = $this->zeszyt($wlasciciel, 'Obiady');
        app(OdpowiedzNaZaproszenie::class)->przyjmij(
            $gosc,
            app(ZaprosDoZeszytu::class)->poNazwie($wlasciciel, $zeszyt, (string) $gosc->profile->username),
        );
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'visibility' => 'public']);
        $zeszyt->recipes()->attach($przepis->id, ['note' => 'A']);

        $kartaGoscia = $this->pola($zeszyt, 'przepis', $przepis->id, 'Gość');
        $this->actingAs($wlasciciel)->patch($this->adres($zeszyt, 'przepis', $przepis->id), $this->pola($zeszyt, 'przepis', $przepis->id, 'Właściciel'))
            ->assertSessionHas('status');

        $this->actingAs($gosc)->patch($this->adres($zeszyt, 'przepis', $przepis->id), $kartaGoscia)
            ->assertSessionHasErrors('note', null, UpdateCollectionItemNote::WOREK_BLEDOW);
        $this->assertSame('Właściciel', $this->notatka($zeszyt));

        // I w drugą stronę: gość zapisuje z aktualnym odciskiem, stara karta właściciela odpada.
        $kartaWlasciciela = $this->pola($zeszyt, 'przepis', $przepis->id, 'Znowu właściciel');
        $this->actingAs($gosc)->patch($this->adres($zeszyt, 'przepis', $przepis->id), $this->pola($zeszyt, 'przepis', $przepis->id, 'Gość'))
            ->assertSessionHas('status');
        $this->actingAs($wlasciciel)->patch($this->adres($zeszyt, 'przepis', $przepis->id), $kartaWlasciciela)
            ->assertSessionHasErrors('note', null, UpdateCollectionItemNote::WOREK_BLEDOW);
        $this->assertSame('Gość', $this->notatka($zeszyt));
    }

    private function zeszyt(User $wlasciciel, string $nazwa, string $widocznosc = 'private'): Collection
    {
        return Collection::create(['owner_id' => $wlasciciel->getKey(), 'name' => $nazwa, 'visibility' => $widocznosc]);
    }

    /**
     * Pola formularza tak, jak wyśle je przeglądarka: tekst + odcisk notatki
     * widzianej w chwili renderowania (czyli TERAZ w bazie).
     *
     * @return array<string, string>
     */
    private function pola(Collection $zeszyt, string $typ, string $id, string $tekst): array
    {
        return [
            'note' => $tekst,
            UpdateCollectionItemNote::POLE_ODCISKU => UpdateCollectionItemNote::odcisk($this->notatka($zeszyt)),
        ];
    }

    private function notatka(Collection $zeszyt): ?string
    {
        return DB::table('collection_items')->where('collection_id', $zeszyt->id)->value('note');
    }

    private function adres(Collection $zeszyt, string $typ, string $id): string
    {
        return route('collections.note', ['collection' => $zeszyt, 'typ' => $typ, 'pozycja' => $id]);
    }

    /** @return array<string, mixed> */
    private function eksport(User $kto): array
    {
        $dane = app(CollectUserExportData::class)->handle($kto, new ExportPhotoPlan($kto), now());

        return collect($dane['kolekcje'])->firstWhere('nazwa', 'Obiady');
    }

    private function strona(User $kto, Collection $zeszyt): string
    {
        $html = (string) $this->actingAs($kto)->get(route('collections.show', $zeszyt))->assertOk()->getContent();

        // Bez znaczników, żeby „<strong>Twoja notatka</strong> (…)" czytało się
        // jak zdanie na ekranie.
        return (string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html)));
    }
}
