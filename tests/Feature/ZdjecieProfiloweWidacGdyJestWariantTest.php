<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

/**
 * Zdjęcie profilowe widać, gdy istnieje WARIANT — issue #448.
 *
 * CO BYŁO ŹLE
 * `resources/views/components/avatar.blade.php` i ekran `/ustawienia/zdjecie`
 * pytały o `Media::isReady()`, czyli o STAN WIERSZA w tabeli `media`. Wiersz
 * robi się `ready` dopiero wtedy, gdy zadanie w tle skończy liczyć warianty —
 * a człowiek jest na ekranie natychmiast po wgraniu. W miejscu swojej twarzy
 * dostawał więc napis o przygotowywaniu, także wtedy, gdy plik nadający się
 * do pokazania już istniał.
 *
 * Zdjęcie profilowe wgrywa się RAZ, na początku, i jest to pierwszy moment,
 * w którym ktoś 50+ sprawdza, czy „to działa". Napis zamiast twarzy kosztuje
 * tu najwięcej.
 *
 * REGUŁA PO ZMIANIE
 * Widok pyta o PLIK, który wyszedł z naszego kodera — przez
 * `Profile::zdjecieDoPokazania()`, a pod nim `Media::wariantDoSerwowania()`,
 * czyli jedyne miejsce w serwisie, które wie, który wariant idzie do
 * przeglądarki. Bez drugiej kopii tej reguły: dwie kopie rozjechałyby się
 * przy pierwszej zmianie listy wariantów.
 *
 * REGUŁA SIĘ ZWĘZIŁA, A NIE ROZSZERZYŁA — i tego pilnują dwa testy niżej:
 *   • wiersz przejęty do skasowania (`deleted`) nie pokazuje NICZEGO, choć
 *     klucze wariantów zostają w nim do czasu sprzątnięcia plików;
 *   • ORYGINAŁ (plik wgrany przez człowieka, z lokalizacją kuchni w EXIF-ie)
 *     nie jest wariantem i jego klucz nie pojawia się w dokumencie.
 *
 * OGRANICZENIE TEGO PLIKU, NAPISANE WPROST
 * Testy sprawdzają WIDOK. Nie dowodzą, że trasa `media.show` odda bajty
 * wariantu wiersza, który nie jest jeszcze `ready` — o tym rozstrzyga
 * `App\Domain\Media\DostepDoZdjecia` i to jest osobna praca (#430).
 */
class ZdjecieProfiloweWidacGdyJestWariantTest extends TestCase
{
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    public function test_ekran_ustawien_pokazuje_zdjecie_zanim_wiersz_zrobi_sie_ready(): void
    {
        $czlowiek = $this->zKtoregoWlasnieWgranoZdjecie('halina');

        $html = (string) $this->actingAs($czlowiek)
            ->get(route('settings.avatar'))
            ->assertOk()
            ->getContent();

        $tresc = $this->trescEkranu($html);

        $this->assertMatchesRegularExpression(
            '/<img[^>]*class="avatar"/',
            $tresc,
            'Ekran zdjęcia profilowego pokazuje inicjał zamiast zdjęcia, choć wariant '.
            'dla tego wiersza już istnieje. Widok pyta o status wiersza, a nie o plik.',
        );

        $this->assertStringContainsString(
            'To jest Twoje zdjęcie.',
            $tresc,
            'Podpis pod zdjęciem nie zgadza się z obrazkiem obok — a to jedno i to samo '.
            'pytanie zadane dwa razy.',
        );

        // Asercji „czegoś nie ma" NIE zwężamy do `<main>` (pułapka 1b
        // z `docs/PULAPKI_TESTOW.md`) — szersze spojrzenie jest ostrożniejsze.
        $this->assertStringNotContainsString(
            'Twoje nowe zdjęcie się przygotowuje',
            $html,
            'Ekran dalej każe czekać na zdjęcie, które można już pokazać.',
        );

        // ORYGINAŁ NIE MA PRAWA NIGDZIE TRAFIĆ: to jest plik przysłany przez
        // człowieka, z nietkniętym EXIF-em, czyli z lokalizacją jego kuchni.
        $this->assertStringNotContainsString(
            $czlowiek->profile->avatar->object_key,
            $html,
            'W dokumencie stoi klucz ORYGINAŁU. Oryginał nie jest wariantem i nie wolno '.
            'go podać przeglądarce ani nazwać.',
        );
    }

