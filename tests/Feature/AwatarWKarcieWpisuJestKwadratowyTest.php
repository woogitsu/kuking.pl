<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Post;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Awatar w karcie wpisu nie daje się zgnieść w poziomie.
 *
 * CO SIĘ STAŁO
 * Na zrzucie z produkcji (Chrome na Androidzie, ~390 px) zdjęcie autorki
 * w karcie wpisu było wyraźnie węższe niż wyższe — twarz ściśnięta w poziomie.
 *
 * PRZYCZYNA NIE JEST TA, KTÓRA SIĘ NASUWA. `.avatar` MA `flex: none`
 * (`resources/css/app.css`) i rozmiar 52 px też ma — obie te reguły są na
 * miejscu i nie one zawiodły. Zgniatał się element PIĘTRO WYŻEJ: awatar stoi
 * w karcie wpisu owinięty odnośnikiem do profilu, a `.post-card-head` jest
 * `display: flex`, więc elementem flex tego rzędu jest TEN `<a>`, a nie awatar
 * w jego środku. `<a>` bez własnej reguły dostaje domyślne `flex-shrink: 1`
 * i kurczy się, gdy w rzędzie robi się ciasno (długa nazwa autorki, data,
 * plakietka „Tylko dla obserwujących", przycisk „Więcej"). Dalej robi to już
 * `max-width: 100%` z preflightu Tailwinda: dociska SZEROKOŚĆ `<img>` do
 * zwężonego rodzica, podczas gdy `height: 52px` zostaje na swoim miejscu.
 *
 * Dotyczy to wariantu ze zdjęciem (`<img class="avatar">`). Wariant z inicjałem
 * (`<span class="avatar">`) ma `max-width: none` i się nie zgniata — dlatego
 * usterkę widać wyłącznie u osób, które mają zdjęcie profilowe.
 *
 * ZMIERZONE W PRZEGLĄDARCE (Chromium, żywa instancja, `/home`, zalogowany widz)
 *
 *   przypadek                       390 px            320 px
 *   długa nazwa + plakietka         18,91 × 52 px     10,95 × 52 px
 *   zwykłe konto demo               26,38 × 52 px     15,28 × 52 px
 *   po poprawce (oba przypadki)     52,00 × 52 px     52,00 × 52 px
 *
 * Zgniatało więc KAŻDĄ kartę wpisu ze zdjęciem profilowym, a nie tylko tę
 * z długą nazwą: w spisie 52 z 52 awatarów rozmiaru 52 px miało proporcję inną
 * niż 1:1. Przy szerokości 1280 px zgniecione zostawały jeszcze 3 z 52 —
 * właśnie konta z długą nazwą.
 *
 * Pozostałe rozmiary awatara w użyciu (32, 40, 44, 48, 56, 64, 88, 120, 128)
 * zmierzono w tym samym przebiegu jako kwadratowe — ich miejsca wywołania albo
 * wstawiają `<x-avatar>` BEZPOŚREDNIO w rząd flex (komentarze, powiadomienia,
 * karta ugotowania), albo mają na opakowaniu własne `flex: none`
 * (`.kuking-board-avatar`), albo `min-width` na opakowaniu (`.osoba-link`).
 *
 * CZEGO TEN TEST PILNUJE — DWIE POŁOWY JEDNEJ POPRAWKI
 * PHPUnit nie składa strony, więc pikseli tu nie zmierzymy. Mierzalne w HTML-u
 * i w arkuszu są za to obie części warunku, bez których zgniecenie wraca:
 *
 *   1. element flex rzędu nagłówka, w którym siedzi awatar, ma NAZWANĄ KLASĘ;
 *   2. ta klasa ma w `app.css` regułę wyłączającą kurczenie (`flex: none`
 *      albo `flex-shrink: 0`).
 *
 * Skasowanie którejkolwiek z tych dwóch rzeczy przywraca usterkę i oblewa ten
 * test osobno — dlatego są to dwie asercje, a nie jedna.
 *
 * CZEGO TEN TEST NIE OBIECUJE
 * Nie mierzy proporcji w pikselach i nie zastąpi pomiaru w przeglądarce.
 * Nie chroni też pozostałych miejsc z awatarem — pilnuje karty wpisu, bo tam
 * usterka wystąpiła; ogólną listę rozmiarów trzyma
 * `AwatarZnaKazdyRozmiarZWidokowTest`.
 */
