<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Notifications\Push\OdlaczUrzadzeniePush;
use App\Domain\Notifications\Push\TransportPush;
use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Models\Notification;
use App\Models\PushSubscription;
use App\Models\Recipe;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FalszywyTransportPush;
use Tests\TestCase;

/**
 * #1979: „Wyloguj się" wyłącza powiadomienia poza serwisem na TYM urządzeniu.
 *
 * Przed poprawką: Basia włącza Web Push, wylogowuje się na wspólnym
 * komputerze, a jej wiersz `push_subscriptions` dalej wskazuje tę
 * przeglądarkę — „Marek — ugotowane z Twojego przepisu…" pokazuje się na
 * ekranie, przy którym siedzi już Zenek.
 *
 * Serwer rozpoznaje urządzenie po sesji (bez JavaScriptu) albo po adresie
 * subskrypcji z formularza wylogowania (uzupełnia go skrypt; ta część ma
 * swoje testy w `resources/js/powiadomienia-push.test.mjs`).
 */
final class WylogowanieWylaczaPushNaUrzadzeniuTest extends TestCase
{
    use RefreshDatabase;

    private const KOMPUTER = 'https://fcm.googleapis.com/fcm/send/wspolny-komputer';

    private const TELEFON = 'https://updates.push.services.mozilla.com/wpush/v2/telefon-basi';

