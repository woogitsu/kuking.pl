<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * Zapis przepisu i egzekucja kasowania konta biorą wiersze `users`
 * i `recipes` w TEJ SAMEJ kolejności (zakleszczenie zmierzone 11.09.2026).
 *
 * ══════════════════════════════════════════════════════════════════════
 *  CZEGO TEN PLIK DOTYCZY I CZEGO NIE
 * ══════════════════════════════════════════════════════════════════════
 *
 * To NIE jest żadne ze znalezisk audytu z 11.09.2026 (A01–A15). Wyszło
 * przy sprawdzaniu, czy poprawka A01 nie psuje kolejności blokad — i nie
 * psuje: ten sam pomiar oblewa się dokładnie tak samo na kodzie SPRZED
 * niej. Usterka jest starsza, a audyt jej nie widzi, bo widać ją wyłącznie
 * na dwóch połączeniach (`docs/PULAPKI_TESTOW.md` §6).
 *
 * ── DWIE KOLEJNOŚCI, KTÓRE SIĘ ZDERZAŁY ──
 *
 *   `EraseAccountData::handle()`       `PublishRecipe::handle()` (przed poprawką)
 *   ───────────────────────────────    ────────────────────────────────────────
 *   1. `users`   FOR UPDATE            1. `media`   FOR UPDATE
 *   2. …                               2. `recipes` FOR UPDATE
 *   3. `recipes` (usunTresci)          3. `users`   FOR KEY SHARE
 *                                         (klucz obcy `recipe_versions.editor_id`)
 *
 * Czyli jedna strona `users` → `recipes`, druga `recipes` → `users`. To jest
 * dokładnie ta rodzina usterek, którą zamykały D-093 i D-103, tylko na innej
 * parze tabel.
 *
 * ── DLACZEGO NIE ZALEŻY TO OD GRANICY TRANSAKCJI ──
 *
 * Bo `INSERT` w PostgreSQL wkłada wiersz do sterty PRZED sprawdzeniem klucza
 * obcego. Nawet gdy zapis wersji stał POZA transakcją zapisu przepisu (kod
 * sprzed poprawki A01), kasowanie konta i tak czekało na niezatwierdzony
 * wiersz `recipe_versions`, a zapis autora czekał na `users`. Zmierzone
 * w obie strony, na obu wersjach kodu — dlatego w komentarzu naprawy stoi,
 * że usterka jest STARSZA niż A01.
 *
 * ── CO ZMIENIŁA NAPRAWA ──
 *
 * `PublishRecipe` bierze teraz wiersz autora pod `FOR KEY SHARE` ZARAZ po
 * blokadzie zdjęć, czyli zanim sięgnie po wiersz przepisu. Kolejność jest
 * więc `media` → `users` → `recipes` i mieści się w obu regułach, które to
 * repozytorium już ma zmierzone: `media` przed `users` (D-103, komentarz
 * klasy `PrzypnijAwatar`) i `users` przed rzeczą zależną (D-079 §1).
 *
 * ── CZEGO TEN TEST NIE DOWODZI ──
 *
 * Że `PublishRecipe` jest wolne od zakleszczeń w ogóle. Mierzy JEDEN
 * przeplot — ten, który był zmierzoną usterką. Nie dotyka też pary
 * `users` × `media` przy kasowaniu konta; to osobne pytanie i osobny test.
 */
#[Group('dwa-polaczenia')]
final class EdycjaPrzepisuNieZakleszczaSieZKasowaniemKontaTest extends TestDwochPolaczen
{
    /** @var list<string> Identyfikatory przepisów utworzonych w teście — do sprzątania. */
    private array $przepisy = [];

