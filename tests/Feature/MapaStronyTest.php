<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Mapa strony i krótkie znaczniki PHP.
 *
 * DLACZEGO TEN PLIK POWSTAŁ
 * `/sitemap.xml` oddawał na produkcji HTTP 500, podczas gdy ten sam kod
 * na maszynie deweloperskiej działał bez zarzutu. Różnicą było jedno
 * ustawienie PHP-a: `short_open_tag`, włączone w obrazie produkcyjnym
 * i wyłączone lokalnie.
 *
 * Blade przepisuje `<?xml version="1.0"?>` do skompilowanego PHP-a
 * w niezmienionej postaci. Przy włączonych krótkich znacznikach PHP czyta
 * `<?` jako otwarcie bloku kodu, a `xml version="1.0"` jako instrukcje —
 * i widok wybucha. Google dostawał „Server Error" zamiast mapy strony,
 * a nikt tego nie zauważył, bo map nie odwiedzają ludzie.
 */
class MapaStronyTest extends TestCase
{
    use RefreshDatabase;

    public function test_mapa_strony_zwraca_poprawny_xml(): void
    {
        Post::factory()->create([
            'author_id' => User::factory()->create()->getKey(),
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $odpowiedz = $this->get('/sitemap.xml');

        $odpowiedz->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=utf-8');

        $tresc = $odpowiedz->getContent();

        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $tresc);

        // Parser, a nie `assertStringContains`: uszkodzony XML potrafi zawierać
        // wszystkie oczekiwane fragmenty i mimo to być nie do odczytania
        // przez robota, dla którego ta strona istnieje.
        $poprzedni = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($tresc);
        $bledy = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($poprzedni);

        $this->assertNotFalse($xml, 'Mapa strony nie jest poprawnym XML-em.');
        $this->assertSame([], $bledy, 'Parser XML zgłosił zastrzeżenia do mapy strony.');
        $this->assertGreaterThan(0, $xml->count(), 'Mapa strony jest pusta.');
    }

    /**
     * MAPA NIE OGŁASZA ZAPOWIEDZI CUDZEGO, UKRYTEGO PRZEPISU — i robi to
     * warunkiem `whereNotNull('body')`, a nie bramką widoczności przepisu
     * (przegląd po #941).
     *
     * Wpis wskazujący przepis (#368) jest na stałe `public` i `publiclyVisible()`
     * go przepuszcza, więc na pierwszy rzut oka mapy brakuje
     * `Post::scopeZWidocznymPrzepisem()`. Nie brakuje: taki wpis nie ma
     * `body` — nie ma własnej treści, bo cała stoi w przepisie — a mapa
     * bierze wyłącznie wpisy Z TREŚCIĄ („Do mapy trafia TYLKO to, co realnie
     * ma wartość dla czytelnika", nagłówek `SitemapController`). Warunek
     * postawiony dla jakości treści zamyka tu także granicę prywatności.
     *
     * DLATEGO TEN TEST ISTNIEJE. Zależność jest cicha i łatwa do zgubienia:
     * dzień, w którym mapa zacznie brać wpisy bez `body` — albo zapowiedź
     * dostanie własny opis — jest dniem, w którym mapa zaczyna podawać
     * Google'owi adres prowadzący do przepisu „tylko dla obserwujących".
     */
    public function test_mapa_nie_oglasza_zapowiedzi_ukrytego_przepisu(): void
    {
        $basia = $this->user('basia');

        // KONTROLA DODATNIA: zwykły publiczny wpis Z TREŚCIĄ ma w mapie być.
        $zwykly = Post::factory()->create([
            'author_id' => $basia->getKey(),
            'body' => 'Zwykły obiad, bez przepisu.',
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $przepis = app(PublishRecipe::class)->handle(
            author: $basia,
            attributes: ['title' => 'Bigos z kapusty kiszonej', 'visibility' => 'followers', 'source_type' => 'own'],
            ingredients: [['text' => 'kapusta kiszona']],
            steps: [['instruction' => 'Gotuj powoli, przez trzy godziny.']],
            publish: true,
        );

        /** @var Post $zapowiedz */
        $zapowiedz = Post::query()->where('recipe_id', $przepis->getKey())->firstOrFail();

        $this->assertNull($zapowiedz->body, 'Zapowiedź przepisu dostała własną treść — mapa przestaje ją odcinać.');
        $this->assertSame(Post::VISIBILITY_PUBLIC, $zapowiedz->visibility);

        $mapa = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString(
            route('posts.show', $zwykly),
            $mapa,
            'Mapa nie ma nawet zwykłego publicznego wpisu — asercje niżej nie mówiłyby wtedy o przepisie.',
        );

        $this->assertStringNotContainsString(
            route('posts.show', $zapowiedz),
            $mapa,
            'Mapa podaje wyszukiwarkom adres zapowiedzi przepisu „tylko dla obserwujących". '
            .'Wejście w ten adres przekierowuje na przepis, czyli wydaje jego tytuł w slugu.',
        );

        $this->assertStringNotContainsString(
            route('recipes.show', $przepis->slug),
            $mapa,
            'Mapa podaje adres samego przepisu „tylko dla obserwujących".',
        );
    }

    public function test_zaden_widok_nie_uzywa_krotkiego_znacznika_php(): void
    {
        // TO JEST TEST, KTÓRY BY TAMTO ZŁAPAŁ.
        //
        // Test funkcjonalny wyżej przechodził także PRZED naprawą, bo lokalnie
        // `short_open_tag` jest wyłączone — awaria była widoczna wyłącznie
        // na produkcji. Sprawdzamy więc źródło widoków, a nie zachowanie:
        // to jedyna asercja, która nie zależy od ustawienia PHP-a maszyny,
        // na której akurat chodzi.
        $winne = [];
        $przejrzane = 0;

        foreach (File::allFiles(resource_path('views')) as $plik) {
            if (! str_ends_with($plik->getFilename(), '.blade.php')) {
                continue;
            }

            $przejrzane++;

            // Komentarze Blade'a lecą do kosza PRZED dopasowaniem. Pierwsza
            // wersja tego testu tego nie robiła i oblała na komentarzu, który
            // tłumaczy, dlaczego gołego `<?` nie wolno używać — czyli na
            // własnym opisie naprawy. Ten sam błąd zdarzył się już w teście
            // entrypointu; wygląda na to, że to stała pułapka przy testach
            // czytających kod źródłowy.
            $tresc = preg_replace('/\{\{--.*?--\}\}/s', '', File::get($plik->getPathname())) ?? '';

            // `<?php` i `<?=` są w porządku; chodzi o gołe `<?`, które przy
            // włączonych krótkich znacznikach otwiera blok kodu.
            if (preg_match('/<\?(?!php\b|=)/', $tresc) === 1) {
                $winne[] = str_replace(resource_path('views').'/', '', $plik->getPathname());
            }
        }

        // ASERCJA KONTROLNA. `$winne` zostaje puste także wtedy, gdy skan nie
        // przejrzał ANI JEDNEGO widoku — a wtedy ten test nie pilnuje niczego,
        // wyglądając dokładnie tak samo jak wcześniej. Zmierzone: po podmianie
        // skanowanego katalogu na taki, w którym nie ma żadnego `.blade.php`,
        // test był dalej zielony.
        $this->assertGreaterThan(
            50,
            $przejrzane,
            'Nie przejrzałem widoków (znalazłem '.$przejrzane.'). Ten test nie sprawdza wtedy niczego.',
        );

        $this->assertSame(
            [],
            $winne,
            'Widok zawiera goły znacznik `<?`. Przy short_open_tag = On PHP czyta go jako kod '
            ."i widok wybucha wyłącznie na produkcji. Zapisz to jako łańcuch znaków, na przykład:\n"
            .'{!! \'<\'.\'?xml version="1.0" encoding="UTF-8"?\'.\'>\' !!}',
        );
    }
}
