<?php

declare(strict_types=1);

namespace App\Domain\Wydania\Actions;

use App\Support\SlugGfm;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Rejestruje BIEŻĄCE wdrożenie w dzienniku `wdrozenia` — numer wersji
 * z końcówką (issue #1932, D-318).
 *
 * WOŁANA Z KOMENDY `kuking:zarejestruj-wdrozenie`, w tym samym kroku
 * wdrożenia co `php artisan migrate --force` (`.railway/railway.ts`,
 * `preDeployCommand`) — PO migracjach, bo tabela `wdrozenia` musi już
 * istnieć.
 *
 * IDEMPOTENCJA (`UNIQUE (commit)`)
 * Ten sam commit zarejestrowany drugi raz (redeploy bez zmiany kodu,
 * ponowiony krok pre-deploy po chwilowym błędzie sieci) NIE zakłada
 * drugiego wiersza i NIE zużywa kolejnego numeru — `handle()` sprawdza
 * istniejący wiersz PRZED wstawieniem nowego i, jeśli jest, kończy bez
 * zmian.
 *
 * BEZPIECZEŃSTWO PRZY RÓWNOLEGŁYM STARCIE
 * `numer` liczymy jako `MAX(numer) WHERE etykieta = ?) + 1` — to NIE jest
 * bezpieczne bez blokady, bo dwa równoległe starty (np. redeploy uruchomiony
 * tuż po poprzednim, zanim ten pierwszy zdążył zatwierdzić transakcję)
 * przeczytałyby ten sam `MAX` i policzyły ten sam `numer + 1`. Dlatego całe
 * `handle()` chodzi w `DB::transaction()`, która NAJPIERW bierze
 * `pg_advisory_xact_lock(hashtext($etykieta))` — blokadę na CAŁY czas
 * transakcji, zwalnianą automatycznie przy jej końcu (COMMIT albo
 * ROLLBACK). Drugi równoległy start z TĄ SAMĄ etykietą czeka na tę blokadę,
 * aż pierwszy zatwierdzi swój wiersz, i dopiero wtedy liczy `MAX` na nowo —
 * pod odczytem `READ COMMITTED` (domyślny w PostgreSQL) widzi już
 * zatwierdzony wiersz pierwszego. Test na dwóch prawdziwych połączeniach:
 * `tests/Dwa/RejestracjaWdrozeniaNaDwochPolaczeniachTest.php`.
 *
 * Różne etykiety (np. „Alfa 0.68" i „Alfa 0.69" tuż po podbiciu dużego
 * numeru) mają RÓŻNE klucze blokady — rejestracje pod różnymi etykietami
 * nie czekają na siebie nawzajem, bo liczą różne sekwencje.
 *
 * FUNKCJE „OD ALFA 0.68.NNN" — PRZY TYM SAMYM PRZEBIEGU
 * Zaraz po zapisaniu (albo odnalezieniu) wiersza `wdrozenia` (patrz issue,
 * sekcja „Proponowane rozwiązanie": „przy tym samym przebiegu komenda
 * zapisuje, które nagłówki funkcji … pojawiły się pierwszy raz"), funkcja
 * czyta `resources/nowosci/tresc.md`, wycina sekcję „## Najnowsze zmiany"
 * (to samo wycinanie co `StraznikNowosciKazdaNowaFunkcjaMaAkapitTest`) i dla
 * KAŻDEGO nagłówka `###` w niej liczy slug (`SlugGfm`, ten sam algorytm co
 * kotwice wydań, #1909). Nagłówek, którego sluga NIE MA jeszcze w
 * `wdrozenia_funkcje` (pod ŻADNĄ etykietą — `naglowek_slug` jest `UNIQUE`
 * samo w sobie, patrz D-318 w migracji), dostaje wiersz z numerem TEGO
 * wdrożenia — `INSERT … ON CONFLICT (naglowek_slug) DO NOTHING`, więc
 * nagłówek widziany już wcześniej (np. w poprzednim wdrożeniu tej samej
 * etykiety, gdy ktoś dopisał do niego kolejne zdanie — ALBO w ogóle pod
 * INNĄ, wcześniejszą etykietą, sprzed podbicia dużego numeru) NIE dostaje
 * nowego, późniejszego numeru — zostaje przy TYM, pod którym pojawił się
 * pierwszy raz. To jest właśnie sens „od Alfa 0.68.NNN": data pierwszego
 * pojawienia się, nie data ostatniej edycji — i zostaje przy nim także
 * wtedy, gdy nagłówek później przejdzie z „Najnowsze zmiany" do sekcji
 * nazwanego wydania (dopisek jest wtedy stały, decyzja właściciela
 * z 26 września 2026 — patrz `App\Http\Controllers\NowosciController`).
 *
 * Ten krok jest wykonywany TYLKO gdy wiersz `wdrozenia` był NOWY (nie przy
 * idempotentnym powtórzeniu) — powtórzenie tego samego commita nie może
 * przypadkiem nadać numeru funkcjom, których jeszcze nie było w bazie,
 * gdyby ktoś wywołał komendę ręcznie drugi raz z innym stanem pliku na
 * dysku niż przy pierwszym uruchomieniu (w praktyce plik jest częścią tego
 * samego commita i się nie zmienia, ale funkcja nie zakłada tego na słowo).
 *
 * DLACZEGO SKANUJEMY WYŁĄCZNIE „NAJNOWSZE ZMIANY", NIE SEKCJE WYDAŃ (#1932)
 * Rozważaliśmy też skanowanie nagłówków `###` w już nazwanych sekcjach
 * wydania („## Alfa 0.68" itd.) — ale to jest NIEBEZPIECZNE właśnie PRZY
 * PIERWSZYM uruchomieniu tego kodu: tabela `wdrozenia_funkcje` jest wtedy
 * pusta, więc KAŻDY nagłówek z KAŻDEJ już wydanej sekcji (Alfa 0.62…0.68)
 * wyglądałby jak „nowy" i dostałby numer BIEŻĄCEGO wdrożenia — fałszywie
 * przypisując świeżą datę funkcji, która działa od tygodni. Sekcja
 * „Najnowsze zmiany" nie ma tego problemu, bo z definicji zawiera tylko to,
 * co jeszcze nie ma numeru wydania. Zamiast tego trwałość dopisku przy
 * przenosinach nagłówka do sekcji wydania załatwia SAM SLUG: `naglowek_slug`
 * jest `UNIQUE` niezależnie od etykiety (patrz wyżej), a nagłówek NIE zmienia
 * tekstu przy przenosinach z „Najnowsze zmiany" do nazwanej sekcji (sprawdzone
 * na `resources/nowosci/tresc.md` — nagłówki wydanych sekcji brzmią tak samo
 * jak wtedy, gdy stały jeszcze w „Najnowsze zmiany"). Wiersz zapisany TU,
 * póki nagłówek jeszcze stał w „Najnowsze zmiany", więc dalej pasuje po
 * przenosinach — `App\Http\Controllers\NowosciController` dopasowuje po
 * samym slugu, w CAŁYM dokumencie, nie tylko w tej sekcji.
 */
final class ZarejestrujWdrozenie
{
    public function handle(string $commit, string $etykieta): int
    {
        $commit = strtolower(trim($commit));
        $etykieta = trim($etykieta);

        if ($commit === '' || ! preg_match('/^[0-9a-f]{40}$/', $commit)) {
            throw new RuntimeException(
                'Commit musi być pełnym SHA-1 gita (40 znaków szesnastkowych), a nie „'.$commit.'".',
            );
        }

        if ($etykieta === '') {
            throw new RuntimeException('Etykieta wersji jest pusta — sprawdź kuking.wersja.etykieta.');
        }

        return DB::transaction(function () use ($commit, $etykieta): int {
            // Blokada na CAŁY czas transakcji: dwa równoległe wywołania pod tą
            // samą etykietą serializują się tutaj, zamiast policzyć ten sam
            // `MAX(numer) + 1` naraz.
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$etykieta]);

            $istniejacy = DB::table('wdrozenia')->where('commit', $commit)->first();

            if ($istniejacy !== null) {
                // Idempotencja: ten sam commit drugi raz nie zużywa numeru
                // i nie dotyka `wdrozenia_funkcje` (patrz komentarz klasy).
                return (int) $istniejacy->numer;
            }

            $numer = (int) (DB::table('wdrozenia')->where('etykieta', $etykieta)->max('numer') ?? 0) + 1;

            DB::table('wdrozenia')->insert([
                'commit' => $commit,
                'etykieta' => $etykieta,
                'numer' => $numer,
                'created_at' => now(),
            ]);

            $this->zarejestrujNoweFunkcje($etykieta, $numer);

            return $numer;
        });
    }

    private function zarejestrujNoweFunkcje(string $etykieta, int $numer): void
    {
        $sciezka = (string) config('kuking.nowosci.tresc');

        if (! is_file($sciezka)) {
            // Brak pliku nie ma prawa wywalić wdrożenia — rejestracja numeru
            // wdrożenia jest ważniejsza niż ten krok pomocniczy.
            return;
        }

        $tresc = (string) file_get_contents($sciezka);
        $sekcja = $this->sekcjaNajnowszychZmian($tresc);

        if ($sekcja === null) {
            return;
        }

        if (preg_match_all('/^###\s+(.+)$/mu', $sekcja, $dopasowania) === false || $dopasowania[1] === []) {
            return;
        }

        foreach ($dopasowania[1] as $naglowek) {
            $naglowek = trim($naglowek);
            $slug = SlugGfm::z($naglowek);

            if ($slug === '') {
                continue;
            }

            DB::table('wdrozenia_funkcje')->insertOrIgnore([
                'etykieta' => $etykieta,
                'naglowek_slug' => $slug,
                'naglowek_tekst' => mb_substr($naglowek, 0, 300),
                'numer' => $numer,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Treść między „## Najnowsze zmiany" a NASTĘPNYM nagłówkiem `## ` (albo
     * końcem pliku) — to samo wycinanie co
     * `StraznikNowosciKazdaNowaFunkcjaMaAkapitTest::sekcja()`, celowo: obie
     * strony (CHANGELOG „## Nieopublikowane" i ten plik) muszą się zgadzać
     * co do tego, co jest „jeszcze bez numeru wydania".
     */
    private function sekcjaNajnowszychZmian(string $tresc): ?string
    {
        $wzor = '/^##\s+Najnowsze zmiany\R(.*?)(?=^##\s|\z)/msu';

        if (preg_match($wzor, $tresc, $dopasowanie) !== 1) {
            return null;
        }

        return $dopasowanie[1];
    }
}
