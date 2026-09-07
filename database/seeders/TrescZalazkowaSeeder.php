<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Recipes\Actions\GenerateRecipeSlug;
use App\Domain\Recipes\Actions\SnapshotRecipeVersion;
use App\Domain\Tags\Actions\ResolveTagsForPost;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Treść zalążkowa — D-025, `docs/DECISIONS.md`.
 *
 * PO CO TO JEST
 * Serwis działa i nie jest promowany — pierwsza zaproszona osoba trafiała na
 * pusty feed. Właściciel, wprost zapytany, odpowiedział: „Tak, ale jawnie
 * oznaczone" — stąd dwanaście kont-person, czterdzieści przepisów,
 * osiemdziesiąt wpisów i sześćdziesiąt komentarzy z `dane/
 * tresc-zalazkowa.json`, każde konto oznaczone `users.is_seeded = true`
 * (migracja `2026_09_07_700000_add_is_seeded_to_users`) i widocznie
 * podpisane w interfejsie komponentem `x-konto-przykladowe` wszędzie tam,
 * gdzie serwis pokazuje autora — profil, karta wpisu, karta przepisu,
 * komentarz.
 *
 * DANE SĄ W PLIKU, NIE W TEJ KLASIE — ten sam powód co `TagSeeder`
 * (`dane/README.md`): kolejna wersja treści podmienia jeden plik bez
 * ruszania kodu, a zawartość dało się zweryfikować maszynowo (zero
 * e-maili/telefonów/adresów/emotikon/wykrzykników/obietnic zdrowotnych,
 * nazwy kont zgodne z `[a-z0-9_]{2,20}`, integralność odwołań pełna) zanim
 * ten seeder w ogóle powstał.
 *
 * PIĘĆ KROKÓW WALIDACJI, W TEJ KOLEJNOŚCI (wzorem `TagSeeder`, SPEC §1.4)
 *   1. struktura pliku — brakująca tablica najwyższego poziomu albo zepsuty
 *      JSON to nie sytuacja, którą wolno przemilczeć: seeder bez treści
 *      utworzyłby zero kont i zaraportował sukces, więc rzucamy wyjątek;
 *   2. kształt kont — nazwa użytkownika pasuje do `^[a-z0-9_]{2,20}$`
 *      (jak deklaruje `dane/README.md`) I do CHECK-a bazy
 *      `^[a-zA-Z0-9_]{3,40}$` (`profiles_username_check`), unikalna
 *      w obrębie pliku, ma niepustą nazwę wyświetlaną;
 *   3. integralność odwołań — `ref` unikalny w obrębie swojej listy, `autor`
 *      każdego przepisu/wpisu/komentarza wskazuje istniejące konto z pliku,
 *      `do` każdego komentarza wskazuje istniejący `ref` (przepis albo wpis);
 *   4. kolizja z istniejącą bazą — nazwa użytkownika już zajęta przez
 *      PRAWDZIWEGO człowieka (konto bez `is_seeded`) NIE JEST RUSZANA:
 *      import tego jednego konta, razem z całą jego treścią z pliku, jest
 *      pomijany i zgłaszany, dokładnie jak `TagSeeder` przy tagu utworzonym
 *      ręcznie. Zajęta przez konto TEGO seedera (drugie uruchomienie) —
 *      używamy istniejącego konta, nic nie tworzymy drugi raz;
 *   5. raport na końcu — liczby utworzonych/pominiętych kont, przepisów,
 *      wpisów, komentarzy, z powodem każdego pominięcia.
 *
 * Punkty 2 i 3 NIE przerywają całego importu przy pojedynczym złym wpisie —
 * ten wpis jest pomijany i zgłaszany, reszta pliku importuje się normalnie.
 * Plik jest już zweryfikowany maszynowo (patrz wyżej), więc w praktyce te
 * gałęzie są siatką bezpieczeństwa na wypadek przyszłej, niesprawdzonej
 * aktualizacji pliku — nie usuwamy ich „bo dziś nigdy się nie wykonają".
 *
 * IDEMPOTENCJA. Seeder chodzi też na istniejącej bazie (`db:seed` bez
 * `migrate:fresh`) i JEST WOŁANY Z `DatabaseSeeder` TAKŻE NA PRODUKCJI —
 * to jest dosłownie sens D-025 („treść zalążkowa ma wejść na produkcję").
 * Naturalny klucz idempotencji dla każdego typu wiersza:
 *   - konto: `lower(username)` (ten sam indeks co reszta serwisu);
 *   - przepis: `(author_id, title)` — brak duplikatu tytułu w pliku
 *     (zmierzone), a `GenerateRecipeSlug` i tak nadałby drugi slug drugiemu
 *     uruchomieniu, gdybyśmy nie sprawdzili tego wcześniej;
 *   - wpis: `(author_id, body)` — treść wpisów w pliku jest unikalna;
 *   - komentarz: `(author_id, cel, body)` — jak wyżej.
 * Drugie uruchomienie nie tworzy niczego nowego i raportuje same zera.
 *
 * CZEGO TEN SEEDER ŚWIADOMIE NIE TWORZY
 *
 * `tagi` PRZY PRZEPISACH NIE MAJĄ GDZIE ZAMIESZKAĆ. Schemat ma `post_tags`
 * (`Post::tags()`), ale NIE MA odpowiednika dla `recipes` — żadna migracja
 * w tym repozytorium nie tworzy `recipe_tags`. Wymyślenie takiej tabeli na
 * potrzeby tego seedera byłoby dokładnie tym, czego zakazuje AGENTS.md §3
 * („Zakaz overengineeringu") i sekcja o ADR-ach: nowa architektura bez
 * decyzji właściciela. Dwa tagi, które w pliku występują WYŁĄCZNIE przy
 * przepisach („mięso", „na niedzielę" — sprawdzone: żaden wpis ich nie ma)
 * więc nigdy nie stają się wierszem `tags`. Pozostałe 38 unikalnych nazw
 * z `przepisy[].tagi` I TAK powstają, bo te same nazwy występują też
 * w `wpisy[].tagi` (zmierzone: wszystkie dziesięć tagów promowanych ma
 * pełne pokrycie w tagach WPISÓW, nie tylko przepisów) — więc żaden
 * z dziesięciu tagów promowanych nie ginie, tylko odzyskuje istnienie inną
 * drogą niż ta, którą podpowiada plik.
 *
 * `tagi_promowane` Z PLIKU NIE TRAFIAJĄ DO `tag_promotions`. Ta tabela ma
 * już WŁASNY, chroniony mechanizm zasilania — `TagPromotionSeeder` (czyta
 * `dane/tagi-promowane.json`, ADR `docs/decyzje/TAGI_PROMOWANE.md`,
 * status na dziś: DO DECYZJI WŁAŚCICIELA) z twardą zasadą „działam TYLKO
 * na pustej liście, bo to jest wybór gospodarza z panelu, nie coś, co
 * seeder ma prawo nadpisać ani przywrócić". Ta lista i `tagi_promowane`
 * z `tresc-zalazkowa.json` NIE SĄ TĄ SAMĄ LISTĄ (różne tagi, różna treść
 * zdań) — pisanie do `tag_promotions` z DWÓCH niezależnych seederów
 * złamałoby właśnie tę zasadę, którą `TagPromotionSeeder` istnieje, żeby
 * egzekwować. Który zestaw dziesięciu/dwunastu tagów ma być tą listą —
 * albo czy obie mają się scalić — jest decyzją właściciela, nie tego
 * seedera. Same tagi z `tagi_promowane` powstają (patrz wyżej, przez
 * tagowanie wpisów) i da się je kliknąć; po prostu żaden nie jest dziś
 * PROMOWANY przez ten import.
 *
 * `source_type` PRZEPISU JEST ZAWSZE `own`. Plik nie ma pola źródła
 * (`przepisy[].source_type`/`source_person` nie istnieją w danych — tylko
 * `ref`, `autor`, `tytul`, `tagi`, `tresc`) mimo że część tytułów sugeruje
 * `family` („Sernik po mamie"). Zgadywanie tego z treści tytułu byłoby
 * tworzeniem danych, których plik nie podaje — `attributionLine()` pokaże
 * po prostu „przepis {imię}", co jest prawdziwe (przepis NALEŻY do tego
 * konta), tylko mniej szczegółowe niż mogłoby być.
 *
 * `glos` PRZY KONCIE NIE JEST ZAPISYWANE NIGDZIE. To wskazówka tonu dla
 * osoby, która pisała treść tego pliku („krótko i rzeczowo", „dużo
 * dygresji") — pilnuje SPÓJNOŚCI głosu między czterdziestoma przepisami
 * i osiemdziesięcioma wpisami tej samej persony podczas PISANIA pliku,
 * nie jest daną o koncie. Żadna kolumna w tym repozytorium na nią nie
 * czeka i seeder jej nie czyta.
 */
class TrescZalazkowaSeeder extends Seeder
{
    private const PLIK = 'tresc-zalazkowa.json';

    /** Domena zarezerwowana wyłącznie do testów (RFC 2606) — nigdy nie dostarcza poczty. */
    private const DOMENA_EMAIL = 'example.test';

    /**
     * @var array{
     *     konta: int, konta_juz_istniejace: int, konta_pominiete: list<string>,
     *     przepisy: int, przepisy_pominiete: list<string>,
     *     wpisy: int, wpisy_pominiete: list<string>,
     *     komentarze: int, komentarze_pominiete: list<string>,
     * }
     */
    private array $raport = [
        'konta' => 0,
        'konta_juz_istniejace' => 0,
        'konta_pominiete' => [],
        'przepisy' => 0,
        'przepisy_pominiete' => [],
        'wpisy' => 0,
        'wpisy_pominiete' => [],
        'komentarze' => 0,
        'komentarze_pominiete' => [],
    ];

    public function run(): void
    {
        $dane = $this->wczytajPlik();

        // Krok 1: struktura.
        $this->walidujStrukture($dane);

        // Krok 2: kształt i unikalność kont.
        $konta = $this->walidujKonta($dane['konta']);
        $nazwyKont = array_map(static fn (array $k): string => $k['nazwa'], $konta);

        // Krok 3: integralność odwołań.
        $wszystkieRefy = [
            ...array_map(static fn (array $p): string => (string) $p['ref'], $dane['przepisy']),
            ...array_map(static fn (array $w): string => (string) $w['ref'], $dane['wpisy']),
        ];
        $przepisy = $this->walidujOdwolaniaTresci($dane['przepisy'], $nazwyKont, 'przepisy_pominiete');
        $wpisy = $this->walidujOdwolaniaTresci($dane['wpisy'], $nazwyKont, 'wpisy_pominiete');
        $komentarze = $this->walidujKomentarze($dane['komentarze'], $nazwyKont, $wszystkieRefy);

        // Krok 4: kolizje z bazą + utworzenie/odzyskanie kont.
        $uzytkownicy = $this->utworzKonta($konta);

        $mapaPrzepisow = $this->utworzPrzepisy($przepisy, $uzytkownicy);
        $mapaWpisow = $this->utworzWpisy($wpisy, $uzytkownicy);
        $this->utworzKomentarze($komentarze, $uzytkownicy, $mapaPrzepisow, $mapaWpisow);

        // Krok 5: raport.
        $this->zgloscRaport();
    }

    /** @return array<string, mixed> */
    private function wczytajPlik(): array
    {
        $sciezka = database_path('seeders/dane/'.self::PLIK);
        $surowe = file_get_contents($sciezka);

        if ($surowe === false) {
            throw new \RuntimeException("Nie da się wczytać treści zalążkowej: {$sciezka}");
        }

        $dane = json_decode($surowe, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($dane)) {
            throw new \RuntimeException('Treść zalążkowa nie jest obiektem JSON.');
        }

        return $dane;
    }

    /**
     * Krok 1: pięć tablic najwyższego poziomu muszą istnieć. Brak którejś
     * oznacza plik uszkodzony albo podmieniony na inny kształt — importowanie
     * połowy treści zalążkowej byłoby gorsze niż odmowa startu.
     *
     * @param  array<string, mixed>  $dane
     */
    private function walidujStrukture(array $dane): void
    {
        foreach (['konta', 'przepisy', 'wpisy', 'komentarze', 'tagi_promowane'] as $klucz) {
            if (! isset($dane[$klucz]) || ! is_array($dane[$klucz])) {
                throw new \RuntimeException("Treść zalążkowa nie ma tablicy `{$klucz}`.");
            }
        }
    }

    /**
     * Krok 2: kształt i unikalność kont.
     *
     * @param  list<array<string, mixed>>  $konta
     * @return list<array{nazwa: string, wyswietlana: string}> konta, które przeszły walidację
     */
    private function walidujKonta(array $konta): array
    {
        $widziane = [];
        $poprawne = [];

        foreach ($konta as $konto) {
            $nazwa = trim((string) ($konto['nazwa'] ?? ''));
            $wyswietlana = trim((string) ($konto['wyswietlana'] ?? ''));

            if ($nazwa === '' || $wyswietlana === '') {
                $this->raport['konta_pominiete'][] = "(puste pole u konta „{$nazwa}”)";

                continue;
            }

            // `dane/README.md` deklaruje `[a-z0-9_]{2,20}`, ale
            // `profiles_username_check` w bazie wymaga MINIMUM 3 znaków
            // (`^[a-zA-Z0-9_]{3,40}$`) — sprawdzamy oba, żeby nie odkryć
            // rozjazdu dopiero na `INSERT`-cie.
            if (preg_match('/^[a-z0-9_]{2,20}$/', $nazwa) !== 1 || mb_strlen($nazwa) < 3) {
                $this->raport['konta_pominiete'][] = "„{$nazwa}” (nazwa nie pasuje do wzorca konta)";

                continue;
            }

            if (isset($widziane[$nazwa])) {
                $this->raport['konta_pominiete'][] = "„{$nazwa}” (powtórzona nazwa w pliku)";

                continue;
            }

            $widziane[$nazwa] = true;
            $poprawne[] = ['nazwa' => $nazwa, 'wyswietlana' => $wyswietlana];
        }

        return $poprawne;
    }

    /**
     * Krok 3 (przepisy/wpisy): `ref` unikalny w tej liście, `autor` istnieje.
     *
     * @param  list<array<string, mixed>>  $wpisy
     * @param  list<string>  $nazwyKont
     * @param  'przepisy_pominiete'|'wpisy_pominiete'  $kluczRaportu
     * @return list<array<string, mixed>>
     */
    private function walidujOdwolaniaTresci(array $wpisy, array $nazwyKont, string $kluczRaportu): array
    {
        $kontaZbior = array_fill_keys($nazwyKont, true);
        $widzianeRefy = [];
        $poprawne = [];

        foreach ($wpisy as $wpis) {
            $ref = trim((string) ($wpis['ref'] ?? ''));
            $autor = trim((string) ($wpis['autor'] ?? ''));

            if ($ref === '' || isset($widzianeRefy[$ref])) {
                $this->raport[$kluczRaportu][] = $ref === '' ? '(brak ref)' : "„{$ref}” (powtórzony ref)";

                continue;
            }

            if (! isset($kontaZbior[$autor])) {
                $this->raport[$kluczRaportu][] = "„{$ref}” (autor „{$autor}” nie jest na liście kont)";

                continue;
            }

            $widzianeRefy[$ref] = true;
            $poprawne[] = $wpis;
        }

        return $poprawne;
    }

    /**
     * Krok 3 (komentarze): `autor` istnieje, `do` wskazuje istniejący ref.
     *
     * @param  list<array<string, mixed>>  $komentarze
     * @param  list<string>  $nazwyKont
     * @param  list<string>  $wszystkieRefy
     * @return list<array<string, mixed>>
     */
    private function walidujKomentarze(array $komentarze, array $nazwyKont, array $wszystkieRefy): array
    {
        $kontaZbior = array_fill_keys($nazwyKont, true);
        $refyZbior = array_fill_keys($wszystkieRefy, true);
        $poprawne = [];

        foreach ($komentarze as $i => $komentarz) {
            $autor = trim((string) ($komentarz['autor'] ?? ''));
            $cel = trim((string) ($komentarz['do'] ?? ''));
            $tresc = trim((string) ($komentarz['tresc'] ?? ''));

            if ($tresc === '') {
                $this->raport['komentarze_pominiete'][] = "(pusta treść, pozycja {$i})";

                continue;
            }

            if (! isset($kontaZbior[$autor])) {
                $this->raport['komentarze_pominiete'][] = "(pozycja {$i}: autor „{$autor}” nie jest na liście kont)";

                continue;
            }

            if (! isset($refyZbior[$cel])) {
                $this->raport['komentarze_pominiete'][] = "(pozycja {$i}: „do” wskazuje nieistniejący „{$cel}”)";

                continue;
            }

            $poprawne[] = $komentarz;
        }

        return $poprawne;
    }

    /**
     * Krok 4: dla każdego konta, które przeszło krok 2 — utwórz, odzyskaj
     * (już zaimportowane) albo pomiń (nazwa zajęta przez prawdziwego
     * człowieka).
     *
     * @param  list<array{nazwa: string, wyswietlana: string}>  $konta
     * @return array<string, User> nazwa z pliku => User
     */
    private function utworzKonta(array $konta): array
    {
        $wynik = [];
        $teraz = now();

        foreach ($konta as $konto) {
            $nazwa = $konto['nazwa'];

            $istniejacyProfil = Profile::query()
                ->whereRaw('lower(username) = ?', [$nazwa])
                ->with('user')
                ->first();

            if ($istniejacyProfil !== null) {
                $istniejacyUzytkownik = $istniejacyProfil->user;

                if ($istniejacyUzytkownik === null || ! $istniejacyUzytkownik->isSeeded()) {
                    // Nazwa zajęta przez prawdziwego człowieka (albo przez
                    // konto osierocone bez usera — i tak nie ruszamy).
                    // Cała treść tej persony zostaje pominięta niżej, bo nie
                    // ma pod kogo jej podpiąć.
                    $this->raport['konta_pominiete'][] = "„{$nazwa}” (nazwa użytkownika należy już do innego konta)";

                    continue;
                }

                // Drugie uruchomienie — konto już zaimportowane, używamy go.
                $this->raport['konta_juz_istniejace']++;
                $wynik[$nazwa] = $istniejacyUzytkownik;

                continue;
            }

            $wyswietlana = $konto['wyswietlana'];
            $id = (string) Str::uuid();

            DB::table('users')->insert([
                'id' => $id,
                'email' => $nazwa.'@'.self::DOMENA_EMAIL,
                // Hasło losowe i nikomu nie znane — te konta nie mają być
                // logowalne przez nikogo, to persony, nie ludzie (D-025).
                'password' => Hash::make(Str::random(64)),
                'status' => User::STATUS_ACTIVE,
                'role' => User::ROLE_USER,
                'is_seeded' => true,
                'locale' => 'pl',
                'text_scale' => 100,
                // Bez zgody na digest — nikt jej nie wyraził (ta sama zasada
                // co migracja `..._default_weekly_digest_to_off`).
                'wants_weekly_digest' => false,
                'age_confirmed_at' => $teraz,
                'email_verified_at' => $teraz,
                'created_at' => $teraz,
                'updated_at' => $teraz,
            ]);

            DB::table('profiles')->insert([
                'user_id' => $id,
                'username' => $nazwa,
                'display_name' => $wyswietlana,
                'created_at' => $teraz,
                'updated_at' => $teraz,
            ]);

            $this->raport['konta']++;
            $wynik[$nazwa] = User::query()->findOrFail($id);
        }

        return $wynik;
    }

    /**
     * @param  list<array<string, mixed>>  $przepisy
     * @param  array<string, User>  $uzytkownicy
     * @return array<string, Recipe> ref => Recipe
     */
    private function utworzPrzepisy(array $przepisy, array $uzytkownicy): array
    {
        $mapa = [];
        $slugi = app(GenerateRecipeSlug::class);
        $wersje = app(SnapshotRecipeVersion::class);

        foreach ($przepisy as $i => $przepis) {
            $ref = (string) $przepis['ref'];
            $autorNazwa = (string) $przepis['autor'];
            $autor = $uzytkownicy[$autorNazwa] ?? null;

            if ($autor === null) {
                // Konto pominięte w kroku 4 (kolizja z prawdziwym człowiekiem).
                $this->raport['przepisy_pominiete'][] = "„{$ref}” (konto „{$autorNazwa}” nie zostało zaimportowane)";

                continue;
            }

            $tytul = trim((string) $przepis['tytul']);

            $istniejacy = Recipe::query()
                ->where('author_id', $autor->getKey())
                ->where('title', $tytul)
                ->first();

            if ($istniejacy !== null) {
                $mapa[$ref] = $istniejacy;

                continue;
            }

            $opublikowano = now()->subDays(90 - $i);

            $recipe = Recipe::create([
                'author_id' => $autor->getKey(),
                'title' => $tytul,
                'slug' => $slugi->handle($tytul),
                // `tresc` z pliku jest prozą pisaną w pierwszej osobie —
                // dokładnie to, do czego służy `summary` (pokazywane pod
                // tytułem, nad składnikami). Struktura na składniki/kroki
                // nie istnieje w tych danych — patrz komentarz klasy, sekcja
                // „czego ten seeder świadomie nie tworzy" o `recipe_tags`
                // dla tej samej klasy decyzji (nie zgadujemy podziału, którego
                // plik nie podaje).
                'summary' => (string) $przepis['tresc'],
                'visibility' => 'public',
                'status' => Recipe::STATUS_PUBLISHED,
                'source_type' => Recipe::SOURCE_OWN,
                'published_at' => $opublikowano,
            ]);

            $wersje->handle($recipe, $autor, 'Treść zalążkowa (D-025)');

            $this->raport['przepisy']++;
            $mapa[$ref] = $recipe;
        }

        return $mapa;
    }

    /**
     * @param  list<array<string, mixed>>  $wpisy
     * @param  array<string, User>  $uzytkownicy
     * @return array<string, Post> ref => Post
     */
    private function utworzWpisy(array $wpisy, array $uzytkownicy): array
    {
        $mapa = [];
        $tagi = app(ResolveTagsForPost::class);

        foreach ($wpisy as $i => $wpis) {
            $ref = (string) $wpis['ref'];
            $autorNazwa = (string) $wpis['autor'];
            $autor = $uzytkownicy[$autorNazwa] ?? null;

            if ($autor === null) {
                $this->raport['wpisy_pominiete'][] = "„{$ref}” (konto „{$autorNazwa}” nie zostało zaimportowane)";

                continue;
            }

            $tresc = trim((string) $wpis['tresc']);

            $istniejacy = Post::query()
                ->where('author_id', $autor->getKey())
                ->where('body', $tresc)
                ->first();

            if ($istniejacy !== null) {
                $mapa[$ref] = $istniejacy;

                continue;
            }

            $opublikowano = now()->subHours((count($wpisy) - $i) * 6);

            $post = Post::create([
                'author_id' => $autor->getKey(),
                'body' => $tresc,
                'visibility' => Post::VISIBILITY_PUBLIC,
                'status' => Post::STATUS_PUBLISHED,
                'published_at' => $opublikowano,
            ]);

            /** @var list<string> $nazwyTagow */
            $nazwyTagow = array_values(array_filter(
                (array) ($wpis['tagi'] ?? []),
                static fn ($t): bool => is_string($t) && trim($t) !== '',
            ));

            foreach ($tagi->handle($nazwyTagow) as $pozycja => $tag) {
                $post->tags()->syncWithoutDetaching([$tag->getKey() => ['position' => $pozycja]]);
            }

            $this->raport['wpisy']++;
            $mapa[$ref] = $post;
        }

        return $mapa;
    }

    /**
     * @param  list<array<string, mixed>>  $komentarze
     * @param  array<string, User>  $uzytkownicy
     * @param  array<string, Recipe>  $mapaPrzepisow
     * @param  array<string, Post>  $mapaWpisow
     */
    private function utworzKomentarze(array $komentarze, array $uzytkownicy, array $mapaPrzepisow, array $mapaWpisow): void
    {
        foreach ($komentarze as $komentarz) {
            $autorNazwa = (string) $komentarz['autor'];
            $cel = (string) $komentarz['do'];
            $tresc = trim((string) $komentarz['tresc']);

            $autor = $uzytkownicy[$autorNazwa] ?? null;

            if ($autor === null) {
                $this->raport['komentarze_pominiete'][] = "(autor „{$autorNazwa}” nie został zaimportowany)";

                continue;
            }

            $jestPrzepisem = str_starts_with($cel, 'p');
            $przepis = $jestPrzepisem ? ($mapaPrzepisow[$cel] ?? null) : null;
            $wpis = $jestPrzepisem ? null : ($mapaWpisow[$cel] ?? null);

            if ($przepis === null && $wpis === null) {
                // Cel nie został utworzony (np. autor JEGO przepisu/wpisu
                // został pominięty w kroku 4) — komentarz nie ma do czego
                // się podpiąć.
                $this->raport['komentarze_pominiete'][] = "(cel „{$cel}” nie został zaimportowany)";

                continue;
            }

            $query = Comment::query()->where('author_id', $autor->getKey())->where('body', $tresc);
            $query = $przepis !== null
                ? $query->where('recipe_id', $przepis->getKey())
                : $query->where('post_id', $wpis->getKey());

            if ($query->exists()) {
                continue;
            }

            Comment::create([
                'author_id' => $autor->getKey(),
                'recipe_id' => $przepis?->getKey(),
                'post_id' => $wpis?->getKey(),
                'body' => $tresc,
                'status' => Comment::STATUS_PUBLISHED,
            ]);

            $this->raport['komentarze']++;
        }
    }

    private function zgloscRaport(): void
    {
        $this->command?->info(sprintf(
            'TrescZalazkowaSeeder: %d nowych kont (%d już istniało), %d przepisów, %d wpisów, %d komentarzy.',
            $this->raport['konta'],
            $this->raport['konta_juz_istniejace'],
            $this->raport['przepisy'],
            $this->raport['wpisy'],
            $this->raport['komentarze'],
        ));

        $wszystkiePominiecia = [
            ...$this->raport['konta_pominiete'],
            ...$this->raport['przepisy_pominiete'],
            ...$this->raport['wpisy_pominiete'],
            ...$this->raport['komentarze_pominiete'],
        ];

        foreach ($wszystkiePominiecia as $powod) {
            $this->command?->warn('  pominięto: '.$powod);
        }
    }
}
