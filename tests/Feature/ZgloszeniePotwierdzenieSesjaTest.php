<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

class ZgloszeniePotwierdzenieSesjaTest extends TestCase
{
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    public function test_swiezy_get_nie_twierdzi_ze_przyjal_zgloszenie(): void
    {
        $html = $this->trescEkranu($this->get(route('zglos.nielegalna.potwierdzenie'))->assertOk()->getContent());
        $this->assertStringNotContainsString('Przyjęliśmy Twoje zgłoszenie', $html);
        $this->assertStringContainsString('Nie wysyłaj go ponownie tylko z tego powodu.', $html);
    }

    public function test_numer_zostaje_po_odswiezeniu_i_nie_przechodzi_do_innej_karty_ani_sesji(): void
    {
        Notification::fake();
        $receipts = [];
        foreach (['osoba@example.test', null] as $email) {
            $response = $this->post(route('zglos.nielegalna.store'), [
                'notifier_name' => 'Osoba testowa', 'notifier_email' => $email,
                'target_url' => 'https://kuking.pl/przepisy/przyklad', 'reason' => 'minor',
                'illegality_explanation' => 'Opis testowy treści wymagającej sprawdzenia.', 'good_faith' => '1',
            ])->assertRedirect();
            $this->withCookie((string) config('session.cookie'), session()->getId());
            $receipts[] = [$response->headers->get('Location'), Report::query()->latest('id')->firstOrFail()->numer_sprawy];
        }
        $this->assertNotSame($receipts[0][0], $receipts[1][0]);
        foreach ($receipts as [$url, $number]) {
            for ($i = 0; $i < 3; $i++) {
                $html = $this->trescEkranu($this->get($url)->assertOk()->getContent());
                $this->assertStringContainsString('Przyjęliśmy Twoje zgłoszenie', $html);
                $this->assertStringContainsString($number, $html);
                $this->get(route('kontakt'))->assertOk();
            }
        }
        $this->get(route('kontakt.potwierdzenie').'?'.parse_url($url, PHP_URL_QUERY))
            ->assertOk()->assertDontSee($number)->assertDontSee('Mamy Twoją wiadomość');
        $this->assertSame(2, Report::count());
        $this->travel(31)->minutes();
        $this->get($url)->assertOk()->assertDontSee($number)->assertDontSee('Przyjęliśmy Twoje zgłoszenie');
        $this->travelBack();
        session()->invalidate();
        session()->save();
        $this->withCookie((string) config('session.cookie'), session()->getId());
        $this->get($receipts[0][0])->assertOk()->assertDontSee($receipts[0][1]);
    }

    public function test_odswiezenie_nie_gubi_numeru(): void
    {
        Notification::fake();
        $response = $this->post(route('zglos.nielegalna.store'), [
            'notifier_name' => 'Osoba testowa', 'notifier_email' => null,
            'target_url' => 'https://kuking.pl/przepisy/przyklad', 'reason' => 'minor',
            'illegality_explanation' => 'Opis testowy treści wymagającej sprawdzenia.', 'good_faith' => '1',
        ])->assertRedirect();
        $this->withCookie((string) config('session.cookie'), session()->getId());
        $number = Report::sole()->numer_sprawy;
        $url = $response->headers->get('Location');
        $this->get($url)->assertOk()->assertSee($number);
        $this->get($url)->assertOk()->assertSee($number);
    }
}
