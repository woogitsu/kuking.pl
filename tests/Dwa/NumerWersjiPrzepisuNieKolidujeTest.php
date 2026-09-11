<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * A01 NA DWÓCH POŁĄCZENIACH: dwie równoległe edycje tego samego przepisu nie
 * wyliczają tego samego numeru wersji — i żadna nie zostawia publicznej
 * treści bez wpisu w historii.
 *
 * ══════════════════════════════════════════════════════════════════════
 *  CO DOKŁADNIE TEN TEST MIERZY
 * ══════════════════════════════════════════════════════════════════════
 *
 * Audyt z 11.09.2026 napisał o A01: „Równoległe edycje mogą wyliczyć ten sam
 * numer wersji". Podstawa tego zdania to „analiza kodu; bez testu awarii
 * w pełnej aplikacji" — czyli rozumowanie. Ten test je mierzy.
 *
 * Przed poprawką `PublishRecipe::handle()` wyglądało tak:
 *
 *     $recipe = DB::transaction(function () { … UPDATE recipes … });  // COMMIT
 *     if ($publish) {
 *         $this->snapshots->handle($recipe, …);   // max(version_number)+1
 *     }                                           // POZA transakcją
 *
 * I to jest sedno: blokada wiersza `recipes`, którą bierze `UPDATE`, zostaje
 * ZWOLNIONA przy commicie — a numer wersji liczy się DOPIERO POTEM, bez
 * żadnej blokady. Dwie edycje mogą więc odczytać to samo `max()`.
 *
 * ── PRZEPLOT, KTÓRY TO ROZSTRZYGA, I DLACZEGO BARIERA STOI NA `users` ──
 *
 * Bariera musi zatrzymać uczestnika POMIĘDZY odczytem `max(version_number)`
 * a `INSERT`-em do `recipe_versions` — bo to jest całe okno usterki. Wiersz
 * `users` autora nadaje się do tego jako jedyny:
 *
 *  * `SELECT max(version_number) …` nie dotyka `users` w ogóle, więc
 *    PRZECHODZI przez barierę;
 *  * `INSERT INTO recipe_versions` sprawdza klucz obcy `editor_id → users`,
 *    czyli bierze na tym wierszu `FOR KEY SHARE` — i CZEKA, bo bariera
 *    trzyma `FOR UPDATE`;
 *  * `UPDATE recipes` przez barierę przechodzi: `author_id` się nie zmienia,
 *    nie wchodzi więc nawet do klauzuli `SET`, a PostgreSQL pomija
 *    sprawdzenie klucza obcego, którego wartość została ta sama (to samo
 *    ustalenie, które D-103 zmierzyło dwiema sesjami `psql`).
 *
 * Bariera na wierszu `recipes` byłaby tu bezużyteczna: zatrzymałaby oba
 * uczestników PRZED `UPDATE`, czyli przed oknem usterki, i test byłby
 * zielony niezależnie od kodu.
 *
 *   przed poprawką                        po poprawce
 *   ──────────────────────────────        ──────────────────────────────
 *   A: UPDATE recipes, COMMIT             A: recipes FOR UPDATE, UPDATE,
 *      (blokada wiersza ZWOLNIONA)           snapshot: max=1 → 2,
 *      snapshot: max=1 → 2,                  INSERT czeka na `users`
 *      INSERT czeka na `users`               (blokada wiersza TRZYMANA)
 *   B: UPDATE recipes (wiersz wolny),     B: recipes FOR UPDATE — czeka
 *      COMMIT, snapshot: max=1 → 2,          za A
 *      INSERT czeka na `users`
 *   zwolnienie bariery:                   zwolnienie bariery:
 *   A wstawia wersję 2,                   A wstawia wersję 2 i commituje,
 *   B dostaje 23505 (unique) —            B dostaje wiersz, czyta max=2
 *   jego treść JEST publiczna,            → wstawia wersję 3
 *   jego wersji NIE MA
 *
 * ── KONTROLA UJEMNA (wykonana, nie zaplanowana) ──
 *
 * Cofnięcie poprawki w `PublishRecipe::handle()` (snapshot i wpis audytu
 * wyprowadzone za `DB::transaction()`, blokada wiersza przepisu usunięta)
 * daje tu dosłownie:
 *
 *     SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value
 *     violates unique constraint "recipe_versions_recipe_id_version_number_unique"
 *     DETAIL:  Key (recipe_id, version_number)=(…, 2) already exists.
 *
 * Dosłowny komunikat i pełny przebieg kontroli są w opisie PR-a tej gałęzi.
 *
 * ── CZEGO TEN TEST NIE DOWODZI ──
 *
 * Nie dowodzi, że `PublishRecipe` jest wolne od zakleszczeń w ogóle — mierzy
 * JEDEN przeplot, ten, który audyt nazwał. Nie dowodzi też atomowości zapisu
 * przy awarii historii: na to jest
 * `tests/Feature/ZapisPrzepisuIHistoriiJestAtomowyTest.php`, który mierzy
 * poziom transakcji i stan bazy po wymuszonym błędzie.
 */
