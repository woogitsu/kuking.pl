<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADRES STRONY, Z KTÓREJ PRZEPIS POCHODZI, IDZIE DO `isBasedOn` — I TYLKO TAM.
 *
 * ZOSTAWIONE ŚWIADOMIE PRZY D-156. Tamta zmiana wyjęła pochodzenie przepisu
 * z `author` i przeniosła je do `citation`, a `recipes.source_url` zostawiła
 * poza danymi strukturalnymi z jawnym uzasadnieniem: „tam `isBasedOn`
 * BYŁOBY uczciwe, bo to prawdziwy URL — ale to poszerza zakres poza naprawiany
 * błąd". To jest ta druga połowa.
 *
 * DLACZEGO TU WOLNO, A PRZY `source_person` NIE BYŁO WOLNO. Zasada z D-156
 * mówi: wartości, o której nie wiemy, jakim typem encji jest, nie wolno
 * wkładać do pola, które typ wymusza. `source_person` jest wolnym tekstem
 * („od mamy", nazwa grupy na Facebooku) i dlatego poszedł do `citation` jako
 * zwykły `Text`. `source_url` jest adresem strony i niczym innym — obie drogi
 * zapisu walidują go regułą `url`, a `isBasedOn` przyjmuje `URL` obok
 * `CreativeWork` i `Product` (schema.org V30.0, https://schema.org/isBasedOn;
 * stoi na `CreativeWork`, po którym `Recipe` dziedziczy). Typ jest ZNANY,
 * więc pole jest uczciwe.
 *
 * DWA STANY EKRANU (D-099, D-106). Cały blok JSON-LD stoi pod `@if($isPublic)`,
 * więc test sprawdzający ZAWARTOŚĆ musi renderować przepis PUBLICZNY
 * I OPUBLIKOWANY — inaczej mierzyłby stan, w którym sprawdzanego fragmentu
 * nie ma w ogóle. Osobne twierdzenie pilnuje drugiego stanu z drugiej strony:
 * przy przepisie prywatnym danych strukturalnych NIE MA WCALE, a adres źródła
 * nie wychodzi wtedy żadnym skryptem.
 *
 * DANE SĄ WROGIE, a nie wygodne (docs/PULAPKI_TESTOW.md): adres z parametrem
 * `&` (koder zamienia go na `&`, więc szukanie po surowym HTML-u dawałoby
 * fałszywą czerwień), adres z polskimi znakami w ścieżce i adres grupy na
 * Facebooku — czyli dokładnie ta wartość, która przy D-156 kusiła, żeby zrobić
 * z niej nazwaną encję.
 */
class ZrodloZewnetrzneWDanychStrukturalnychTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private const WROGIE_ADRESY = [
        'https://naszesmaki.example/przepisy/rosol-z-kaczki',
        'https://przyklad.test/przepisy?id=7&utm_source=fb&ref=grupa',
        'https://przyklad.test/przepisy/żurek-na-zakwasie',
        'https://www.facebook.com/groups/naszesmaki/posts/1234567890',
    ];

    private const NAZWA_KONTA = 'Żaneta Kowalska';

    private const LOGIN_KONTA = 'zaneta';

    private ?User $autor = null;

    // -----------------------------------------------------------------
    // Twierdzenie 1: adres źródła zewnętrznego stoi w `isBasedOn`
    // -----------------------------------------------------------------

    public function test_adres_zrodla_zewnetrznego_stoi_w_is_based_on(): void
    {
        foreach (self::WROGIE_ADRESY as $adres) {
            $przepis = $this->publicznyPrzepis(Recipe::SOURCE_EXTERNAL, $adres);

            $dane = $this->daneStrukturalnePrzepisu($przepis);

            $this->assertArrayHasKey(
                'isBasedOn',
                $dane,
                "Adres „{$adres}” nie wyszedł do danych strukturalnych wcale.",
            );

            // `URL` w schema.org to GOŁY NAPIS — nie obiekt z `@type`.
            // Adres nie ma prawa udawać encji, bo o żadnej nic nie wiemy.
            $this->assertIsString(
                $dane['isBasedOn'],
                "Adres „{$adres}” wyszedł jako obiekt, a `URL` to zwykły napis.",
            );

            $this->assertSame(
                $adres,
                $dane['isBasedOn'],
                "Adres „{$adres}” wyszedł zmieniony — wartość ma iść dosłownie.",
            );
        }
    }

    // -----------------------------------------------------------------
    // Twierdzenie 2: pusty adres nie zostawia pustego pola
    // -----------------------------------------------------------------

    public function test_pusty_adres_nie_zostawia_pustego_is_based_on(): void
    {
        // `array_filter` na końcu bloku wyrzuca `null` i `[]`, ale PUSTY NAPIS
        // BY PRZEPUŚCIŁ — i wtedy w danych stałoby `"isBasedOn": ""`.
        foreach ([null, ''] as $puste) {
            $przepis = $this->publicznyPrzepis(Recipe::SOURCE_EXTERNAL, $puste);

            $dane = $this->daneStrukturalnePrzepisu($przepis);

            $this->assertArrayNotHasKey(
                'isBasedOn',
                $dane,
                'Przepis bez adresu źródła dostał pole `isBasedOn` — '
                .'zapewne pusty napis przeszedł przez `array_filter`.',
            );
        }
    }

    // -----------------------------------------------------------------
    // Twierdzenie 3: adres przy innym źródle niż zewnętrzne nie wychodzi
    // -----------------------------------------------------------------

    public function test_adres_przy_innym_zrodle_niz_zewnetrzne_nie_idzie_do_danych(): void
    {
        // Formularz nie ukrywa pola „Adres strony, z której jest przepis"
        // przy pozostałych trzech odpowiedziach, więc adres BYWA tam wpisany.
        // Widoczna treść strony pokazuje zdanie „Przepis pochodzi ze strony"
        // tylko przy `external` — a dane strukturalne mają odzwierciedlać to,
        // co widzi człowiek (`sd-policies`, docs/seo/SEO_TECHNICAL.md sekcja 2).
        $inne = [Recipe::SOURCE_OWN, Recipe::SOURCE_FAMILY, Recipe::SOURCE_ADAPTATION];

        foreach ($inne as $typ) {
            $adres = 'https://naszesmaki.example/przepisy/rosol-z-kaczki';
            $przepis = $this->publicznyPrzepis($typ, $adres);

            $html = $this->stronaPrzepisu($przepis);
            $dane = $this->daneStrukturalnePrzepisu($przepis, $html);

            $this->assertArrayNotHasKey(
                'isBasedOn',
                $dane,
                "Przepis o źródle „{$typ}” wypuścił adres do danych strukturalnych, "
                .'choć na ekranie tego adresu nie ma.',
            );

            // I na pewno mierzymy to, co trzeba: na ekranie adresu naprawdę
            // nie ma, więc brak w danych jest zgodnością, a nie przypadkiem.
            $this->assertStringNotContainsString('Przepis pochodzi ze strony', $html);
        }
    }

    // -----------------------------------------------------------------
    // Drugi stan ekranu: przepis prywatny nie oddaje danych strukturalnych
    // -----------------------------------------------------------------

    public function test_przepis_prywatny_nie_oddaje_zadnych_danych_strukturalnych(): void
    {
        $adres = 'https://naszesmaki.example/przepisy/rosol-z-kaczki';

        $autor = $this->konto();

        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'private',
            'published_at' => now(),
            'source_type' => Recipe::SOURCE_EXTERNAL,
            'source_url' => $adres,
        ]);

        // Prywatny przepis widzi tylko jego autor — i dlatego trzeba tu być
        // zalogowanym: inaczej odpowiedź byłaby pusta z innego powodu niż ten,
        // którego pilnujemy (D-099, D-106).
        $html = $this->actingAs($autor)
            ->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->getContent();

        $this->assertIsString($html);
        $this->assertNotSame('', $html);
        $this->assertStringNotContainsString('application/ld+json', $html);
        $this->assertStringNotContainsString($adres, $this->tylkoSkrypty($html));
    }

    // -----------------------------------------------------------------
    // Twierdzenie 4: adres nie wchodzi do `author` (D-156 zostaje nietknięte)
    // -----------------------------------------------------------------

    public function test_adres_nie_wchodzi_do_autora(): void
    {
        foreach (self::WROGIE_ADRESY as $adres) {
            $przepis = $this->publicznyPrzepis(Recipe::SOURCE_EXTERNAL, $adres);

            $dane = $this->daneStrukturalnePrzepisu($przepis);

            // `author` opisuje konto publikujące i tylko je (D-156).
            $this->assertSame(self::NAZWA_KONTA, $dane['author']['name']);
            $this->assertSame(route('profile.show', self::LOGIN_KONTA), $dane['author']['url']);

            // Nigdzie w całym poddrzewie `author` nie ma tego adresu.
            $this->assertStringNotContainsString(
                $adres,
                json_encode($dane['author'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                "Adres „{$adres}” wszedł do `author` — autorem jest konto, nie strona.",
            );
        }
    }

    // -----------------------------------------------------------------
    // Twierdzenie 5: adres nigdzie na stronie nie udaje nazwanej encji
    // -----------------------------------------------------------------

    public function test_adres_nigdzie_nie_udaje_nazwanej_encji(): void
    {
        foreach (self::WROGIE_ADRESY as $adres) {
            $przepis = $this->publicznyPrzepis(Recipe::SOURCE_EXTERNAL, $adres);

            // Szukamy REKURENCYJNIE i po WSZYSTKICH blokach strony, nie tylko
            // w `isBasedOn`: twierdzenie brzmi „adres nigdzie nie udaje osoby
            // ani organizacji", więc test nie może pilnować jednego miejsca.
            foreach ($this->wszystkieEncjeNazwane($this->blokiJsonLd($przepis)) as $sciezka => $encja) {
                $this->assertNotSame(
                    $adres,
                    $encja['name'],
                    "Adres „{$adres}” wyszedł jako nazwa encji `{$encja['@type']}` w `{$sciezka}`.",
                );
            }
        }
    }

    // -----------------------------------------------------------------
    // Pomocnicze
    // -----------------------------------------------------------------

    private function konto(): User
    {
        // JEDNO konto na cały test, nie jedno na obieg pętli: `username` jest
        // w bazie unikalny, a twierdzenie o adresie profilu potrzebuje loginu
        // znanego z góry.
        return $this->autor ??= $this->user(
            self::LOGIN_KONTA,
            ['display_name' => self::NAZWA_KONTA],
        );
    }

    private function publicznyPrzepis(string $typ, ?string $adres): Recipe
    {
        return Recipe::factory()->create([
            'author_id' => $this->konto()->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
            'source_type' => $typ,
            'source_url' => $adres,
            'source_person' => null,
            'source_note' => null,
        ]);
    }

    private function stronaPrzepisu(Recipe $przepis): string
    {
        $html = $this->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->getContent();

        $this->assertIsString($html);

        return $html;
    }

    /**
     * Wszystkie bloki `application/ld+json` ze strony przepisu, zdekodowane.
     *
     * @return list<array<string, mixed>>
     */
    private function blokiJsonLd(Recipe $przepis, ?string $html = null): array
    {
        preg_match_all(
            '#<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>#s',
            $html ?? $this->stronaPrzepisu($przepis),
            $trafienia,
        );

        $this->assertNotEmpty(
            $trafienia[1],
            'Strona publicznego przepisu nie oddała ani jednego bloku JSON-LD — '
            .'test mierzy zły stan ekranu (D-099, D-106), a nie zawartość danych.',
        );

        return array_map(
            fn (string $json): array => json_decode($json, true, 512, JSON_THROW_ON_ERROR),
            $trafienia[1],
        );
    }

    /**
     * Blok `Recipe` — ten, w którym stoi `isBasedOn`.
     *
     * @return array<string, mixed>
     */
    private function daneStrukturalnePrzepisu(Recipe $przepis, ?string $html = null): array
    {
        foreach ($this->blokiJsonLd($przepis, $html) as $blok) {
            if (($blok['@type'] ?? null) === 'Recipe') {
                return $blok;
            }
        }

        $this->fail('Na stronie publicznego przepisu nie ma bloku JSON-LD typu `Recipe`.');
    }

    /**
     * Każda encja z nazwą i typem, jaka stoi gdziekolwiek w podanych blokach.
     *
     * @param  list<array<string, mixed>>  $bloki
     * @return array<string, array{@type: string, name: string}>
     */
    private function wszystkieEncjeNazwane(array $bloki): array
    {
        $znalezione = [];

        $chodz = function (mixed $wezel, string $sciezka) use (&$chodz, &$znalezione): void {
            if (! is_array($wezel)) {
                return;
            }

            $typ = $wezel['@type'] ?? null;
            $nazwa = $wezel['name'] ?? null;

            if (is_string($typ) && is_string($nazwa)) {
                $znalezione[$sciezka] = ['@type' => $typ, 'name' => $nazwa];
            }

            foreach ($wezel as $klucz => $dziecko) {
                $chodz($dziecko, $sciezka === '' ? (string) $klucz : $sciezka.'.'.$klucz);
            }
        };

        foreach ($bloki as $i => $blok) {
            $chodz($blok, 'blok'.$i);
        }

        return $znalezione;
    }

    /**
     * Sama treść elementów `<script>` — żeby twierdzenie o przepisie prywatnym
     * nie łapało adresu pokazanego autorowi w widocznej treści strony
     * (tam ma prawo stać).
     */
    private function tylkoSkrypty(string $html): string
    {
        preg_match_all('#<script[^>]*>(.*?)</script>#s', $html, $trafienia);

        return implode("\n", $trafienia[1]);
    }
}