    private FalszywyTransportPush $transport;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kuking.strefa' => 'Europe/Warsaw',
            'kuking.push.vapid_public_key' => 'BTestowyKluczPublicznyNieDoWysylki',
            'kuking.push.vapid_private_key' => 'testowy-klucz-prywatny',
            'kuking.notifications.zewnetrzne.wlaczone' => true,
            'kuking.notifications.zewnetrzne.cisza_od_godziny' => 21,
            'kuking.notifications.zewnetrzne.cisza_do_godziny' => 8,
            'kuking.notifications.zewnetrzne.dzienny_limit' => 5,
        ]);

        $this->transport = new FalszywyTransportPush;
        $this->app->instance(TransportPush::class, $this->transport);

        // 12:00 w Warszawie — poza ciszą nocną.
        $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00:00', 'UTC'));
    }

    public function test_wylogowanie_bez_javascriptu_gasi_urzadzenie_wlaczone_w_tej_sesji(): void
    {
        $basia = $this->user('basia_wyloguj');
        $telefon = $this->subskrypcja($basia, self::TELEFON);

        $this->actingAs($basia)
            ->postJson(route('settings.notifications.subscribe'), $this->daneSubskrypcji(self::KOMPUTER))
            ->assertCreated()
            ->assertSessionHas(OdlaczUrzadzeniePush::KLUCZ_SESJI);

        // Zwykły formularz, bez pola `push_endpoint` — tak wysyła przeglądarka bez skryptu.
        $this->post(route('logout'))->assertRedirect(route('landing'));

        $this->assertGuest();
        $this->assertFalse(PushSubscription::query()->where('endpoint', self::KOMPUTER)->exists());
        // Kontrola dodatnia: telefon Basi, na którym się nie wylogowała, zostaje.
        $this->assertTrue($telefon->fresh() !== null);
    }

    public function test_po_wylogowaniu_zadne_kolejne_powiadomienie_nie_idzie_na_to_urzadzenie(): void
    {
        $basia = $this->user('basia_potem');
        $marek = $this->user('marek_potem', ['display_name' => 'Marek']);
        $this->subskrypcja($basia, self::TELEFON);
        $przepis = Recipe::factory()->create(['author_id' => $basia->getKey(), 'title' => 'Pierogi z kaszą']);

        $this->actingAs($basia)
            ->postJson(route('settings.notifications.subscribe'), $this->daneSubskrypcji(self::KOMPUTER))
            ->assertCreated();
        $this->post(route('logout'))->assertRedirect();

        app(RecordCookedEvent::class)->handle($marek, $przepis);

        // Powiadomienie w serwisie jest (pułapka 4 z docs/PULAPKI_TESTOW.md)…
        $this->assertSame(1, $basia->notifications()->where('type', Notification::TYPE_COOKED)->count());
        // …push wyszedł, ale tylko na telefon — nie na wspólny komputer.
        $this->assertSame([self::TELEFON], array_column($this->transport->wyslane, 'endpoint'));
    }

    /**
     * Przeglądarka włączyła powiadomienia w sesji, która już wygasła — nowa
     * sesja nic o niej nie wie. Adres z formularza (uzupełnia go skrypt)
     * wystarcza.
     */
    public function test_adres_z_formularza_gasi_urzadzenie_wlaczone_w_dawnej_sesji(): void
    {
        $basia = $this->user('basia_dawna');
        $this->subskrypcja($basia, self::KOMPUTER);
        $telefon = $this->subskrypcja($basia, self::TELEFON);

        $this->actingAs($basia)
            ->post(route('logout'), ['push_endpoint' => self::KOMPUTER])
            ->assertRedirect(route('landing'));

        $this->assertFalse(PushSubscription::query()->where('endpoint', self::KOMPUTER)->exists());
        $this->assertNotNull($telefon->fresh());
    }

    /** Samo wygaśnięcie sesji niczego nie wyłącza — tylko świadome „Wyloguj się". */
    public function test_wylogowanie_z_pustym_polem_i_bez_sesji_urzadzenia_niczego_nie_kasuje(): void
    {
        $basia = $this->user('basia_nic');
        $this->subskrypcja($basia, self::KOMPUTER);

        $this->actingAs($basia)->post(route('logout'), ['push_endpoint' => ''])->assertRedirect();

        $this->assertSame(1, PushSubscription::query()->count());
    }

    /**
     * Adres i identyfikator z zewnątrz nie są autoryzacją: przeglądarka
     * przepięta już na Zenka nie gaśnie, gdy wylogowuje się Basia.
     */
    public function test_cudzego_urzadzenia_nie_da_sie_zgasic_ani_adresem_ani_sesja(): void
    {
        $basia = $this->user('basia_cudze');
        $zenek = $this->user('zenek_cudze');
        $zenka = $this->subskrypcja($zenek, self::KOMPUTER);

        $this->actingAs($basia)
            ->withSession([OdlaczUrzadzeniePush::KLUCZ_SESJI => $zenka->getKey()])
            ->post(route('logout'), ['push_endpoint' => self::KOMPUTER])
            ->assertRedirect();

        $this->assertGuest();
        $this->assertNotNull($zenka->fresh());
        $this->assertSame($zenek->getKey(), $zenka->fresh()->user_id);
    }

    /** Śmieci w sesji albo w polu nie mogą zablokować wyjścia z konta. */
    public function test_niepoprawne_dane_nie_blokuja_wylogowania(): void
    {
        $basia = $this->user('basia_smieci');
        $this->subskrypcja($basia, self::KOMPUTER);

        $this->actingAs($basia)
            ->withSession([OdlaczUrzadzeniePush::KLUCZ_SESJI => 'to-nie-uuid'])
            ->post(route('logout'), ['push_endpoint' => ['tablica' => 'zamiast tekstu']])
            ->assertRedirect(route('landing'));

        $this->assertGuest();
        $this->assertSame(1, PushSubscription::query()->count());
    }

    /** Adres subskrypcji nie trafia do HTML-a: pole w formularzu wylogowania jest puste. */
    public function test_formularz_wylogowania_nie_zdradza_adresu_subskrypcji(): void
    {
        $basia = $this->user('basia_html');
        $this->subskrypcja($basia, self::KOMPUTER);

        $this->actingAs($basia)->get(route('home'))
            ->assertOk()
            ->assertSee('<input type="hidden" name="push_endpoint" value="" data-wyloguj-push>', false)
            ->assertDontSee('wspolny-komputer', false);
    }

    private function subskrypcja(User $user, string $endpoint): PushSubscription
    {
        $sub = new PushSubscription;
        $sub->forceFill([
            'user_id' => $user->getKey(),
            'endpoint' => $endpoint,
            'klucz_p256dh' => str_repeat('A', 87),
            'klucz_auth' => str_repeat('B', 22),
            'kodowanie' => 'aes128gcm',
        ])->save();

        return $sub;
    }

    /** @return array<string, mixed> */
    private function daneSubskrypcji(string $endpoint): array
    {
        return [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)],
            'contentEncoding' => 'aes128gcm',
        ];
    }
}
