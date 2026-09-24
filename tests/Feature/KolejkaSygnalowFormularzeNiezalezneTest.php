<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Moderacja\OcenaModelem;
use App\Support\WierszFormularza;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `/admin/sygnaly` STAWIA DO DWUDZIESTU PIĘCIU OSOBNYCH FORMULARZY NA
 * STRONIE — PO JEDNYM NA GRUPĘ (issue #243).
 *
 * Dwie usterki zastane, obie tego samego kształtu: strona traktowała
 * dwadzieścia pięć niezależnych formularzy tak, jakby to był jeden.
 *
 *  1. `old('note')` nie wie, z KTÓREGO formularza przyszła. Odrzucenie
 *     jednej grupy z uzasadnieniem wstawiało tę samą treść w pole notatki
 *     WSZYSTKICH pozostałych grup na stronie.
 *
 *  2. Każde pole notatki dostawało ten sam `id` (`f-note`), więc
 *     `<label for="f-note">` wiązał się z PIERWSZYM takim polem
 *     w dokumencie — klik w etykietę przy drugiej grupie ustawiał kursor
 *     w polu pierwszej.
 */
class KolejkaSygnalowFormularzeNiezalezneTest extends TestCase
{
    use RefreshDatabase;

    /** Oznaczenie automatu na koncie $autor, bez uruchamiania wykrywacza — patrz KolejkaSygnalowPokazujePodgladTest. */
    private function oznaczenie(User $autor): Report
    {
        $wpis = Post::factory()->for($autor, 'author')->create(['status' => Post::STATUS_PUBLISHED]);

        return Report::create([
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'autor_tresci_id' => $autor->getKey(),
            'subject_user_id' => $autor->getKey(),
            'source' => Report::SOURCE_AUTOMAT,
            'status' => Report::STATUS_OPEN,
            'reason' => OcenaModelem::KOD,
            'details' => 'Model ocenił zdjęcie: przemoc (pewność 86%).',
        ]);
    }

    // -----------------------------------------------------------------
    // USTERKA 1: old() wypełnia wszystkie formularze na stronie
    // -----------------------------------------------------------------

    #[Test]
    public function test_odrzucenie_jednej_grupy_nie_wypelnia_notatki_przy_pozostalych(): void
    {
        $moderator = $this->moderator();

        $pierwsza = $this->user('pierwszagrupa');
        $druga = $this->user('drugagrupa');
        $trzecia = $this->user('trzeciagrupa');

        $this->oznaczenie($pierwsza);
        $this->oznaczenie($druga);
        $this->oznaczenie($trzecia);

        // Notatka PRZY DRUGIEJ grupie jest za długa — to jedyny błąd, jaki
        // walidacja tego formularza umie wyprodukować (`note` to `nullable
        // string max:2000`).
        $zaDluga = str_repeat('a', 2001);

        $this->actingAs($moderator)
            ->from(route('admin.sygnaly'))
            ->post(route('admin.sygnaly.dismiss'), [
                'autor' => (string) $druga->getKey(),
                'oznaczenia' => $this->oznaczeniaNaEkranie(),
                // `_wiersz` tak, jak wysyła go prawdziwy formularz (issue
                // #243, `App\Support\WierszFormularza`).
                WierszFormularza::POLE => (string) $druga->getKey(),
                'note' => $zaDluga,
            ])
            ->assertSessionHasErrors('note');

        // Kolejne żądanie musi odczytać tę samą sesję JSON, jak przeglądarka.
        $kolejka = $this->withCookie(config('session.cookie'), session()->getId())
            ->actingAs($moderator)->get(route('admin.sygnaly'))->assertOk();
        $html = (string) $kolejka->getContent();

        $kolejka->assertSee('class="error-summary"', false)
            ->assertSee('role="alert"', false)
            ->assertSee('Sprawdź formularz');

        // Notatka WRACA dokładnie raz — we WŁASNYM polu drugiej grupy
        // (AGENTS.md §5: poprawne dane nigdy nie znikają, a nawet te za
        // długie mają wrócić do POPRAWIENIA, nie zniknąć bez śladu).
        $this->assertSame(
            1,
            substr_count($html, $zaDluga),
            'Notatka drugiej grupy zniknęła całkowicie albo wyciekła do więcej niż jednego pola.',
        );

        // Pierwszej i trzeciej grupy walidacja w ogóle nie dotyczyła — ich
        // WŁASNE pole notatki (po jego WŁASNYM `id`) ma zostać puste, a nie
        // pokazywać cudzy tekst.
        foreach ([$pierwsza, $trzecia] as $inna) {
            $poczatek = strpos($html, 'id="f-note-'.$inna->getKey().'"');
            $this->assertIsInt($poczatek, 'Brak pola notatki dla grupy '.$inna->getKey().'.');

            $this->assertStringNotContainsString(
                $zaDluga,
                substr($html, $poczatek, 2200),
                'Notatka z odrzuconego formularza drugiej grupy wyciekła do pola grupy '.$inna->getKey().' na tej samej stronie.',
            );
        }
    }

    #[Test]
    public function test_notatka_wpisana_przy_odrzuconej_grupie_zostaje_wlasnie_tam(): void
    {
        // Kontrola pozytywna do testu wyżej: mechanizm ograniczający old()
        // do jednego wiersza nie ma prawa zgubić danych GRUPIE, KTÓRA
        // NAPRAWDĘ wróciła z błędem (AGENTS.md §5: poprawne dane nigdy nie
        // znikają — a treść, która przekroczyła limit, ma wrócić do
        // POPRAWIENIA, nie zniknąć).
        $moderator = $this->moderator();
        $jedyna = $this->user('jedynagrupa');
        $this->oznaczenie($jedyna);

        $zaDluga = str_repeat('b', 2001);

        $this->actingAs($moderator)
            ->from(route('admin.sygnaly'))
            ->post(route('admin.sygnaly.dismiss'), [
                'autor' => (string) $jedyna->getKey(),
                'oznaczenia' => $this->oznaczeniaNaEkranie(),
                WierszFormularza::POLE => (string) $jedyna->getKey(),
                'note' => $zaDluga,
            ])
            ->assertSessionHasErrors('note');

        $this->actingAs($moderator)->get(route('admin.sygnaly'))
            ->assertOk()
            ->assertSee($zaDluga, false);
    }

    // -----------------------------------------------------------------
    // USTERKA 2: `id` pól się dubluje
    // -----------------------------------------------------------------

    #[Test]
    public function test_zadne_id_pola_notatki_nie_powtarza_sie_na_stronie_z_wieloma_grupami(): void
    {
        $moderator = $this->moderator();

        $autorzy = [
            $this->user('grupaid1'),
            $this->user('grupaid2'),
            $this->user('grupaid3'),
        ];

        foreach ($autorzy as $autor) {
            $this->oznaczenie($autor);
        }

        $html = (string) $this->actingAs($moderator)
            ->get(route('admin.sygnaly'))->assertOk()->getContent();

        preg_match_all('/\sid="([^"]+)"/', $html, $dopasowania);
        $identyfikatory = $dopasowania[1];

        $this->assertNotEmpty($identyfikatory, 'Strona nie ma ani jednego id — test niczego by nie sprawdzał.');
        $this->assertSame(
            count($identyfikatory),
            count(array_unique($identyfikatory)),
            'Na stronie z trzema grupami powtarza się jakiś `id` — HTML tego zakazuje, '
            .'a `<label for="...">` wiąże się wtedy z PIERWSZYM takim polem w dokumencie.',
        );

        // Każdy `id` pola notatki niesie identyfikator SWOJEJ grupy.
        foreach ($autorzy as $autor) {
            $this->assertStringContainsString('id="f-note-'.$autor->getKey().'"', $html);
        }
    }

    #[Test]
    public function test_etykieta_notatki_wskazuje_pole_tej_samej_grupy(): void
    {
        $moderator = $this->moderator();

        $pierwsza = $this->user('etykietagrupa1');
        $druga = $this->user('etykietagrupa2');
        $this->oznaczenie($pierwsza);
        $this->oznaczenie($druga);

        $html = (string) $this->actingAs($moderator)
            ->get(route('admin.sygnaly'))->assertOk()->getContent();

        foreach ([$pierwsza, $druga] as $autor) {
            $oczekiwanyId = 'f-note-'.$autor->getKey();

            $this->assertStringContainsString(
                'for="'.$oczekiwanyId.'"',
                $html,
                'Brak etykiety wskazującej pole notatki grupy '.$autor->getKey(),
            );
            $this->assertStringContainsString(
                'id="'.$oczekiwanyId.'"',
                $html,
            );

            // `for` MUSI wskazywać pole, które istnieje dokładnie raz —
            // inaczej klik w etykietę trafia w przeglądarce w losowe miejsce.
            $this->assertSame(
                1,
                substr_count($html, 'id="'.$oczekiwanyId.'"'),
                'Pole o id '.$oczekiwanyId.' występuje więcej niż raz.',
            );
        }
    }

    /**
     * Identyfikatory otwartych oznaczeń automatu — to, co formularz grupy
     * niesie z ekranu (#1059). Przysłana lista tylko ogranicza zakres, więc
     * oznaczenia innych grup w niej nie szkodzą.
     *
     * @return list<string>
     */
    private function oznaczeniaNaEkranie(): array
    {
        return \App\Models\Report::query()
            ->where('source', \App\Models\Report::SOURCE_AUTOMAT)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }
}
