<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\PhpIniRozmiar;
use Tests\TestCase;

/**
 * Test regresyjny audytu A31: „post_max_size 28M w docker/php.ini vs.
 * do 90 MB dopuszczane przez config/kuking.php".
 *
 * `docker/php.ini` NIE CZYTA konfiguracji Laravela — to dwa całkiem
 * niezależne pliki, w dwóch różnych ekosystemach (php-fpm i Laravel).
 * Jedyny sposób, żeby nie mogły się po cichu rozjechać, to test, który
 * czyta OBA i porównuje wynik. Bez tego testu następna zmiana
 * `max_bytes` albo `max_per_post` — bez pamiętania o `docker/php.ini` —
 * wróciłaby do dokładnie tego samego błędu: PHP odrzuca całe żądanie
 * (razem z tokenem CSRF), człowiek dostaje "Page Expired" i traci
 * wpisany tekst.
 */
class UploadLimitsAgreementTest extends TestCase
{
    /**
     * Margines na nagłówki multipart/form-data i pozostałe pola formularza
     * (tekst wpisu, token CSRF, `visibility`, `topic_id`...). To NIE jest
     * kodowanie base64 — multipart dokłada tylko kilkaset bajtów granic
     * i nagłówków na plik — ale mimo to liczymy z zapasem, nie styk w styk.
     */
    private const ZAKLADANY_NARZUT_FORMULARZA_BAJTY = 64 * 1024;

    public function test_budzet_zdjec_z_configu_miesci_sie_w_post_max_size_z_zapasem(): void
    {
        $tresc = $this->trescPhpIni();

        $postMaxSize = PhpIniRozmiar::naBajty(
            PhpIniRozmiar::dyrektywaZTekstuIni($tresc, 'post_max_size')
                ?? $this->fail('docker/php.ini nie ustawia post_max_size.'),
        );

        $maxBytes = (int) config('kuking.media.max_bytes');
        $maxPerPost = (int) config('kuking.media.max_per_post');
        $budzetAplikacji = $maxBytes * $maxPerPost;

        $this->assertLessThanOrEqual(
            $postMaxSize,
            $budzetAplikacji + self::ZAKLADANY_NARZUT_FORMULARZA_BAJTY,
            'config/kuking.php (max_bytes × max_per_post) + zapas na formularz PRZEKRACZA post_max_size '.
            'z docker/php.ini. PHP odrzuci takie żądanie w całości (razem z tokenem CSRF) — obniż '.
            'max_bytes albo max_per_post w config/kuking.php, albo podnieś post_max_size w docker/php.ini.',
        );

        // Prawdziwy, a nie tylko "mieszczący się": budżet aplikacji nie może
        // zajmować więcej niż 90% limitu PHP. Bez tego test przeszedłby
        // nawet wtedy, gdyby zapas wynosił dosłownie kilkadziesiąt bajtów —
        // co jest właśnie "liczeniem styk w styk", którego zakazuje audyt A31.
        $this->assertLessThanOrEqual(
            (int) floor($postMaxSize * 0.9),
            $budzetAplikacji,
            'Budżet zdjęć z config/kuking.php zajmuje ponad 90% post_max_size z docker/php.ini — '.
            'to policzone "styk w styk", nie "z zapasem".',
        );
    }

    public function test_pojedyncze_zdjecie_miesci_sie_w_upload_max_filesize(): void
    {
        $tresc = $this->trescPhpIni();

        $uploadMaxFilesize = PhpIniRozmiar::naBajty(
            PhpIniRozmiar::dyrektywaZTekstuIni($tresc, 'upload_max_filesize')
                ?? $this->fail('docker/php.ini nie ustawia upload_max_filesize.'),
        );

        $this->assertLessThanOrEqual(
            $uploadMaxFilesize,
            (int) config('kuking.media.max_bytes'),
            'Limit jednego zdjęcia w config/kuking.php (max_bytes) przekracza upload_max_filesize '.
            'z docker/php.ini — PHP odrzuci plik, zanim walidacja Laravela go zobaczy.',
        );
    }

    public function test_max_file_uploads_pozwala_na_tyle_plikow_ile_max_per_post(): void
    {
        $tresc = $this->trescPhpIni();

        $maxFileUploads = (int) (
            PhpIniRozmiar::dyrektywaZTekstuIni($tresc, 'max_file_uploads')
                ?? $this->fail('docker/php.ini nie ustawia max_file_uploads.')
        );

        $this->assertGreaterThanOrEqual(
            (int) config('kuking.media.max_per_post'),
            $maxFileUploads,
            'max_file_uploads w docker/php.ini jest mniejszy niż max_per_post z config/kuking.php — '.
            'PHP po cichu obcina nadmiarowe pliki z $_FILES, zanim walidacja Laravela je zobaczy.',
        );
    }

    private function trescPhpIni(): string
    {
        $sciezka = base_path('docker/php.ini');

        $tresc = file_get_contents($sciezka);

        if ($tresc === false) {
            $this->fail("Nie mogę odczytać {$sciezka}.");
        }

        return $tresc;
    }
}
