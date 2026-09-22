<?php

declare(strict_types=1);

namespace Tests\Feature;

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
 *  1. przycisk stoi na obu ekranach, na których widać „Masz to w zeszycie”;
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
     * (`ZapisyWpisu::dolicz()` / `doliczDoWpisu()`), wraz z nazwą przycisku,
     * która ma na nich stać.
     *
     * NAZWA ZALEŻY OD EKRANU, BO ZALEŻY OD ZAKRESU (D-231). W środku
     * konkretnego zeszytu wyjmujemy z TEGO zeszytu, więc przycisk nazywa się
     * „Usuń z tego zeszytu". Poza zeszytem nie ma „tego zeszytu", do którego
     * dałoby się odnieść, więc zakres jest globalny i przycisk nazywa się
     * „Usuń z zeszytu" — tak samo jak na stronie przepisu.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function ekrany(): array
    {
        return [
            'zeszyt' => ['zeszyt', 'Usuń z tego zeszytu'],
            'karta wpisu' => ['wpis', 'Usuń z zeszytu'],
        ];
    }

    #[DataProvider('ekrany')]
    public function test_na_ekranie_ze_stanem_zapisu_stoi_przycisk_wyjecia(string $ekran, string $nazwaPrzycisku): void
    {
        [$basia, $wpis] = $this->zapisanyWpis(['body' => 'Rosół na niedzielę.']);

        $html = $this->otworz($ekran, $basia, $wpis)->getContent();

        // Scena ma mierzyć kartę W STANIE „zapisany" — inaczej mierzyłaby
        // sama siebie. Wpis musi więc najpierw BYĆ na ekranie...
        $this->assertStringContainsString('Rosół na niedzielę.', $html, "Ekran „{$ekran}” nie pokazuje wcale tego wpisu.");

        // ...a poza zeszytem ten stan widać dodatkowo jako zdanie „Masz to
        // w zeszycie". W samym zeszycie tego zdania CELOWO nie ma (D-231):
        // prowadzi do listy zeszytów, a człowiek stojący W zeszycie już wie,
        // że wpis tam leży.
        if ($ekran === 'zeszyt') {
            $this->assertStringNotContainsString('Masz to w zeszycie', $html, 'W zeszycie odnośnik stanu nie ma już stać (D-231).');
        } else {
            $this->assertStringContainsString('Masz to w zeszycie', $html, "Ekran „{$ekran}” nie pokazuje stanu zapisu.");
        }

        $formularz = $this->formularzWyjecia($html, $wpis);

        $this->assertNotNull(
            $formularz,
            "Ekran „{$ekran}” pokazuje zapisany wpis, ale nie daje żadnej drogi wyjęcia go z zeszytu (audyt L1).",
        );

        $przycisk = (new DOMXPath($formularz->ownerDocument))->query('.//button', $formularz)?->item(0);

        $this->assertInstanceOf(DOMElement::class, $przycisk, 'Formularz wyjęcia nie ma przycisku.');
        $this->assertStringContainsString($nazwaPrzycisku, $przycisk->textContent);
    }

    /**
     * DOKŁADNIE JEDNA DROGA WYJĘCIA NA EKRAN — TO JEST SEDNO D-231.
     *
     * #789 (przycisk globalny wszędzie) i #776 (przycisk lokalny w zeszycie)
     * powstały równolegle i nie wiedziały o sobie. Złożone wprost dawały na
     * ekranie zeszytu DWA przyciski o prawie identycznych nazwach i różnym
     * zasięgu — „Usuń z zeszytu" i „Usuń z tego zeszytu" jeden pod drugim.
     * Ta scena jest jedynym dowodem, że to się nie wróci: liczy formularze
     * wyjęcia i czyta nazwę tego jedynego.
     */
    #[DataProvider('ekrany')]
    public function test_na_ekranie_jest_dokladnie_jedna_droga_wyjecia(string $ekran, string $nazwaPrzycisku): void
    {
        [$basia, $wpis] = $this->zapisanyWpis();

        $html = $this->otworz($ekran, $basia, $wpis)->getContent();

        $formularze = $this->formularzeWyjecia($html, $wpis);

        $this->assertCount(
            1,
            $formularze,
            sprintf(
                'Ekran „%s” pokazuje %d dróg wyjęcia wpisu z zeszytu zamiast jednej. Dwie prawie identyczne nazwy o różnym zasięgu są dla tej grupy gorsze niż brak którejkolwiek (D-231).',
                $ekran,
                count($formularze),
            ),
        );

        $przycisk = (new DOMXPath($formularze[0]->ownerDocument))->query('.//button', $formularze[0])?->item(0);
        $this->assertInstanceOf(DOMElement::class, $przycisk);
        $this->assertStringContainsString($nazwaPrzycisku, $przycisk->textContent);

        // I nazwa TEJ DRUGIEJ drogi nie pada nigdzie na tym ekranie — także
        // poza formularzem (nagłówek, podpowiedź, menu „…").
        // (Jedna nazwa nie jest podciągiem drugiej — po „Usuń z " stoi
        // w wariancie lokalnym słowo „tego" — więc zwykłe szukanie wystarcza.)
        $druga = $nazwaPrzycisku === 'Usuń z zeszytu' ? 'Usuń z tego zeszytu' : 'Usuń z zeszytu';

        $this->assertStringNotContainsString(
            $druga,
            $html,
            "Na ekranie „{$ekran}” pada też nazwa drugiej drogi wyjęcia („{$druga}”) — a ma być wyłącznie „{$nazwaPrzycisku}”.",
        );
    }

    #[DataProvider('ekrany')]
    public function test_droga_wyjecia_dziala_bez_javascriptu(string $ekran, string $nazwaPrzycisku): void
    {
        // Martwy przycisk jest gorszy niż brak przycisku (AGENTS.md §5, D-053).
        // Tu nie ma powodu na skrypt: to jest zwykły formularz, więc sprawdzamy
        // jego KSZTAŁT — metodę, podmianę metody, token i brak czegokolwiek,
        // co bez JavaScriptu przestaje działać.
        [$basia, $wpis] = $this->zapisanyWpis();

        $html = $this->otworz($ekran, $basia, $wpis)->getContent();
        $formularz = $this->formularzWyjecia($html, $wpis);

        $this->assertNotNull($formularz, "Brak formularza wyjęcia na ekranie „{$ekran}” (przycisk „{$nazwaPrzycisku}”).");

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

        // I to, co ten formularz wysyła, naprawdę wyjmuje wpis — RAZEM
        // z ukrytymi polami, bo to one niosą zakres (`collection_id`).
        $pola = [];
        foreach ($xpath->query('.//input[@type="hidden"]', $formularz) as $ukryte) {
            $pola[$ukryte->getAttribute('name')] = $ukryte->getAttribute('value');
        }

        unset($pola['_method'], $pola['_token']);

        $this->actingAs($basia)
            ->from($formularz->getAttribute('action'))
            ->delete($formularz->getAttribute('action'), $pola)
            ->assertRedirect();

        $this->assertSame(0, $basia->defaultCollection()->posts()->count());
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
        // z zeszytu, a nie z serwisu. I droga powrotu naprawdę PRZYWRACA,
        // a nie zapisuje od nowa: `remove()` oddaje zdjęte wiersze razem
        // z notatką, `restore()` odkłada je tam, skąd zeszły (D-242), więc
        // komunikat może obiecać przywrócenie, nie samo „zapisz ponownie".
        $odpowiedz->assertSessionHas('status', fn (string $tekst) => str_contains($tekst, 'wyjęty z zeszytu')
            && str_contains($tekst, 'Nie usunęliśmy go z serwisu')
            && str_contains($tekst, 'przywrócić'));

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

        // …a etykieta mówi „przywróć", nie „zapisz ponownie", bo to jest
        // teraz prawda: wraca ten sam wiersz, nie nowy (D-242).
        $przycisk = (new DOMXPath($powrot->ownerDocument))->query('.//button', $powrot)?->item(0);
        $this->assertInstanceOf(DOMElement::class, $przycisk);
        $this->assertStringContainsString('Przywróć do zeszytu', $przycisk->textContent);
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
        return $this->formularzeWyjecia($html, $wpis)[0] ?? null;
    }

    /**
     * WSZYSTKIE formularze wyjęcia tego wpisu — bo sednem D-231 jest ICH
     * LICZBA, a metoda oddająca „pierwszy trafiony" zielenieje tak samo przy
     * jednym przycisku, jak przy dwóch.
     *
     * @return list<DOMElement>
     */
    private function formularzeWyjecia(string $html, Post $wpis): array
    {
        $adres = route('collections.unsave-post', $wpis);

        return $this->elementy($html, sprintf(
            '//form[@action=%s][.//input[@name="_method"][translate(@value, "delete", "DELETE")="DELETE"]]',
            $this->cytat($adres),
        ));
    }

    private function element(string $html, string $wyrazenie): ?DOMElement
    {
        return $this->elementy($html, $wyrazenie)[0] ?? null;
    }

    /** @return list<DOMElement> */
    /** @return list<DOMElement> */
    private function elementy(string $html, string $wyrazenie): array
    {
        $dokument = new DOMDocument;
        $poprzednie = libxml_use_internal_errors(true);
        $dokument->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($poprzednie);

        $trafienia = [];

        foreach ((new DOMXPath($dokument))->query($wyrazenie) ?: [] as $trafienie) {
            if ($trafienie instanceof DOMElement) {
                $trafienia[] = $trafienie;
            }
        }

        return $trafienia;
    }

    /** XPath 1.0 nie ma znaku ucieczki w łańcuchu — apostrof trzeba obejść. */
    private function cytat(string $wartosc): string
    {
        return str_contains($wartosc, "'")
            ? 'concat("'.str_replace('"', '", \'"\', "', $wartosc).'")'
            : "'".$wartosc."'";
    }
}
