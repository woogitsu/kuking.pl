<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #1952, druga połowa: publiczny feed dla gościa to DWIE trasy.
 *
 * `/odkryj` dostała limit w pierwszej poprawce (`OdkrywanieLimitZapytanTest`),
 * ale landing (`/`) woła dla gościa DOKŁADNIE TO SAMO zapytanie
 * (`DiscoverFeed::paginate(null, 9)`) i dokłada tablicę dnia oraz kolaż.
 * Pomiar z `docs/infra/ODKRYJ_KOSZT_1952.md`: przy 100 tys. publicznych wpisów
 * jedno wejście gościa na `/` to ok. 130 ms samego SQL — tyle samo co
 * `/odkryj` — i do tej pory bez żadnego limitu. Automat, który chciał
 * obciążyć bazę, nie musiał nawet szukać `/odkryj`.
 *
 * TE TESTY ODRÓŻNIAJĄ CZŁOWIEKA OD AUTOMATU, a nie tylko „czy jest 429":
 *  – zwykłe przeglądanie (wejście na stronę główną i kilkanaście kliknięć
 *    „Pokaż więcej" po prawdziwych odnośnikach) przechodzi bez 429;
 *  – seria powtarzanych anonimowych żądań ponad próg dostaje 429 —
 *    i to jest ta sama, przyjazna strona co każde inne 429 w serwisie;
 *  – zalogowana osoba za TYM SAMYM adresem (jedno gospodarstwo domowe,
 *    jeden NAT) nie jest zablokowana cudzą serią;
 *  – inne publiczne strony (profil, wpis, wyszukiwarka) nie są objęte;
 *  – po minucie limit sam się odnawia — nikt nie zostaje odcięty na dłużej.
 *
 * Progi czytamy z `config/kuking.php`, nie wklejamy liczb — patrz
 * uzasadnienie wzorca w `LimityTrasZapisujacychTest`.
 */
class PublicznyFeedLimitGosciTest extends TestCase
{
    use RefreshDatabase;

    private function prog(string $klucz): int
    {
        return (int) explode(',', (string) config("kuking.limits.{$klucz}"))[0];
    }

    /** Wyczerpuje budżet gościa na trasie — każde z tych żądań musi przejść. */
    private function wyczerpBudzetGoscia(string $adres, int $ile): void
    {
        for ($i = 0; $i < $ile; $i++) {
            $this->assertNotSame(
                429,
                $this->get($adres)->getStatusCode(),
                "Żądanie numer {$i} na {$adres} (w granicach limitu {$ile}/min) dostało 429.",
            );
        }
    }

    private function odnosnikPokazWiecej(string $html): ?string
    {
        if (preg_match('#href="([^"]*/odkryj\?[^"]*cursor=[^"]+)"#', $html, $m) !== 1) {
            return null;
        }

        return html_entity_decode($m[1]);
    }

    public function test_zwykle_przegladanie_goscia_przechodzi_bez_429(): void
    {
        // Trzydzieści osób po dwa wpisy — wystarczy na kilkanaście stron
        // „Pokaż więcej" (strona `/odkryj` ma 15 wpisów, rotacja autorów).
        for ($i = 0; $i < 30; $i++) {
            $autor = $this->user("przegl1952{$i}");
            Post::factory()->count(12)->create([
                'author_id' => $autor->getKey(),
                'published_at' => now()->subMinutes($i + 1),
            ]);
        }

        // Wejście na stronę główną, powrót na nią i wejście w „Świeżo z Kuking".
        $this->get(route('landing'))->assertOk();
        $this->get(route('landing'))->assertOk();
        $html = (string) $this->get(route('discover'))->assertOk()->getContent();

        // Kilkanaście kliknięć „Pokaż więcej" — po PRAWDZIWYCH odnośnikach
        // z widoku, nie po wyliczonych adresach.
        $strony = 1;

        while ($strony < 15 && ($url = $this->odnosnikPokazWiecej($html)) !== null) {
            $odpowiedz = $this->get($url);

            $this->assertNotSame(
                429,
                $odpowiedz->getStatusCode(),
                "Strona {$strony} „Pokaż więcej\" dostała 429 — zwykłe przeglądanie zostało odbite.",
            );

            $html = (string) $odpowiedz->assertOk()->getContent();
            $strony++;
        }

        $this->assertGreaterThanOrEqual(12, $strony, 'Za mało stron, żeby test mówił cokolwiek o przeglądaniu.');

        // I druga osoba w tym samym domu (ten sam adres) wchodzi na główną.
        $this->get(route('landing'))->assertOk();
    }

    public function test_seria_anonimowych_zadan_na_strone_glowna_dostaje_429(): void
    {
        $prog = $this->prog('landing');

        // Dolna granica rzędu wielkości: poniżej tego limit zacząłby łapać
        // ludzi (odświeżanie, powroty „Wróć na stronę główną" z każdej strony).
        $this->assertGreaterThanOrEqual(
            60,
            $prog,
            'Limit strony głównej spadł poniżej poziomu, który mieści zwykłe wejścia '
            .'i powroty kilku osób za jednym łączem — to zmiana w config/kuking.php '
            .'i wymaga świadomej decyzji.',
        );

        $this->wyczerpBudzetGoscia(route('landing'), $prog);

        // KONTROLA UJEMNA: bez limitu na trasie `landing` to żądanie dostaje
        // 200, jak wszystkie poprzednie, i test oblewa.
        $odbicie = $this->get(route('landing'));

        $odbicie->assertStatus(429);
        $this->assertTrue($odbicie->headers->has('Retry-After'), 'Brak nagłówka Retry-After przy 429.');

        // Ta sama przyjazna strona co każde inne 429: po polsku, z tym, co
        // zrobić — nie gołe „Too Many Requests".
        $odbicie->assertSee('Za dużo prób');
        $odbicie->assertSee('Spróbuj ponownie za 1 min.');
        $odbicie->assertDontSee('Too Many Requests');

        // Główny przycisk nie może prowadzić z powrotem na tę samą, wciąż
        // zablokowaną stronę główną — odsyła na `/odkryj` z osobnym budżetem.
        $odbicie->assertDontSee('class="btn btn-primary" href="'.route('landing').'"', false);
        $odbicie->assertSee('class="btn btn-primary" href="'.route('discover').'"', false);
        $odbicie->assertSee('Zobacz dania i przepisy');
        $this->get(route('discover'))->assertOk();

        // Po minucie budżet wraca sam — nikt nie zostaje odcięty na dłużej.
        $this->travel(61)->seconds();
        $this->get(route('landing'))->assertOk();
    }

    public function test_zalogowany_za_tym_samym_adresem_nie_jest_blokowany_limitem_gosci(): void
    {
        $this->wyczerpBudzetGoscia(route('landing'), $this->prog('landing'));
        $this->wyczerpBudzetGoscia(route('discover'), $this->prog('discover'));

        // Gość z tego adresu jest już odbijany na obu trasach…
        $this->get(route('landing'))->assertStatus(429);
        $this->get(route('discover'))->assertStatus(429);

        // …ale osoba z tego samego domu, zalogowana, ma własny koszyk
        // (klucz liczy się po koncie, nie po adresie).
        $domownik = $this->user('domownik1952');

        $this->actingAs($domownik)->get(route('discover'))->assertOk();

        // Zalogowany na `/` dostaje swój feed albo przekierowanie do niego —
        // byle nie 429 i nie błąd.
        $status = $this->actingAs($domownik)->get(route('landing'))->getStatusCode();
        $this->assertNotSame(429, $status, 'Zalogowany domownik odbił się o limit gościa na stronie głównej.');
        $this->assertLessThan(400, $status);
    }

    public function test_inne_publiczne_strony_nie_sa_objete_limitem_feedu(): void
    {
        $autor = $this->user('autorka1952');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);

        $this->wyczerpBudzetGoscia(route('landing'), $this->prog('landing'));
        $this->wyczerpBudzetGoscia(route('discover'), $this->prog('discover'));
        $this->get(route('landing'))->assertStatus(429);

        // Profil, wpis i wyszukiwarka mają własne koszyki (albo żadnego)
        // — wyczerpany feed nie odcina gościa od reszty serwisu.
        $this->get(route('profile.show', ['username' => $autor->profile->username]))->assertOk();
        $this->get(route('posts.show', ['post' => $wpis->getKey()]))->assertOk();
        $this->get(route('search', ['q' => 'sernik']))->assertOk();
    }
}