    /**
     * Przepisy przed kontami: `recipe_versions.editor_id` ma
     * `ON DELETE RESTRICT`, więc `DELETE FROM users` ze sprzątaczki klasy
     * bazowej odbiłby się o ten klucz obcy.
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

    private function opublikowanyPrzepis(User $autor): Recipe
    {
        $znacznik = bin2hex(random_bytes(5));

        $przepis = Recipe::create([
            'author_id' => $autor->getKey(),
            'title' => 'Rosół egzekucyjny '.$znacznik,
            'slug' => 'rosol-egzekucyjny-'.$znacznik,
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

    /**
     * PRZEPLOT, KTÓRY TO ROZSTRZYGA
     *
     *  1. bariera trzyma wiersz „Ugotowałem" tej osoby. `usunTresci()`
     *     kasuje `cooked_events` PRZED `recipes`, więc egzekucja staje
     *     dokładnie POMIĘDZY wzięciem wiersza `users` a sięgnięciem po
     *     wiersz przepisu — czyli w jedynym miejscu, w którym da się
     *     wymusić spotkanie obu kolejności;
     *  2. w tym czasie autor zapisuje przepis;
     *  3. bariera puszcza, egzekucja idzie po `recipes`.
     *
     * Przed naprawą zamykał się tu cykl i PostgreSQL zabijał zapis autora
     * (`40P01`). Po naprawie zapis autora ustawia się w kolejce po wiersz
     * konta, przepuszcza egzekucję i kończy się zdaniem po polsku.
     */
    public function test_zapis_przepisu_obok_egzekucji_kasowania_konta_nie_zakleszcza_sie(): void
    {
        $autor = $this->konto();
        $autor->markForDeletion(User::DELETE_SCOPE_EVERYTHING);

        $przepis = $this->opublikowanyPrzepis($autor);

        CookedEvent::create([
            'user_id' => $autor->getKey(),
            'recipe_id' => $przepis->getKey(),
        ]);

        $bariera = $this->bariera(
            'SELECT 1 FROM cooked_events WHERE user_id = ? FOR UPDATE',
            [(string) $autor->getKey()],
        );

        $kasowanie = $this->wTle('kasowanie', ['konto' => (string) $autor->getKey()]);
        $this->czekajNaZablokowane(1);

        $edycja = $this->wTle('edytuj-przepis', [
            'autor' => (string) $autor->getKey(),
            'przepis' => (string) $przepis->getKey(),
            'tytul' => 'Rosół zapisany obok egzekucji',
            'skladnik' => 'lubczyk',
        ]);
        $this->czekajNaZablokowane(2);

        $this->zwolnijBariere($bariera);

        $wynikKasowania = $kasowanie->wynik();
        $wynikEdycji = $edycja->wynik();

        // TO JEST CAŁE ZNALEZISKO.
        $this->assertBezZakleszczenia($wynikKasowania, 'egzekucja kasowania konta obok zapisu przepisu');
        $this->assertBezZakleszczenia($wynikEdycji, 'zapis przepisu obok egzekucji kasowania konta');

        // KONTROLA DODATNIA NR 1: egzekucja NAPRAWDĘ się wykonała. Bez tego
        // test przechodzi także wtedy, gdy proces wywrócił się przed
        // pierwszym zapytaniem — a wtedy nie było czego mierzyć.
        $this->assertTrue(
            $wynikKasowania['ok'],
            'Egzekucja kasowania nie przeszła, więc nie ma czego mierzyć: '
            .$wynikKasowania['wyjatek'].' '.$wynikKasowania['komunikat'],
        );
        $this->assertTrue($wynikKasowania['wartosc'], 'Egzekucja nie wymazała konta — przeplot był inny niż opisany.');

        // KONTROLA DODATNIA NR 2: zapis autora też doszedł do bazy, a nie
        // wywrócił się na czymkolwiek innym. Musi się ODBIĆ — przepis został
        // w tym czasie skasowany razem z kontem — ale zdaniem po polsku,
        // nie ekranem błędu.
        $this->assertFalse($wynikEdycji['ok'], 'Zapis przeszedł na przepisie, który przestał istnieć.');
        $this->assertStringContainsString(
            'Tego przepisu już nie ma',
            (string) $wynikEdycji['komunikat'],
            'Zapis padł z innego powodu niż zniknięcie przepisu: '
            .$wynikEdycji['wyjatek'].' '.$wynikEdycji['komunikat'],
        );

        $this->assertSame(
            0,
            Recipe::query()->whereKey($przepis->getKey())->count(),
            'Przepis przetrwał egzekucję kasowania konta.',
        );
    }
}
