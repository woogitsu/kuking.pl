<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * „Link cofnięcia przy odmowie logowania” (D-333, decyzja właściciela z 30.09.2026).
 *
 * Przy odmowie wejścia na konto CZEKAJĄCE na usunięcie adres strony
 * cofnięcia stał w zdaniu jako zwykły tekst (`KomunikatZamknietegoKonta`,
 * `EnsureAccountIsActive::wyloguj()`) — błąd przy polu i komunikat w layoucie
 * nie mają odnośników, więc na telefonie trzeba go było przepisać. Teraz pod
 * komunikatami na górze strony logowania stoi przycisk „Cofnij usunięcie
 * konta” (`status_akcja`, ten sam mechanizm co #2245), a zdanie wskazuje go
 * słowami.
 *
 * Każdy test czyta KOŃCOWY HTML strony logowania (po przekierowaniu), bo to
 * jest to, co widzi człowiek. Drogi przez Google i Facebooka pilnują
 * `LogowanieKontemGoogleTest` i `LogowanieKontemFacebookiemTest`.
 */
class OdmowaLogowaniaPrzyciskCofnieciaTest extends TestCase
{
    use RefreshDatabase;

    private function kontoDoUsuniecia(): User
    {
        $basia = $this->user('basia');
        DB::table('users')->where('id', $basia->getKey())->update([
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now(),
        ]);

        return $basia->refresh();
    }

    /** @return list<string> adresy przycisków `status_akcja` pod komunikatami */
    private function przyciskiPodKomunikatem(string $html): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $xpath = new \DOMXPath($dom);

        $adresy = [];
        foreach ($xpath->query('//p[contains(concat(" ", normalize-space(@class), " "), " flash-akcja ")]//a') as $a) {
            $this->assertInstanceOf(\DOMElement::class, $a);
            $this->assertStringContainsString('btn', $a->getAttribute('class'), 'Odnośnik ma być przyciskiem (cel ≥ 48 px, klasa .btn).');
            $adresy[$a->getAttribute('href')] = trim($a->textContent);
        }

        return array_map(fn ($href, $etykieta) => $etykieta.' → '.$href, array_keys($adresy), $adresy);
    }

    /** Treść błędu przy polu „login” z końcowego HTML-a (sesja jest już po odczycie). */
    private function bladPrzyLoginie(string $html): string
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $xpath = new \DOMXPath($dom);
        $bledy = $xpath->query('//*[@id="f-login-error"]');

        return $bledy->length > 0 ? trim($bledy->item(0)->textContent) : '';
    }

    private function oczekiwanyPrzycisk(): string
    {
        return 'Cofnij usunięcie konta → '.route('account.delete.cancel');
    }

    private function zalogujHaslem(string $haslo = 'haslo-testowe-123'): TestResponse
    {
        return $this->from(route('login'))->followingRedirects()->post(route('login'), [
            'login' => 'basia',
            'password' => $haslo,
        ]);
    }

    public function test_odmowa_logowania_haslem_ma_przycisk_cofniecia_a_zdanie_nie_ma_adresu(): void
    {
        $this->kontoDoUsuniecia();

        $html = (string) $this->zalogujHaslem()->assertOk()->getContent();

        $this->assertGuest();
        $this->assertSame([$this->oczekiwanyPrzycisk()], $this->przyciskiPodKomunikatem($html),
            'Pod komunikatami na stronie logowania nie ma przycisku „Cofnij usunięcie konta”.');

        $blad = $this->bladPrzyLoginie($html);
        $this->assertStringContainsString('oznaczone do usunięcia', $blad);
        $this->assertStringContainsString('przycisku „Cofnij usunięcie konta” na górze tej strony', $blad);
        $this->assertStringNotContainsString(route('account.delete.cancel'), $blad,
            'Adres strony cofnięcia wrócił do zdania odmowy jako zwykły tekst.');
        $this->assertStringNotContainsString('http', $blad);
    }

    /**
     * Kontrola ujemna na tej samej ścieżce: złe hasło do tego samego konta
     * nie pokazuje przycisku. Inaczej samo wpisanie cudzego loginu zdradzałoby,
     * że to konto czeka na usunięcie (odmowa zapada dopiero po sprawdzeniu hasła).
     */
    public function test_zle_haslo_do_konta_do_usuniecia_nie_pokazuje_przycisku(): void
    {
        $this->kontoDoUsuniecia();

        $html = (string) $this->zalogujHaslem('zupelnie-nie-to-haslo')->assertOk()->getContent();

        $this->assertGuest();
        $this->assertSame([], $this->przyciskiPodKomunikatem($html));
        $blad = $this->bladPrzyLoginie($html);
        $this->assertNotSame('', $blad, 'Odmowa nie dotarła do pola (kontrola dodatnia).');
        $this->assertStringNotContainsString('oznaczone do usunięcia', $blad);
    }

    public function test_konto_zablokowane_i_wymazane_nie_dostaja_przycisku_cofniecia(): void
    {
        $basia = $this->user('basia');

        foreach ([User::STATUS_BANNED, User::STATUS_ERASED] as $status) {
            DB::table('users')->where('id', $basia->getKey())->update([
                'status' => $status,
                'data_erased_at' => $status === User::STATUS_ERASED ? now() : null,
            ]);

            $html = (string) $this->zalogujHaslem()->assertOk()->getContent();

            $this->assertGuest();
            $this->assertSame([], $this->przyciskiPodKomunikatem($html),
                "Konto o stanie {$status} nie ma czego cofać — przycisk byłby obietnicą bez pokrycia.");
            $this->assertNotSame('', $this->bladPrzyLoginie($html),
                "Odmowa dla stanu {$status} nie dotarła do pola (kontrola dodatnia).");
        }
    }

    public function test_wylogowanie_konta_do_usuniecia_z_otwartej_sesji_ma_przycisk_cofniecia(): void
    {
        // Sesja zalogowana, a konto jest już w karencji (np. prośba z drugiego
        // urządzenia) — `EnsureAccountIsActive` wylogowuje przy kolejnym żądaniu.
        $this->actingAs($this->kontoDoUsuniecia());

        $odpowiedz = $this->get(route('settings.data'));
        $odpowiedz->assertRedirect(route('login'));
        $this->assertSame(
            ['url' => route('account.delete.cancel'), 'etykieta' => 'Cofnij usunięcie konta'],
            session('status_akcja'),
        );

        $html = (string) $this->get(route('login'))->assertOk()->getContent();

        $this->assertGuest();
        $this->assertSame([$this->oczekiwanyPrzycisk()], $this->przyciskiPodKomunikatem($html));

        $blad = $this->bladPrzyLoginie($html);
        $this->assertStringContainsString('wylogowaliśmy Cię z serwisu', $blad);
        $this->assertStringNotContainsString(route('account.delete.cancel'), $blad);
    }

    /**
     * API (aplikacja) nie ma przycisku z `status_akcja`, więc tam adres
     * zostaje w zdaniu — inaczej osoba z aplikacji nie miałaby żadnej drogi.
     */
    public function test_api_zachowuje_adres_w_zdaniu_bo_nie_ma_tam_przycisku(): void
    {
        config(['kuking.api.wlaczone' => true]);
        $this->kontoDoUsuniecia();

        $odpowiedz = $this->postJson('/api/v1/tokeny', [
            'login' => 'basia',
            'password' => 'haslo-testowe-123',
            'device_name' => 'Telefon Basi',
        ]);

        $odpowiedz->assertStatus(422);
        $this->assertStringContainsString(route('account.delete.cancel'), (string) $odpowiedz->json('errors.login.0'));
        $this->assertStringNotContainsString('na górze tej strony', (string) $odpowiedz->json('errors.login.0'));
    }
}
