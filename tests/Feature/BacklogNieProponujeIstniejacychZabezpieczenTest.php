<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Rules\ReservedUsername;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Lista „do założenia jako issues" nie proponuje rzeczy, które już działają
 * (audyt zewnętrzny, G15).
 *
 * CO BYŁO NIE TAK
 * `docs/DO_ZALOZENIA_JAKO_ISSUES.md` powstał, gdy skończył się limit API
 * GitHuba, i miał na górze zdanie: „Każda pozycja jest gotowa do przeklejenia
 * jako issue. Po założeniu issue — usuń pozycję stąd."
 *
 * Wszystkie SZEŚĆ pozycji zostało w międzyczasie zaimplementowanych, a plik
 * został z listą do zrobienia i z niezaznaczonymi polami wyboru. Następna
 * sesja czytała to jako sześć rzeczy do zbudowania — i w najlepszym razie
 * traciła czas na sprawdzanie, a w najgorszym zakładała sześć duplikatów
 * albo budowała drugi raz coś, co już stoi.
 *
 * DLACZEGO TO JEST TEST, A NIE SAMA POPRAWKA DOKUMENTU
 * Bo rozjazd wróci. Dokument opisujący stan kodu starzeje się dokładnie tak
 * samo jak `DATABASE.md` przy G13 i runbook przy G14 — po cichu, bez żadnego
 * czerwonego ekranu. Ten test wiąże KAŻDĄ pozycję z artefaktem, na który
 * dokument się powołuje: jeśli artefakt zniknie, test powie, że dokument
 * zaczął kłamać.
 *
 * CZEGO TEN TEST NIE ROBI
 * Nie sprawdza, czy zabezpieczenia DZIAŁAJĄ — od tego są ich własne testy,
 * wymienione w dokumencie (`AccountStatusTest`, `ZastrzezoneNazwyTest`,
 * `SkladnikBezIlosciTest`, `UnikalnoscZeszytowTest`, macierz
 * `tests/Feature/Visibility/`). Tu chodzi wyłącznie o to, żeby dokument
 * i kod mówiły to samo.
 */
class BacklogNieProponujeIstniejacychZabezpieczenTest extends TestCase
{
    use RefreshDatabase;

    private function dokument(): string
    {
        return (string) file_get_contents(base_path('docs/DO_ZALOZENIA_JAKO_ISSUES.md'));
    }

    /**
     * Żadnej niezaznaczonej pozycji „do zrobienia".
     *
     * To jest cała treść usterki w jednej asercji: `- [ ]` w tym pliku znaczy
     * „zbuduj to", a nie ma tu już czego budować. Gdyby kiedyś doszło nowe
     * znalezisko, ma trafić na GitHuba jako issue albo do `docs/ROADMAP.md` —
     * nie z powrotem tutaj, bo ten plik istniał wyłącznie jako obejście
     * wyczerpanego limitu API.
     */
    public function test_dokument_nie_zawiera_juz_listy_do_zrobienia(): void
    {
        $this->assertStringNotContainsString(
            '- [ ]',
            $this->dokument(),
            'W `docs/DO_ZALOZENIA_JAKO_ISSUES.md` znowu jest lista „do zrobienia". '
            .'Ten plik jest ZAPISEM WERYFIKACJI zamkniętych znalezisk, nie backlogiem — '
            .'nowe zadania idą na GitHuba albo do `docs/ROADMAP.md`.',
        );
    }

    /**
     * Każdy artefakt, na który powołuje się dokument, naprawdę istnieje.
     *
     * Kolejność jak w dokumencie, żeby dało się czytać jedno przy drugim.
     */
    public function test_wszystkie_szesc_zabezpieczen_naprawde_stoi_w_kodzie(): void
    {
        // 1. Sesje unieważniane przy zmianie statusu, sprawdzane przy każdym żądaniu.
        $this->assertTrue(
            method_exists(User::class, 'invalidateSessions'),
            'Zniknęło `User::invalidateSessions()`. Bez tego zbanowane konto działa '
            .'do końca sesji, czyli przy `SESSION_LIFETIME=10080` przez tydzień.',
        );
        $this->assertFileExists(app_path('Http/Middleware/EnsureAccountIsActive.php'));

        // 2. Kara ma termin, a termin ma kto przywrócić.
        $this->assertTrue(
            $this->kolumnaIstnieje('users', 'status_expires_at'),
            'Zniknęła kolumna `users.status_expires_at`. Bez niej każda blokada '
            .'czasowa staje się trwała, bo przy jednym moderatorze nikt jej nie odklika.',
        );
        $this->assertFileExists(app_path('Console/Commands/RestoreExpiredSuspensions.php'));

        // 3. Kanoniczna macierz widoczności.
        $this->assertFileExists(base_path('tests/Feature/Visibility/WidocznoscTestCase.php'));

        // 4. Zastrzeżone nazwy — reguła ORAZ niepusta lista.
        $this->assertTrue(class_exists(ReservedUsername::class));
        $this->assertNotEmpty(
            (array) config('kuking.account.reserved_usernames'),
            'Lista zastrzeżonych nazw jest pusta. Reguła bez listy przepuszcza '
            .'konto „moderacja" — gotowe narzędzie phishingu.',
        );

        // 5. Składnik bez ilości.
        $this->assertTrue($this->kolumnaIstnieje('recipe_ingredients', 'no_amount'));

        // 6. Trzy ograniczenia unikalności, każde z osobna.
        foreach ([
            'collection_items' => ['collection_items_recipe_unique', 'collection_items_post_unique'],
            'collections' => ['collections_owner_name_lower_unique'],
            'post_media' => ['post_media_pkey'],
        ] as $tabela => $indeksy) {
            $istniejace = array_column(
                DB::select('SELECT indexname FROM pg_indexes WHERE tablename = ?', [$tabela]),
                'indexname',
            );

            foreach ($indeksy as $indeks) {
                $this->assertContains(
                    $indeks,
                    $istniejace,
                    "Zniknął indeks `{$indeks}` na `{$tabela}`. Dokument "
                    .'`docs/DO_ZALOZENIA_JAKO_ISSUES.md` twierdzi, że stoi.',
                );
            }
        }
    }

    /**
     * Kontrola metody pomiaru: `kolumnaIstnieje()` umie zwrócić `false`.
     *
     * Bez tego dwie asercje na kolumny wyżej przechodziłyby także wtedy, gdyby
     * ta metoda zawsze mówiła „jest" — a wtedy nie mierzyłyby niczego.
     */
    public function test_kontrola_sprawdzanie_kolumny_wykrywa_brak(): void
    {
        $this->assertFalse($this->kolumnaIstnieje('users', 'kolumna_ktorej_nie_ma'));
        $this->assertFalse($this->kolumnaIstnieje('tabela_ktorej_nie_ma', 'id'));
    }

    private function kolumnaIstnieje(string $tabela, string $kolumna): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            [$tabela, $kolumna],
        ) !== [];
    }
}
