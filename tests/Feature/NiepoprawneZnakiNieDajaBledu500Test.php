<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\OdrzucNiepoprawneZnaki;
use App\Models\Collection;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * NIEPOPRAWNY UTF-8 I BAJT NUL TO HTTP 400, NIE 500 (audyt odporności, fuzz HTTP).
 *
 * `%FF%FE%C3` w adresie albo `\0` w polu formularza dochodziły do PostgreSQL
 * (SQLSTATE 22021) albo do funkcji PHP (`Str::squish()` → null → TypeError,
 * `DateTime` → ValueError). Rozwiązanie jest jedno, globalne:
 * `OdrzucNiepoprawneZnaki`. Ten plik sprawdza każdą trasę z audytu osobno.
 */
class NiepoprawneZnakiNieDajaBledu500Test extends TestCase
{
    use RefreshDatabase;

    private const ZLY_UTF8 = "\xFF\xFE\xC3";

    private const NUL = "a\0b";

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: array<string, string>, 4: string}>
     *                                                                                                    metoda, trasa, aktor, dane, zły tekst
     */
    public static function trasyZAudytu(): array
    {
        $trasy = [
            ['GET', 'tags.suggestions', 'osoba', ['q' => '@']],
            ['GET', 'posts.create', 'osoba', ['tag' => '@']],
            ['GET', 'questions.create', 'osoba', ['tag' => '@']],
            ['POST', 'logout', 'osoba', ['push_endpoint' => '@']],
            ['DELETE', 'settings.notifications.unsubscribe', 'osoba', ['endpoint' => '@']],
            ['POST', 'pantry.store', 'osoba', ['nazwa' => '@']],
            ['POST', 'cooked.thank', 'wlasciciel_przepisu', ['body' => '@']],
            ['POST', 'admin.unanswered.reply', 'moderator', ['body' => '@']],
            ['PATCH', 'collections.update', 'osoba', ['name' => '@', 'description' => 'Opis.', 'visibility' => 'private']],
            ['PATCH', 'collections.update', 'osoba', ['name' => 'Zeszyt', 'description' => '@', 'visibility' => 'private']],
            ['PATCH', 'collections.note', 'osoba', ['note' => '@']],
            ['PUT', 'settings.profile', 'osoba', ['display_name' => '@']],
            ['GET', 'admin.reports', 'moderator', ['status' => '@', 'q' => '@', 'typ' => '@']],
            ['GET', 'admin.appeals', 'moderator', ['status' => '@', 'q' => '@']],
            ['POST', 'admin.tag-highlights.store', 'moderator', ['tag_tygodnia' => '@', 'od_dnia' => '2026-11-23', 'do_dnia' => '2026-11-29']],
            // Klucz, nie wartość: `?a%FFb=1`.
            ['GET', 'tags.suggestions', 'osoba', ['@klucz' => 'x']],
        ];

        $przypadki = [];

        foreach ($trasy as [$metoda, $trasa, $aktor, $dane]) {
            foreach (['zly_utf8' => self::ZLY_UTF8, 'nul' => self::NUL] as $rodzaj => $zlyTekst) {
                $przypadki[$metoda.' '.$trasa.' '.implode(',', array_keys($dane)).' ('.$rodzaj.')'] = [$metoda, $trasa, $aktor, $dane, $zlyTekst];
            }
        }

        return $przypadki;
    }

    /**
     * @param  array<string, string>  $dane
     */
    #[DataProvider('trasyZAudytu')]
    public function test_zly_tekst_daje_400_a_nie_500(string $metoda, string $trasa, string $aktor, array $dane, string $zlyTekst): void
    {
        // Flagi włączone, żeby bez middleware trasa dochodziła do kodu
        // (kontrola ujemna), a nie kończyła się 404 na fladze.
        config(['kuking.questions.enabled' => true, 'kuking.tag_tygodnia.wlaczony' => true]);
        $swiat = $this->swiat();

        $wejscie = [];
        foreach ($dane as $klucz => $wartosc) {
            $klucz = $klucz === '@klucz' ? 'a'.$zlyTekst.'b' : $klucz;
            $wejscie[$klucz] = $wartosc === '@' ? $zlyTekst : $wartosc;
        }

        $odpowiedz = $this->actingAs($swiat[$aktor])
            ->call($metoda, route($trasa, $swiat['parametry'][$trasa] ?? []), $wejscie);

        $this->assertSame(400, $odpowiedz->getStatusCode(), $trasa.' z niepoprawnym tekstem ma dać 400, dało '.$odpowiedz->getStatusCode().'.');
    }

    public function test_400_dla_html_jest_zwykla_strona_bledu_po_polsku(): void
    {
        $odpowiedz = $this->actingAs($this->user())->get(route('tags.suggestions').'?q=%FF%FE%C3');

        $odpowiedz->assertStatus(400);
        $this->assertStringContainsString('text/html', (string) $odpowiedz->headers->get('Content-Type'));
        $odpowiedz->assertSee('Nie udało się otworzyć tej strony');
    }

    public function test_400_dla_zadania_json_jest_jsonem(): void
    {
        $this->actingAs($this->user())
            ->getJson(route('tags.suggestions').'?q=%FF%FE%C3')
            ->assertStatus(400)
            ->assertJsonStructure(['message']);
    }

    public function test_400_takze_dla_api_v1_jest_jsonem_a_nie_500(): void
    {
        $this->getJson('/api/v1/feed?cursor=%FF%FE%C3')->assertStatus(400)->assertJsonStructure(['message']);
    }

    /** KONTROLA DODATNIA: poprawny tekst (z polskimi znakami i emoji) przechodzi. */
    public function test_poprawny_tekst_z_polskimi_znakami_i_emoji_przechodzi(): void
    {
        $this->actingAs($this->user())
            ->get(route('tags.suggestions', ['q' => 'żółć 🍲']))
            ->assertOk();
    }

    public function test_plik_binarny_w_uploadzie_nie_jest_sprawdzany(): void
    {
        $plik = UploadedFile::fake()->createWithContent('zdjecie.jpg', "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\xFF\xFE\xC3");
        $zadanie = Request::create('/x', 'POST', ['nazwa' => 'Bigos'], [], ['plik' => $plik]);
        $przeszlo = false;

        (new OdrzucNiepoprawneZnaki)->handle($zadanie, function () use (&$przeszlo) {
            $przeszlo = true;

            return response('ok');
        });

        $this->assertTrue($przeszlo, 'Binarny plik z uploadu nie może być odrzucony jako zły tekst.');
    }

    public function test_surowe_cialo_bez_naglowka_json_nie_jest_ruszane(): void
    {
        // Jak webhook z podpisem albo raport CSP: ciało jest surowe, nie pola.
        $zadanie = Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], "\xFF\xFE\xC3\0");
        $przeszlo = false;

        (new OdrzucNiepoprawneZnaki)->handle($zadanie, function () use (&$przeszlo) {
            $przeszlo = true;

            return response('ok');
        });

        $this->assertTrue($przeszlo);
    }

    public function test_json_z_zerem_w_polu_daje_400(): void
    {
        $this->actingAs($this->user())
            ->postJson(route('pantry.store'), ['nazwa' => "a\u{0000}b"])
            ->assertStatus(400);
    }

    /**
     * @return array<string, mixed>
     */
    private function swiat(): array
    {
        $osoba = $this->user('basia');
        $moderator = $this->moderator();

        $przepis = Recipe::factory()->create(['author_id' => $osoba->getKey()]);
        $wykonanie = CookedEvent::factory()->create([
            'user_id' => $this->user('zenek')->getKey(),
            'recipe_id' => $przepis->getKey(),
        ]);
        $zeszyt = Collection::create([
            'owner_id' => $osoba->getKey(),
            'name' => 'Prywatny zeszyt',
            'visibility' => 'private',
        ]);
        $zeszyt->recipes()->attach($przepis->id);
        $wpis = Post::factory()->create(['author_id' => $osoba->getKey()]);
        Tag::create(['slug' => 'bigos', 'name' => 'Bigos', 'normalized_name' => 'bigos']);

        return [
            'osoba' => $osoba,
            'moderator' => $moderator,
            'wlasciciel_przepisu' => $osoba,
            'parametry' => [
                'cooked.thank' => ['cookedEvent' => $wykonanie],
                'admin.unanswered.reply' => ['post' => $wpis],
                'collections.update' => ['collection' => $zeszyt],
                'collections.note' => ['collection' => $zeszyt, 'typ' => 'przepis', 'pozycja' => $przepis->getKey()],
            ],
        ];
    }
}
