<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\Actions\SavePostToCollection;
use App\Domain\Social\Actions\FollowUser;
use App\Models\Post;
use App\Models\User;
use App\Support\Czas;
use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Karta wpisu mówi „Masz to w zeszycie" na KAŻDYM ekranie, który tę kartę
 * pokazuje (issue #275, D-081).
 *
 * CZEGO TEN PLIK PILNUJE, A CZEGO NIE
 * Nie atrybutu `czy_zapisany` i nie tego, że ktoś zawołał `dolicz()`. Pilnuje
 * NAPISU, który widzi człowiek mający wpis u siebie w zeszycie. Test na
 * obecność kolumny przeszedłby także wtedy, gdyby widok tej kolumny nie
 * czytał — a to jest dokładnie ta usterka, o którą tu chodzi, tylko o jedno
 * piętro wyżej.
 *
 * TRZY ŚCIEŻKI, BO TRZY RÓŻNE ZAPYTANIA
 * `/home` (feed obserwowanych — `FollowingFeed`), `/odkryj` (`DiscoverFeed`)
 * i karta „Wspomnienie" na `/home` (`Wspomnienia::dlaOsoby()`). Pierwsze dwa
 * przechodziły już przed tą zmianą i zostają tu jako STRAŻ: obie klasy feedu
 * dokładają kolumnę same z siebie, a skasowanie tego `tap()` nie oblewa
 * dzisiaj żadnego testu zachowania. Trzecia jest tą naprawianą.
 */
class StanZeszytuNaKarcieWFeedzieTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_obserwowanych_mowi_ze_wpis_jest_w_zeszycie(): void
    {
        $widz = $this->widzZZapisanymWpisem(obserwuje: true);

        $karta = $this->kartaWpisu($this->htmlEkranu($widz, route('home')), '//div[@class="stack"]/article');

        $this->assertStringContainsString('Masz to w zeszycie', $karta);
        $this->assertStringNotContainsString('Zapisuję', $karta);
    }

    public function test_odkryj_mowi_ze_wpis_jest_w_zeszycie(): void
    {
        $widz = $this->widzZZapisanymWpisem(obserwuje: false);

        $karta = $this->kartaWpisu($this->htmlEkranu($widz, route('discover')), '//div[@class="stack"]/article');

        $this->assertStringContainsString('Masz to w zeszycie', $karta);
        $this->assertStringNotContainsString('Zapisuję', $karta);
    }

    public function test_wspomnienie_mowi_ze_wpis_jest_w_zeszycie(): void
    {
        $widz = $this->user('w'.substr(md5($this->name()), 0, 10));

        // Wspomnienie jest ZAWSZE własnym wpisem oglądającego (issue #34),
        // więc i zapis do zeszytu jest własny. Do licznika `zapisow_count`
        // taki zapis się nie liczy (ZapisyWpisu: „własny zapis autora się nie
        // liczy"), ale do pytania „czy JA to mam u siebie" — owszem, i o to
        // pytanie tu chodzi.
        $kiedy = Czas::lokalnie(Carbon::now())->subYear()->setTime(12, 0);

        $wpis = Post::factory()->create([
            'author_id' => $widz->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => $kiedy->copy()->utc(),
        ]);

        app(SavePostToCollection::class)->handle($widz, $wpis);

        $karta = $this->kartaWpisu(
            $this->htmlEkranu($widz, route('home')),
            '//section[contains(@class,"wspomnienie")]//article',
        );

        $this->assertStringContainsString('Masz to w zeszycie', $karta);
        $this->assertStringNotContainsString('Zapisuję', $karta);
    }

    /** Widz, który ma w zeszycie jeden cudzy, publiczny wpis. */
    private function widzZZapisanymWpisem(bool $obserwuje): User
    {
        // Nazwy krótkie z wyliczonego powodu: `profiles.username` to
        // `varchar(40)`, a nazwa metody testowej doklejona do prefiksu ten
        // limit przekracza i test pada na wstawieniu profilu, zanim dojdzie
        // do czegokolwiek, co mierzy.
        $widz = $this->user('w'.substr(md5($this->name()), 0, 10));
        $autor = $this->user('a'.substr(md5($this->name()), 0, 10));

        if ($obserwuje) {
            app(FollowUser::class)->handle($widz, $autor);
        }

        $wpis = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => Carbon::now()->subHour(),
        ]);

        app(SavePostToCollection::class)->handle($widz, $wpis);

        return $widz->fresh();
    }

    private function htmlEkranu(User $widz, string $adres): string
    {
        return (string) $this->actingAs($widz)->get($adres)->assertOk()->getContent();
    }

    /**
     * Jedna karta wpisu, wycięta ze strony — bez nawigacji, szyny i stopki
     * (pułapka 1 z `docs/PULAPKI_TESTOW.md`).
     */
    private function kartaWpisu(string $html, string $xpath): string
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $szukaj = new DOMXPath($dom);
        $wezly = $szukaj->query($xpath);

        $this->assertNotFalse($wezly);
        $this->assertSame(1, $wezly->length, 'Wycinek ma trafiać w dokładnie jedną kartę wpisu: '.$xpath);

        $karta = $wezly->item(0);
        $this->assertInstanceOf(DOMNode::class, $karta);

        // PANEL „WYBIERZ ZESZYT" WYCHODZI Z WYCINKA — I TO NIE JEST
        // ROZLUŹNIENIE ASERCJI. Ten panel (components/wybor-zeszytu.blade.php)
        // stoi na karcie ZAWSZE, gdy ktoś jest zalogowany, niezależnie od
        // stanu zapisu, i ma w środku przycisk „Zapisuję w tym zeszycie".
        // Zostawiony w wycinku sprawia, że asercja „nie ma napisu Zapisuję"
        // oblewa ZAWSZE — także przy kodzie, który działa. Zmierzone: przed
        // tym wycięciem oblewały wszystkie trzy przypadki, w tym dwa, które
        // były poprawne (pułapka 1 — to samo słowo skądinąd).
        //
        // Pytanie tego testu brzmi: czy GŁÓWNA akcja karty to „Zapisuję",
        // czy „Masz to w zeszycie". Panel wyboru zeszytu na to pytanie nie
        // odpowiada i nigdy nie odpowiadał.
        $panele = $szukaj->query('.//details[contains(@class,"wybor-zeszytu")]', $karta);

        if ($panele !== false) {
            foreach (iterator_to_array($panele) as $panel) {
                $panel->parentNode?->removeChild($panel);
            }
        }

        return (string) $dom->saveHTML($karta);
    }
}
