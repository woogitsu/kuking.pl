<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\LimityZdjec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Każdy formularz przyjmuje dokładnie te formaty, które obiecuje (audyt W3-08).
 *
 * SPROSTOWANIE DO AUDYTU, BO LICZY SIĘ, CO JEST W KODZIE, A NIE CO W RAPORCIE
 * Audyt twierdził, że laravelowa reguła `image` to
 * `mimes:jpg,jpeg,png,gif,bmp,svg,webp` i że przez nią odpadał AVIF.
 * W tej wersji frameworka jest inaczej — `ValidatesAttributes::validateImage()`:
 *
 *     ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'avif', 'heic', 'heif']
 *
 * czyli AVIF przechodził, a SVG był odrzucany (wymaga jawnego `allow_svg`).
 * Sprawdzone przez uruchomienie tej macierzy na starej regule: przechodziła
 * w dziesięciu przypadkach na jedenaście.
 *
 * ROZJAZD JEST JEDNAK PRAWDZIWY, TYLKO W DRUGĄ STRONĘ
 * `image` przyjmuje GIF, BMP, HEIC i HEIF — cztery formaty, których potok
 * mediów NIE obsługuje. Taki plik przechodził walidację, po czym
 * `StoreUploadedImage` rzucał wyjątek, a kontroler zamieniał go w błąd
 * przypisany do CAŁEJ grupy `photos`, nie do konkretnego pliku. Przy czterech
 * wybranych zdjęciach człowiek nie wiedział, o które chodzi. HEIC był tu
 * najgorszy: `image` go przepuszczał, a produkt od issue #119 wprost go nie
 * obsługuje.
 *
 * Do tego komunikaty mówiły „JPG, PNG lub WebP" — trzecia lista, niezgodna
 * z pozostałymi dwiema.
 *
 * Teraz jest jedna lista i jedno sprawdzenie (`RozpoznanieZdjecia`), używane
 * i przez regułę walidacji, i przez `StoreUploadedImage`. Ten test jest
 * MACIERZĄ: każdy obiecany format razy każda droga wgrywania — poprawienie
 * jednego kontrolera z pięciu wyglądałoby na skończoną robotę.
 */
