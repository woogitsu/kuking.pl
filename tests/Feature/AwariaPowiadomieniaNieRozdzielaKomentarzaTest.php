<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\PublishComment;
use App\Models\Comment;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Komentarz i powiadomienie o nim to jedna operacja, nie dwie po sobie.
 *
 * Ta sama klasa błędu co G04 („Ugotowałem"). Audyt zewnętrzny wskazał
 * `PublishComment` jako podejrzany, ale go NIE odtworzył i uczciwie tego nie
 * policzył jako drugiej potwierdzonej usterki. Ten plik jest tym pomiarem.
 *
 * CO ZMIERZONE PRZED ZMIANĄ
 * `PublishComment::handle()` nie miał ani jednej transakcji: `Comment::create`
 * szedł sam, a po nim jedno albo dwa powiadomienia. Wyjątek przy zapisie
 * powiadomienia zostawiał opublikowany komentarz, o którym adresat nie
 * wiedział.
 *
 * RÓŻNICA WOBEC G04 — i dlatego to jest lżejsze.
 * Komentarze nie mają `klucz_wyslania`, więc ponowienie zapisuje komentarz
 * jeszcze raz i przy okazji dowozi powiadomienie. Człowiek NIE jest
 * zablokowany na zawsze, tylko zostaje z duplikatem. Naprawiamy to mimo to,
 * bo koszt jest jedną transakcją, a rozmowa, o której nikt nie wie, to
 * dokładnie ta rzecz, po którą ten serwis istnieje.
 */
class AwariaPowiadomieniaNieRozdzielaKomentarzaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Psuje N-ty z kolei zapis powiadomienia (licząc od jedynki).
     *
     * Numer, a nie „pierwszy", bo pod odpowiedzią powstają DWA powiadomienia
     * i chcemy umieć trafić w to drugie.
     */
    private function zepsujZapisPowiadomienia(int $ktory): callable
    {
        $licznik = 0;
        $uzbrojona = true;

        Notification::creating(function () use (&$licznik, &$uzbrojona, $ktory): void {
            if (! $uzbrojona) {
                return;
            }

            $licznik++;

            if ($licznik === $ktory) {
                $uzbrojona = false;

                throw new RuntimeException('Symulowana awaria zapisu powiadomienia.');
            }
        });

        return function () use (&$uzbrojona): void {
            $uzbrojona = false;
        };
    }

    private function przepis(User $autor): Recipe
    {
        return Recipe::factory()->for($autor, 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
    }

    public function test_kontrola_bez_awarii_komentarz_i_powiadomienie_powstaja(): void
    {
        $autor = $this->user('autorkontrolakom');
        $piszacy = $this->user('piszacykontrola');
        $przepis = $this->przepis($autor);

        app(PublishComment::class)->handle($piszacy, $przepis, 'Bardzo dobre.');

        $this->assertSame(1, Comment::query()->count());
        $this->assertSame(1, Notification::query()->where('user_id', $autor->getKey())->count());
    }

    public function test_awaria_powiadomienia_cofa_takze_komentarz(): void
    {
        $autor = $this->user('autorawariikom');
        $piszacy = $this->user('piszacyawaria');
        $przepis = $this->przepis($autor);

        $rozbroj = $this->zepsujZapisPowiadomienia(1);

        try {
            app(PublishComment::class)->handle($piszacy, $przepis, 'Bardzo dobre.');

            $this->fail('Awaria zapisu powiadomienia nie wyszła na zewnątrz akcji.');
        } catch (RuntimeException) {
            // O to chodzi — liczy się to, co zostało w bazie.
        } finally {
            $rozbroj();
        }

        $this->assertSame(
            0,
            Comment::query()->count(),
            'Komentarz został opublikowany, choć autor treści nigdy się o nim nie dowie.',
        );

        $this->assertSame(0, Notification::query()->count());
    }

    /**
     * Pod ODPOWIEDZIĄ powstają dwa powiadomienia: do autora treści i do
     * osoby, której się odpowiada. Awaria tego DRUGIEGO nie ma prawa
     * zostawić komentarza z połową wiadomości.
     */
    public function test_awaria_drugiego_powiadomienia_takze_cofa_calosc(): void
    {
        $autorTresci = $this->user('autortresci');
        $pierwszyPiszacy = $this->user('pierwszypisz');
        $odpowiadajacy = $this->user('odpowiadajacy');
        $przepis = $this->przepis($autorTresci);

        $rodzic = app(PublishComment::class)->handle($pierwszyPiszacy, $przepis, 'Pierwszy głos.');

        $komentarzyPrzed = Comment::query()->count();
        $powiadomienPrzed = Notification::query()->count();

        $rozbroj = $this->zepsujZapisPowiadomienia(2);

        try {
            app(PublishComment::class)->handle($odpowiadajacy, $przepis, 'Odpowiadam.', $rodzic);

            $this->fail('Awaria drugiego powiadomienia nie wyszła na zewnątrz akcji.');
        } catch (RuntimeException) {
            // Jak wyżej.
        } finally {
            $rozbroj();
        }

        $this->assertSame(
            $komentarzyPrzed,
            Comment::query()->count(),
            'Odpowiedź została zapisana, mimo że jedno z dwóch powiadomień padło.',
        );

        $this->assertSame(
            $powiadomienPrzed,
            Notification::query()->count(),
            'Zostało powiadomienie z cofniętej odpowiedzi.',
        );
    }
}
