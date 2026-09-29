# Audyt błędów poprawności w przepływach domeny — 29/30 września 2026

**Obszar:** `bledy-przeplywy` — logika domeny w `app/Domain`, `app/Http`,
`app/Jobs`, `app/Livewire`.
**Baza:** `origin/claude/paczka-i-kandydat` = `5548c7e16` (przyszły `main`).

**Metoda.** Przeczytany kod domeny w obszarach z zadania, a potem hipotezy
sprawdzone testami PHPUnit na PostgreSQL 18
(`APP_BASE_PATH=$(pwd) php artisan test <plik>`). Każde znalezisko ma test,
który **dziś oblewa na bazie**. Przy BP-01 jest też kontrola dodatnia: ten sam
scenariusz z większym budżetem przechodzi. Testy są niżej jako gotowy kod
regresyjny (namespace `Tests\Feature`). Do `tests/` ich nie dodano, bo to
audyt, nie poprawka. Duplikaty sprawdzone wyszukiwarką issues GitHuba
(otwarte i zamknięte) oraz grepem w `docs/audyt*` i `docs/audits/*`.
Bezpośredni `curl` do API GitHuba blokuje w tej sesji piaskownica, więc
wyszukiwanie szło przez narzędzie MCP (tylko odczyt).

## Podsumowanie

5 znalezisk: **P0: 0, P1: 0, P2: 3, P3: 2.** Rdzeń obietnic produktu jest
w kodzie pilnowany starannie i testy nie znalazły w nim błędu. Dotyczy to
„Ugotowałem” z trzema granicami powiadomienia, idempotencji wysłań, blokad,
zamków par kont, wspólnych zeszytów i strefy Europe/Warsaw. Znaleziska leżą
na krawędziach:

