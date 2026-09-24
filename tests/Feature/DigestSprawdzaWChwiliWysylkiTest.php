<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Digest\OdnosnikWypisania;
use App\Domain\Social\Actions\BlockUser;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Tygodniowy list sprawdza adresata i treść W CHWILI WYSYŁKI, nie tylko
 * przy kolejkowaniu (#1328, #1383).
 *
 * Każdy test idzie PRAWDZIWĄ drogą: komenda → `Mail::queue()` →
 * `jobs.payload` (kolejka `database`) → zmiana stanu w bazie → wykonanie
 * zadania → transport `array`. Nic nie wychodzi poza proces: `MAIL_MAILER`
 * jest `array`, a asercje czytają wiadomość z transportu, nie
 * `Mail::assertQueued()` — ta druga mówi tylko, że list wszedł do kolejki.
 */
class DigestSprawdzaWChwiliWysylkiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('queue.default', 'database');
        config()->set('mail.default', 'array');
        config()->set('kuking.digest.wlaczony', true);
        config()->set('kuking.digest.dzienny_limit', 60);
        config()->set('kuking.digest.odstep_dni', 7);
        config()->set('kuking.digest.okno_dni', 7);
        config()->set('kuking.digest.odstep_sekund', 20);
        config()->set('kuking.digest.max_pozycji', 3);
    }

    /** Odbiorca obserwujący autora, który w tym tygodniu pokazał wpis. */
    private function odbiorcaZWpisem(string $tresc = 'Pierogi z kaszą od babci Heli', string $widocznosc = Post::VISIBILITY_FOLLOWERS): array
    {
        $odbiorca = $this->user('odbiorca1328', ['display_name' => 'Odbiorca Listu']);
        // Autorka bez zgody: inaczej dostałaby własny list o nowym obserwującym
        // i w kolejce byłyby dwa zadania zamiast jednego.
        $autor = $this->user('autor1383', ['display_name' => 'Autorka Wpisu', 'wants_weekly_digest' => false]);
        $odbiorca->following()->attach($autor->getKey());

        $wpis = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'body' => $tresc,
            'visibility' => $widocznosc,
            'published_at' => now()->subDay(),
        ]);

        return [$odbiorca, $autor, $wpis];
    }

    private function zakolejkuj(): void
    {
        Artisan::call('kuking:wyslij-podsumowania');
        $this->assertDatabaseCount('jobs', 1);
    }

    /** @return list<Email> */
    private function wykonajZadania(): array
    {
        foreach (DB::table('jobs')->orderBy('id')->pluck('payload') as $payload) {
            $zadanie = unserialize(json_decode((string) $payload, true, flags: JSON_THROW_ON_ERROR)['data']['command']);
            $this->assertInstanceOf(SendQueuedMailable::class, $zadanie);
            app()->call([$zadanie, 'handle']);
        }

        $transport = app('mailer')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        return array_map(
            static fn ($wiadomosc): Email => $wiadomosc->getOriginalMessage(),
            iterator_to_array($transport->messages()),
        );
    }

    private function trescListu(Email $list): string
    {
        return (string) $list->getHtmlBody().(string) $list->getTextBody().(string) $list->getSubject();
    }

    // -----------------------------------------------------------------
    //  KONTROLA DODATNIA — bez zmian list wychodzi z treścią
    // -----------------------------------------------------------------

    public function test_bez_zmian_list_wychodzi_z_trescia_na_aktualny_adres(): void
    {
        [$odbiorca] = $this->odbiorcaZWpisem();
        $this->zakolejkuj();

        $listy = $this->wykonajZadania();

        $this->assertCount(1, $listy);
        $this->assertSame($odbiorca->email, $listy[0]->getTo()[0]->getAddress());
        $this->assertStringContainsString('Pierogi z kaszą od babci Heli', $this->trescListu($listy[0]));
        $this->assertStringContainsString('Autorka Wpisu', $this->trescListu($listy[0]));
    }

    // -----------------------------------------------------------------
    //  #1328 — adresat sprawdzany od nowa
    // -----------------------------------------------------------------

    public function test_wypisanie_po_kolejkowaniu_zatrzymuje_list(): void
    {
        [$odbiorca] = $this->odbiorcaZWpisem();
        $this->zakolejkuj();

        $this->post(OdnosnikWypisania::dla($odbiorca))->assertOk();

        $this->assertSame([], $this->wykonajZadania());
    }

    public function test_konto_zawieszone_po_kolejkowaniu_nie_dostaje_listu(): void
    {
        [$odbiorca] = $this->odbiorcaZWpisem();
        $this->zakolejkuj();

        $odbiorca->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->assertSame([], $this->wykonajZadania());
    }

    public function test_zmiana_adresu_po_kolejkowaniu_kieruje_list_na_nowy_adres(): void
    {
        [$odbiorca] = $this->odbiorcaZWpisem();
        $stary = $odbiorca->email;
        $this->zakolejkuj();

        $odbiorca->forceFill(['email' => 'nowy-adres-1328@example.test', 'email_verified_at' => now()])->save();

        $listy = $this->wykonajZadania();

        $this->assertCount(1, $listy);
        $adresy = array_map(static fn ($a): string => $a->getAddress(), $listy[0]->getTo());
        $this->assertSame(['nowy-adres-1328@example.test'], $adresy);
        $this->assertNotContains($stary, $adresy);
    }

    public function test_adres_niepotwierdzony_po_zmianie_nie_dostaje_listu(): void
    {
        [$odbiorca] = $this->odbiorcaZWpisem();
        $this->zakolejkuj();

        $odbiorca->forceFill(['email' => 'literowka-1328@example.test', 'email_verified_at' => null])->save();

        $this->assertSame([], $this->wykonajZadania());
    }

    // -----------------------------------------------------------------
    //  #1383 — treść czytana świeżo, z bramką widoczności
    // -----------------------------------------------------------------

    public function test_usuniety_wpis_nie_trafia_do_listu_a_pusty_list_nie_wychodzi(): void
    {
        [, , $wpis] = $this->odbiorcaZWpisem();
        $this->zakolejkuj();

        $wpis->delete();

        $this->assertSame([], $this->wykonajZadania());
    }

    public function test_wpis_przestawiony_na_prywatny_nie_trafia_do_listu(): void
    {
        [, , $wpis] = $this->odbiorcaZWpisem();
        $this->zakolejkuj();

        $wpis->forceFill(['visibility' => Post::VISIBILITY_PRIVATE])->save();

        $this->assertSame([], $this->wykonajZadania());
    }

    public function test_koniec_obserwowania_zdejmuje_wpis_z_listu(): void
    {
        [$odbiorca, $autor] = $this->odbiorcaZWpisem();
        $this->zakolejkuj();

        $odbiorca->following()->detach($autor->getKey());

        $this->assertSame([], $this->wykonajZadania());
    }

    public function test_blokada_zdejmuje_wpis_z_listu(): void
    {
        [$odbiorca, $autor] = $this->odbiorcaZWpisem();
        $this->zakolejkuj();

        app(BlockUser::class)->handle($autor, $odbiorca);

        $this->assertSame([], $this->wykonajZadania());
    }

    public function test_poprawiony_wpis_idzie_w_aktualnym_brzmieniu(): void
    {
        [, , $wpis] = $this->odbiorcaZWpisem('Pierwsza wersja z adresem domowym');
        $this->zakolejkuj();

        $wpis->forceFill(['body' => 'Poprawiona wersja bez adresu'])->save();

        $listy = $this->wykonajZadania();

        $this->assertCount(1, $listy);
        $this->assertStringContainsString('Poprawiona wersja bez adresu', $this->trescListu($listy[0]));
        $this->assertStringNotContainsString('Pierwsza wersja z adresem domowym', $this->trescListu($listy[0]));
    }

    public function test_znikniecie_jednej_pozycji_zostawia_reszte_listu(): void
    {
        [$odbiorca, , $wpis] = $this->odbiorcaZWpisem('Wpis do usunięcia');
        $kucharz = $this->user('kucharz1383', ['display_name' => 'Kucharz Z Wykonaniem']);
        $przepis = Recipe::factory()->for($odbiorca, 'author')->create(['title' => 'Rosół odbiorcy']);
        $wykonanie = CookedEvent::factory()->for($kucharz, 'user')->for($przepis, 'recipe')->create([
            'cooked_at' => now()->subDay(),
            'note' => 'Wyszło świetnie',
        ]);
        $this->zakolejkuj();

        $wpis->delete();
        $wykonanie->forceFill(['note' => 'Notatka poprawiona po kolejkowaniu'])->save();

        $listy = $this->wykonajZadania();

        $this->assertCount(1, $listy);
        $tresc = $this->trescListu($listy[0]);
        $this->assertStringNotContainsString('Wpis do usunięcia', $tresc);
        $this->assertStringContainsString('Kucharz Z Wykonaniem', $tresc);
        $this->assertStringContainsString('Notatka poprawiona po kolejkowaniu', $tresc);
        $this->assertStringNotContainsString('Wyszło świetnie', $tresc);
    }

    public function test_cofniete_ugotowanie_nie_trafia_do_listu(): void
    {
        $odbiorca = $this->user('autor_przepisu1383');
        $kucharz = $this->user('kucharz_cofa1383');
        $przepis = Recipe::factory()->for($odbiorca, 'author')->create();
        $wykonanie = CookedEvent::factory()->for($kucharz, 'user')->for($przepis, 'recipe')->create([
            'cooked_at' => now()->subDay(),
        ]);
        $this->zakolejkuj();

        $wykonanie->delete();

        $this->assertSame([], $this->wykonajZadania());
    }
}
