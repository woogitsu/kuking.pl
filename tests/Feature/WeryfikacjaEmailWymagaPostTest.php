<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Notifications\PotwierdzenieAdresu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * WERYFIKACJA E-MAILA POTWIERDZA DOPIERO NA `POST` (issue #1862).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ZGŁOSZENIE
 * ────────────────────────────────────────────────────────────────────────
 *
 * Do 26 września 2026 `EmailVerificationController::verify()` wołało
 * `EmailVerificationRequest::fulfill()` już na samym `GET` — czyli robił to
 * KAŻDY, kto ten link otworzył, nie tylko właściciel konta świadomie
 * potwierdzający swój adres. Skaner odnośników w bramce antywirusowej,
 * podgląd linku w kliencie pocztowym albo prefetch przeglądarki wykonują
 * dokładnie takie samo żądanie `GET`, w kontekście tej samej aktywnej
 * sesji (trasa stoi za `auth`) — i „potwierdzały" adres e-mail, zanim
 * człowiek świadomie kliknął cokolwiek.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  NAPRAWA — WZORZEC `login.link.confirm` (D-056) I `appeals.reporter`
 * ────────────────────────────────────────────────────────────────────────
 *
 * Jedna trasa, `GET` i `POST`, `signed` w trasie chroni obie metody.
 * `GET` tylko pokazuje ekran z przyciskiem (`auth.potwierdz-adres-formularz`)
 * i niczego w koncie nie zmienia. `fulfill()` woła dopiero `POST` z tego
 * przycisku — formularz POST-uje na `$request->fullUrl()`, czyli na TEN
 * SAM podpisany, wciąż ważny adres z linku w mailu, więc jeden klik w mailu
 * plus jeden przycisk na stronie wystarczają — bez nowego linku, bez
 * nowego maila.
 */
final class WeryfikacjaEmailWymagaPostTest extends TestCase
{
    use RefreshDatabase;

    private function podpisanyLink(): array
    {
        $user = $this->user(null, ['email_verified_at' => null]);
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        return [$user, $url];
    }

    /**
     * TEST REGRESYJNY: samo `GET` — czyli to, co robi prefetch albo skaner
     * linków — nie ustawia `email_verified_at`.
     */
    public function test_get_nie_potwierdza_adresu(): void
    {
        [$user, $url] = $this->podpisanyLink();

        Notification::fake();

        $this->actingAs($user)->get($url)
            ->assertOk()
            ->assertViewIs('auth.potwierdz-adres-formularz')
            ->assertSee('Potwierdź adres e-mail');

        $this->assertFalse($user->fresh()->hasVerifiedEmail(),
            'Sam GET (np. prefetch albo skaner linków) potwierdził adres e-mail — regresja #1862.');

        // Powtórzone GET-y (skaner potrafi odpytać link kilka razy) też nic
        // nie zmieniają — kontrola, że to nie jest kwestia jednego żądania.
        $this->actingAs($user)->get($url)->assertOk();
        $this->actingAs($user)->get($url)->assertOk();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());

        Notification::assertNothingSent();
    }

    /**
     * KONTROLA DODATNIA: `POST` na ten sam, wciąż podpisany adres
     * potwierdza adres jednokrotnie i jest odporny na ponowienie.
     */
    public function test_post_potwierdza_adres_jednokrotnie(): void
    {
        [$user, $url] = $this->podpisanyLink();

        $this->actingAs($user)->post($url)
            ->assertRedirect(route('home'))
            ->assertSessionHas('status', 'Adres e-mail potwierdzony. Dziękujemy.');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        // Ponowny POST tym samym, wciąż podpisanym linkiem (np. dwukrotne
        // kliknięcie przycisku) nie wywraca niczego i nie jest błędem.
        $this->actingAs($user)->post($url)->assertRedirect(route('home'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    /**
     * Drugie kliknięcie linku po tym, jak adres jest już potwierdzony
     * (np. inną drogą), po prostu wraca na stronę główną — GET i POST
     * zachowują się tu tak samo, żadne z nich nie pokazuje formularza
     * do potwierdzenia czegoś, co już jest potwierdzone.
     */
    public function test_juz_potwierdzony_adres_wraca_na_strone_glowna(): void
    {
        $user = $this->user(null, ['email_verified_at' => now()]);
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect(route('home'));
        $this->actingAs($user)->post($url)->assertRedirect(route('home'));
    }

    /**
     * Link z maila działa jednym kliknięciem plus jednym przyciskiem: GET
     * pokazuje formularz akcji wskazujący dokładnie na adres, z którego
     * przyszło żądanie (podpis i termin ważności linku bez zmian), a nie
     * na nowo wygenerowany, niepodpisany adres.
     */
    public function test_formularz_wraca_na_ten_sam_podpisany_adres(): void
    {
        [$user, $url] = $this->podpisanyLink();

        $odpowiedz = $this->actingAs($user)->get($url)->assertOk();

        // `assertSee` porównuje HTML już wyrenderowany przez Blade'a, więc
        // `&` w query string wychodzi jako encja `&amp;` — porównujemy więc
        // ten sam, poprawnie zescapowany napis.
        $odpowiedz->assertSee('action="'.e($url).'"', false);
    }

    public function test_notice_wysyla_link_ktory_naprawde_dziala_tylko_klikiem_i_przyciskiem(): void
    {
        [$user] = $this->podpisanyLink();

        $this->assertFalse($user->hasVerifiedEmail());

        // Sam widok formularza nie wysyła żadnej dodatkowej wiadomości.
        Notification::fake();
        $this->actingAs($user)->get(route('verification.notice'))->assertOk();
        Notification::assertNotSentTo($user, PotwierdzenieAdresu::class);
    }
}