- kolejność kandydatów do tygodniowego podsumowania;
- luka po równoległym scaleniu dwóch funkcji z 26.09;
- rzutowanie wejścia na tekst przed walidacją;
- dwa drobiazgi w nowych funkcjach V2 (#2024 i #2016).

## Znaleziska

| ID | Waga | Tytuł | Dowód (plik:linia na bazie) | Odtworzenie | Wpływ | Proponowana poprawka i test | Rozmiar | Duplikat? |
|---|---|---|---|---|---|---|---|---|
| BP-01 | P2 | Tygodniowe podsumowanie: konta bez treści na stałe zajmują okno kandydatów i odcinają od listu osoby z treścią | `app/Console/Commands/WyslijPodsumowaniaTygodnia.php:201-205` (pusty list: `continue` bez znacznika), `:166` (`naDzis($budzet * 3)`); `app/Domain/Digest/OdbiorcyDigestu.php`, `naDzis()` (`ORDER BY weekly_digest_sent_at ASC NULLS FIRST, created_at, id` + `LIMIT`) | test BP-01: oblewa przy `--limit=1` przez 3 dni, przechodzi przy `--limit=2` | Gdy starszych kont ze zgodą, którym nic się nie dzieje, jest co najmniej 3 × budżet (dziś 180), list nie dochodzi do nikogo młodszego. Nie dostaje go nawet autor, którego przepis ktoś ugotował. Jedyny ślad to wiersz „Pominięto bez treści”, bez alarmu | Kandydatów z pustą treścią przesunąć na koniec kolejki. Na przykład znacznik „sprawdzone w tym tygodniu” (osobna kolumna, jeśli `weekly_digest_sent_at` ma znaczyć tylko „wysłano”). Druga droga: dobierać kandydatów partiami, aż skończy się budżet. Test BP-01 | S–M | nie |
| BP-02 | P2 | „Zrób swoją wersję” gubi zamienniki składników od autora (D-284) | `app/Domain/Recipes/Actions/ZrobWlasnaWersje.php:94-105` (`RecipeIngredient::create` bez `substitutes`); `app/Domain/Recipes/MojaWersja.php:218-223` (odcisk różnicy bez `substitutes`) | test BP-02 | Kopia przepisu po cichu traci zdanie w rodzaju „margaryna albo olej kokosowy”. Ktoś robi własną wersję właśnie po to, żeby dopasować przepis, i traci dokładnie tę podpowiedź. To luka po równoległym scaleniu dwóch gałęzi z 26.09: `a4898cc79` (D-284) i `157ab82c6` (#23) | Dopisać `'substitutes' => $skladnik->substitutes` przy kopiowaniu. Do decyzji: czy zmiana samego zamiennika liczy się jako różnica w `MojaWersja::odcisk()`. Test BP-02 | S | nie |
| BP-03 | P3 | Pierwsza publikacja szkicu zapisuje w historii wersję 1 jako „Aktualizacja przepisu” | `app/Domain/Recipes/Actions/PublishRecipe.php:628-632` (warunek `$existing === null` zamiast „nigdy nie opublikowany”) | test BP-03 | Tak jest na najczęstszej drodze: kreator zapisuje szkic, potem człowiek klika „Opublikuj”. Tak samo po imporcie i przy „Mojej wersji”. Na publicznym ekranie historii (#2024) pierwsza wersja wygląda wtedy jak poprawka, a wcześniejszej wersji nie ma | Gdy przepis nie ma jeszcze żadnej wersji albo przed zapisem był szkicem, wołać `handle(..., 'Pierwsza publikacja')`. Test BP-03 | S | nie (#2024 zamknięte, tej drogi nie obejmowało) |
| BP-04 | P2 | Tablica w polu formularza albo w parametrze adresu daje HTTP 500: rejestracja, formularz nowego hasła, wejście linkiem, planer | `app/Http/Controllers/Auth/RegisterController.php:108`, `:127` (`(string) $request->input(...)` przed walidacją); `app/Http/Controllers/Auth/PasswordResetController.php:156`; `app/Http/Controllers/Auth/LoginLinkController.php:287`; `app/Http/Controllers/PlanerController.php:30`, `:136` (`PlanerTygodnia::poniedzialek(?string)` dostaje tablicę, więc `TypeError`), `:41` (`(string)` na tablicy) | test BP-04: 7 z 9 przypadków daje 500. W `storage/logs/laravel.log`: „Array to string conversion” i „must be of type ?string, array given … PlanerController.php on line 30/136” | Publiczne formularze rejestracji i logowania odpowiadają błędem 500 na zwykłe `email[]=`. Każde takie żądanie wywołuje alarm na Discordzie (`blad_webhook`) i zaśmieca monitoring. Osoba z zepsutym odnośnikiem do planera widzi stronę błędu zamiast bieżącego tygodnia | Czytać wejście przez `$request->string('…')` albo `is_string(...) ? ... : ''` (tak jak przy zamkniętym #1344). `PlanerTygodnia::poniedzialek(mixed)`. Test BP-04 z przypadkiem dla każdej trasy | S | nie (ten sam wzór co zamknięte #1344 dla Facebooka) |
| BP-05 | P3 | Tryb gotowania z synchronizacją: po podwójnym kliknięciu „Zrobione” pojawia się komunikat o zmianie „na innym urządzeniu”, a porcje z formularza znikają | `app/Http/Controllers/CookingModeController.php`, `zaznacz()`: `$rozbieznaRewizja` porównuje rewizję ze strony z rewizją, którą podbiło pierwsze kliknięcie na tym samym urządzeniu; `app/Domain/Recipes/Gotowanie/PostepGotowania.php`, `ustaw()`: rewizja rośnie także przy zapisie bez zmiany | test BP-05 | Osoba z jednym urządzeniem dostaje fałszywy komunikat o drugim. Przy rozbieżności pomijane jest `zapamietajPorcjeZFormularza()`, a z adresu znika `porcje` | Rozbieżność zgłaszać tylko wtedy, gdy stan po zapisie różni się od tego, o co prosiło żądanie. Jeśli krok już był w żądanym stanie, rozbieżności nie ma. `ustaw()` nie podbija rewizji, gdy nic się nie zmienia (jak `ustawSkladniki()`). Test BP-05 | S | nie (#2016 zamknięte) |

## Opisy i testy regresyjne

### BP-01 — tygodniowe podsumowanie: konta bez treści blokują kolejkę

`naDzis()` pobiera `budżet × 3` kont posortowanych według
`weekly_digest_sent_at NULLS FIRST`, a potem według daty założenia. Konto,
dla którego `TrescDigestu` jest pusta, jest pomijane bez żadnego znacznika.
Następnego dnia stoi więc znowu na początku kolejki. Gdy takich kont jest co
najmniej `3 × budżet`, codziennie wypełniają całe okno kandydatów i nikt
dalej w kolejce listu nie dostaje.

W teście wystarczą trzy starsze „ciche” konta i budżet 1: autor, którego
przepis ktoś ugotował, przez trzy kolejne dni nie dostaje listu. Ten sam test
z `--limit=2` (okno 6 kont) przechodzi, więc przyczyną jest właśnie okno.

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\PodsumowanieTygodnia;
use App\Models\CookedEvent;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DigestPusteKontaNieZaglodzajaKolejkiTest extends TestCase
{
    use RefreshDatabase;

    public function test_osoby_bez_tresci_nie_blokuja_na_zawsze_osob_z_trescia(): void
    {
        config()->set('kuking.digest.wlaczony', true);
        config()->set('kuking.digest.odstep_dni', 7);
        config()->set('kuking.digest.okno_dni', 7);
        config()->set('kuking.digest.odstep_sekund', 0);

        // Trzy starsze konta ze zgodą, którym w tygodniu nic się nie wydarzyło.
        foreach (['cisza_a', 'cisza_b', 'cisza_c'] as $i => $nazwa) {
            $this->user($nazwa)->forceFill(['created_at' => now()->subYear()->addMinutes($i)])->save();
        }

        // Młodsze konto z realnym powodem do listu.
        $autor = $this->user('autor_z_trescia');
        $kucharz = $this->user('kucharz', ['wants_weekly_digest' => false]);
        $przepis = Recipe::factory()->for($autor, 'author')->create();
        CookedEvent::factory()->for($kucharz, 'user')->for($przepis, 'recipe')->create(['cooked_at' => now()->subDay()]);

        Mail::fake();

        // Trzy kolejne dni z budżetem jednego listu — autor powinien go dostać.
        $start = now()->startOfDay();
        foreach ([0, 1, 2] as $dzien) {
            $this->travelTo($start->copy()->addDays($dzien)->setTime(8, 30));
            Artisan::call('kuking:wyslij-podsumowania', ['--limit' => 1]);
        }

        Mail::assertQueued(PodsumowanieTygodnia::class, fn (PodsumowanieTygodnia $l) => $l->hasTo($autor->email));
    }
}
```

### BP-02 — „Zrób swoją wersję” bez zamienników

Opis klasy (`ZrobWlasnaWersje.php:21-22`) obiecuje skopiować „to, z czego się
gotuje”. Lista pól kopiowanych przy składniku kończy się jednak na
`no_amount`, bo kolumna `substitutes` weszła tego samego dnia z innej gałęzi.

`MojaWersja::odcisk()` też nie zna tej kolumny. Po samej poprawce kopiowania
wersja, w której zmieniono tylko zamiennik, zostanie więc odrzucona jako „bez
zmian”. Trzeba to rozstrzygnąć razem z poprawką.

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MojaWersjaKopiujeZamiennikiTest extends TestCase
{
    use RefreshDatabase;

    public function test_moja_wersja_zachowuje_zamienniki_skladnikow_od_autora(): void
    {
        $basia = $this->user('basia');
        $oryginal = app(PublishRecipe::class)->handle(
            $basia,
            ['title' => 'Ciasto drożdżowe babci', 'visibility' => 'public'],
            [['text' => '100 g masła', 'substitutes' => 'margaryna albo olej kokosowy'], ['text' => '500 g mąki']],
            [['instruction' => 'Wymieszaj wszystko i odstaw w ciepłe miejsce na godzinę.']],
            publish: true,
        );
        $this->assertSame('margaryna albo olej kokosowy', $oryginal->ingredients()->orderBy('position')->first()->substitutes);

        $jan = $this->user('jan');
        $this->actingAs($jan)->post(route('recipes.fork', $oryginal->slug))->assertRedirect();

        $wersja = Recipe::query()->where('author_id', $jan->getKey())->where('forked_from_id', $oryginal->getKey())->sole();

        $this->assertSame(
            'margaryna albo olej kokosowy',
            $wersja->ingredients()->orderBy('position')->first()->substitutes,
            '„Zrób swoją wersję” zgubiła zamiennik składnika wpisany przez autora oryginału (D-284).',
        );
    }
}
```

### BP-03 — wersja 1 szkicu podpisana „Aktualizacja przepisu”

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\RecipeVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PierwszaPublikacjaSzkicuTest extends TestCase
{
    use RefreshDatabase;

    public function test_pierwsza_publikacja_szkicu_jest_w_historii_pierwsza_publikacja(): void
    {
        $basia = $this->user('basia');
        $publikuj = app(PublishRecipe::class);
        $skladniki = [['text' => '1 kura rosołowa'], ['text' => '2 marchewki']];
        $kroki = [['instruction' => 'Zalej kurę zimną wodą i gotuj na małym ogniu przez trzy godziny.']];

        // Najczęstsza droga: kreator zapisuje szkic, a potem człowiek klika „Opublikuj".
        $szkic = $publikuj->handle($basia, ['title' => 'Rosół babci Zofii', 'visibility' => 'public'], $skladniki, $kroki, publish: false);
        $this->assertSame(0, RecipeVersion::query()->where('recipe_id', $szkic->getKey())->count());

        $publikuj->handle($basia, ['title' => 'Rosół babci Zofii', 'visibility' => 'public'], $skladniki, $kroki, publish: true, existing: $szkic->fresh());

        $wersja = RecipeVersion::query()->where('recipe_id', $szkic->getKey())->sole();
        $this->assertSame(1, (int) $wersja->version_number);
        $this->assertSame('Pierwsza publikacja', $wersja->change_note,
            'Wersja 1 przepisu, który był szkicem, jest w historii podpisana jak poprawka opublikowanego przepisu.');
    }
}
```

### BP-04 — tablica w parametrze kończy się błędem 500

Globalne `TrimStrings` i walidacja nie chronią dwóch rodzajów miejsc:

- takich, które rzutują wejście na tekst **przed** walidacją — rejestracja
  normalizuje e-mail i nazwę konta przed `validate()`;
- takich, które wejścia w ogóle nie walidują — parametry nawigacji planera,
  `?email=` w formularzu nowego hasła i token wejścia linkiem.

Laravel zamienia ostrzeżenie „Array to string conversion” w wyjątek, więc to
jest 500 także na produkcji.

Dla porównania te adresy odpowiadają poprawnie (sprawdzone tym samym
wzorem testu):

- `/szukaj?q[]=` i `/przepisy/{slug}?porcje[]=`;
- tryb gotowania z `?krok[]=` i `?porcje[]=`;
- historia wersji z `?page[]=`;
- `POST /nie-pamietam-hasla` z `email[]`.

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TabliceWFormularzachNieDajaBledu500Test extends TestCase
{
    use RefreshDatabase;

    private function sprawdz(string $opis, int $status): void
    {
        $this->assertLessThan(500, $status, "{$opis} dał HTTP {$status}");
    }

    public function test_rejestracja_z_tablica_w_adresie_email(): void
    {
        $r = $this->post('/register', ['email' => ['a@b.pl'], 'username' => 'jan', 'display_name' => 'Jan', 'password' => 'bardzo-dlugie-haslo-123', 'password_confirmation' => 'bardzo-dlugie-haslo-123', 'terms_accepted' => '1']);
        $this->sprawdz('POST /register email[]', $r->getStatusCode());
    }

    public function test_rejestracja_z_tablica_w_nazwie_konta(): void
    {
        $r = $this->post('/register', ['email' => 'a@b.pl', 'username' => ['jan'], 'display_name' => 'Jan', 'password' => 'bardzo-dlugie-haslo-123', 'password_confirmation' => 'bardzo-dlugie-haslo-123', 'terms_accepted' => '1']);
        $this->sprawdz('POST /register username[]', $r->getStatusCode());
    }

    public function test_formularz_nowego_hasla_z_tablica_w_email(): void
    {
        $r = $this->get('/nowe-haslo/abc?email[]=a@b.pl');
        $this->sprawdz('GET /nowe-haslo/{token}?email[]', $r->getStatusCode());
    }

    public function test_przypomnienie_hasla_z_tablica_w_email(): void
    {
        $r = $this->post('/nie-pamietam-hasla', ['email' => ['a@b.pl']]);
        $this->sprawdz('POST /nie-pamietam-hasla email[]', $r->getStatusCode());
    }

    public function test_wejscie_linkiem_z_tablica_w_tokenie(): void
    {
        config()->set('kuking.login_link.enabled', true);
        $r = $this->post('/logowanie/link/wejdz', ['token' => ['x']]);
        $this->sprawdz('POST /logowanie/link/wejdz token[]', $r->getStatusCode());
    }

    public function test_zeszyt_z_tablica_w_szukaj(): void
    {
        $basia = $this->user('basia');
        $zeszyt = Collection::query()->where('owner_id', $basia->getKey())->first()
            ?? $basia->collections()->create(['name' => 'Obiady', 'visibility' => 'private']);
        $r = $this->actingAs($basia)->get('/zeszyt/'.$zeszyt->getKey().'?szukaj[]=ros');
        $this->sprawdz('GET /zeszyt/{id}?szukaj[]', $r->getStatusCode());
    }

    public function test_kopiowanie_tygodnia_planera_z_tablica(): void
    {
        $r = $this->actingAs($this->user('basia'))->post('/planer/kopiuj-tydzien', ['tydzien' => ['2026-09-28']]);
        $this->sprawdz('POST /planer/kopiuj-tydzien tydzien[]', $r->getStatusCode());
    }

    public function test_planer_z_tablica_w_tygodniu(): void
    {
        $r = $this->actingAs($this->user('basia'))->get('/planer?tydzien[]=2026-09-28');
        $this->sprawdz('GET /planer?tydzien[]', $r->getStatusCode());
    }

    public function test_planer_z_tablica_w_frazie(): void
    {
        $dzien = now('Europe/Warsaw')->toDateString();
        $r = $this->actingAs($this->user('basia'))->get('/planer?dzien='.$dzien.'&q[]=ros');
        $this->sprawdz('GET /planer?q[]', $r->getStatusCode());
    }
}
```

### BP-05 — podwójne kliknięcie w trybie gotowania

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\CookingProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PodwojneKlikniecieKrokuNieStraszyInnymUrzadzeniemTest extends TestCase
{
    use RefreshDatabase;

    public function test_drugie_klikniecie_tego_samego_kroku_nie_mowi_o_innym_urzadzeniu(): void
    {
        $basia = $this->user('basia');
        $przepis = app(PublishRecipe::class)->handle(
            $basia,
            ['title' => 'Rosół babci Zofii', 'visibility' => 'public'],
            [['text' => '1 kura rosołowa']],
            [['instruction' => 'Zalej kurę zimną wodą i gotuj na małym ogniu przez trzy godziny.'],
                ['instruction' => 'Dodaj warzywa i gotuj jeszcze godzinę, potem przecedź.']],
            publish: true,
        );
        $halina = $this->user('halina');

        $this->actingAs($halina)->post(route('cooking.show', $przepis->slug).'/synchronizacja')->assertRedirect();
        $postep = CookingProgress::query()->where('user_id', $halina->getKey())->sole();
        $rewizjaNaEkranie = $postep->revision;
        $krok = $przepis->steps()->orderBy('position')->first();

        $dane = ['krok_id' => $krok->getKey(), 'krok' => 1, 'zrobiono' => '1', 'rewizja' => $rewizjaNaEkranie];

        // Dwa wysłania tego samego formularza z tej samej strony (podwójne kliknięcie „Zrobione").
        $this->actingAs($halina)->post(route('cooking.show', $przepis->slug), $dane)->assertRedirect();
        $drugie = $this->actingAs($halina)->post(route('cooking.show', $przepis->slug), $dane)->assertRedirect();

        $komunikat = json_encode(session()->all(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('innym urządzeniu', (string) $komunikat,
            'Podwójne kliknięcie na jednym urządzeniu kończy się komunikatem o zmianie na innym urządzeniu.');
    }
}
```

## Sprawdzone i w porządku

Przeczytane (a tam, gdzie zaznaczono, także sprawdzone testem) i bez uwag:

- **„Ugotowałem”.** `RecordCookedEvent`: zamki kont w kolejności id, ponowny
  odczyt przepisu i Policy `cook` pod zamkiem, klucz wysłania z powrotem po
  23505. `NotifyUser`: trzy granice z AGENTS.md (własny przepis, zamknięte
  konto, blokada), zawieszony autor dostaje powiadomienie, bez przełączników.
  `UgotowalemZawszePowiadamiaAutoraTest` na bazie: 15/15. Refaktor #970
  (`CookedEventController` i `Requests/Cooked/*`): zdjęcia zapisują się przed
  walidacją pozostałych pól, a ponowienie jest rozpoznawane przed zapisem
  zdjęć.
- **Komentarze i odpowiedzi.** `PublishComment`: blokada doradcza na
  tożsamość wysłania, okno powtórzenia, spłaszczanie odpowiedzi do wątku,
  powiadomienie autora komentarza, na który ktoś odpowiada. `EditComment`:
  konflikt wersji i podwójny zapis. `DeleteComment`: znacznik zamiast
  kasowania wątku z odpowiedziami.
  - Odpowiedź na odpowiedź pod przepisem i pod wykonaniem: interfejs daje
    „Odpowiedz” tylko przy komentarzu głównym, więc relacja `comments()` bez
    odpowiedzi jest spójna.
  - Treść złożona z samych niewidocznych znaków (U+2800, U+3164, U+200D,
    U+2060) nie przechodzi w komentarzu, we wpisie bez zdjęcia ani w pozycji
    planera. Test z kontrolą dodatnią.
- **Obserwowanie i blokady.** `FollowUser`, `BlockUser` (`ZamekPary`,
  `ZerwijWspoldzielenie::miedzy()`), `UnblockUser`.
- **Wspólne zeszyty (D-302).**
  - `ZaprosDoZeszytu`: miejsca liczone pod zamkiem, zaproszenie po nazwie
    idempotentne.
  - `OdpowiedzNaZaproszenie`: kolejność zamków, podwójne „Dołączam”.
  - `DostepDoZeszytu` i `ZerwijWspoldzielenie`, w tym kolejność blokad przy
    wymazaniu konta.
  - Podpisy „Dodane przez” widzą tylko osoby z dostępem.
  - „Podziel się” (#2000) idzie przez Policy dla gościa.
  - `SaveRecipeToCollection` i `NotifyRecipeSaved`: partia powiadomień
    o zapisaniu i ich cofanie.
- **Historia wersji (#2024).** `HistoriaWersji` (bramka `view` plus
  opublikowanie), `SnapshotRecipeVersion::poprawka()` (numer pod blokadą,
  porównanie migawek), `PorownanieWersji` i `MigawkaWersji`. Ilości są częścią
  `ingredient_text`, więc porównanie je widzi.
- **Synchronizacja gotowania (#2016).** `PostepGotowania`: ustawianie
  idempotentne, blokada wiersza, `insertOrIgnore` przy włączaniu, wygasanie.
  `CookingProgressPolicy`. Jedyny wyjątek to BP-05.
- **Planer.** `DodajDoPlanu` i `SkopiujPoprzedniTydzien`: indeksy unikalne
  zgodne z porównaniem w PHP, zamek konta. Jedyny wyjątek to BP-04.
- **Strefa Europe/Warsaw i północ.** `Czas`, `Urodziny` (29 lutego),
  `RocznicaDolaczenia`, `Wspomnienia`, `Hide::domyslnyTermin()`.
  `PrzypomnienieDobowe` liczy dobę w UTC świadomie, zgodnie
  z `DziennyBudzetListow`. Tydzień podsumowania liczony raz na przebieg.
  `WyslijZyczeniaUrodzinowe`: rezerwacja dnia.
- **Moderacja i sankcje.** `ZdejmijWygasleZawieszenie` i
  `kuking:zdejmij-wygasle-kary`. Kara odłożona w `punishment_status` przy
  koncie w `pending_delete`: `markForDeletion`, `cancelDeletion`,
  `nalozKare`, `reinstate`.
- **Publikacja wpisu.** `PublishPost`: klucz wysłania, stan konta i zdjęcia
  pod zamkiem. `EditPost`: konflikt, podwójny zapis, ponowna analiza po
  zmianie treści.
- **Import.** `ZapiszSzkicZImportu`. Atomowe przejście do `w_toku`
  w `OdczytajPrzepis` (#2213).
- **Eksport i wymazanie.** `InwentarzDanychKonta` obejmuje nowe tabele
  (`cooking_progress`, `collection_members`, `collection_invitations`,
  `meal_plan_entries`). `EraseAccountData` woła
  `ZerwijWspoldzielenie::przyWymazaniu()`.
- **Rejestracja (#2217).** Dowód akceptacji regulaminu powstaje w transakcji
  zakładania konta. Oba wywołania `ZalozKonto::handle()` przekazują źródło.
- **Reakcje „Smakowicie”** i ich zbiorcze powiadomienie
  (`PowiadomOSmakowicie`): blokady, stan kont, `notified_at` pod zamkiem.

## Podejrzenia (niepotwierdzone testem, poza tabelą)

- `NotifyRecipeSaved::cofnijJesliNigdzieNieZostal()` patrzy tylko na
  **własne** zeszyty osoby. Scenariusz: ktoś zapisał przepis do cudzego
  wspólnego zeszytu i do swojego „Zapisane”, a potem wyjmuje go ze swojego.
  Powiadomienie „zapisano” zostaje wtedy cofnięte, choć przepis dalej leży
  we wspólnym zeszycie, do którego ta osoba go dodała. Skutek drobny, do
  potwierdzenia testem.
- `ZaprosDoZeszytu::linkiem()` nie jest idempotentne. Podwójne kliknięcie
  „Utwórz link” tworzy dwa oczekujące zaproszenia i każde zajmuje miejsce
  w limicie `max_members`. Drobne, bo zaproszenie da się odwołać.
- API v1 (`RecipeResource`) nie wydaje zamienników składników (D-284). API
  jest wyłączone flagą, więc to raczej uwaga do zakresu niż błąd.
