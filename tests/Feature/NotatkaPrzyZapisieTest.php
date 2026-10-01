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
use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
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
            ->patch($this->adres($obiady, 'przepis', $przepis->id), [
                'note' => '  Na urodziny taty — mniej soli.  ',
                '_odcisk_notatki' => UpdateCollectionItemNote::odcisk(null),
            ])
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
            ->patch($this->adres($zeszyt, 'wpis', $wpis->id), [
                'note' => "   \n  ",
                '_odcisk_notatki' => UpdateCollectionItemNote::odcisk('Stara notatka'),
            ])
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
            ->patch($this->adres($zeszyt, 'przepis', $przepis->id), [
                'note' => $zaDluga,
                '_odcisk_notatki' => UpdateCollectionItemNote::odcisk('Poprzednia'),
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
            ->patch($this->adres($zeszyt, 'przepis', $przepis->id), [
                'note' => str_repeat('ż', 500),
                '_odcisk_notatki' => UpdateCollectionItemNote::odcisk('Poprzednia'),
            ])
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
            app(UpdateCollectionItemNote::class)->handle($wlasciciel, $zeszyt, 'przepis', $przepis->id, str_repeat('a', 503), UpdateCollectionItemNote::odcisk('Poprzednia'));
            $this->fail('Za długa notatka przeszła przez akcję domenową.');
        } catch (\Throwable $e) {
            // Konkretny błąd domenowy, nie dowolny wyjątek (np. z limitu kolumny w bazie).
            $this->assertInstanceOf(ValidationException::class, $e);
            $this->assertSame(UpdateCollectionItemNote::WOREK_BLEDOW, $e->errorBag);
            $this->assertStringContainsString('Skróć ją o 3 znaki', $e->errors()['note'][0]);
        }

        $this->assertSame('Poprzednia', DB::table('collection_items')->where('collection_id', $zeszyt->id)->value('note'));
    }

    public function test_stara_karta_nie_nadpisuje_nowszej_notatki_a_szkic_mozna_swiadomie_zapisac(): void
    {
        $wlasciciel = $this->user('wlasciciel');
        $zeszyt = $this->zeszyt($wlasciciel, 'Obiady');
        $przepis = Recipe::factory()->create(['author_id' => $this->user('autor')->getKey(), 'visibility' => 'public']);
        $zeszyt->recipes()->attach($przepis->id, ['note' => 'A']);
        $adres = $this->adres($zeszyt, 'przepis', $przepis->id);
        $wiersz = 'notatka-przepis-'.$przepis->id;

        // Dwie karty otwarte w tej samej chwili widzą ten sam odcisk A.
        $pierwszaKarta = $this->odciskZFormularza($wlasciciel, $zeszyt);
        $drugaKarta = $this->odciskZFormularza($wlasciciel, $zeszyt);
        $this->assertSame(UpdateCollectionItemNote::odcisk('A'), $pierwszaKarta);
        $this->assertSame($pierwszaKarta, $drugaKarta);

        $this->actingAs($wlasciciel)->from(route('collections.show', $zeszyt))
            ->patch($adres, ['note' => 'B', '_odcisk_notatki' => $pierwszaKarta, '_wiersz' => $wiersz])
            ->assertSessionHasNoErrors();

        $html = (string) $this->actingAs($wlasciciel)->from(route('collections.show', $zeszyt))->followingRedirects()
            ->patch($adres, ['note' => 'C', '_odcisk_notatki' => $drugaKarta, '_wiersz' => $wiersz])
            ->assertOk()->getContent();
        $this->assertSame('B', DB::table('collection_items')->where('collection_id', $zeszyt->id)->value('note'), 'NOTATKA_2400_STARA_KARTA_NIE_NADPISUJE');
        $this->assertStringContainsString(UpdateCollectionItemNote::BLAD_KONFLIKTU, $html);
        $this->assertStringContainsString('Zastąp obecną notatkę', $html);

        $xpath = $this->xpath($html);
        $this->assertSame('B', trim($xpath->evaluate('string(//p[contains(@class,"notatka-zapisu-tresc")]/span)')));
        $this->assertSame('C', trim($xpath->evaluate('string(//textarea[@name="note"])')));
        $this->assertSame(UpdateCollectionItemNote::odcisk('B'), $this->odciskZHtml($html));

        // Ponowny, jawnie nazwany przycisk używa już B jako punktu odniesienia.
        $this->actingAs($wlasciciel)->patch($adres, [
            'note' => 'C', '_odcisk_notatki' => $this->odciskZHtml($html), '_wiersz' => $wiersz,
        ])->assertSessionHasNoErrors();
        $this->assertSame('C', DB::table('collection_items')->where('collection_id', $zeszyt->id)->value('note'));
    }

    public function test_wspoltworca_i_puste_pole_nie_kasuja_nowszej_notatki(): void
    {
        $wlasciciel = $this->user('wlasciciel');
        $wspoltworca = $this->user('wspoltworca');
        $zeszyt = $this->zeszyt($wlasciciel, 'Obiady');
        $przepis = Recipe::factory()->create(['author_id' => $this->user('autor')->getKey(), 'visibility' => 'public']);
        $zeszyt->recipes()->attach($przepis->id, ['note' => 'A']);
        $zaproszenie = app(ZaprosDoZeszytu::class)->poNazwie($wlasciciel, $zeszyt, (string) $wspoltworca->profile->username);
        app(OdpowiedzNaZaproszenie::class)->przyjmij($wspoltworca, $zaproszenie);
        $adres = $this->adres($zeszyt, 'przepis', $przepis->id);
        $staryOdcisk = $this->odciskZFormularza($wspoltworca, $zeszyt);

        $this->actingAs($wlasciciel)->patch($adres, [
            'note' => 'B', '_odcisk_notatki' => UpdateCollectionItemNote::odcisk('A'),
        ])->assertSessionHasNoErrors();
        $this->actingAs($wspoltworca)->from(route('collections.show', $zeszyt))
            ->patch($adres, ['note' => "  \n  ", '_odcisk_notatki' => $staryOdcisk, '_wiersz' => 'notatka-przepis-'.$przepis->id])
            ->assertSessionHasErrorsIn(UpdateCollectionItemNote::WOREK_BLEDOW, 'note');
        $this->assertSame('B', DB::table('collection_items')->where('collection_id', $zeszyt->id)->value('note'));

        $this->actingAs($wspoltworca)->patch($adres, [
            'note' => "  \n  ", '_odcisk_notatki' => UpdateCollectionItemNote::odcisk('B'),
        ])->assertSessionHasNoErrors();
        $this->assertNull(DB::table('collection_items')->where('collection_id', $zeszyt->id)->value('note'));
    }

    public function test_blad_dlugosci_nie_zmienia_po_cichu_starego_odcisku_szkicu(): void
    {
        $wlasciciel = $this->user('wlasciciel');
        $zeszyt = $this->zeszyt($wlasciciel, 'Obiady');
        $przepis = Recipe::factory()->create(['author_id' => $this->user('autor')->getKey(), 'visibility' => 'public']);
        $zeszyt->recipes()->attach($przepis->id, ['note' => 'A']);
        $adres = $this->adres($zeszyt, 'przepis', $przepis->id);
        $stary = $this->odciskZFormularza($wlasciciel, $zeszyt);

        $this->actingAs($wlasciciel)->patch($adres, ['note' => 'B', '_odcisk_notatki' => $stary])
            ->assertSessionHasNoErrors();
        $html = (string) $this->actingAs($wlasciciel)->from(route('collections.show', $zeszyt))->followingRedirects()
            ->patch($adres, [
                'note' => str_repeat('C', 501), '_odcisk_notatki' => $stary,
                '_wiersz' => 'notatka-przepis-'.$przepis->id,
            ])->assertOk()->getContent();
        $this->assertSame($stary, $this->odciskZHtml($html), 'Szkic po błędzie długości nadal odnosi się do A.');

        $this->actingAs($wlasciciel)->patch($adres, ['note' => 'C', '_odcisk_notatki' => $this->odciskZHtml($html)])
            ->assertSessionHasErrorsIn(UpdateCollectionItemNote::WOREK_BLEDOW, 'note');
        $this->assertSame('B', DB::table('collection_items')->where('collection_id', $zeszyt->id)->value('note'));
    }

    public function test_brak_odcisku_nie_pozwala_ominac_ochrony(): void
    {
        $wlasciciel = $this->user('wlasciciel');
        $zeszyt = $this->zeszyt($wlasciciel, 'Obiady');
        $przepis = Recipe::factory()->create(['author_id' => $this->user('autor')->getKey(), 'visibility' => 'public']);
        $zeszyt->recipes()->attach($przepis->id, ['note' => 'A']);

        foreach ([[], ['_odcisk_notatki' => 'błędny'], ['_odcisk_notatki' => ['tablica']]] as $niepoprawnyOdcisk) {
            $html = (string) $this->actingAs($wlasciciel)->from(route('collections.show', $zeszyt))->followingRedirects()
                ->patch($this->adres($zeszyt, 'przepis', $przepis->id), [
                    'note' => 'C', '_wiersz' => 'notatka-przepis-'.$przepis->id,
                    ...$niepoprawnyOdcisk,
                ])->assertOk()->getContent();
            $xpath = $this->xpath($html);
            $link = $xpath->evaluate('string(//div[contains(@class,"error-summary")]//a/@href)');
            $this->assertStringStartsWith('#f-note-notatka-przepis-', $link);
            $this->assertStringContainsString('Nie można sprawdzić, czy notatka się zmieniła', $html);
            $this->assertStringContainsString('Nie można sprawdzić, czy notatka się zmieniła',
                $xpath->evaluate('string(//span[@id="'.substr($link, 1).'-error"])'));
            $this->assertSame('C', trim($xpath->evaluate('string(//textarea[@name="note"])')));
            $this->assertStringContainsString('Zapisz notatkę', $html);
            $this->assertStringNotContainsString('Zastąp obecną notatkę', $html);
            $this->assertSame('A', DB::table('collection_items')->where('collection_id', $zeszyt->id)->value('note'));
        }
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
        $this->actingAs($wlasciciel)->patch($this->adres($zeszyt, 'przepis', $niezapisany->id), [
            'note' => 'X', '_odcisk_notatki' => UpdateCollectionItemNote::odcisk(null),
        ])->assertNotFound();
        $this->actingAs($wlasciciel)->patch($this->adres($zeszyt, 'wpis', $przepis->id), [
            'note' => 'X', '_odcisk_notatki' => UpdateCollectionItemNote::odcisk(null),
        ])->assertNotFound();
        $this->actingAs($wlasciciel)->patch(route('collections.note', ['collection' => $zeszyt, 'typ' => 'przepis', 'pozycja' => 'nie-uuid']), ['note' => 'X'])->assertNotFound();

        $this->assertNull(DB::table('collection_items')->where('collection_id', $zeszyt->id)->value('note'));
        $this->assertDatabaseMissing('collection_items', ['recipe_id' => $niezapisany->id]);
    }

    private function zeszyt(User $wlasciciel, string $nazwa, string $widocznosc = 'private'): Collection
    {
        return Collection::create(['owner_id' => $wlasciciel->getKey(), 'name' => $nazwa, 'visibility' => $widocznosc]);
    }

    private function adres(Collection $zeszyt, string $typ, string $id): string
    {
        return route('collections.note', ['collection' => $zeszyt, 'typ' => $typ, 'pozycja' => $id]);
    }

    private function odciskZFormularza(User $kto, Collection $zeszyt): string
    {
        $html = (string) $this->actingAs($kto)->get(route('collections.show', $zeszyt))->assertOk()->getContent();

        return $this->odciskZHtml($html);
    }

    private function odciskZHtml(string $html): string
    {
        $pola = $this->xpath($html)->query('//input[@name="_odcisk_notatki"]');
        $this->assertInstanceOf(DOMNodeList::class, $pola);
        $this->assertCount(1, $pola, 'Test wymaga dokładnie jednego formularza notatki.');
        $pole = $pola->item(0);
        $this->assertInstanceOf(DOMElement::class, $pole);

        return $pole->getAttribute('value');
    }

    private function xpath(string $html): DOMXPath
    {
        $dokument = new DOMDocument;
        @$dokument->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($dokument);
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
