<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\DziennyBudzetListow;
use App\Mail\PodsumowanieTygodnia;
use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;
use App\Notifications\LinkDoLogowania;
use App\Notifications\PotwierdzenieAdresu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * BIULETYN NIE ZJADA LISTÓW REJESTRACJI I LOGOWANIA — na prawdziwych drogach
 * (decyzja właściciela: ochrona rejestracji i logowania przed biuletynem).
 *
 * `WspolnyLicznikPocztyTest` sprawdza kolejność wygaszania na samym
 * liczniku. Ten plik sprawdza to samo tam, gdzie człowiek to odczuje:
 * prawdziwa komenda `kuking:wyslij-podsumowania` z kolejką chętnych
 * odbiorców większą niż pula, a zaraz po niej prawdziwy formularz
 * rejestracji i prawdziwy formularz logowania linkiem.
 *
 * I DRUGA RZECZ, POWIEDZIANA WPROST: co się dzieje, gdy pula jest pusta
 * DO ZERA. Wspólny licznik nie tworzy listów z niczego — klasa `wejscie`
 * sięga po ostatni list doby, ale gdy i on wyszedł, list logowania nie
 * wyjdzie. Zachowanie jest wtedy jawne: człowiek czyta, że listu nie będzie,
 * że może zalogować się hasłem i gdzie odpisuje człowiek. Test niżej pilnuje
 * tego zdania, żeby nikt nie uznał pustej puli za „list w drodze".
 */
class BiuletynNieZabieraListowWejsciaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // `MAIL_MAILER=array` to dla `App\Support\Poczta` „poczta nie
        // działa" — formularz logowania linkiem chowa się wtedy w całości.
        // Listy i tak nie wychodzą: `Mail::fake()` i `Notification::fake()`.
        config(['mail.default' => 'smtp']);

        config([
            // Mała pula w proporcjach produkcyjnych (300 / 240 / 100 / 0).
            'kuking.poczta.limit_dostawcy_dobowy' => 10,
            'kuking.poczta.progi_wygaszania.podsumowanie' => 8,
            'kuking.poczta.progi_wygaszania.zwykla' => 4,
            'kuking.poczta.progi_wygaszania.wejscie' => 0,
            // Sufity własne wyżej niż pula — wąskim gardłem ma być wspólny
            // licznik, bo to jego tu mierzymy.
            'kuking.login_link.dzienny_budzet' => 100,
            'kuking.digest.dzienny_limit' => 100,
            'kuking.digest.wlaczony' => true,
            'kuking.digest.odstep_dni' => 7,
            'kuking.digest.okno_dni' => 7,
            'kuking.digest.odstep_sekund' => 20,
            'kuking.digest.max_pozycji' => 3,
        ]);
    }

    public function test_biuletyn_zostawia_miejsce_na_rejestracje_i_logowanie(): void
    {
        Mail::fake();
        Notification::fake();

        for ($numer = 0; $numer < 6; $numer++) {
            $this->autorZWykonaniem('czytelnik_'.$numer);
        }

        Artisan::call('kuking:wyslij-podsumowania');

        // Pula 10, próg podsumowania 8 — biuletyn bierze najwyżej 2 listy,
        // choć chętnych jest sześcioro, a jego własny sufit to 100.
        Mail::assertQueuedCount(2);
        Mail::assertQueued(PodsumowanieTygodnia::class);

        // Rejestracja nowej osoby — list potwierdzający MUSI wyjść.
        $this->post(route('register'), [
            'display_name' => 'Halina',
            'username' => 'halina',
            'email' => 'halina@example.com',
            'password' => 'zielonapietruszkarano',
            'age_confirmed' => '1',
            'terms_accepted' => '1',
        ])->assertRedirect();

        $halina = User::query()->where('email', 'halina@example.com')->firstOrFail();
        // Gdyby tu padło: biuletyn zabrał list potwierdzający rejestrację —
        // nowa osoba nie wejdzie do serwisu.
        Notification::assertSentTo($halina, PotwierdzenieAdresu::class);

        // Logowanie linkiem osoby, która już ma konto — link MUSI wyjść.
        $this->app['auth']->logout();
        $this->flushSession();

        $basia = $this->user('basia', ['email' => 'basia@example.com']);

        $this->from(route('login.link'))
            ->post(route('login.link.send'), ['email' => 'basia@example.com'])
            ->assertRedirect(route('login.link'));

        // Gdyby tu padło: biuletyn zabrał link do logowania — osoba, dla
        // której to jedyna droga, nie wróci.
        Notification::assertSentTo($basia, LinkDoLogowania::class);

        $this->assertSame(
            4,
            DziennyBudzetListow::wspolny(DziennyBudzetListow::KLASA_WEJSCIE)->zuzyte(),
            'Wspólna pula nie policzyła któregoś z listów: 2 podsumowania + potwierdzenie + link.',
        );
    }

    /**
     * PULA PUSTA DO ZERA: list logowania NIE wychodzi — i ekran mówi to
     * wprost, zamiast obiecywać wiadomość, która nie przyjdzie.
     *
     * To jest świadoma granica tej zmiany, nie przeoczenie: wspólny licznik
     * ustawia KOLEJNOŚĆ wygaszania, a nie dokłada listów ponad limit
     * dostawcy. Klasa `wejscie` (pierwsze potwierdzenie rejestracji,
     * logowanie linkiem; ponowienie od D-246 ma własną klasę) dzieli ostatnie listy doby między siebie.
     */
    public function test_przy_pustej_puli_logowanie_linkiem_mowi_prawde_i_podaje_wyjscie(): void
    {
        Notification::fake();

        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue(DziennyBudzetListow::dlaPotwierdzeniaAdresu()->sprobujZarezerwowac());
        }

        $basia = $this->user('basia', ['email' => 'basia@example.com']);

        $odpowiedz = $this->from(route('login.link'))
            ->post(route('login.link.send'), ['email' => 'basia@example.com']);

        $odpowiedz->assertRedirect(route('login.link'));
        Notification::assertNotSentTo($basia, LinkDoLogowania::class);

        $komunikat = (string) $odpowiedz->getSession()->get('status', '');

        $this->assertStringContainsString('nie czekaj na niego', $komunikat);
        $this->assertStringContainsString('Zaloguj się hasłem', $komunikat);
        $this->assertStringContainsString((string) config('kuking.community.contact_email'), $komunikat);

        $this->assertSame(
            0,
            DziennyBudzetListow::dlaLinkuLogowania()->zuzyte(),
            'Odmowa wspólnej puli zajęła miejsce w suficie logowania linkiem, nie wysławszy listu.',
        );
    }

    /** Osoba, której przepis ktoś w tym tygodniu ugotował — ma powód dostać list. */
    private function autorZWykonaniem(string $nazwa): User
    {
        $autor = $this->user($nazwa);
        $kucharz = $this->user($nazwa.'_kucharz');

        $przepis = Recipe::factory()->for($autor, 'author')->create();

        CookedEvent::factory()->for($kucharz, 'user')->for($przepis, 'recipe')->create([
            'cooked_at' => now()->subDay(),
        ]);

        return $autor;
    }
}
