<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\FollowUser;
use App\Domain\Social\Actions\UnfollowUser;
use App\Models\Notification;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Cykl „obserwuj → przestań → obserwuj" nie zasypuje powiadomieniami
 * (R3 §7).
 *
 * CO BYŁO ZEPSUTE, I CZEGO NIE BYŁO
 * `FollowUser::handle()` jest idempotentny wobec POWTÓRZONEGO obserwowania:
 * sprawdza `isFollowing()` i wychodzi wcześniej. `UnfollowUser` nigdy nie
 * wysyłał powiadomienia. Obie te rzeczy działały poprawnie od początku.
 *
 * Brakowało dokładnie jednego: `unfollow` robi twardy `detach()`, więc
 * kolejny `follow` widzi „nie obserwuję" i tworzy powiadomienie OD ZERA.
 * Ktoś, kto trzy razy w ciągu dnia odobserwuje i wróci — na przykład
 * sprawdzając, czy dana osoba zniknie mu z tablicy — wysyłał drugiej
 * stronie trzy identyczne powiadomienia.
 *
 * DLACZEGO SAM LIMIT LICZBY ŻĄDAŃ TEGO NIE ZAŁATWIA
 * Limit `follow` 10/min zatrzymuje spam w obrębie jednej minuty i nie
 * dotyka wcale wzorca rozłożonego na godziny. To jest problem SEMANTYKI
 * powiadomienia, nie tempa żądań.
 *
 * DLACZEGO OKNO OBEJMUJE TYLKO CZĘŚĆ RODZAJÓW POWIADOMIEŃ
 * „X Cię obserwuje" to STAN — powtórzenie nie niesie nowej informacji.
 * „X skomentował" to ZDARZENIE — drugi komentarz jest nową rzeczą i musi
 * dojść. Test kontrolny niżej pilnuje właśnie tej różnicy; bez niego
 * naprawa mogłaby wyciszyć komentarze i nikt by tego nie zauważył.
 */
class PowiadomienieOObserwowaniuNieWracaTest extends TestCase
{
    use RefreshDatabase;

    private function ilePowiadomien(User $odbiorca, string $typ): int
    {
        return Notification::query()
            ->where('user_id', $odbiorca->getKey())
            ->where('type', $typ)
            ->count();
    }

    public function test_pierwsze_obserwowanie_wysyla_powiadomienie(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek');

        app(FollowUser::class)->handle($marek, $basia);

        $this->assertSame(
            1,
            $this->ilePowiadomien($basia, Notification::TYPE_FOLLOW),
            'Pierwsze obserwowanie nie wysłało powiadomienia — test nie mierzy tego, co myśli.',
        );
    }

    public function test_cykl_w_ciagu_doby_nie_wysyla_drugiego_powiadomienia(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek');

        app(FollowUser::class)->handle($marek, $basia);
        app(UnfollowUser::class)->handle($marek, $basia);
        app(FollowUser::class)->handle($marek, $basia);
        app(UnfollowUser::class)->handle($marek, $basia);
        app(FollowUser::class)->handle($marek, $basia);

        $this->assertSame(
            1,
            $this->ilePowiadomien($basia, Notification::TYPE_FOLLOW),
            'Trzykrotny cykl obserwuj-przestań-obserwuj wysłał więcej niż jedno powiadomienie.',
        );
    }

    /**
     * Po dobie to jest już nowa informacja — ktoś wrócił po dłuższym czasie
     * i druga strona ma prawo o tym wiedzieć. Bez tego testu okno mogłoby
     * być nieskończone i nikt by nie zauważył.
     */
    public function test_po_dobie_powiadomienie_przychodzi_znowu(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek');

        app(FollowUser::class)->handle($marek, $basia);
        app(UnfollowUser::class)->handle($marek, $basia);

        Carbon::setTestNow(now()->addHours(25));

        app(FollowUser::class)->handle($marek, $basia);

        Carbon::setTestNow();

        $this->assertSame(
            2,
            $this->ilePowiadomien($basia, Notification::TYPE_FOLLOW),
            'Powrót po dobie nie wysłał powiadomienia — okno wyciszenia jest za szerokie.',
        );
    }

    /**
     * Okno dotyczy PARY osób, nie odbiorcy. Dwie różne osoby obserwujące
     * tego samego człowieka to dwie różne informacje.
     */
    public function test_okno_nie_wycisza_innej_osoby(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek');
        $ania = $this->user('ania');

        app(FollowUser::class)->handle($marek, $basia);
        app(FollowUser::class)->handle($ania, $basia);

        $this->assertSame(
            2,
            $this->ilePowiadomien($basia, Notification::TYPE_FOLLOW),
            'Powiadomienie od drugiej osoby zostało wyciszone oknem pierwszej.',
        );
    }

    /**
     * KONTROLA NAJWAŻNIEJSZA W TEJ KLASIE. Komentarz to ZDARZENIE, nie stan
     * — drugi komentarz tej samej osoby pod tym samym wpisem musi dojść.
     *
     * Bez tej asercji naprawa mogłaby wyciszyć rozmowę pod wpisem i
     * wszystkie pozostałe testy nadal byłyby zielone.
     */
    public function test_drugi_komentarz_tej_samej_osoby_nadal_powiadamia(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek');

        $wpis = Post::create([
            'author_id' => $basia->getKey(),
            'body' => 'Rosół na niedzielę.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        foreach (['Pierwszy komentarz', 'Drugi komentarz'] as $tresc) {
            $this->actingAs($marek)
                ->post(route('posts.comment', $wpis), ['body' => $tresc])
                ->assertRedirect();
        }

        $this->assertSame(
            2,
            $this->ilePowiadomien($basia, Notification::TYPE_COMMENT),
            'Drugi komentarz nie wysłał powiadomienia — okno wyciszenia objęło ZDARZENIA, nie tylko stany.',
        );
    }
}
