<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Contact\DzwonekOperatora;
use App\Logging\KanalyAlarmowe;
use App\Mail\AlarmOperacyjny;
use App\Models\ContactMessage;
use App\Poczta\DziennyBudzetListow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * „Napisz do nas" także mailem (decyzja właściciela 30.09.2026).
 *
 * Dzwonek operatora idzie przez `KanalyAlarmowe`, więc przy ustawionym
 * `KUKING_ALARM_EMAIL` wychodzi też listem. Kontrakt:
 *   1. list niesie DOKŁADNIE to, co Discord — identyfikator, rodzaj, adres
 *      panelu — i nic z tego, co wpisał człowiek;
 *   2. formularz wypełnia każdy, więc listy o wiadomościach mają WŁASNY,
 *      niższy sufit dobowy i NIE zjadają sufitu alarmów o awariach;
 *   3. po wyczerpaniu sufitu Discord dostaje dalej każdą wiadomość.
 */
final class DzwonekOperatoraMailemTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK = 'https://discord.example.test/api/webhooks/000/tajny-token/slack';

    private const SKRZYNKA = 'operator-alarmy@kuking.test';

    private const TRESC_WIADOMOSCI = 'Nie mogę się zalogować. Moja córka Kasia próbowała z Warszawy i też nie działa.';

    private const ADRES_CZLOWIEKA = 'basia.kowalska@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->ustaw(webhook: null, poczta: null);
    }

    private function ustaw(?string $webhook, ?string $poczta): void
    {
        config()->set('logging.channels.blad_webhook.url', $webhook);
        config()->set('logging.channels.blad_email.adres', $poczta);
        Log::forgetChannel(KanalyAlarmowe::DISCORD);
        Log::forgetChannel(KanalyAlarmowe::POCZTA);
    }

    private function wiadomosc(): ContactMessage
    {
        return ContactMessage::factory()->create([
            'kind' => ContactMessage::KIND_BLAD,
            'message' => self::TRESC_WIADOMOSCI,
            'contact_email' => self::ADRES_CZLOWIEKA,
            'page_path' => '/przepisy/rosol-babci',
        ]);
    }

    /** @return list<string> */
    private function naDiscordzie(): array
    {
        return Http::recorded()
            ->filter(fn (array $para): bool => $para[0]->url() === self::WEBHOOK)
            ->map(fn (array $para): string => (string) ($para[0]['text'] ?? ''))
            ->values()
            ->all();
    }

    #[Test]
    public function wyslany_formularz_przychodzi_listem_bez_danych_czlowieka(): void
    {
        Mail::fake();
        Http::fake();
        $this->ustaw(webhook: null, poczta: self::SKRZYNKA);

        $this->post(route('kontakt.store'), [
            'kind' => ContactMessage::KIND_BLAD,
            'message' => self::TRESC_WIADOMOSCI,
            'contact_email' => self::ADRES_CZLOWIEKA,
        ])->assertRedirectContains(route('kontakt.potwierdzenie').'?potwierdzenie=');

        $zapisana = ContactMessage::sole();

        Http::assertNothingSent();
        Mail::assertSentCount(1);
        Mail::assertSent(AlarmOperacyjny::class, function (AlarmOperacyjny $list) use ($zapisana): bool {
            $this->assertTrue($list->hasTo(self::SKRZYNKA));
            // Ta sama treść co na Discordzie — lista dozwolonych pól.
            $this->assertStringContainsString(app(DzwonekOperatora::class)->tresc($zapisana), $list->tresc);
            $this->assertStringContainsString((string) $zapisana->getKey(), $list->tresc);
            $this->assertStringStartsWith('Kontakt: ', $list->temat);

            foreach ([$list->tresc, $list->temat] as $tekst) {
                foreach ([self::TRESC_WIADOMOSCI, 'Kasia', 'Warszawy', self::ADRES_CZLOWIEKA, 'basia.kowalska'] as $czegoNieWolno) {
                    $this->assertStringNotContainsString($czegoNieWolno, $tekst);
                }
            }

            return true;
        });

        $this->assertSame(1, DziennyBudzetListow::dlaDzwonkaKontaktu()->zuzyte());
        $this->assertSame(0, DziennyBudzetListow::dlaAlarmuOperacyjnego()->zuzyte(), 'Wiadomość od człowieka zjadła miejsce w suficie alarmów o awariach.');
    }

    #[Test]
    public function wlasny_sufit_kontaktu_nie_zjada_alarmow_a_discord_dostaje_kazda_wiadomosc(): void
    {
        Mail::fake();
        Http::fake([self::WEBHOOK => Http::response('ok', 200)]);
        config()->set('kuking.poczta.kontakt_operatora_na_dobe', 2);
        config()->set('kuking.poczta.alarm_operacyjny_na_dobe', 1);
        $this->ustaw(webhook: self::WEBHOOK, poczta: self::SKRZYNKA);

        $dzwonek = app(DzwonekOperatora::class);
        for ($i = 0; $i < 5; $i++) {
            $dzwonek->zadzwon($this->wiadomosc());
        }

        // Skrzynka: dwie wiadomości i koniec do północy — bez zalewu.
        Mail::assertSentCount(2);
        $this->assertCount(5, array_filter(
            $this->naDiscordzie(),
            fn (string $t): bool => str_contains($t, 'Napisz do nas'),
        ));

        // Sufit alarmów (tu: jeden list) jest nietknięty — prawdziwa awaria
        // po fali wiadomości dalej przychodzi pocztą.
        $this->assertSame(0, DziennyBudzetListow::dlaAlarmuOperacyjnego()->zuzyte());
        $this->assertTrue(KanalyAlarmowe::zadzwon('czujka: baza nie odpowiada'));
        $this->assertTrue(KanalyAlarmowe::wyniki()[KanalyAlarmowe::POCZTA]);
        Mail::assertSentCount(3);
        Mail::assertSent(AlarmOperacyjny::class, fn (AlarmOperacyjny $l): bool => str_starts_with($l->temat, 'Alarm: ')
            && str_contains($l->tresc, 'baza nie odpowiada'));
    }

    #[Test]
    public function sufit_zero_wylacza_listy_o_wiadomosciach_a_zostawia_discorda(): void
    {
        Mail::fake();
        Http::fake([self::WEBHOOK => Http::response('ok', 200)]);
        config()->set('kuking.poczta.kontakt_operatora_na_dobe', 0);
        $this->ustaw(webhook: self::WEBHOOK, poczta: self::SKRZYNKA);

        app(DzwonekOperatora::class)->zadzwon($this->wiadomosc());

        Mail::assertNothingSent();
        Http::assertSent(fn (Request $r): bool => str_contains((string) ($r->data()['text'] ?? ''), 'Napisz do nas'));
    }

    #[Test]
    public function awaria_poczty_nie_przewraca_wyslanego_formularza(): void
    {
        $this->ustaw(webhook: null, poczta: self::SKRZYNKA);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('EmailLabs leży.'));

        $this->post(route('kontakt.store'), [
            'kind' => ContactMessage::KIND_INNE,
            'message' => 'Chciałam tylko powiedzieć, że fajnie tu u Was.',
        ])->assertRedirectContains(route('kontakt.potwierdzenie').'?potwierdzenie=');

        $this->assertSame(1, ContactMessage::count());
        $this->assertSame(0, DziennyBudzetListow::dlaDzwonkaKontaktu()->zuzyte(), 'Nieudany list zjadł miejsce w suficie.');
    }
}
