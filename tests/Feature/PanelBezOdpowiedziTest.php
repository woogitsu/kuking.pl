<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\Comment;
use App\Models\Notification;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Panel gospodarza: wpisy bez odpowiedzi (issue #6).
 *
 * NAJTWARDSZA LICZBA Z CAŁEGO RESEARCHU
 * 55% osób 55-64 i 62% osób 65+ w mediach społecznościowych to WYŁĄCZNIE
 * odbiorcy treści (docs/research/AUDIENCE_50_PLUS.md). Kto opublikuje, robi
 * to wbrew własnemu nawykowi.
 *
 * Wniosek: odpowiedź od człowieka w ciągu doby na pierwszy wpis jest
 * ważniejsza niż którakolwiek funkcja z MVP. Wpis bez żadnej reakcji to
 * koniec — ta osoba już nie wróci.
 */
class PanelBezOdpowiedziTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Gospodarz — moderator o STAŁEJ nazwie użytkownika.
     *
     * `TestCase::moderator()` losuje nazwę, a issue #6 wiąże powiadomienie
     * o pierwszym wpisie z `kuking.community.host_username`. Bez stałej nazwy
     * nie da się sprawdzić, że alert trafia do właściwej osoby.
     */
    private ?User $gospodarz = null;

    private function gospodarz(): User
    {
        // Pamiętane w polu, bo `user()` przy każdym wywołaniu ZAKŁADA konto —
        // a nazwa `gospodarz` musi być stała i jedna, żeby dało się sprawdzić,
        // do kogo trafia alert o pierwszym wpisie.
        if ($this->gospodarz !== null) {
            return $this->gospodarz;
        }

        $gospodarz = $this->user('gospodarz', ['role' => User::ROLE_MODERATOR]);

        // 2FA potwierdzone — inaczej middleware EnsureModeratorHasTwoFactor
        // (issue #12) blokuje wejście do panelu `/admin/bez-odpowiedzi`,
        // zanim ten test w ogóle sprawdzi coś ze swojej właściwej sprawy.
        $totp = app(TwoFactorAuthenticator::class);
        $gospodarz->beginTwoFactorSetup($totp->generateSecret());
        $gospodarz->confirmTwoFactor($totp->hashBackupCodes($totp->generateBackupCodes()));

        return $this->gospodarz = $gospodarz->refresh();
    }

    private function wpis(User $autor, string $tresc, mixed $kiedy = null): Post
    {
        return Post::factory()->create([
            'author_id' => $autor->getKey(),
            'body' => $tresc,
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => $kiedy ?? now(),
        ]);
    }

    // ---------------------------------------------------------------
    // Dostęp
    // ---------------------------------------------------------------

    public function test_zwykly_uzytkownik_dostaje_404_a_nie_403(): void
    {
        // 404, nie 403: istnienie panelu moderacji nie jest informacją,
        // którą warto potwierdzać komukolwiek.
        $this->actingAs($this->user('basia'))->get(route('admin.unanswered'))->assertNotFound();

        // Gość dostaje 404, a nie przekierowanie na logowanie: informacja
        // „ten adres istnieje, tylko musisz się zalogować" też jest informacją.
        $this->get(route('admin.unanswered'))->assertNotFound();
    }

    // ---------------------------------------------------------------
    // Lista
    // ---------------------------------------------------------------

    public function test_wpis_z_komentarzem_znika_z_listy(): void
    {
        $bezOdpowiedzi = $this->wpis($this->user('basia'), 'Rosol bez odpowiedzi');
        $zOdpowiedzia = $this->wpis($this->user('marek'), 'Sernik z odpowiedzia');

        Comment::factory()->create([
            'post_id' => $zOdpowiedzia->getKey(),
            'author_id' => $this->gospodarz()->getKey(),
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        $this->actingAs($this->gospodarz())->get(route('admin.unanswered'))
            ->assertOk()
            ->assertSee('Rosol bez odpowiedzi')
            ->assertDontSee('Sernik z odpowiedzia');

        $this->assertNotNull($bezOdpowiedzi);
    }

    public function test_pierwszy_wpis_osoby_jest_oznaczony_i_na_gorze(): void
    {
        $weteran = $this->user('weteran');

        // Pierwszy wpis weterana dostał już odpowiedź — schodzi z listy.
        $odpowiedziany = $this->wpis($weteran, 'Stary wpis weterana', now()->subDays(5));
        Comment::factory()->create([
            'post_id' => $odpowiedziany->getKey(),
            'author_id' => $this->gospodarz()->getKey(),
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        // Kolejny wpis weterana czeka DŁUŻEJ niż wpis nowej osoby.
        $this->wpis($weteran, 'Nowszy wpis weterana', now()->subHours(30));

        $nowa = $this->user('nowa');
        $this->wpis($nowa, 'Pierwszy wpis nowej osoby', now()->subHour());

        $odpowiedz = $this->actingAs($this->gospodarz())->get(route('admin.unanswered'))->assertOk();
        $lista = $odpowiedz->viewData('wpisy');

        // NAJWAŻNIEJSZA ASERCJA W TYM PLIKU.
        //
        // Pierwszy wpis idzie na górę, MIMO że czeka o dwadzieścia dziewięć
        // godzin krócej. To nie jest kolejka FIFO — to lista ułożona według
        // tego, gdzie brak odpowiedzi kosztuje najwięcej. Wpis weterana bez
        // reakcji jest przykry; pierwszy wpis bez reakcji kończy czyjąś
        // obecność w serwisie.
        $this->assertSame('Pierwszy wpis nowej osoby', $lista->first()->body);
        $this->assertTrue($lista->first()->toPierwszyWpis);
        $this->assertSame('Nowszy wpis weterana', $lista->last()->body);
        $this->assertFalse($lista->last()->toPierwszyWpis);

        $odpowiedz->assertSee('Pierwszy wpis tej osoby');
    }

    public function test_starszy_pierwszy_wpis_wyprzedza_nowszy_pierwszy_wpis(): void
    {
        $this->wpis($this->user('wczorajsza'), 'Pierwszy wpis sprzed doby', now()->subHours(26));
        $this->wpis($this->user('dzisiejsza'), 'Pierwszy wpis sprzed godziny', now()->subHour());

        $lista = $this->actingAs($this->gospodarz())->get(route('admin.unanswered'))
            ->assertOk()->viewData('wpisy');

        // Wśród pierwszych wpisów decyduje czas czekania. Doba to cały budżet,
        // jaki mamy na odpowiedź — ten wpis właśnie go wyczerpał.
        $this->assertSame('Pierwszy wpis sprzed doby', $lista->first()->body);
    }

    public function test_progi_pilnosci_licza_sie_od_publikacji(): void
    {
        $this->wpis($this->user('ala'), 'Swiezy', now()->subHour());
        $this->wpis($this->user('bogdan'), 'Uwaga', now()->subHours(8));
        $this->wpis($this->user('celina'), 'Alarm', now()->subHours(30));

        $lista = $this->actingAs($this->gospodarz())->get(route('admin.unanswered'))
            ->assertOk()->viewData('wpisy')->keyBy('body');

        $this->assertSame('spokojnie', $lista['Swiezy']->pilnosc);
        $this->assertSame('uwaga', $lista['Uwaga']->pilnosc);
        $this->assertSame('alarm', $lista['Alarm']->pilnosc);
    }

    public function test_wpis_prywatny_nie_czeka_na_odpowiedz(): void
    {
        Post::factory()->create([
            'author_id' => $this->user('basia')->getKey(),
            'body' => 'Notatka tylko dla mnie',
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'private',
            'published_at' => now(),
        ]);

        // ASERCJA KONTROLNA: identyczny wpis PUBLICZNY, też bez komentarza,
        // MUSI być na liście.
        //
        // Bez niej „nie widać wpisu prywatnego" przechodziło także wtedy, gdy
        // lista nie pokazywała NICZEGO — a to jest awaria, nie poprawność.
        // Zmierzone: po zawężeniu warunku widoczności w
        // `BezOdpowiedziController::index()` do pustego zbioru (lista zawsze
        // pusta) ten test był dalej zielony, choć trzy inne w tym pliku padły.
        $this->wpis($this->user('marek'), 'Publiczny wpis bez odpowiedzi');

        // Nikt poza autorem tego nie widzi, więc brak komentarza nie jest
        // problemem — a lista ma pokazywać rzeczy do zrobienia, nie wszystko.
        $this->actingAs($this->gospodarz())->get(route('admin.unanswered'))
            ->assertOk()
            ->assertSee('Publiczny wpis bez odpowiedzi')
            ->assertDontSee('Notatka tylko dla mnie');
    }

    public function test_licznik_mowi_ile_czeka_i_jak_dlugo(): void
    {
        $this->wpis($this->user('ala'), 'Jeden', now()->subHours(30));
        $this->wpis($this->user('bogdan'), 'Dwa', now()->subHours(2));

        $this->actingAs($this->gospodarz())->get(route('admin.unanswered'))
            ->assertOk()
            // Odmiana liczebnika przez wspólną regułę — „2 wpisów czeka"
            // brzmiałoby jak automat, a ten ekran ma brzmieć jak notatka.
            ->assertSee('2 wpisy czekają', escape: false)
            ->assertSee('Najstarszy czeka 30 godzin', escape: false);
    }

    // ---------------------------------------------------------------
    // Odpowiedź wprost z listy
    // ---------------------------------------------------------------

    public function test_odpowiedz_z_listy_zdejmuje_wpis_z_listy(): void
    {
        $wpis = $this->wpis($this->user('basia'), 'Czeka na odpowiedz');
        $gospodarz = $this->gospodarz();

        $this->actingAs($gospodarz)
            ->post(route('admin.unanswered.reply', $wpis), ['body' => 'Ale ładnie wyszło!'])
            ->assertRedirect();

        // Wejście we wpis i powrót przy dwudziestu pozycjach to dwadzieścia
        // przeładowań strony — playbook przestaje być wykonalny.
        $this->assertSame(1, $wpis->allComments()->count());

        $this->actingAs($gospodarz)->get(route('admin.unanswered'))
            ->assertOk()
            ->assertDontSee('Czeka na odpowiedz');
    }

    public function test_zwykly_uzytkownik_nie_odpowie_przez_ten_endpoint(): void
    {
        $wpis = $this->wpis($this->user('basia'), 'Czeka');

        $this->actingAs($this->user('obcy'))
            ->post(route('admin.unanswered.reply', $wpis), ['body' => 'Podszywam sie'])
            ->assertNotFound();

        $this->assertSame(0, $wpis->allComments()->count());
    }

    // ---------------------------------------------------------------
    // Alert o pierwszym wpisie
    // ---------------------------------------------------------------

    public function test_pierwszy_wpis_powiadamia_gospodarza(): void
    {
        Storage::fake('public');
        $gospodarz = $this->gospodarz();
        config(['kuking.community.host_username' => 'gospodarz']);

        $nowa = $this->user('nowa');

        $this->actingAs($nowa)->post(route('posts.store'), [
            'body' => 'Moj pierwszy rosol',
            'visibility' => 'public',
        ])->assertRedirect();

        $this->assertSame(1, Notification::query()
            ->where('user_id', $gospodarz->getKey())
            ->where('type', Notification::TYPE_FIRST_POST)
            ->count());
    }

    public function test_drugi_wpis_juz_nie_powiadamia(): void
    {
        Storage::fake('public');
        $gospodarz = $this->gospodarz();
        config(['kuking.community.host_username' => 'gospodarz']);

        $nowa = $this->user('nowa');

        foreach (['Pierwszy', 'Drugi'] as $tresc) {
            $this->actingAs($nowa)->post(route('posts.store'), [
                'body' => $tresc,
                'visibility' => 'public',
            ])->assertRedirect();
        }

        // Druga strona reguły: alert o KAŻDYM wpisie zamieniłby powiadomienia
        // gospodarza w szum, a wtedy przestałby je czytać — i pierwszy wpis
        // nowej osoby utonąłby razem z resztą.
        $this->assertSame(1, Notification::query()
            ->where('user_id', $gospodarz->getKey())
            ->where('type', Notification::TYPE_FIRST_POST)
            ->count());
    }

    public function test_pierwszy_wpis_prywatny_nie_powiadamia(): void
    {
        Storage::fake('public');
        $gospodarz = $this->gospodarz();
        config(['kuking.community.host_username' => 'gospodarz']);

        $this->actingAs($this->user('nowa'))->post(route('posts.store'), [
            'body' => 'Notatka dla siebie',
            'visibility' => 'private',
        ])->assertRedirect();

        // Nikt poza autorem tego nie widzi, więc nie ma na co odpowiadać.
        $this->assertSame(0, Notification::query()
            ->where('type', Notification::TYPE_FIRST_POST)
            ->count());
    }
}