#[Group('dwa-polaczenia')]
final class NumerWersjiPrzepisuNieKolidujeTest extends TestDwochPolaczen
{
    /** @var list<string> Identyfikatory przepisów utworzonych w teście — do sprzątania. */
    private array $przepisy = [];

    /**
     * Przepisy trzeba skasować PRZED kontami, i to nie jest ostrożność na
     * zapas: `recipe_versions.editor_id` ma `ON DELETE RESTRICT` (historia
     * nie znika razem z osobą, która ją zapisała), więc `DELETE FROM users`
     * ze sprzątaczki klasy bazowej odbiłby się o ten klucz obcy. Kasując
     * przepis, zabieramy jego wersje kaskadą po `recipe_id` — i dopiero
     * wtedy konto da się usunąć.
     */
    protected function tearDown(): void
    {
        if ($this->przepisy !== []) {
            try {
                DB::table('recipe_versions')->whereIn('recipe_id', $this->przepisy)->delete();
                DB::table('recipe_slug_redirects')->whereIn('recipe_id', $this->przepisy)->delete();
                DB::table('recipes')->whereIn('id', $this->przepisy)->delete();
            } catch (\Throwable $e) {
                fwrite(STDERR, "\nNie udało się posprzątać przepisów testu: ".$e->getMessage()."\n");
            }

            $this->przepisy = [];
        }

        parent::tearDown();
    }

