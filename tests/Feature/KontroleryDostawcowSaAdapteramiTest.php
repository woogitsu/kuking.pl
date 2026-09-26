<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\WejsciePrzezDostawce\WejdzPrzezDostawce;
use App\Http\Controllers\Auth\FacebookLoginController;
use App\Http\Controllers\Auth\GoogleLoginController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * KONTROLERY GOOGLE I FACEBOOKA SĄ ADAPTERAMI, NIE DRUGIM ŹRÓDŁEM REGUŁ
 * (issue #1035).
 *
 * Przed #1035 oba kontrolery miały własne `wpusc`, `wolnoPolaczyc`,
 * `proponowanaNazwa` i zapis sesji — 459 identycznych linii. Każda poprawka
 * wymagała zgadywania, czy stosować ją raz, dwa razy, czy tylko u jednego
 * dostawcy. Ten test oblewa, gdy wspólna logika wejścia, łączenia lub
 * zakładania konta wróci do któregokolwiek kontrolera — a wtedy właściwym
 * miejscem jest `WejdzPrzezDostawce`.
 *
 * Czego nie sprawdza: poprawności samych reguł — to robi
 * `WejdzPrzezDostawceTest` i testy HTTP obu dostawców.
 */
class KontroleryDostawcowSaAdapteramiTest extends TestCase
{
    /**
     * Ślady wspólnej logiki, której w kontrolerze dostawcy być nie może.
     * Każdy ma w `WejdzPrzezDostawce` swoje jedno miejsce.
     */
    private const WSPOLNA_LOGIKA = [
        'Auth::login(' => 'wejście na konto (wpusc / zalozKonto)',
        'ZamekKonta' => 'zapis powiązania pod blokadą (polacz)',
        'AuditLogEntry' => 'dziennik wejścia i połączenia',
        'TwoFactorAuthenticator' => 'bramka drugiego składnika (wpusc)',
        'STATUSY_ZAMKNIETEGO_KONTA' => 'bramka konta zamkniętego (odmowaWejscia)',
        'hasStaffRole(' => 'bramka konta obsługi serwisu (odmowaWejscia)',
        '->validate(' => 'walidacja ekranu domknięcia (daneDomkniecia)',
        'ExternalRegistrationDraft' => 'szkic domknięcia (#850)',
        'createFromTimestamp' => 'wygaśnięcie tożsamości w sesji',
        '->handle(' => 'założenie konta (zalozKonto)',
    ];

    /** Metody, które istniały w obu kontrolerach i mają jedno źródło. */
    private const WSPOLNE_METODY = [
        'wolnoPolaczyc', 'proponowanaNazwa', 'tozsamoscZSesji', 'zapomnijTozsamosc', 'kontoZSesji',
    ];

    /** @return array<string, array{class-string}> */
    public static function kontrolery(): array
    {
        return [
            'google' => [GoogleLoginController::class],
            'facebook' => [FacebookLoginController::class],
        ];
    }

    /** Kod bez komentarzy — opis reguły w komentarzu nie jest jej kopią. */
    private function kodBezKomentarzy(string $klasa): string
    {
        $plik = (string) (new ReflectionClass($klasa))->getFileName();
        $kod = '';

        foreach (token_get_all((string) file_get_contents($plik)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $kod .= is_array($token) ? $token[1] : $token;
        }

        return $kod;
    }

    #[Test]
    #[DataProvider('kontrolery')]
    public function test_kontroler_nie_ma_wlasnej_kopii_wspolnej_logiki(string $klasa): void
    {
        $kod = $this->kodBezKomentarzy($klasa);

        foreach (self::WSPOLNA_LOGIKA as $slad => $co) {
            $this->assertStringNotContainsString($slad, $kod,
                class_basename($klasa)." ma własną kopię: {$co}. Wspólna reguła mieszka w WejdzPrzezDostawce (#1035).");
        }

        foreach (self::WSPOLNE_METODY as $metoda) {
            $this->assertFalse(method_exists($klasa, $metoda),
                class_basename($klasa)."::{$metoda}() wróciło do kontrolera. Jedno źródło: WejdzPrzezDostawce (#1035).");
        }
    }

    #[Test]
    #[DataProvider('kontrolery')]
    public function test_kontroler_deleguje_do_wspolnego_przypadku_uzycia(string $klasa): void
    {
        $kod = $this->kodBezKomentarzy($klasa);

        foreach (['->wpusc($request', '->polacz($request', '->zalozKonto($request', '->daneDomkniecia($request', '->zapamietaj($request'] as $wywolanie) {
            $this->assertStringContainsString('$this->wejscie()'.$wywolanie, $kod,
                class_basename($klasa).' nie woła WejdzPrzezDostawce'.$wywolanie.').');
        }
    }

    /**
     * Wspólny przypadek użycia nie rozgałęzia się po dostawcy: różnice
     * przychodzą przez `DostawcaWejscia`, nie przez `if google`.
     */
    #[Test]
    public function test_wspolny_przypadek_uzycia_nie_zna_konkretnego_dostawcy(): void
    {
        $kod = $this->kodBezKomentarzy(WejdzPrzezDostawce::class);

        foreach (['Google', 'Facebook', 'google', 'facebook', 'DOSTAWCA_'] as $slad) {
            $this->assertStringNotContainsString($slad, $kod,
                "WejdzPrzezDostawce zna dostawcę ({$slad}). Różnica należy do DostawcaWejscia i jego adapterów.");
        }
    }
}