class AwatarWKarcieWpisuJestKwadratowyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Karta wpisu autorki, która MA zdjęcie profilowe.
     *
     * Zdjęcie jest tu warunkiem sensu testu, nie ozdobą: bez niego `x-avatar`
     * renderuje `<span>`, a `<span>` nie ma `max-width: 100%` i nie zgniata się
     * nawet w zwężonym rodzicu. Test na koncie bez zdjęcia opisywałby więc
     * sytuację, w której usterki nie ma.
     */
    private function kartaZeZdjeciemProfilowym(): string
    {
        $autorka = $this->user('malgorzata', ['display_name' => 'Małgorzata Wiśniewska-Kowalczyk']);

        $zdjecie = Media::factory()->create([
            'owner_id' => $autorka->getKey(),
            'metadata' => ['variants' => ['thumb' => ['key' => 'media/test/awatar.webp', 'width' => 200, 'height' => 200]]],
        ]);

        $autorka->profile->forceFill(['avatar_media_id' => $zdjecie->getKey()])->save();

        // Widoczność zostaje domyślna. Plakietka „Tylko dla obserwujących"
        // z oryginalnego zrzutu zaciska rząd i dlatego zgniatała awatar
        // NAJMOCNIEJ, ale warunek, którego pilnuje ten test, jest od niej
        // niezależny: opakowanie awatara nie ma prawa się kurczyć niezależnie
        // od tego, co jeszcze stoi w rzędzie. Pomiar z plakietką jest w opisie
        // klasy wyżej i został zrobiony w przeglądarce.
        Post::factory()->create(['author_id' => $autorka->getKey()]);

        $html = (string) $this->actingAs($autorka->fresh())
            ->get(route('home'))
            ->assertOk()
            ->getContent();

        $start = strpos($html, '<article class="card post-card">');

        $this->assertNotFalse(
            $start,
            'Na stronie głównej nie ma ani jednej karty wpisu — nie ma czego sprawdzać.',
        );

        $koniec = strpos($html, '</article>', $start);

        $this->assertNotFalse($koniec, 'Karta wpisu nie ma zamknięcia — zmienił się jej kształt?');

        $karta = substr($html, $start, $koniec - $start);

        // KONTROLA DODATNIA: to musi być wariant ZE ZDJĘCIEM. Gdyby zdjęcie
        // nie doszło, w karcie stałby `<span class="avatar">` i cały test
        // pilnowałby sytuacji, w której usterka nie występuje (pułapka 4).
        $this->assertMatchesRegularExpression(
            '/<img[^>]*class="avatar"/',
            $karta,
            'Karta pokazuje awatar z inicjałem, a nie zdjęcie — wariant `<span>` '.
            'nie ma `max-width: 100%` i nie zgniata się, więc test nic by nie mierzył. '.
            'Zdjęcie profilowe nie doszło do widoku.',
        );

        $this->assertSame(1, substr_count($karta, 'Małgorzata Wiśniewska-Kowalczyk'), 'Karta nie pokazuje nazwy autorki — wyrenderowała się inna karta.');

        return $karta;
    }

    public function test_awatar_w_naglowku_karty_stoi_w_elemencie_ktory_sie_nie_kurczy(): void
    {
        $karta = $this->kartaZeZdjeciemProfilowym();

        $dokument = new DOMDocument;
        @$dokument->loadHTML('<?xml encoding="UTF-8">'.$karta, LIBXML_NOERROR | LIBXML_NOWARNING);

        $xpath = new DOMXPath($dokument);

        $naglowek = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' post-card-head ')]")?->item(0);

        $this->assertInstanceOf(
            DOMElement::class,
            $naglowek,
            'Karta wpisu nie ma `.post-card-head` — zmienił się jej kształt i strażnik patrzy w złe miejsce.',
        );

        $awatar = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' avatar ')]", $naglowek)?->item(0);

        $this->assertInstanceOf(
            DOMElement::class,
            $awatar,
            'W nagłówku karty nie ma awatara — nie ma czego sprawdzać.',
        );

        // Element flex rzędu to BEZPOŚREDNIE dziecko `.post-card-head`,
        // w którym siedzi awatar. Awatar może nim być sam — wtedy broni go
        // własne `flex: none` z `.avatar`.
        $elementFlex = $awatar;

        while ($elementFlex->parentNode !== $naglowek) {
            $rodzic = $elementFlex->parentNode;

            $this->assertInstanceOf(
                DOMElement::class,
                $rodzic,
                'Awatar nie leży wewnątrz `.post-card-head` — zmienił się kształt karty.',
            );

            $elementFlex = $rodzic;
        }

        if ($elementFlex === $awatar) {
            return; // Awatar jest elementem flex — `.avatar { flex: none }` wystarcza.
        }

        $klasy = preg_split('/\s+/', trim($elementFlex->getAttribute('class'))) ?: [];
        $klasy = array_values(array_filter($klasy));

        $this->assertNotSame(
            [],
            $klasy,
            "Awatar w nagłówku karty wpisu jest owinięty elementem <{$elementFlex->tagName}> BEZ KLASY. ".
            '`.post-card-head` jest `display: flex`, więc to opakowanie jest elementem flex tego rzędu '.
            "i bez własnej reguły dostaje domyślne `flex-shrink: 1`.\n".
            'Wtedy przy długiej nazwie autorki rząd zwęża opakowanie, `max-width: 100%` dociska do niego '.
            'szerokość `<img>`, a wysokość zostaje — i twarz jest ściśnięta w poziomie (zmierzone: 18,91 × 52 px). '.
            'Nadaj temu opakowaniu klasę i wyłącz dla niej kurczenie w `app.css`.',
        );

        $arkusz = (string) file_get_contents(resource_path('css/app.css'));

        $zNieKurczeniem = array_values(array_filter(
            $klasy,
            fn (string $klasa): bool => $this->klasaWylaczaKurczenie($arkusz, $klasa),
        ));

        $this->assertNotSame(
            [],
            $zNieKurczeniem,
            'Opakowanie awatara (<'.$elementFlex->tagName.' class="'.implode(' ', $klasy).'">) jest elementem flex '.
            "w `.post-card-head`, ale ŻADNA z jego klas nie ma w `app.css` reguły wyłączającej kurczenie.\n".
            'Bez `flex: none` (albo `flex-shrink: 0`) rząd zwęża to opakowanie przy długiej nazwie autorki, '.
            '`max-width: 100%` dociska do niego szerokość `<img>`, a `height` zostaje przy 52 px — '.
            "awatar wychodzi 18,91 × 52 px zamiast 52 × 52 px.\n".
            'Ta sama choroba jest już wyleczona tak samo przy `.kuking-board-avatar` w tym samym arkuszu.',
        );
    }

    /**
     * Czy `app.css` wyłącza kurczenie dla tej klasy.
     *
     * Czytamy WYŁĄCZNIE regułę o selektorze będącym samą tą klasą. Reguła
     * złożona (`.a .b`) mówi o innej sytuacji niż nasza i nie wolno jej tu
     * uznać za spełnienie warunku.
     */
    private function klasaWylaczaKurczenie(string $arkusz, string $klasa): bool
    {
        $wzorzec = sprintf(
            '~(?:^|[,{}])\s*\.%s\s*(?:,[^{}]*)?\{([^{}]*)\}~m',
            preg_quote($klasa, '~'),
        );

        if (preg_match_all($wzorzec, $arkusz, $trafienia) === 0) {
            return false;
        }

        foreach ($trafienia[1] as $cialo) {
            if (preg_match('~\bflex\s*:\s*none\b~', $cialo) === 1) {
                return true;
            }

            if (preg_match('~\bflex-shrink\s*:\s*0\b~', $cialo) === 1) {
                return true;
            }

            // `flex: 0 0 auto` i pokrewne — pierwsza liczba po `flex:` to
            // `flex-grow`, druga `flex-shrink`. Zero na drugiej pozycji też
            // wyłącza kurczenie.
            if (preg_match('~\bflex\s*:\s*\d+\s+0(\s|;|$)~', $cialo) === 1) {
                return true;
            }
        }

        return false;
    }
}
