<?php

declare(strict_types=1);

namespace Tests\Feature\Visibility;

use App\Domain\Recipes\WpisWskazujacyPrzepis;
use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use App\Policies\CookedEventPolicy;
use App\Policies\PostPolicy;
use App\Policies\RecipePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TEST KONTRAKTOWY: zakresy list treści a Policy (issue #1687, etap 7).
 *
 * `Post::scopeWidoczneDla()`, `Recipe::scopeWidoczneDla()` i
 * `CookedEvent::scopeWidoczneDla()` odpowiadają na to samo pytanie co
 * `PostPolicy::view()`, `RecipePolicy::view()` i `CookedEventPolicy::view()`,
 * tyle że dla wielu wierszy naraz. Do tej pory ich zgodność pilnowały
 * wyłącznie komentarze w modelach. Ten test porównuje obie odpowiedzi
 * na macierzy: typ treści × status konta autora × stan treści × widoczność
 * × widz (właściciel, obserwujący, obcy, gość, blokada w obie strony).
 *
 * WYNIK USTALENIA (etap 7): RÓŻNICE SĄ ZAMIERZONE, NIE BŁĘDEM. Cztery rzeczy
 * różnią zakres od Policy i każda ma jawny powód:
 *
 *   1. STATUS KONTA AUTORA (Post, Recipe). Zakres odpowiada na pytanie
 *      „relacja widz ↔ autor" i świadomie nie liczy statusu konta —
 *      granicę `banned` / `pending_delete` dokłada wywołujący
 *      (`->whereHas('author', fn ($a) => $a->dostepnyJakoAutor())`, tak jak
 *      robią to zeszyt, profil, mapa strony, eksport, „Ostatnio zapisane").
 *      Powód opisuje `Post::scopeTylkoOdAktywnychAutorow()`: zakres z
 *      wbudowanym statusem odciąłby autora od własnych treści. Test pilnuje
 *      kontraktu: zakres + `dostepnyJakoAutor()` = Policy. `suspended`
 *      i `erased` NIE zamykają treści ani tu, ani w Policy.
 *   2. WŁAŚCICIEL ZAMKNIĘTEGO KONTA. Policy wpuszcza autora do własnej
 *      treści zawsze (furtka właściciela), a filtr statusu listy tnie
 *      także jego. Konto `banned` i `pending_delete` nie ma otwartej sesji
 *      (`EnsureAccountIsActive`), więc lista i tak nie jest tam oglądana.
 *   3. ZAPOWIEDŹ WŁASNEGO PRZEPISU UKRYTEGO PRZEZ MODERACJĘ. Bramka zapowiedzi
 *      (`zWidocznymPrzepisem()`) wymaga przepisu opublikowanego, więc autor
 *      nie zobaczy jej w strumieniach, choć Policy wpuszcza go pod adres.
 *      Lista jest tu ostrzejsza od Policy, nie luźniejsza.
 *   4. „UGOTOWAŁEM" (CookedEvent). Wykonanie idzie za PRZEPISEM: jego
 *      widoczność rozstrzyga strona przepisu jednym wywołaniem
 *      `RecipePolicy::view()` na całą stronę galerii (N+1 w przeciwnym
 *      razie), a zakres liczy tylko blokadę z kucharzem i status jego konta.
 *      Dlatego zakres nie zna stanu przepisu ani blokady z jego autorem.
 *
 * ŚWIADOMIE POZA MACIERZĄ: moderator (Policy ma dla niego furtkę, zakres
 * nie — jest ostrzejszy, nie luźniejszy) oraz zapowiedź przepisu (bramka
 * przepisu to osobny zakres `zWidocznymPrzepisem()`, pilnują go
 * `StronaWpisuBramkaPrzepisuTest` i `PowiadomieniaZgodneZPolicyTest`).
 *
 * Nowa reguła w Policy bez odpowiednika w zakresie daje tu czerwony wynik
 * z nazwą komórki. Sprawdzane jest też, że każdy zamierzony rozjazd
 * naprawdę zachodzi — opis nie może przeżyć zmiany zachowania.
 */
class ListyTresciZgodneZPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const STATUSY_AUTORA = ['active', 'suspended', 'banned', 'pending_delete', 'erased'];

    private const WIDZOWIE = ['wlasciciel', 'obserwujacy', 'obcy', 'gosc', 'zablokowany_przez_widza', 'zablokowal_widza'];

    private const WIDOCZNOSCI = ['public', 'followers', 'private'];

    private const STANY = ['published', 'draft', 'hidden'];

    public function test_wpis_zakres_plus_status_konta_odpowiada_jak_policy(): void
    {
        $this->sprawdzMacierz('wpis');
    }

    public function test_przepis_zakres_plus_status_konta_odpowiada_jak_policy(): void
    {
        $this->sprawdzMacierz('przepis');
    }

    private function sprawdzMacierz(string $typ): void
    {
        $rozjazdy = [];
        $zgodneTak = 0;
        $zgodneNie = 0;
        $znane = ['status_konta_autora' => 0, 'wlasciciel_zamknietego_konta' => 0];

        foreach (self::STATUSY_AUTORA as $statusAutora) {
            foreach (self::WIDZOWIE as $rola) {
                foreach (self::WIDOCZNOSCI as $widocznosc) {
                    foreach (self::STANY as $stan) {
                        [$policy, $zakres, $zakresZeStatusem] = $this->komorka($typ, $statusAutora, $rola, $widocznosc, $stan);

                        $opis = sprintf(
                            '%s / autor: %s / widz: %s / widoczność: %s / stan: %s — Policy: %s, zakres: %s, zakres + status konta: %s',
                            $typ, $statusAutora, $rola, $widocznosc, $stan,
                            $policy ? 'widzi' : 'nie widzi',
                            $zakres ? 'widzi' : 'nie widzi',
                            $zakresZeStatusem ? 'widzi' : 'nie widzi',
                        );

                        $zamkniete = in_array($statusAutora, User::STATUSY_UKRYWAJACE_TRESC, true);

                        // Zakres SAM: jedyny dozwolony rozjazd to status konta,
                        // i tylko w jedną stronę (zakres pokazuje więcej).
                        if ($policy !== $zakres) {
                            if ($zamkniete && $rola !== 'wlasciciel' && $zakres && ! $policy) {
                                $znane['status_konta_autora']++;
                            } else {
                                $rozjazdy[] = 'SAM ZAKRES: '.$opis;
                            }
                        }

                        // Zakres + granica statusu konta: zgodny z Policy,
                        // poza właścicielem zamkniętego konta (Policy wpuszcza,
                        // filtr statusu tnie — jedna strona).
                        if ($policy !== $zakresZeStatusem) {
                            if ($zamkniete && $rola === 'wlasciciel' && $policy && ! $zakresZeStatusem) {
                                $znane['wlasciciel_zamknietego_konta']++;
                            } else {
                                $rozjazdy[] = 'ZAKRES + STATUS: '.$opis;
                            }

                            continue;
                        }

                        $policy ? $zgodneTak++ : $zgodneNie++;
                    }
                }
            }
        }

        $this->assertSame([], $rozjazdy, "Zakres listy i Policy odpowiadają inaczej:\n".implode("\n", $rozjazdy));

        // Macierz z samymi „nie widzi" (albo samymi „widzi") przeszłaby
        // asercję wyżej z niewłaściwego powodu.
        $this->assertGreaterThan(20, $zgodneTak, 'Za mało komórek, w których obie strony widzą.');
        $this->assertGreaterThan(20, $zgodneNie, 'Za mało komórek, w których obie strony odmawiają.');

        // Każdy zamierzony rozjazd musi rzeczywiście zachodzić.
        $this->assertGreaterThan(0, $znane['status_konta_autora'], 'Zakres przestał różnić się od Policy statusem konta autora — zaktualizuj opis w docblocku i ARCHITECTURE.md.');
        $this->assertGreaterThan(0, $znane['wlasciciel_zamknietego_konta'], 'Filtr statusu przestał ciąć właściciela zamkniętego konta — zaktualizuj opis.');
    }

    /**
     * Zapowiedź przepisu (wpis bez własnej treści): bramką jest PRZEPIS.
     * Zakres wpisu + `zWidocznymPrzepisemAlboWlasnaTrescia()` + status konta
     * musi odpowiadać `PostPolicy::view()` (autor wpisu = autor przepisu,
     * `WpisWskazujacyPrzepis::dopisz()`).
     */
    public function test_zapowiedz_przepisu_zakres_odpowiada_jak_policy(): void
    {
        $rozjazdy = [];
        $zgodneTak = 0;
        $zgodneNie = 0;
        $wlasneUkryte = 0;

        foreach (self::STATUSY_AUTORA as $statusAutora) {
            foreach (['wlasciciel', 'obserwujacy', 'obcy', 'gosc', 'zablokowany_przez_widza', 'zablokowal_widza'] as $rola) {
                foreach (self::WIDOCZNOSCI as $widocznosc) {
                    foreach (['published', 'hidden'] as $stanPrzepisu) {
                        $autor = $this->user();
                        $widz = match ($rola) {
                            'wlasciciel' => $autor,
                            'gosc' => null,
                            default => $this->user(),
                        };

                        if ($rola === 'obserwujacy') {
                            app(FollowUser::class)->handle($widz, $autor);
                        }

                        if ($rola === 'zablokowany_przez_widza') {
                            app(BlockUser::class)->handle($widz, $autor);
                        }

                        if ($rola === 'zablokowal_widza') {
                            app(BlockUser::class)->handle($autor, $widz);
                        }

                        $przepis = Recipe::factory()->for($autor, 'author')->create([
                            'visibility' => $widocznosc,
                            'status' => Recipe::STATUS_PUBLISHED,
                            'published_at' => now()->subHour(),
                        ]);
                        $zapowiedz = WpisWskazujacyPrzepis::dopisz($przepis);
                        $this->assertNotNull($zapowiedz);
                        $this->assertTrue($zapowiedz->czyJestZapowiedziaPrzepisu());

                        // Stan przepisu zmienia się PO powstaniu zapowiedzi.
                        DB::table('recipes')->where('id', $przepis->getKey())->update(['status' => $stanPrzepisu]);

                        DB::table('users')->where('id', $autor->getKey())->update($this->kolumnyStatusu($statusAutora));

                        $widzTeraz = $widz === null ? null : User::query()->findOrFail($widz->getKey());

                        $policy = app(PostPolicy::class)->view($widzTeraz, Post::query()->findOrFail($zapowiedz->getKey()));
                        $lista = Post::query()
                            ->whereKey($zapowiedz->getKey())
                            ->widoczneDla($widzTeraz)
                            ->zWidocznymPrzepisemAlboWlasnaTrescia($widzTeraz)
                            ->whereHas('author', fn ($a) => $a->dostepnyJakoAutor())
                            ->exists();

                        $zamknieteWlasne = $rola === 'wlasciciel'
                            && in_array($statusAutora, User::STATUSY_UKRYWAJACE_TRESC, true);

                        // ZAMIERZONE (docblock `Post::scopeZWidocznymPrzepisem()`):
                        // bramka to przepis „opublikowany, nieusunięty i widoczny".
                        // Własna zapowiedź przepisu zdjętego przez moderację
                        // wypada ze strumieni (Policy jeszcze wpuszcza autora
                        // pod adres — przepis ma własny ekran statusu).
                        $wlasnyUkryty = $rola === 'wlasciciel' && $stanPrzepisu === 'hidden' && $policy && ! $lista;

                        if ($wlasnyUkryty) {
                            $wlasneUkryte++;

                            continue;
                        }

                        if ($policy === $lista || ($zamknieteWlasne && $policy && ! $lista)) {
                            $policy ? $zgodneTak++ : $zgodneNie++;

                            continue;
                        }

                        $rozjazdy[] = sprintf(
                            'zapowiedź / autor: %s / widz: %s / przepis: %s, %s — Policy: %s, lista: %s',
                            $statusAutora, $rola, $widocznosc, $stanPrzepisu,
                            $policy ? 'widzi' : 'nie widzi', $lista ? 'widzi' : 'nie widzi',
                        );
                    }
                }
            }
        }

        $this->assertSame([], $rozjazdy, "Lista zapowiedzi i Policy odpowiadają inaczej:\n".implode("\n", $rozjazdy));
        $this->assertGreaterThan(10, $zgodneTak);
        $this->assertGreaterThan(10, $zgodneNie);
        $this->assertGreaterThan(0, $wlasneUkryte, 'Lista wpuściła własną zapowiedź przepisu ukrytego przez moderację — zaktualizuj opis.');
    }

    /**
     * @return array{0: bool, 1: bool, 2: bool} [Policy, sam zakres, zakres + `dostepnyJakoAutor()`]
     */
    private function komorka(string $typ, string $statusAutora, string $rola, string $widocznosc, string $stan): array
    {
        $autor = $this->user();
        $widz = match ($rola) {
            'wlasciciel' => $autor,
            'gosc' => null,
            default => $this->user(),
        };

        if ($rola === 'obserwujacy') {
            app(FollowUser::class)->handle($widz, $autor);
        }

        if ($rola === 'zablokowany_przez_widza') {
            app(BlockUser::class)->handle($widz, $autor);
        }

        if ($rola === 'zablokowal_widza') {
            app(BlockUser::class)->handle($autor, $widz);
        }

        $atrybuty = [
            'visibility' => $widocznosc,
            'status' => $stan,
            'published_at' => $stan === 'draft' ? null : now()->subHour(),
        ];

        $tresc = $typ === 'wpis'
            ? Post::factory()->for($autor, 'author')->create($atrybuty)
            : Recipe::factory()->for($autor, 'author')->create($atrybuty);

        // Status konta nakładamy PO utworzeniu treści i bez ubocznych skutków
        // `ban()` / `markForDeletion()`: mierzymy sam wiersz `users.status`.
        DB::table('users')->where('id', $autor->getKey())->update($this->kolumnyStatusu($statusAutora));

        $widzTeraz = $widz === null ? null : User::query()->findOrFail($widz->getKey());
        $model = $tresc::class;
        $swiezy = $model::query()->findOrFail($tresc->getKey());

        $policy = $typ === 'wpis'
            ? app(PostPolicy::class)->view($widzTeraz, $swiezy)
            : app(RecipePolicy::class)->view($widzTeraz, $swiezy);

        $zakres = $model::query()->whereKey($tresc->getKey())->widoczneDla($widzTeraz)->exists();

        $zakresZeStatusem = $model::query()
            ->whereKey($tresc->getKey())
            ->widoczneDla($widzTeraz)
            ->whereHas('author', fn ($a) => $a->dostepnyJakoAutor())
            ->exists();

        return [$policy, $zakres, $zakresZeStatusem];
    }

    public function test_pytania_zamkniete_konfiguracja_tak_samo_w_zakresie_i_policy(): void
    {
        foreach ([false, true] as $wlaczone) {
            config(['kuking.questions.enabled' => $wlaczone]);

            $autor = $this->user();
            $pytanie = Post::factory()->question()->for($autor, 'author')->create();
            $obcy = $this->user();

            $policy = app(PostPolicy::class)->view($obcy, Post::query()->findOrFail($pytanie->getKey()));
            $zakres = Post::query()->whereKey($pytanie->getKey())->widoczneDla($obcy)->exists();

            $this->assertSame($wlaczone, $policy, 'Policy dla pytania przy questions.enabled='.var_export($wlaczone, true));
            $this->assertSame($policy, $zakres, 'Zakres dla pytania przy questions.enabled='.var_export($wlaczone, true));
        }
    }

    /**
     * „Ugotowałem": zakres liczy blokadę z KUCHARZEM i status jego konta.
     * Stan przepisu i blokada z jego autorem należą do strony przepisu
     * (`RecipePolicy::view()` raz na całą galerię) — zamierzony rozjazd nr 4.
     */
    public function test_wykonanie_zakres_odpowiada_jak_policy_poza_zamierzonymi_roznicami(): void
    {
        $rozjazdy = [];
        $zgodneTak = 0;
        $zgodneNie = 0;
        $znane = ['stan_przepisu' => 0, 'wlasciciel_zamknietego_konta' => 0];

        foreach (self::STATUSY_AUTORA as $statusKucharza) {
            foreach (['wlasciciel', 'obcy', 'gosc', 'zablokowany_przez_widza', 'zablokowal_widza'] as $rola) {
                foreach (['public', 'private'] as $widocznoscPrzepisu) {
                    [$policy, $zakres] = $this->komorkaWykonania($statusKucharza, $rola, $widocznoscPrzepisu);

                    $opis = sprintf(
                        'wykonanie / kucharz: %s / widz: %s / przepis: %s — Policy: %s, zakres: %s',
                        $statusKucharza, $rola, $widocznoscPrzepisu,
                        $policy ? 'widzi' : 'nie widzi',
                        $zakres ? 'widzi' : 'nie widzi',
                    );

                    if ($policy === $zakres) {
                        $policy ? $zgodneTak++ : $zgodneNie++;

                        continue;
                    }

                    $zamkniete = in_array($statusKucharza, User::STATUSY_UKRYWAJACE_TRESC, true);

                    // Rozjazd 4: przepis niepubliczny — Policy odmawia obcemu,
                    // zakres nie zna stanu przepisu.
                    if ($widocznoscPrzepisu === 'private' && $rola !== 'wlasciciel' && $zakres && ! $policy && ! $zamkniete) {
                        $znane['stan_przepisu']++;

                        continue;
                    }

                    // Rozjazd 2: kucharz zamkniętego konta widzi własne
                    // wykonanie w Policy, zakres go tnie.
                    if ($zamkniete && $rola === 'wlasciciel' && $policy && ! $zakres) {
                        $znane['wlasciciel_zamknietego_konta']++;

                        continue;
                    }

                    // Zamknięte konto kucharza przy niepublicznym przepisie:
                    // oba rozjazdy naraz nie występują dla obcego (obie
                    // strony odmawiają), więc każda inna komórka to błąd.
                    $rozjazdy[] = $opis;
                }
            }
        }

        $this->assertSame([], $rozjazdy, "Zakres wykonań i Policy odpowiadają inaczej:\n".implode("\n", $rozjazdy));
        $this->assertGreaterThan(5, $zgodneTak);
        $this->assertGreaterThan(5, $zgodneNie);
        $this->assertGreaterThan(0, $znane['stan_przepisu'], 'Zakres wykonań zaczął znać stan przepisu — zaktualizuj opis.');
        $this->assertGreaterThan(0, $znane['wlasciciel_zamknietego_konta'], 'Zakres wykonań przestał ciąć właściciela zamkniętego konta — zaktualizuj opis.');
    }

    /**
     * @return array{0: bool, 1: bool}
     */
    private function komorkaWykonania(string $statusKucharza, string $rola, string $widocznoscPrzepisu): array
    {
        $kucharz = $this->user();
        $autorPrzepisu = $this->user();
        $widz = match ($rola) {
            'wlasciciel' => $kucharz,
            'gosc' => null,
            default => $this->user(),
        };

        if ($rola === 'zablokowany_przez_widza') {
            app(BlockUser::class)->handle($widz, $kucharz);
        }

        if ($rola === 'zablokowal_widza') {
            app(BlockUser::class)->handle($kucharz, $widz);
        }

        $przepis = Recipe::factory()->for($autorPrzepisu, 'author')->create([
            'visibility' => $widocznoscPrzepisu,
            'status' => Recipe::STATUS_PUBLISHED,
            'published_at' => now()->subHour(),
        ]);

        $wykonanie = CookedEvent::factory()->create([
            'user_id' => $kucharz->getKey(),
            'recipe_id' => $przepis->getKey(),
        ]);

        DB::table('users')->where('id', $kucharz->getKey())->update($this->kolumnyStatusu($statusKucharza));

        $widzTeraz = $widz === null ? null : User::query()->findOrFail($widz->getKey());

        $policy = app(CookedEventPolicy::class)->view(
            $widzTeraz,
            CookedEvent::query()->findOrFail($wykonanie->getKey()),
        );

        $zakres = CookedEvent::query()->whereKey($wykonanie->getKey())->widoczneDla($widzTeraz)->exists();

        return [$policy, $zakres];
    }

    /**
     * Ograniczenie `users_data_erased_at_check` wymaga daty wymazania przy
     * statusie `erased` — inne statusy jej nie mają.
     *
     * @return array<string, mixed>
     */
    private function kolumnyStatusu(string $status): array
    {
        return $status === User::STATUS_ERASED
            ? ['status' => $status, 'data_erased_at' => now()]
            : ['status' => $status];
    }
}