    public function test_wlasny_profil_i_pasek_gorny_pokazuja_zdjecie_zanim_wiersz_zrobi_sie_ready(): void
    {
        $czlowiek = $this->zKtoregoWlasnieWgranoZdjecie('basia');

        $html = (string) $this->actingAs($czlowiek)
            ->get(route('profile.show', $czlowiek->profile->username))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<img[^>]*class="avatar"/',
            $this->trescEkranu($html),
            'Na własnym profilu w główce stoi inicjał, a nie wgrane przed chwilą zdjęcie.',
        );

        // PASEK GÓRNY OSOBNO, NIE PRZEZ CAŁY DOKUMENT: awatar stoi na tej
        // stronie także w główce profilu, więc asercja po całym HTML-u
        // przechodziłaby również wtedy, gdyby z belki zniknął (pułapka 1).
        $this->assertMatchesRegularExpression(
            '/<img[^>]*class="avatar"/',
            $this->pasekGorny($html),
            'W pasku górnym stoi inicjał, a nie wgrane przed chwilą zdjęcie.',
        );
    }

    /**
     * Wiersz przejęty do skasowania nie pokazuje NICZEGO.
     *
     * `KasujZdjecie::przejmij()` oznacza wiersz jako `deleted` PRZED
     * skasowaniem plików i zostawia w `metadata.variants` klucze potrzebne do
     * sprzątania. Gdyby widok pytał wyłącznie „czy jest wariant", zdjęcie
     * odchodzące wróciłoby na ekran — a przy wymazaniu konta i przy decyzji
     * moderacyjnej to jest dokładnie ten wiersz, który ma zniknąć.
     *
     * KONTROLA DODATNIA JEST W TYM SAMYM TEŚCIE (pułapka 4): obok karty
     * osoby ze zdjęciem przejętym do skasowania stoi karta osoby, której
     * zdjęcie ma wariant i JEST widoczne. Bez tej pary test przechodziłby
     * także wtedy, gdyby strumień nie pokazywał niczyjego zdjęcia.
     */
    public function test_wiersz_przejety_do_skasowania_nie_pokazuje_niczego(): void
    {
        $odchodzace = $this->zKtoregoWlasnieWgranoZdjecie('zofia', Media::STATUS_DELETED);
        $widoczne = $this->zKtoregoWlasnieWgranoZdjecie('marek');

        Post::factory()->create(['author_id' => $odchodzace->getKey(), 'body' => 'Placki ziemniaczane u Zofii.']);
        Post::factory()->create(['author_id' => $widoczne->getKey(), 'body' => 'Rosół u Marka.']);

        $html = (string) $this->get(route('discover'))->assertOk()->getContent();

        $kartaOdchodzacej = $this->kartaWpisu($html, 'Placki ziemniaczane u Zofii.');
        $kartaWidocznego = $this->kartaWpisu($html, 'Rosół u Marka.');

        // KONTROLA DODATNIA — mechanizm naprawdę pracował na tym ekranie.
        $this->assertMatchesRegularExpression(
            '/<img[^>]*class="avatar"/',
            $kartaWidocznego,
            'Żadne zdjęcie profilowe nie doszło do strumienia, więc brak zdjęcia przy karcie '.
            'obok nie dowodzi niczego.',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/<img[^>]*class="avatar"/',
            $kartaOdchodzacej,
            'Zdjęcie przejęte do skasowania wróciło na ekran. Reguła „pokazujemy wariant" '.
            'rozszerzyła się zamiast zwęzić.',
        );

        $klucze = $odchodzace->profile->avatar->metadata['variants']['thumb']['key'];

        $this->assertStringNotContainsString(
            $klucze,
            $html,
            'W dokumencie stoi klucz wariantu zdjęcia przejętego do skasowania.',
        );

        $this->assertStringNotContainsString(
            $odchodzace->profile->avatar->object_key,
            $html,
            'W dokumencie stoi klucz ORYGINAŁU zdjęcia przejętego do skasowania.',
        );
    }

    /**
     * Ekran ustawień nie obiecuje czekania na zdjęcie, które odchodzi.
     *
     * KONTROLA DODATNIA W TYM SAMYM TEŚCIE: ten sam ekran, obejrzany przez
     * osobę ze zdjęciem mającym wariant, mówi „To jest Twoje zdjęcie".
     */
    public function test_ekran_ustawien_nie_kaze_czekac_na_zdjecie_ktore_odchodzi(): void
    {
        $odchodzace = $this->zKtoregoWlasnieWgranoZdjecie('wanda', Media::STATUS_DELETED);

        $html = (string) $this->actingAs($odchodzace)
            ->get(route('settings.avatar'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'Nie masz jeszcze swojego zdjęcia.',
            $this->trescEkranu($html),
            'Ekran nie mówi wprost, że zdjęcia nie ma.',
        );

        $this->assertStringNotContainsString(
            'Twoje nowe zdjęcie się przygotowuje',
            $html,
            'Ekran obiecuje, że zdjęcie się przygotowuje — a ten wiersz jest przejęty do '.
            'skasowania i nic się z nim już nie stanie. To jest obietnica, która się nie '.
            'spełni (ta sama klasa błędu co issue #112).',
        );

        $this->assertStringNotContainsString(
            'Usuń zdjęcie',
            $html,
            'Pod zdaniem „nie masz jeszcze swojego zdjęcia" stoi przycisk „Usuń zdjęcie" — '.
            'dwa zdania przeczące sobie na jednym ekranie.',
        );

        // KONTROLA DODATNIA: ten sam ekran, ta sama asercja, wiersz z wariantem.
        $widoczne = $this->zKtoregoWlasnieWgranoZdjecie('stefania');

        $trescWidocznego = $this->trescEkranu((string) $this->actingAs($widoczne)
            ->get(route('settings.avatar'))
            ->assertOk()
            ->getContent());

        $this->assertStringContainsString(
            'To jest Twoje zdjęcie.',
            $trescWidocznego,
            'Ekran nie pokazuje zdjęcia NIKOMU, więc jego milczenie przy zdjęciu odchodzącym '.
            'nie dowodzi niczego.',
        );

        $this->assertStringContainsString(
            'Usuń zdjęcie',
            $trescWidocznego,
            'Przycisk usuwania zniknął także tam, gdzie zdjęcie naprawdę jest.',
        );
    }

    /**
     * Konto ze zdjęciem, które ma już wariant, ale wiersz jest dopiero
     * `pending` — czyli dokładnie stan zaraz po wgraniu.
     *
     * Status podajemy jawnie, bo to jest jedyna rzecz, którą ten pomocnik
     * naprawdę różnicuje między testami.
     */
    private function zKtoregoWlasnieWgranoZdjecie(string $nazwa, string $status = Media::STATUS_PENDING): User
    {
        $czlowiek = $this->user($nazwa, []);

        $zdjecie = Media::factory()->create([
            'owner_id' => $czlowiek->getKey(),
            'status' => $status,
            'object_key' => "incoming/{$nazwa}/2026/09/oryginal-{$nazwa}.jpg",
            'metadata' => [
                'variants' => [
                    'thumb' => ['key' => "media/{$nazwa}/2026/09/wariant-{$nazwa}_thumb.webp", 'width' => 320, 'height' => 320],
                ],
            ],
        ]);

        $czlowiek->profile->forceFill(['avatar_media_id' => $zdjecie->getKey()])->save();

        return $czlowiek->refresh();
    }

    /** `<header class="topbar">` jako HTML — główka profilu też jest `<header>`, ale bez tej klasy. */
    private function pasekGorny(string $html): string
    {
        return $this->wezelPoXPath(
            $html,
            "//header[contains(concat(' ', normalize-space(@class), ' '), ' topbar ')]",
            'Nie znalazłem paska górnego — strona wygląda na pustą.',
        );
    }

    /** Karta wpisu rozpoznana po jego treści, a nie po kolejności w strumieniu. */
    private function kartaWpisu(string $html, string $tresc): string
    {
        $wycinek = $this->wezelPoXPath(
            $html,
            "//article[contains(concat(' ', normalize-space(@class), ' '), ' post-card ')][.//*[contains(text(), ".$this->xpathLiteral($tresc).')]]',
            "Nie znalazłem karty wpisu z treścią „{$tresc}\" — strumień jej nie pokazał.",
        );

        $this->assertStringContainsString($tresc, $wycinek);

        return $wycinek;
    }

    private function wezelPoXPath(string $html, string $zapytanie, string $komunikat): string
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        $wezel = (new DOMXPath($dom))->query($zapytanie)?->item(0);

        $this->assertInstanceOf(DOMElement::class, $wezel, $komunikat);

        $wycinek = (string) $dom->saveHTML($wezel);

        // Pusty kontener przechodzi każdą asercję „czegoś tu nie ma"
        // (pułapki 2 i 4 z `docs/PULAPKI_TESTOW.md`).
        $this->assertGreaterThan(200, mb_strlen($wycinek), 'Wycinek jest podejrzanie krótki — nie wyrenderował się.');

        return $wycinek;
    }

    private function xpathLiteral(string $tekst): string
    {
        return "'".$tekst."'";
    }
}
