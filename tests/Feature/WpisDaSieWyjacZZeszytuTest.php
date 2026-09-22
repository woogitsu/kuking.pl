<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Post;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Wpis da się wyjąć z zeszytu — RĘKĄ CZŁOWIEKA, nie tylko żądaniem z testu
 * (audyt `docs/AUDYT_2026-09.md`, wiersz L1).
 *
 * CO BYŁO ZEPSUTE
 * Trasa `DELETE /wpisy/{post}/zapisz` (`collections.unsave-post`) istniała,
 * była otestowana i bezpieczna — a w całym `resources/` nie miała ANI JEDNEGO
 * wywołania. Jedynym jej użytkownikiem był test. Komentarz na karcie wpisu
 * obiecywał wprost „wyjąć z zeszytu można w samym zeszycie”, a ekran zeszytu
 * renderuje TĘ SAMĄ kartę — czyli obietnica wskazywała na miejsce, w którym
 * przycisku nie było. Człowiek, który odłożył wpis przez pomyłkę, nie miał
 * w serwisie żadnej drogi wyjścia.
 *
 * DLACZEGO TEST NA ZACHOWANIU, A NIE `grep` PO WIDOKU
 * Dokładnie ta usterka przeszła kiedyś przez zielone CI, bo test wołał trasę
 * wprost. Test, który wysyła `DELETE` i sprawdza bazę, przechodziłby dalej
 * także wtedy, gdyby przycisk znów zniknął z widoku. Dlatego sceny niżej
 * zaczynają się od WEJŚCIA NA EKRAN i szukają formularza w wyrenderowanym
 * HTML-u, a dopiero potem wysyłają to, co ten formularz wysyła.
 *
 * CZEGO TEN PLIK PILNUJE
 *  1. droga wyjęcia stoi na OBU ekranach ze stanem zapisu — ale NIE W TYM
 *     SAMYM KSZTAŁCIE, i to jest sedno tego pliku po decyzji właściciela
 *     z 22 września 2026 (patrz `ekrany()` niżej);
 *  2. jest to zwykły formularz `DELETE` — działa bez JavaScriptu, bez
 *     atrybutów zdarzeń i bez Livewire'a (AGENTS.md §5, D-053);
 *  3. właściciel wyjmuje i wpis znika z jego zeszytu;
 *  4. obca osoba nie rusza cudzego zeszytu, a gość nie dochodzi tu wcale;
 *  5. komunikat po akcji mówi, co się stało, i daje drogę powrotu, która
 *     naprawdę zapisuje wpis z powrotem.
 */
class WpisDaSieWyjacZZeszytuTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Oba ekrany, na których stan „mam to w zeszycie” jest policzony
     * (`ZapisyWpisu::dolicz()` / `doliczDoWpisu()`) — RAZEM Z TYM, CZEGO
     * KAŻDY Z NICH MA DOWIEŚĆ.
     *
     * DLACZEGO PROVIDER NIESIE OCZEKIWANIA, A NIE SAMĄ NAZWĘ EKRANU
     * Do 22 września ten provider dawał wyłącznie nazwę ekranu, a obie sceny
     * żądały na obu ekranach DOKŁADNIE TEGO SAMEGO: zdania „Masz to
     * w zeszycie” i przycisku „Usuń z zeszytu”. Naprzeciwko stał
     * `ZeszytUsuwaZapisanyWpisTest`, który na ekranie zeszytu żądał, żeby
     * tego zdania NIE BYŁO. Dwa testy opisywały jedno miejsce w dwa
     * wykluczające się sposoby i żadna poprawka w widoku nie mogła zazielenić
     * obu — bo sprzeczność siedziała w OCZEKIWANIACH, nie w kodzie. Commit
     * `86c36f34` jej nie rozstrzygnął, tylko przeniósł: zostawił ten plik
     * bajt w bajt takim, jakim był w `main`.
     *
     * Właściciel rozstrzygnął 22 września 2026: NA EKRANIE ZESZYTU ZOSTAJE
     * WARIANT Z GAŁĘZI. Ten provider jest miejscem, w którym to
     * rozstrzygnięcie stoi zapisane wykonywalnie:
     *
     *  • EKRAN ZESZYTU (`collections.show`) — człowiek już stoi w zeszycie,
     *    więc zdanie „Masz to w zeszycie” nie niosłoby mu żadnej nowej
     *    wiadomości, a zabierałoby miejsce i jeden cel do kliknięcia obok
     *    przycisku kasującego. Przycisk nazywa się „Usuń z tego zeszytu”
     *    i NIESIE `collection_id`: zakres jest zawężony do tego jednego
     *    zeszytu (issue #775), bo tu wiadomo, o który chodzi;
     *
     *  • EKRAN WPISU (`posts.show`) — nie wiadomo, „w którym zeszycie stoi
     *    człowiek”, więc stan MUSI być powiedziany zdaniem, a zakres zostaje
     *    globalny (bez `collection_id`). Tego wiersza nie wolno osłabić: to
     *    jest audyt L1 — kto odłożył wpis przez pomyłkę, ma mieć drogę
     *    wyjęcia z ekranu, na którym ten wpis ogląda.
     *
     * Wspólne dla obu ekranów i NIEPODLEGAJĄCE różnicowaniu zostaje to, co
     * audyt naprawdę mierzy: że droga wyjęcia W OGÓLE JEST i że działa bez
     * JavaScriptu. Różni się jej kształt, nie jej istnienie.
     *
     * @return array<string, array{0: string, 1: string, 2: bool, 3: bool}>
     */
    public static function ekrany(): array
    {
        return [
            //                ekran     napis na przycisku     zdanie o stanie?  zakres zawężony?
            'zeszyt' => ['zeszyt', 'Usuń z tego zeszytu', false, true],
            'karta wpisu' => ['wpis', 'Usuń z zeszytu', true, false],
        ];
    }

    #[DataProvider('ekrany')]
    public function test_na_ekranie_ze_stanem_zapisu_stoi_przycisk_wyjecia(
        string $ekran,
        string $napis,
        bool $zeZdaniemOStanie,
        bool $zakresZawezony,
    ): void {
        [$basia, $wpis] = $this->zapisanyWpis();

        $html = $this->otworz($ekran, $basia, $wpis)->getContent();

        // STAN ZAPISU — zdaniem tylko tam, gdzie zdanie cokolwiek wnosi.
        if ($zeZdaniemOStanie) {
            $this->assertStringContainsString(
                'Masz to w zeszycie',
                $html,
                "Ekran „{$ekran}” nie pokazuje nawet stanu zapisu — scena mierzy nie to, co trzeba.",
            );
        } else {
            $this->assertStringNotContainsString(
                'Masz to w zeszycie',
                $html,
                "Ekran „{$ekran}” mówi „Masz to w zeszycie” człowiekowi, który stoi w tym właśnie zeszycie (decyzja właściciela z 22 września 2026).",
            );
        }

        // DROGA WYJĘCIA — na obu ekranach, bez wyjątku (audyt L1).
        $formularz = $this->formularzWyjecia($html, $wpis);

        $this->assertNotNull(
            $formularz,
            "Ekran „{$ekran}” pokazuje stan zapisu, ale nie daje żadnej drogi wyjęcia wpisu z zeszytu (audyt L1).",
        );

        $xpath = new DOMXPath($formularz->ownerDocument);

        $przycisk = $xpath->query('.//button', $formularz)?->item(0);

        $this->assertInstanceOf(DOMElement::class, $przycisk, 'Formularz wyjęcia nie ma przycisku.');
        $this->assertStringContainsString($napis, $przycisk->textContent);

        // ZAKRES — widoczny w formularzu, a nie dopiero w komunikacie po fakcie.
        $zakres = $this->poleUkryte($formularz, 'collection_id');

        if ($zakresZawezony) {
            $this->assertSame(
                (string) $basia->defaultCollection()->getKey(),
                $zakres,
                "Przycisk „{$napis}” nie niesie `collection_id` — zdjąłby wpis ze WSZYSTKICH zeszytów, a nie z tego jednego (issue #775).",
            );
        } else {
            $this->assertNull(
                $zakres,
                "Ekran „{$ekran}” nie wie, w którym zeszycie stoi człowiek, więc nie ma prawa wskazywać zeszytu w `collection_id`.",
            );
        }

        // PUŁAPKA ENTERA: formularz wyjęcia niesie DOKŁADNIE JEDEN przycisk
        // wysyłający. Scalony z formularzem zapisu miałby dwa, a wtedy Enter
        // wysyła akcję z PIERWSZEGO przycisku, nie tę, w którą człowiek
        // celował — ten sam błąd wysyłał w innym ekranie panelu nieodwracalny
        // list zamiast zapisać stan.
        $this->assertSame(
            1,
            $xpath->query('.//button[not(@type) or @type="submit"]', $formularz)?->length,
            "Formularz wyjęcia na ekranie „{$ekran}” ma więcej niż jeden przycisk wysyłający — Enter przestaje być przewidywalny.",
        );
    }

    #[DataProvider('ekrany')]
    public function test_droga_wyjecia_dziala_bez_javascriptu(
        string $ekran,
        string $napis,
        bool $zeZdaniemOStanie,
        bool $zakresZawezony,
    ): void {
        // Martwy przycisk jest gorszy niż brak przycisku (AGENTS.md §5, D-053).
        // Tu nie ma powodu na skrypt: to jest zwykły formularz, więc sprawdzamy
        // jego KSZTAŁT — metodę, podmianę metody, token i brak czegokolwiek,
        // co bez JavaScriptu przestaje działać.
        [$basia, $wpis] = $this->zapisanyWpis();

        $html = $this->otworz($ekran, $basia, $wpis)->getContent();
        $formularz = $this->formularzWyjecia($html, $wpis);

        $this->assertNotNull($formularz, "Brak formularza wyjęcia na ekranie „{$ekran}”.");

        $this->assertSame('POST', strtoupper($formularz->getAttribute('method')));

        $xpath = new DOMXPath($formularz->ownerDocument);

        $metoda = $xpath->query('.//input[@name="_method"]', $formularz)?->item(0);
        $this->assertInstanceOf(DOMElement::class, $metoda, 'Formularz nie podmienia metody na DELETE.');
        $this->assertSame('DELETE', strtoupper($metoda->getAttribute('value')));

        $this->assertNotNull(
            $xpath->query('.//input[@name="_token"]', $formularz)?->item(0),
            'Formularz nie ma tokenu CSRF.',
        );

        foreach ($xpath->query('.//@*', $formularz) as $atrybut) {
            $nazwa = strtolower($atrybut->nodeName);

            $this->assertStringStartsNotWith('on', $nazwa, "Formularz wyjęcia wisi na atrybucie zdarzenia `{$nazwa}` — bez skryptu (albo po zaostrzeniu CSP) przestanie działać.");
            $this->assertStringStartsNotWith('wire:', $nazwa, 'Formularz wyjęcia wisi na Livewire — bez skryptu przycisk milczy.');
            $this->assertStringStartsNotWith('x-on:', $nazwa, 'Formularz wyjęcia wisi na Alpine — bez skryptu przycisk milczy.');
        }

        // I to, co ten formularz wysyła, naprawdę wyjmuje wpis.
        //
        // WYSYŁAMY ŁADUNEK FORMULARZA, A NIE SAM ADRES. Scena wysyłała wcześniej
        // puste żądanie pod `action` — a wtedy `collection_id` z ekranu zeszytu
        // po prostu nie docierało i akcja szła szeroką ścieżką. Przy jednym
        // zeszycie skutek wygląda identycznie, więc test milczał o tym, że
        // mierzy coś innego, niż robi człowiek.
        $this->actingAs($basia)
            ->from($formularz->getAttribute('action'))
            ->delete($formularz->getAttribute('action'), $this->ladunek($formularz))
            ->assertRedirect();

        $this->assertSame(0, $basia->defaultCollection()->posts()->count());
    }

    public function test_wyjecie_z_ekranu_zeszytu_nie_rusza_pozostalych_zeszytow(): void
    {
        // DOWÓD NA ZACHOWANIE, NIE NA CISZĘ ASERCJI.
        //
        // Sceny wyżej dowodzą, że formularz NIESIE `collection_id`. To jeszcze
        // nie znaczy, że wysłany naprawdę zawęża zakres — a to jest cała sprawa
        // issue #775: przed nim jedno kliknięcie zdejmowało wpis z pięciu
        // zeszytów i kasowało pięć notatek, bez miękkiego kasowania i bez
        // historii. Dlatego tu wchodzimy na ekran zeszytu, bierzemy formularz
        // Z WYRENDEROWANEGO HTML-u, wysyłamy DOKŁADNIE jego ładunek i patrzymy
        // DO BAZY — na wiersze pivotu i na kolumnę `note`, nie na HTML.
        $basia = $this->user('basia');
        $wpis = Post::factory()->create(['author_id' => $this->user('autor')->getKey()]);

        $stad = Collection::create(['owner_id' => $basia->getKey(), 'name' => 'Obiady', 'visibility' => 'private']);
        $obok = Collection::create(['owner_id' => $basia->getKey(), 'name' => 'Święta', 'visibility' => 'private']);

        $stad->posts()->attach($wpis->getKey(), ['note' => 'Zrobić w niedzielę.']);
        $obok->posts()->attach($wpis->getKey(), ['note' => 'Notatka, która ma przeżyć.']);

        $html = $this->actingAs($basia)->get(route('collections.show', $stad))->assertOk()->getContent();
        $formularz = $this->formularzWyjecia($html, $wpis);

        $this->assertNotNull($formularz, 'Ekran zeszytu nie daje formularza wyjęcia.');
        $this->assertSame((string) $stad->getKey(), $this->poleUkryte($formularz, 'collection_id'));

        $this->actingAs($basia)
            ->from(route('collections.show', $stad))
            ->delete($formularz->getAttribute('action'), $this->ladunek($formularz))
            ->assertRedirect();

        // ODCZYT Z BAZY, nie asercja na HTML-u.
        $this->assertSame(
            0,
            $stad->posts()->whereKey($wpis->getKey())->count(),
            'Wpis został w zeszycie, z którego go wyjmowano.',
        );

        $wDrugim = $obok->posts()->whereKey($wpis->getKey())->first();

        $this->assertNotNull($wDrugim, 'Wyjęcie z jednego zeszytu zabrało wpis także z drugiego.');
        $this->assertSame(
            'Notatka, która ma przeżyć.',
            $wDrugim->pivot->note,
            'Notatka własna z NIERUSZANEGO zeszytu zniknęła razem z wierszem obok.',
        );
    }

    public function test_wlasciciel_wyjmuje_i_wpis_znika_z_zeszytu(): void
    {
        [$basia, $wpis] = $this->zapisanyWpis(['body' => 'Rosół na niedzielę.']);

        $this->actingAs($basia)
            ->get(route('collections.show', $basia->defaultCollection()))
            ->assertOk()
            ->assertSee('Rosół na niedzielę.', escape: false);

        $this->actingAs($basia)
            ->delete(route('collections.unsave-post', $wpis))
            ->assertRedirect();

        $this->actingAs($basia)
            ->get(route('collections.show', $basia->defaultCollection()))
            ->assertOk()
            ->assertDontSee('Rosół na niedzielę.', escape: false)
            // Zeszyt nie kłamie, że coś zostało schowane — wpisu po prostu
            // w nim nie ma (to inny stan niż „zapis niedostępny”).
            ->assertDontSee('data-niedostepne-zapisy', escape: false);

        $this->assertSame(0, $basia->defaultCollection()->posts()->count());
    }

    public function test_obca_osoba_nie_wyjmie_wpisu_z_cudzego_zeszytu(): void
    {
        // UUID w adresie to nie autoryzacja (AGENTS.md §7). Granica jest tu
        // OSTRZEJSZA niż Policy: akcja chodzi wyłącznie po zeszytach osoby,
        // która wysłała żądanie, więc cudzy wiersz jest poza jej zasięgiem
        // niezależnie od tego, co wpisze w adres.
        [$basia, $wpis] = $this->zapisanyWpis();
        $obca = $this->user('obca');

        $this->actingAs($obca)->delete(route('collections.unsave-post', $wpis))->assertRedirect();

        $this->assertSame(
            1,
            $basia->defaultCollection()->posts()->count(),
            'Obca osoba wyjęła wpis z CUDZEGO zeszytu.',
        );
    }

    public function test_obca_osoba_nie_widzi_przycisku_w_cudzym_zeszycie(): void
    {
        // Zeszyt może być publiczny, a wtedy obca osoba ogląda te same karty.
        // Nie wolno jej pokazać przycisku, który i tak niczego by nie ruszył —
        // przycisk bez skutku jest martwym przyciskiem.
        [$basia, $wpis] = $this->zapisanyWpis();

        $zeszyt = $basia->defaultCollection();
        $zeszyt->forceFill(['visibility' => 'public'])->save();

        $html = $this->actingAs($this->user('obca'))
            ->get(route('collections.show', $zeszyt))
            ->assertOk()
            ->getContent();

        $this->assertNull(
            $this->formularzWyjecia($html, $wpis),
            'Obca osoba widzi w cudzym zeszycie przycisk wyjęcia, który nic nie robi.',
        );
    }

    public function test_gosc_nie_dochodzi_do_tej_trasy(): void
    {
        // Trasa stoi za `auth`, więc gość jest zawracany. NIE przypinamy się
        // do adresu, na który zawraca (dziś strona powitalna) — to jest
        // ustawienie całego serwisu, nie właściwość tej trasy. Pilnujemy
        // tego, co tu naprawdę ważne: żaden wiersz z cudzego zeszytu nie ginie.
        [$basia, $wpis] = $this->zapisanyWpis();

        // `actingAs()` z przygotowania zostaje na kolejne żądania tego testu —
        // bez tej linii „gość" byłby dalej Basią i scena mierzyłaby siebie.
        $this->app['auth']->forgetGuards();

        $this->delete(route('collections.unsave-post', $wpis))->assertRedirect();

        $this->assertSame(1, $basia->defaultCollection()->posts()->count());
    }

    public function test_komunikat_mowi_co_sie_stalo_i_daje_droge_powrotu(): void
    {
        [$basia, $wpis] = $this->zapisanyWpis();

        $odpowiedz = $this->actingAs($basia)
            ->from(route('collections.show', $basia->defaultCollection()))
            ->delete(route('collections.unsave-post', $wpis));

        $odpowiedz->assertRedirect();

        // CO SIĘ STAŁO — po polsku i bez dwuznaczności: wpis wyszedł
        // z zeszytu, a nie z serwisu.
        $odpowiedz->assertSessionHas('status', fn (string $tekst) => str_contains($tekst, 'wyjęty z zeszytu')
            && str_contains($tekst, 'Nie usunęliśmy go z serwisu'));

        // DROGA POWROTU — przycisk, nie samo zdanie „możesz zapisać ponownie”.
        $html = $this->actingAs($basia)
            ->get(route('collections.show', $basia->defaultCollection()))
            ->assertOk()
            ->getContent();

        $powrot = $this->element($html, '//form[@class="flash-powrot"]');

        $this->assertNotNull($powrot, 'Po wyjęciu wpisu komunikat nie daje żadnej drogi powrotu.');
        $this->assertSame(route('collections.save-post', $wpis), $powrot->getAttribute('action'));

        // I ta droga naprawdę wraca — wpis jest znów w zeszycie.
        $this->actingAs($basia)->post($powrot->getAttribute('action'))->assertRedirect();

        $this->assertSame(1, $basia->defaultCollection()->posts()->count());
    }

    public function test_po_wyjeciu_karta_znow_proponuje_zapis(): void
    {
        // Ekran wpisu zostaje po wyjęciu na miejscu i pokazuje stan
        // sprzed zapisu — bez tego człowiek nie wie, czy akcja zadziałała.
        [$basia, $wpis] = $this->zapisanyWpis();

        $this->actingAs($basia)->delete(route('collections.unsave-post', $wpis))->assertRedirect();

        $html = $this->actingAs($basia)->get(route('posts.show', $wpis))->assertOk()->getContent();

        $this->assertNull($this->formularzWyjecia($html, $wpis));
        $this->assertStringNotContainsString('Masz to w zeszycie', $html);
        $this->assertStringContainsString('Zapisuję', $html);
    }

    /**
     * Wpis leżący w domyślnym zeszycie Basi.
     *
     * @param  array<string, mixed>  $atrybuty
     * @return array{0: User, 1: Post}
     */
    private function zapisanyWpis(array $atrybuty = []): array
    {
        $basia = $this->user('basia');
        $wpis = Post::factory()->create($atrybuty + ['author_id' => $this->user('autor')->getKey()]);

        $this->actingAs($basia)->post(route('collections.save-post', $wpis))->assertRedirect();

        return [$basia, $wpis->refresh()];
    }

    private function otworz(string $ekran, User $widz, Post $wpis): TestResponse
    {
        $adres = $ekran === 'zeszyt'
            ? route('collections.show', $widz->defaultCollection())
            : route('posts.show', $wpis);

        return $this->actingAs($widz)->get($adres)->assertOk();
    }

    /**
     * Formularz wyjęcia TEGO wpisu w wyrenderowanym HTML-u — albo `null`.
     *
     * ADRES NIE WYSTARCZA, I TO JEST SEDNO TEJ METODY. Zapis i wyjęcie mają
     * DOKŁADNIE TEN SAM adres (`/wpisy/{post}/zapisz`) i różni je wyłącznie
     * metoda HTTP (`routes/web.php`). Selektor po samym `action` trafiałby
     * więc w przycisk „Zapisuję" i cicho przepuszczał ekran, na którym
     * przycisku wyjęcia nie ma wcale. Rozstrzyga ukryte `_method=DELETE`.
     */
    private function formularzWyjecia(string $html, Post $wpis): ?DOMElement
    {
        $adres = route('collections.unsave-post', $wpis);

        return $this->element($html, sprintf(
            '//form[@action=%s][.//input[@name="_method"][translate(@value, "delete", "DELETE")="DELETE"]]',
            $this->cytat($adres),
        ));
    }

    /**
     * Wartość ukrytego pola formularza — albo `null`, gdy pola nie ma.
     *
     * Różnica między „nie ma pola” a „pole jest puste” jest tu całą sprawą:
     * brak `collection_id` to świadomy ZAKRES GLOBALNY (ekran wpisu), a pole
     * puste byłoby usterką. Dlatego `null` znaczy wyłącznie „nie ma”.
     */
    private function poleUkryte(DOMElement $formularz, string $nazwa): ?string
    {
        $pole = (new DOMXPath($formularz->ownerDocument))
            ->query('.//input[@name="'.$nazwa.'"]', $formularz)?->item(0);

        return $pole instanceof DOMElement ? $pole->getAttribute('value') : null;
    }

    /**
     * To, co przeglądarka NAPRAWDĘ wyśle z tego formularza — bez `_token`
     * i `_method`, którymi zajmuje się już warstwa testowa Laravela.
     *
     * Bez tego scena wysyłała pod `action` puste żądanie i cicho mierzyła
     * szeroką ścieżkę tam, gdzie człowiek klika w wąską.
     *
     * @return array<string, string>
     */
    private function ladunek(DOMElement $formularz): array
    {
        $dane = [];

        foreach ((new DOMXPath($formularz->ownerDocument))->query('.//input[@name]', $formularz) as $pole) {
            $nazwa = $pole->getAttribute('name');

            if (in_array($nazwa, ['_token', '_method'], strict: true)) {
                continue;
            }

            $dane[$nazwa] = $pole->getAttribute('value');
        }

        return $dane;
    }

    private function element(string $html, string $wyrazenie): ?DOMElement
    {
        $dokument = new DOMDocument;
        $poprzednie = libxml_use_internal_errors(true);
        $dokument->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($poprzednie);

        $trafienie = (new DOMXPath($dokument))->query($wyrazenie)?->item(0);

        return $trafienie instanceof DOMElement ? $trafienie : null;
    }

    /** XPath 1.0 nie ma znaku ucieczki w łańcuchu — apostrof trzeba obejść. */
    private function cytat(string $wartosc): string
    {
        return str_contains($wartosc, "'")
            ? 'concat("'.str_replace('"', '", \'"\', "', $wartosc).'")'
            : "'".$wartosc."'";
    }
}