    /**
     * Opublikowany przepis z JEDNĄ wersją w historii — czyli stan, w którym
     * `max(version_number) + 1` daje obu uczestnikom to samo `2`.
     *
     * Tytuł i slug są losowe z tego samego powodu co nazwy kont w klasie
     * bazowej: ta grupa zatwierdza dane naprawdę, więc stała nazwa zderzyłaby
     * się z pozostałością poprzedniego przebiegu na `recipes.slug`, który jest
     * unikalny — i oblałaby test z powodu, który nie ma nic wspólnego
     * z blokadami.
     */
    private function opublikowanyPrzepis(User $autor): Recipe
    {
        $znacznik = bin2hex(random_bytes(5));

        $przepis = Recipe::create([
            'author_id' => $autor->getKey(),
            'title' => 'Rosół wyścigowy '.$znacznik,
            'slug' => 'rosol-wyscigowy-'.$znacznik,
            'visibility' => 'public',
            'source_type' => Recipe::SOURCE_OWN,
            'status' => Recipe::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $this->przepisy[] = (string) $przepis->getKey();

        RecipeVersion::create([
            'recipe_id' => $przepis->getKey(),
            'editor_id' => $autor->getKey(),
            'version_number' => 1,
            'change_note' => 'Pierwsza publikacja',
            'snapshot' => ['title' => $przepis->title],
        ]);

        return $przepis;
    }

    public function test_dwie_rownolegle_edycje_dostaja_dwa_rozne_numery_wersji(): void
    {
        $autor = $this->konto();
        $przepis = $this->opublikowanyPrzepis($autor);

        $this->assertSame(1, RecipeVersion::query()->where('recipe_id', $przepis->getKey())->count());

        // Bariera na wierszu autora. Uzasadnienie wyboru właśnie tego wiersza
        // stoi w komentarzu klasy — to jest jedyne miejsce, które przepuszcza
        // odczyt `max(version_number)`, a zatrzymuje zapis wersji.
        $bariera = $this->bariera(
            'SELECT 1 FROM users WHERE id = ? FOR UPDATE',
            [(string) $autor->getKey()],
        );

        // KOLEJNOŚĆ USTAWIANIA SIĘ W KOLEJCE JEST CZĘŚCIĄ TESTU: pierwsza
        // edycja musi dojść do zapisu wersji, zanim druga zacznie cokolwiek
        // liczyć. Inaczej mierzylibyśmy losowy przeplot, a nie ten opisany.
        $pierwsza = $this->wTle('edytuj-przepis', [
            'autor' => (string) $autor->getKey(),
            'przepis' => (string) $przepis->getKey(),
            'tytul' => 'Rosół z lubczykiem',
            'skladnik' => 'lubczyk',
        ]);
        $this->czekajNaZablokowane(1);

        $druga = $this->wTle('edytuj-przepis', [
            'autor' => (string) $autor->getKey(),
            'przepis' => (string) $przepis->getKey(),
            'tytul' => 'Rosół z pietruszką',
            'skladnik' => 'pietruszka',
        ]);
        $this->czekajNaZablokowane(2);

        $this->zwolnijBariere($bariera);

        $wynikPierwszej = $pierwsza->wynik();
        $wynikDrugiej = $druga->wynik();

        $this->assertBezZakleszczenia($wynikPierwszej, 'pierwsza edycja przepisu');
        $this->assertBezZakleszczenia($wynikDrugiej, 'druga edycja przepisu');

        // KONTROLA DODATNIA NR 1: oba zapisy MUSIAŁY się wykonać. Bez tego
        // test przechodzi także wtedy, gdy jeden proces wywrócił się przed
        // pierwszym zapytaniem — a wtedy nie było żadnego wyścigu do zmierzenia.
        $this->assertTrue(
            $wynikPierwszej['ok'],
            'Pierwsza edycja nie przeszła, więc nie ma czego mierzyć: '
            .$wynikPierwszej['wyjatek'].' '.$wynikPierwszej['komunikat'],
        );
        $this->assertTrue(
            $wynikDrugiej['ok'],
            'Druga edycja nie przeszła — to jest znalezisko A01 (najczęściej '
            .'kolizja numeru wersji, SQLSTATE 23505): '
            .$wynikDrugiej['wyjatek'].' '.$wynikDrugiej['komunikat'],
        );

        // TO JEST CAŁE ZNALEZISKO. Dwie edycje, dwa RÓŻNE numery wersji,
        // i kolejne po tym, które już było.
        $numery = RecipeVersion::query()
            ->where('recipe_id', $przepis->getKey())
            ->orderBy('version_number')
            ->pluck('version_number')
            ->map(static fn ($n): int => (int) $n)
            ->all();

        $this->assertSame([1, 2, 3], $numery, 'Numery wersji nie są kolejne i różne — patrz A01.');

        // KONTROLA DODATNIA NR 2: treść, która jest PUBLICZNA, ma swój wpis
        // w historii. Sama liczba wersji tego nie dowodzi — dowodzi tego
        // porównanie tytułu widocznego na stronie z tytułem w NAJNOWSZEJ
        // migawce. Przed poprawką te dwie rzeczy się rozjeżdżały: publiczny
        // był tytuł tego, kto zapisał jako drugi, a najnowsza wersja należała
        // do pierwszego.
        $przepis->refresh();

        $najnowsza = RecipeVersion::query()
            ->where('recipe_id', $przepis->getKey())
            ->orderByDesc('version_number')
            ->firstOrFail();

        /** @var array<string, mixed> $migawka */
        $migawka = $najnowsza->snapshot;

        $this->assertSame(
            $przepis->title,
            $migawka['title'] ?? null,
            'Publiczny tytuł przepisu nie zgadza się z najnowszą wersją w historii — '
            .'czyli jakaś zatwierdzona zmiana nie ma wpisu w historii (A01).',
        );
    }

    /**
     * DRUGA POŁOWA A01: zapis, który CZEKAŁ na blokadę, pyta o stan przepisu
     * jeszcze raz — i nie przywraca do sieci przepisu ukrytego w tym czasie
     * przez moderatora.
     *
     * ── PO CO TEN TEST ISTNIEJE OSOBNO ──
     *
     * Bo kontrola ujemna testu wyżej NIE OBLAŁA SIĘ po zdjęciu jawnego
     * `lockForUpdate()` z `PublishRecipe`, i to było najważniejsze ustalenie
     * tej gałęzi. Przyczyna: `recipes` ma `timestampsTz()`, więc
     * `$recipe->update()` zawsze przesuwa `updated_at`, zawsze wykonuje
     * `UPDATE` i zawsze bierze blokadę wiersza. Numer wersji jest więc
     * serializowany już przez samo wciągnięcie snapshotu do transakcji.
     *
     * Jawna blokada zarabia na siebie czymś innym: tym, że status czytamy
     * POD NIĄ, a nie przed nią. Ten test mierzy dokładnie tę różnicę —
     * i dopiero on obleje się po jej zdjęciu.
     *
     * ── PRZEPLOT ──
     *
     *   1. bariera trzyma wiersz `recipes` (udaje moderatora, który właśnie
     *      otworzył panel);
     *   2. autor zapisuje zmianę i ustawia się w kolejce po ten wiersz;
     *   3. moderator USTAWIA `status = hidden` i ZATWIERDZA — inaczej niż
     *      w pozostałych testach tej grupy, gdzie bariera się wycofuje,
     *      bo tu chodzi o to, żeby czekający zobaczył nowy stan;
     *   4. autor dostaje wiersz.
     *
     *   bez `lockForUpdate()`                z `lockForUpdate()`
     *   ────────────────────────────         ────────────────────────────
     *   status przeczytany PRZED             status przeczytany POD blokadą
     *   kolejką → `published`                → `hidden`
     *   `UPDATE recipes` przechodzi,         odmowa po polsku, ani jednego
     *   `status` wraca na `published`        zapisu, decyzja moderatora stoi
     *   → decyzja moderacji zniesiona
     *      przez osobę, której dotyczy
     *
     * To nie jest przypadek brzegowy: moderator ukrywa przepis najczęściej
     * wtedy, gdy autor jest aktywny — bo właśnie wtedy przepis powstał albo
     * został zgłoszony. A `RecipeStatusTransitions` istnieje w tym
     * repozytorium po to, żeby „moderacja nie była sugestią".
     */
    public function test_zapis_czekajacy_w_kolejce_nie_przywraca_przepisu_ukrytego_w_tym_czasie(): void
    {
        $autor = $this->konto();
        $przepis = $this->opublikowanyPrzepis($autor);
        $tytulPrzed = (string) $przepis->title;

        // Bariera na wierszu przepisu. Ta jedna w całej grupie NIE jest
        // wycofywana, a zatwierdzana — patrz komentarz metody.
        $moderator = $this->nowePolaczenie();
        $moderator->beginTransaction();

        $trzymanie = $moderator->prepare('SELECT 1 FROM recipes WHERE id = ? FOR UPDATE');
        $trzymanie->execute([(string) $przepis->getKey()]);

        $this->assertNotSame(
            0,
            $trzymanie->rowCount(),
            'Bariera nie trafiła w wiersz przepisu — test mierzyłby przeplot, którego nie ma.',
        );

        $edycja = $this->wTle('edytuj-przepis', [
            'autor' => (string) $autor->getKey(),
            'przepis' => (string) $przepis->getKey(),
            'tytul' => 'Rosół, który nie powinien wrócić',
            'skladnik' => 'lubczyk',
        ]);

        // KONTROLA POZYTYWNA PRZEPLOTU: zapis autora NAPRAWDĘ stoi w kolejce
        // po ten wiersz. Bez tego test przechodzi także wtedy, gdy proces
        // wywrócił się przed pierwszym zapytaniem.
        $this->czekajNaZablokowane(1);

        $ukrycie = $moderator->prepare("UPDATE recipes SET status = 'hidden' WHERE id = ?");
        $ukrycie->execute([(string) $przepis->getKey()]);
        $moderator->commit();

        $wynik = $edycja->wynik();

        $this->assertBezZakleszczenia($wynik, 'zapis autora czekający na decyzję moderatora');

        // Zapis MUSI odmówić, i to TĄ odmową — inny wyjątek znaczyłby, że
        // test mierzy jakąś inną awarię, a nie rewalidację pod blokadą.
        $this->assertFalse(
            $wynik['ok'],
            'Zapis autora przeszedł na przepisie ukrytym w trakcie czekania — rewalidacja pod blokadą nie działa (A01).',
        );
        $this->assertStringContainsString(
            'ukryty przez moderację',
            (string) $wynik['komunikat'],
            'Zapis padł z innego powodu niż decyzja moderacji: '.$wynik['wyjatek'].' '.$wynik['komunikat'],
        );

        $przepis->refresh();

        $this->assertSame(Recipe::STATUS_HIDDEN, $przepis->status, 'Decyzja moderatora została zniesiona zapisem autora.');
        $this->assertSame($tytulPrzed, $przepis->title, 'Treść ukrytego przepisu została zmieniona.');
        $this->assertSame(1, RecipeVersion::query()->where('recipe_id', $przepis->getKey())->count());
    }
}