class KazdaDrogaPrzyjmujeTeSameFormatyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['kuking.media.disk' => 'public', 'kuking.media.public_disk' => 'public']);
    }

    /** Prawdziwy plik w podanym formacie — `UploadedFile::fake()` nie umie AVIF. */
    private function zdjecie(string $format): UploadedFile
    {
        $obraz = imagecreatetruecolor(120, 90);
        imagefilledrectangle($obraz, 0, 0, 119, 89, (int) imagecolorallocate($obraz, 200, 120, 60));

        $sciezka = tempnam(sys_get_temp_dir(), 'proba').'.'.$format;

        match ($format) {
            'jpg' => imagejpeg($obraz, $sciezka, 85),
            'png' => imagepng($obraz, $sciezka),
            'webp' => imagewebp($obraz, $sciezka),
            'avif' => imageavif($obraz, $sciezka, 60),
        };

        imagedestroy($obraz);

        return new UploadedFile($sciezka, "sernik.{$format}", null, null, true);
    }

    /** @return list<array{0: string}> */
    public static function formaty(): array
    {
        return [['jpg'], ['png'], ['webp'], ['avif']];
    }

    #[DataProvider('formaty')]
    public function test_wpis_przyjmuje_kazdy_obiecany_format(string $format): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)
            ->post(route('posts.store'), [
                'body' => 'Sernik wyszedł.',
                'visibility' => 'public',
                'photos' => [$this->zdjecie($format)],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseCount('media', 1);
    }

    #[DataProvider('formaty')]
    public function test_awatar_przyjmuje_kazdy_obiecany_format(string $format): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)
            ->from(route('settings.avatar'))
            // Zdjęcie profilowe ma własny ekran: `/ustawienia/zdjecie`.
            // Formularz profilu nie przyjmuje już pliku w ogóle, więc ta
            // droga NIE jest drugą obok tamtej — jest tą samą, przeniesioną.
            ->post(route('settings.avatar.update'), [
                'avatar' => $this->zdjecie($format),
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('media', 1);
    }

    public function test_gif_odpada_przy_polu_a_nie_dopiero_w_potoku(): void
    {
        // GIF to prawdziwy rozjazd: laravelowa reguła `image` go PRZYJMUJE,
        // a `accepted_mime_types` nie. Plik przechodził więc walidację, po czym
        // `StoreUploadedImage` rzucał wyjątek, a kontroler przypisywał błąd
        // całej grupie `photos` — przy czterech wybranych zdjęciach człowiek
        // nie wiedział, o które chodzi.
        //
        // Teraz błąd stoi przy KONKRETNYM pliku (`photos.0`), zgodnie
        // z docs/UX_50_PLUS.md: „błąd przy polu ORAZ w podsumowaniu".
        $obraz = imagecreatetruecolor(60, 40);
        $sciezkaGif = tempnam(sys_get_temp_dir(), 'proba').'.gif';
        imagegif($obraz, $sciezkaGif);
        imagedestroy($obraz);

        $this->actingAs($this->user('basia'))
            ->post(route('posts.store'), [
                'body' => 'Sernik.',
                'visibility' => 'public',
                'photos' => [new UploadedFile($sciezkaGif, 'animacja.gif', null, null, true)],
            ])
            ->assertSessionHasErrors('photos.0');

        $this->assertDatabaseCount('media', 0);
    }

    public function test_svg_dalej_odpada(): void
    {
        // SVG to dokument XML, który potrafi nieść skrypt. Ta wersja Laravela
        // odrzucała go już wcześniej (wymaga jawnego `allow_svg`), więc to nie
        // jest naprawa — to pilnowanie, żeby nowa reguła niczego nie rozmiękczyła.
        $sciezka = tempnam(sys_get_temp_dir(), 'proba').'.svg';
        file_put_contents(
            $sciezka,
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $this->actingAs($this->user('basia'))
            ->post(route('posts.store'), [
                'body' => 'Sernik.',
                'visibility' => 'public',
                'photos' => [new UploadedFile($sciezka, 'obrazek.svg', null, null, true)],
            ])
            ->assertSessionHasErrors('photos.0');

        $this->assertDatabaseCount('media', 0);
    }

    public function test_komunikat_bledu_wymienia_te_same_formaty_co_pole_wyboru_pliku(): void
    {
        // Trzy listy formatów w trzech miejscach to trzy okazje do rozjazdu.
        // Komunikat i atrybut `accept` muszą iść z tego samego źródła.
        $sciezka = tempnam(sys_get_temp_dir(), 'proba').'.txt';
        file_put_contents($sciezka, 'to nie jest zdjęcie');

        $odpowiedz = $this->actingAs($this->user('basia'))
            ->post(route('posts.store'), [
                'body' => 'Sernik.',
                'visibility' => 'public',
                'photos' => [new UploadedFile($sciezka, 'notatka.txt', null, null, true)],
            ]);

        $odpowiedz->assertSessionHasErrors('photos.0');

        $bledy = $odpowiedz->baseResponse->getSession()->get('errors');
        $komunikat = (string) $bledy->get('photos.0')[0];

        foreach (['JPG', 'PNG', 'WebP', 'AVIF'] as $nazwa) {
            $this->assertStringContainsString(
                $nazwa,
                $komunikat,
                "Komunikat nie wymienia formatu {$nazwa}, choć pole wyboru pliku go podpowiada: "
                .LimityZdjec::atrybutAccept(),
            );
        }
    }

    public function test_atrybut_accept_i_komunikat_wymieniaja_dokladnie_to_samo(): void
    {
        $accept = LimityZdjec::atrybutAccept();

        foreach (LimityZdjec::dozwoloneTypy() as $mime) {
            $this->assertStringContainsString($mime, $accept);
        }

        // Tyle nazw w komunikacie, ile typów w konfiguracji — ani mniej, ani
        // więcej. Ostatnia jest doklejona przez „albo", więc liczymy przecinki
        // plus to „albo", a nie same przecinki.
        $dlaCzlowieka = LimityZdjec::formatyDlaCzlowieka();

        $this->assertSame(
            count(LimityZdjec::dozwoloneTypy()),
            substr_count($dlaCzlowieka, ',') + substr_count($dlaCzlowieka, ' albo ') + 1,
            "Komunikat „{$dlaCzlowieka}\" wymienia inną liczbę formatów niż konfiguracja.",
        );
    }
}
