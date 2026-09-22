<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Domain\Recipes\Actions\SnapshotRecipeVersion;
use App\Domain\Tags\Actions\ResolveTagsForPost;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Post;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Models\TagPromotion;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Dane demonstracyjne do pracy lokalnej.
 *
 * WAŻNE: ten seeder służy WYŁĄCZNIE do rozwoju i testów ręcznych.
 * Nigdy nie uruchamiaj go na produkcji. Kuking nie zaczyna od wypełnienia
 * serwisu wymyślonymi treściami — pierwsze konta mają być prawdziwe
 * (docs/product/COLD_START.md, sekcja o anty-wzorcach).
 *
 * Persony pochodzą z docs/PRODUCT.md, żeby dane demo od razu obrazowały
 * realnych użytkowników, a nie „User 1”, „User 2”.
 */
class DemoSeeder extends Seeder
{
    /**
     * Hasło do kont demonstracyjnych.
     *
     * DO 20 WRZEŚNIA 2026 STAŁO TU WPISANE W KODZIE. Zmieniła to decyzja
     * właściciela, po tym jak strażnik `PoswiadczeniaPozaRepozytoriumTest`
     * (R47) pokazał, że repozytorium nosi hasło konta MODERATORA. Chroniły je
     * dwie bramki i jedna z nich jest zmierzona testem — ale bramka jest
     * zabezpieczeniem, a nieobecność sekretu jest brakiem tego, co można
     * wynieść. To drugie jest tańsze i nie wymaga niczyjej czujności.
     *
     * Bez `KUKING_DEMO_HASLO` losujemy hasło na każdy przebieg i wypisujemy
     * je na koniec. Celowo NIE ma tu wartości domyślnej: wartość domyślna
     * wróciłaby do repozytorium tym samym wejściem, którym właśnie wyszła,
     * tylko pod inną nazwą.
     */
    private ?string $haslo = null;

    private function hasloDemo(): string
    {
        if ($this->haslo !== null) {
            return $this->haslo;
        }

        $zOtoczenia = trim((string) config('kuking.demo.haslo', ''));

        return $this->haslo = $zOtoczenia !== ''
            ? $zOtoczenia
            : Str::password(16, symbols: false);
    }

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('DemoSeeder nie może działać na produkcji.');

            return;
        }

        $basia = $this->createUser('basia@example.test', 'basia', 'Basia', [
            'bio' => 'Gotuję codziennie od czterdziestu lat. Najlepiej wychodzą mi zupy i ciasto drożdżowe.',
            'region' => 'Podkarpacie',
            'speciality' => 'zupy i ciasta',
        ]);

        $marek = $this->createUser('marek@example.test', 'marek', 'Marek', [
            'bio' => 'Chleb na zakwasie, kiszonki, wędzenie. Lubię wiedzieć, dlaczego coś działa.',
            'region' => 'Wielkopolska',
            'speciality' => 'chleb i kiszonki',
        ]);

        $ania = $this->createUser('ania@example.test', 'ania', 'Ania', [
            'bio' => 'Dwoje dzieci i praca na etacie. Szukam przepisów, które da się zrobić po pracy.',
            'region' => 'Warszawa',
            'speciality' => 'szybkie obiady',
        ]);

        /*
         * KONTO Z NAZWĄ NA PEŁNY LIMIT — NAJTRUDNIEJSZY WARIANT PROFILU
         * (issue #440, potem #467)
         *
         * `display_name` ma w walidacji `max:` z `config('kuking.profil.dlugosc_nazwy')`
         * (RegisterController, ProfileSettingsController, oba loginy
         * zewnętrzne) i ani jednego ograniczenia na długość pojedynczego
         * SŁOWA. Nazwa niżej ma dokładnie tyle znaków, ile wynosi limit,
         * a najdłuższy nieprzerwany ciąg w niej — 29. Jest więc poprawnym
         * wejściem, jakie człowiek może wpisać dziś, bez żadnej sztuczki.
         *
         * DO 12 WRZEŚNIA LIMIT WYNOSIŁ 100 ZNAKÓW i ta nazwa też tyle miała.
         * Zmierzone wtedy: sam odnośnik nazwy w karcie wpisu brał 825,16 px
         * przy oknie 320 px i czcionce przeglądarki 200%, czyli więcej niż
         * okno, w którym mierzy automat dostępności (740 px). Liczba w tym
         * seederze ma iść ZA limitem, nie obok niego — inaczej automat
         * przestanie widzieć najtrudniejszy wariant, który produkt dopuszcza.
         *
         * DO 12 WRZEŚNIA TAKIEGO KONTA W DANYCH DEMO NIE BYŁO. Najdłuższa
         * nazwa profilu miała 16 znaków („Moderacja Kuking"), a dwie
         * mierzone przez automat — po cztery i pięć („Ania", „Basia").
         * `scripts/dostepnosc.mjs` meldował więc „nic nie wyjeżdża w bok na
         * profilu" o ekranie, którego najtrudniejszego wariantu nie widział
         * ani razu. To jest pułapka 5 z `docs/PULAPKI_TESTOW.md` widziana od
         * strony DANYCH: narzędzie melduje sukces, bo nie dostało tego,
         * o co chodzi — i wygląda przy tym dokładnie tak, jak gdyby dostało.
         *
         * Zofia ma własny wpis ze zdjęciem: profil bez ani jednej treści to
         * pusty ekran, a pusty ekran przechodzi każdy pomiar układu.
         */
        $zofia = $this->createUser(
            'zofia@example.test',
            'zofia_z_bieszczad',
            'Małgorzata Konstantynopolitańczykowianka',
            [
                'bio' => 'Gotuję dla wnuków, kiedy przyjeżdżają na wakacje. Najchętniej pierogi i kompot z rabarbaru.',
                'region' => 'Dolny Śląsk',
                'speciality' => 'pierogi i kompoty',
            ],
        );

        $moderator = $this->createUser('moderacja@example.test', 'moderacja', 'Moderacja Kuking', [
            'bio' => 'Konto zespołu Kuking.',
        ], role: User::ROLE_MODERATOR);

        /*
         * ZDJĘCIE PROFILOWE DLA KONTA, KTÓRYM LOGUJE SIĘ AUTOMAT DOSTĘPNOŚCI
         * (`ania` — patrz `KONTO_ZALOGOWANE` w `scripts/dostepnosc.mjs`).
         *
         * PO CO TO JEST. Ekran `/ustawienia/zdjecie` ma TRZY stany: „to jest
         * Twoje zdjęcie", „zdjęcie się przygotowuje" i „nie masz jeszcze
         * zdjęcia". Różnią się nie jednym zdaniem, tylko układem: przy
         * gotowym zdjęciu dochodzi obrazek 88 px obok akapitu, zmienia się
         * napis na polu pliku i pojawia się CAŁA sekcja „Usunięcie zdjęcia"
         * z przyciskiem potwierdzenia. Do 12 września dane demo nie dawały
         * temu kontu żadnego zdjęcia, więc automat mierzyłby jedyny z tych
         * trzech stanów, w którym tego wszystkiego NIE MA — i wpisałby „✓"
         * dla ekranu, którego trudnej połowy nie widział ani razu
         * (pułapki 2 i 4 z `docs/PULAPKI_TESTOW.md`).
         *
         * PRAWDZIWY PLIK, NIE SAM WIERSZ W BAZIE. Zdjęcia demonstracyjne
         * wpisów mają `metadata.variants` puste, bo do ich układu to
         * wystarcza. Tutaj nie wystarcza: bez wariantu `Media::url()` oddaje
         * `icons/kuking-mark.svg`, czyli znak Kuking o `viewBox 0 0 64 64`.
         * Mierzyłoby się wtedy kwadrat 64 × 64 podstawiony za zdjęcie —
         * dane, które wyglądają na prawdziwe i nie są.
         */
        $this->nadajZdjecieProfilowe($ania);

        // Graf społeczny
        $ania->following()->syncWithoutDetaching([$basia->getKey() => ['created_at' => now()], $marek->getKey() => ['created_at' => now()]]);
        $marek->following()->syncWithoutDetaching([$basia->getKey() => ['created_at' => now()]]);
        $basia->following()->syncWithoutDetaching([$marek->getKey() => ['created_at' => now()]]);

        // Wpisy
        $rosolWpis = Post::create([
            'author_id' => $basia->getKey(),
            'body' => 'Rosół na niedzielę. Gotował się cztery godziny, jak trzeba.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subDays(3),
        ]);

        $chlebWpis = Post::create([
            'author_id' => $marek->getKey(),
            'body' => 'Chleb z zakwasu, który hoduję od 2019 roku. Dziś wyszedł najlepszy do tej pory.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subDays(1),
        ]);

        $nalesnikiWpis = Post::create([
            'author_id' => $ania->getKey(),
            'body' => 'Naleśniki po pracy. Dzieci zjadły wszystko, więc chyba się udało.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subHours(5),
        ]);

        $this->otagujWpisy($rosolWpis, $chlebWpis, $nalesnikiWpis, $basia, $ania);

        // Wpisy z KILKOMA zdjęciami — po jednym na każdy tryb wyświetlania
        // (issue #92). Bez nich automat układu (scripts/dostepnosc.mjs) nie ma
        // czego zmierzyć: karuzela i kolaż istnieją wyłącznie wtedy, gdy wpis
        // ma co najmniej dwa zdjęcia, a pusty ekran przechodzi każdy pomiar,
        // nie sprawdzając niczego.
        /*
         * KARUZELA NALEŻY DO `ania`, NIE DO `basia` — i to nie jest szczegół.
         *
         * `scripts/dostepnosc.mjs` loguje się jako `ania` (bo `basia` bywa
         * personą treści zalążkowej z losowym hasłem, D-025) i mierzy ekran
         * kolejności zdjęć `/wpisy/{wpis}/zdjecia`. Ten ekran widzi WYŁĄCZNIE
         * autor wpisu (`PostPolicy::update`). Gdy karuzela była wpisem basi,
         * serwis odpowiadał `ania` kodem 403, a automat wpisywał „✓" — mierzył
         * stronę błędu, która przechodzi każdy audyt dostępności, nie
         * sprawdzając niczego. Zmierzone 7 września.
         */
        $this->wpisZKilkomaZdjeciami(
            $ania,
            'Rosół krok po kroku: warzywa, szumowiny, gotowy talerz.',
            Post::DISPLAY_CAROUSEL,
            ['Warzywa do rosołu na desce', 'Garnek z rosołem w trakcie gotowania', 'Talerz gotowego rosołu z makaronem'],
            now()->subHours(9),
        );

        $this->wpisZKilkomaZdjeciami(
            $basia,
            'Ciasto drożdżowe — cztery ujęcia, bo za pierwszym razem nie widać, jak wyrosło.',
            Post::DISPLAY_COLLAGE,
            ['Ciasto drożdżowe przed wyrastaniem', 'Ciasto po wyrośnięciu', 'Ciasto w blaszce', 'Upieczone ciasto na kratce'],
            now()->subHours(7),
        );

        $this->wpisZKilkomaZdjeciami(
            $basia,
            'Pierogi z niedzieli, po prostu jedno pod drugim.',
            Post::DISPLAY_NORMAL,
            ['Lepione pierogi na stolnicy', 'Pierogi na talerzu ze skwarkami'],
            now()->subHours(6),
        );

        /*
         * TRZECI AUTOR ZE ZDJĘCIEM — INACZEJ KOLAŻ W HERO NIE MA CZEGO POKAZAĆ.
         *
         * Kolaż na stronie powitalnej (`App\Domain\Feed\HeroKolaz`) bierze
         * najpierw po JEDNYM zdjęciu od osoby i dopiero potem po drugim, a
         * renderuje się wyłącznie wtedy, gdy uzbiera cztery. Do 11 września
         * zdjęcia w danych demo miały tylko DWIE osoby (`ania` i `basia`), więc
         * `scripts/dostepnosc.mjs` mierzyłby stronę powitalną ZAWSZE w stanie
         * „kolażu nie ma" — a ten stan odpowiada 200 i w raporcie wygląda
         * identycznie jak stan pełny. Dokładnie pułapka 5 z
         * `docs/PULAPKI_TESTOW.md`, ta sama, która przy issue #294 dała trzy
         * ptaszki nad pustą kolejką moderatora.
         *
         * `marek` ma już wpis o chlebie bez zdjęć — to jest ten sam chleb,
         * tylko pokazany.
         */
        $this->wpisZKilkomaZdjeciami(
            $marek,
            'Zakwas i bochenek — ten sam chleb, dwa zdjęcia.',
            Post::DISPLAY_NORMAL,
            ['Słoik zakwasu na parapecie', 'Bochenek chleba przekrojony na desce'],
            now()->subHours(4),
        );

        /*
         * Wpis Zofii — żeby jej profil miał co pokazać. Bez ani jednej treści
         * profil jest pustym ekranem, a pusty ekran przechodzi każdy pomiar
         * układu, nie sprawdzając niczego.
         */
        $this->wpisZKilkomaZdjeciami(
            $zofia,
            'Pierogi ruskie na niedzielę, tak jak robiła je moja mama.',
            Post::DISPLAY_NORMAL,
            ['Pierogi ruskie na talerzu', 'Farsz z ziemniaków i twarogu w misce'],
            now()->subHours(3),
        );

        // Przepis rodzinny — pokazuje, po co jest sekcja „Skąd ten przepis”
        $rosol = Recipe::create([
            'author_id' => $basia->getKey(),
            'title' => 'Rosół babci Zofii',
            'slug' => 'rosol-babci-zofii',
            'summary' => 'Rosół, który u nas w domu gotuje się na każdą niedzielę i na każde święta.',
            'servings' => 6,
            'prep_minutes' => 20,
            'cook_minutes' => 240,
            'difficulty' => 'easy',
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
            'source_type' => Recipe::SOURCE_FAMILY,
            'source_person' => 'od babci Zofii',
            'source_note' => 'Babcia mieszkała pod Rzeszowem i gotowała ten rosół w sobotę wieczorem, żeby w niedzielę tylko podgrzać. Mówiła, że rosół nie znosi pośpiechu i że nigdy nie wolno go zagotować na dużym ogniu, bo zrobi się mętny. Kartka z tym przepisem leżała w jej kredensie przez trzydzieści lat.',
            'family_since_year' => 1974,
            'published_at' => now()->subDays(10),
        ]);

        $this->addIngredients($rosol, [
            '1 kurczak zagrodowy, najlepiej starsza kura',
            '2 duże marchewki',
            '1 pietruszka, korzeń',
            'kawałek selera, wielkości pięści',
            '1 por, sama biała część',
            '1 cebula, opalona nad palnikiem',
            '4 ziarna ziela angielskiego',
            '2 liście laurowe',
            'sól — do smaku, na końcu',
            'natka pietruszki do podania',
        ]);

        $this->addSteps($rosol, [
            'Kurczaka opłucz, włóż do dużego garnka i zalej zimną wodą tak, żeby był przykryty na dwa palce.',
            'Zagotuj na średnim ogniu, a gdy zacznie wrzeć, zbierz łyżką szumowiny. To decyduje o tym, czy rosół będzie klarowny.',
            'Zmniejsz ogień do najmniejszego. Od tej chwili rosół ma tylko „mrugać”, nigdy nie wrzeć.',
            'Dodaj warzywa, ziele angielskie i liście laurowe. Cebulę wcześniej opal nad palnikiem — od niej rosół ma kolor.',
            'Gotuj bez przykrycia przez cztery godziny. Nie mieszaj, nie dolewaj wody.',
            'Posól dopiero na końcu. Odcedź, podawaj z makaronem i natką.',
        ]);

        app(SnapshotRecipeVersion::class)->handle($rosol, $basia, 'Pierwsza publikacja');

        $chleb = Recipe::create([
            'author_id' => $marek->getKey(),
            'title' => 'Chleb pszenno-żytni na zakwasie',
            'slug' => 'chleb-pszenno-zytni-na-zakwasie',
            'summary' => 'Prosty chleb na co dzień. Wymaga czasu, ale nie wymaga wprawy.',
            'servings' => 1,
            'prep_minutes' => 30,
            'cook_minutes' => 50,
            'difficulty' => 'medium',
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
            'source_type' => Recipe::SOURCE_OWN,
            'source_note' => 'Doszedłem do tych proporcji po jakichś dwóch latach prób. Wcześniejsze wersje były za kwaśne.',
            'published_at' => now()->subDays(6),
        ]);

        $this->addIngredients($chleb, [
            '150 g aktywnego zakwasu żytniego',
            '350 g mąki pszennej chlebowej typ 750',
            '150 g mąki żytniej typ 720',
            '350 ml letniej wody',
            '10 g soli',
        ]);

        $this->addSteps($chleb, [
            'Wymieszaj zakwas z wodą, dodaj obie mąki i wyrób ciasto. Odstaw na 30 minut.',
            'Dodaj sól i wyrabiaj jeszcze pięć minut. Ciasto ma być klejące — tak ma być.',
            'Fermentuj 3-4 godziny w temperaturze pokojowej, składając ciasto co godzinę.',
            'Uformuj bochenek, przełóż do koszyka i wstaw na noc do lodówki.',
            'Piecz 20 minut w 240 stopniach pod przykryciem, potem 30 minut w 210 stopniach bez przykrycia.',
        ]);

        app(SnapshotRecipeVersion::class)->handle($chleb, $marek, 'Pierwsza publikacja');

        /*
         * „Ugotowałem” — najważniejsze zdarzenie w produkcie, i dlatego idzie
         * TĄ SAMĄ AKCJĄ DOMENOWĄ co formularz, a nie gołym
         * `CookedEvent::create()`.
         *
         * ZMIERZONE PRZED ZMIANĄ (12 września 2026): seeder zapisywał dwa
         * wykonania, a `NotifyUser` wołał przy JEDNYM z nich. Wykonanie Marka
         * nie powiadamiało Basi w ogóle. Obietnica z `AGENTS.md` §1
         * („«Ugotowałem» ZAWSZE powiadamia autora przepisu”) była więc
         * w danych demonstracyjnych prawdziwa w połowie przypadków — a to są
         * dane, na których chodzi automat dostępności i na których wygląd
         * serwisu ogląda każdy, kto pracuje lokalnie.
         *
         * Powtórzone obok zapisu powiadomienie jest jedną linijką do
         * zapomnienia i dokładnie tak zostało zapomniane. `RecordCookedEvent`
         * trzyma zapis i powiadomienie w JEDNEJ transakcji, więc rozdzielić
         * ich przez przeoczenie się nie da — a reguła domenowa zostaje
         * w `app/Domain` (`AGENTS.md` §4) zamiast mieć tutaj drugą,
         * uproszczoną kopię.
         */
        $ugotowalem = app(RecordCookedEvent::class);

        $this->przesunWykonanie($ugotowalem->handle(
            cook: $ania,
            recipe: $rosol,
            note: 'Zrobiłam w sobotę, żeby w niedzielę tylko podgrzać. Rzeczywiście wyszedł klarowny.',
            wouldMakeAgain: true,
            perceivedDifficulty: 'easy',
            actualMinutes: 260,
            changesNote: 'Dałam pół selera zamiast całego, bo dzieci nie lubią.',
        ), now()->subDays(2));

        $this->przesunWykonanie($ugotowalem->handle(
            cook: $marek,
            recipe: $rosol,
            note: 'Klasyka. Zrobiłem dokładnie tak, jak napisane, i nie ma o czym dyskutować.',
            wouldMakeAgain: true,
            perceivedDifficulty: 'easy',
            actualMinutes: 250,
        ), now()->subDay());

        Comment::create([
            'author_id' => $ania->getKey(),
            'recipe_id' => $rosol->getKey(),
            'body' => 'Pani Basiu, a można dać kaczkę zamiast kury?',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        $this->komunikatKoncowy($moderator->email);
    }

    /**
     * Cofa datę wykonania, żeby dane demonstracyjne wyglądały na rozłożone
     * w czasie, a nie zapisane wszystkie w jednej sekundzie.
     *
     * To JEDYNE, czego `RecordCookedEvent` nie przyjmuje: akcja stempluje
     * `cooked_at` chwilą zapisu, i słusznie — w produkcie nie ma miejsca,
     * w którym człowiek podaje datę ugotowania z ręki. Dlatego data wraca tu
     * osobnym zapisem PO wykonaniu akcji, zamiast otwierać w akcji parametr
     * potrzebny wyłącznie seederowi.
     */
    private function przesunWykonanie(CookedEvent $wykonanie, \DateTimeInterface $kiedy): CookedEvent
    {
        $wykonanie->forceFill(['cooked_at' => $kiedy])->save();

        return $wykonanie;
    }

    /** @param  array<string, mixed>  $profile */
    /**
     * TAGI W DANYCH DEMONSTRACYJNYCH (D-021).
     *
     * PO CO TO JEST, skoro `TagSeeder` istnieje osobno: `scripts/dostepnosc.mjs`
     * uruchamia `migrate:fresh --seed --seeder=DemoSeeder`, czyli WYŁĄCZNIE ten
     * seeder — `TagSeeder` wołany z `DatabaseSeeder` wtedy nie idzie. Bez tego
     * kroku publiczna strona tagu dawałaby 404, „Twoje tagi" byłyby puste,
     * a sekcja tagów w formularzu wpisu nigdy nie pokazałaby ani jednej
     * podpowiedzi. Pusty ekran przechodzi każdy pomiar dostępności i układu,
     * więc automat mierzyłby wtedy nic — dokładnie ta sama pułapka, którą
     * komentarz przy wpisach z kilkoma zdjęciami opisuje dla karuzeli i kolażu.
     *
     * NIE WOŁAMY TU `TagSeeder` przez `$this->call()`: on wgrywa pełny słownik
     * (~1200 tagów), co przy każdym przebiegu automatu byłoby kilkusekundowym
     * kosztem za coś, czego pomiar nie potrzebuje. Trzy tagi wystarczą, żeby
     * każdy ekran miał realną treść, a `TagSeeder` zostaje jedynym źródłem
     * słownika produkcyjnego.
     */
    private function otagujWpisy(Post $rosol, Post $chleb, Post $nalesniki, User $basia, User $ania): void
    {
        // `ResolveTagsForPost`, NIE `Tag::create()`. Trzy powody, każdy
        // zmierzony:
        //   1. `db:seed` (bez `--seeder`) woła najpierw `TagSeeder`, więc
        //      „chleb na zakwasie" i „zupy" JUŻ SĄ w bazie — `Tag::create()`
        //      leciało wtedy na `UNIQUE(normalized_name)` i cały `db:seed`
        //      kończył się wyjątkiem (zmierzone na czystej bazie);
        //   2. „zupy" jest w słowniku ALIASEM tagu „zupa" — ta akcja
        //      rozwiązuje alias do tagu kanonicznego, zamiast tworzyć drugi
        //      tag na to samo pojęcie;
        //   3. to jest ta sama bramka, przez którą idzie prawdziwy formularz
        //      wpisu, więc treść demo nie może być „bardziej poprawna" niż
        //      to, co da się wpisać ręcznie.
        $rozwiazane = app(ResolveTagsForPost::class)->handle(['zupy', 'chleb na zakwasie', 'szybkie obiady']);

        $tagi = [];
        foreach ($rozwiazane as $tag) {
            $tagi[$tag->normalized_name] = $tag;
        }

        // Klucze po nazwie KANONICZNEJ, nie po tym, co wpisaliśmy wyżej —
        // „zupy" rozwiązuje się do „zupa", gdy słownik jest w bazie, i do
        // „zupy", gdy go nie ma (`--seeder=DemoSeeder`). Oba przypadki muszą
        // działać, bo automat dostępności uruchamia właśnie ten drugi.
        $zupa = $tagi['zupa'] ?? $tagi['zupy'];
        $chlebNaZakwasie = $tagi['chleb na zakwasie'];
        $szybkieObiady = $tagi['szybkie obiady'];

        $rosol->tags()->syncWithoutDetaching([$zupa->getKey() => ['position' => 0]]);
        $chleb->tags()->syncWithoutDetaching([$chlebNaZakwasie->getKey() => ['position' => 0]]);
        $nalesniki->tags()->syncWithoutDetaching([$szybkieObiady->getKey() => ['position' => 0]]);

        // Jeden tag promowany — to jest lista, którą czyta onboarding i szyna
        // na stronie głównej, więc bez niej te dwa ekrany też mierzyłyby pustkę.
        TagPromotion::query()->firstOrCreate(['tag_id' => $zupa->getKey()], [
            'position' => 1,
            'note' => 'Zupa na listopad — najłatwiejszy tydzień w całym kalendarzu.',
        ]);

        // Ktoś MUSI obserwować tag, żeby ekran „Twoje tagi" miał co pokazać
        // poza stanem pustym, i żeby `TagFeed` nie był pusty dla tego konta.
        $ania->followedTags()->syncWithoutDetaching([
            $zupa->getKey() => ['created_at' => now()],
        ]);
        $basia->followedTags()->syncWithoutDetaching([
            $chlebNaZakwasie->getKey() => ['created_at' => now()],
        ]);
    }

    /**
     * @var list<string> adresy, pod którymi DA SIĘ zalogować hasłem demo —
     *                   sprawdzone, nie założone (patrz `komunikatKoncowy()`)
     */
    private array $logowalne = [];

    /** @var list<string> wszystkie adresy, o które ten seeder prosił */
    private array $probowane = [];

    private function createUser(string $email, string $username, string $displayName, array $profile = [], string $role = User::ROLE_USER): User
    {
        // `firstOrCreate()` NIE NADAJE SIĘ TU OD ISSUE #195: adres e-mail
        // wypadł z `User::$fillable` (ten sam powód co `status` i `role`),
        // więc masowe przypisanie po prostu by go pominęło — a kolumna jest
        // NOT NULL. Przy okazji widać, że `status`, `role`
        // i `email_verified_at` NIGDY tędy nie przechodziły: były poza
        // `$fillable` od początku, a seeder ustawiał je bezskutecznie
        // (konto „moderatora demo" nie było moderatorem). Zapisujemy więc
        // wprost, przez `assignEmail()` i `forceFill()`.
        $user = User::query()->where('email', User::normalizeEmail($email))->first();

        if ($user === null) {
            $user = (new User([
                'locale' => 'pl',
                'text_scale' => 100,
                'age_confirmed_at' => now(),
            ]))
                ->assignEmail($email, potwierdzony: true)
                // `password` jest poza `$fillable` tak samo jak `email`
                // — obie wartości wchodzą jawną, nazwaną metodą.
                ->assignPassword($this->hasloDemo());

            $user->forceFill([
                'status' => User::STATUS_ACTIVE,
                'role' => $role,
            ])->save();
        }

        Profile::firstOrCreate(
            ['user_id' => $user->getKey()],
            array_merge(['username' => $username, 'display_name' => $displayName], $profile),
        );

        /*
         * KOLIZJA Z PERSONAMI TREŚCI ZALĄŻKOWEJ — sprawdzana, nie zakładana.
         *
         * `TrescZalazkowaSeeder` tworzy dwanaście person pod adresami
         * `{nazwa}@example.test` i nadaje im hasło LOSOWE, bo D-025 mówi
         * wprost, że te konta nie mają być logowalne przez nikogo — to
         * persony, nie ludzie. Jedno imię, `basia`, jest jednocześnie na
         * liście person i na liście kont demonstracyjnych.
         *
         * `firstOrCreate()` znajduje wtedy personę i NIE ustawia jej hasła
         * demo (bo nie tworzy wiersza) — a poprzednia wersja tej klasy i tak
         * wypisywała „Zaloguj się jako basia@example.test". To zdanie było
         * nieprawdą przy każdym uruchomieniu `db:seed`, w którym oba seedery
         * szły po kolei, i przez to `scripts/dostepnosc.mjs` nie logował się
         * wcale: automat dostępności mierzył ekrany gościa, będąc pewnym, że
         * mierzy ekrany zalogowanej osoby.
         *
         * Nie „naprawiamy" tego, nadpisując hasło persony — to złamałoby
         * D-025 w drugą stronę. Zapamiętujemy, komu hasło demo NAPRAWDĘ
         * działa, i tylko o takich kontach mówimy człowiekowi.
         */
        $this->probowane[] = $email;

        if (Hash::check($this->hasloDemo(), (string) $user->password)) {
            $this->logowalne[] = $email;
        }

        return $user->refresh();
    }

    /**
     * Gotowe zdjęcie profilowe z PRAWDZIWEGO pliku (issue #26, #80).
     *
     * Plik źródłowy leży w repozytorium (`database/seeders/zdjecia`), ma
     * 320 × 320 px — dokładnie tyle, ile `config('kuking.media.variants.thumb')`
     * — i jest kopiowany pod klucz wariantu `thumb`, czyli pod ten sam, który
     * wylicza `Media::kluczPublicznegoWariantu()`. Dzięki temu
     * `MediaController` naprawdę ma co oddać na dysku lokalnym, a wymiary
     * w bazie są wymiarami pliku, nie liczbami wpisanymi z ręki.
     *
     * ORYGINAŁU NIE PODKŁADAMY I TO JEST CELOWE. Na stronę trafia wyłącznie
     * to, co wyszło z naszego kodera (`Media::maWariantDoPokazania()`),
     * a `url()` oryginału nie zna. Plik pod `object_key` byłby więc bajtami,
     * których nikt nigdy nie serwuje.
     */
    private function nadajZdjecieProfilowe(User $user): void
    {
        $zrodlo = database_path('seeders/zdjecia/awatar-demo.webp');

        // Bez pliku nie ma czego nadawać, a cicha zgoda na brak zdjęcia jest
        // tu najgorszą z opcji: automat mierzyłby wtedy stan „nie masz
        // jeszcze zdjęcia", nie wiedząc o tym.
        if (! is_file($zrodlo)) {
            $this->command?->warn('Brak pliku '.$zrodlo.' — konto '.$user->email.' zostaje bez zdjęcia profilowego.');

            return;
        }

        $wymiary = getimagesize($zrodlo);

        if ($wymiary === false) {
            $this->command?->warn('Plik '.$zrodlo.' nie jest obrazem — konto '.$user->email.' zostaje bez zdjęcia profilowego.');

            return;
        }

        [$szerokosc, $wysokosc] = $wymiary;

        $objectKey = 'media/demo/'.Str::uuid()->toString().'.webp';
        $kluczThumb = Media::kluczPublicznegoWariantu($objectKey, 'thumb');

        Storage::disk('public')->put($kluczThumb, (string) file_get_contents($zrodlo));

        $zdjecie = Media::create([
            'owner_id' => $user->getKey(),
            'disk' => 'public',
            'object_key' => $objectKey,
            'mime_type' => 'image/webp',
            'bytes' => (int) filesize($zrodlo),
            'width' => $szerokosc,
            'height' => $wysokosc,
            'status' => Media::STATUS_READY,
            // `alt` awatara jest pusty z założenia (`components/avatar.blade.php`:
            // imię stoi obok, więc czytnik ekranu nie ma powtarzać go drugi raz).
            // Tekst zostaje mimo to, bo opisuje plik, a nie miejsce jego użycia.
            'alt_text' => 'Zdjęcie profilowe konta demonstracyjnego',
            'metadata' => ['variants' => [
                'thumb' => ['key' => $kluczThumb, 'width' => $szerokosc, 'height' => $wysokosc],
            ]],
        ]);

        $user->profile->update(['avatar_media_id' => $zdjecie->getKey()]);
    }

    /**
     * Wpis z kilkoma zdjęciami i wybranym trybem wyświetlania (issue #92).
     *
     * Zdjęcia demonstracyjne nie mają wygenerowanych wariantów — `Media::url()`
     * podstawia wtedy znak Kuking. Do pomiaru układu to wystarcza: przeglądarka
     * układa `<img>` po `width`/`height` i CSS-ie, a nie po tym, co jest
     * w pliku. Do pracy nad wyglądem trzeba wgrać prawdziwe zdjęcia — dlatego
     * teksty alternatywne są tu prawdziwe, żeby przynajmniej czytnik ekranu
     * dostał to, co dostanie u człowieka.
     *
     * @param  list<string>  $opisy  teksty alternatywne, po jednym na zdjęcie
     */
    private function wpisZKilkomaZdjeciami(
        User $autor,
        string $tresc,
        string $tryb,
        array $opisy,
        Carbon $kiedy,
    ): void {
        $wpis = Post::create([
            'author_id' => $autor->getKey(),
            'body' => $tresc,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'display_mode' => $tryb,
            'published_at' => $kiedy,
        ]);

        foreach ($opisy as $pozycja => $opis) {
            $zdjecie = Media::create([
                'owner_id' => $autor->getKey(),
                'disk' => 'public',
                'object_key' => 'media/demo/'.Str::uuid()->toString().'.webp',
                'mime_type' => 'image/webp',
                'bytes' => 180_000,
                'width' => 1600,
                'height' => 1200,
                'status' => Media::STATUS_READY,
                'alt_text' => $opis,
                'metadata' => ['variants' => []],
            ]);

            $wpis->media()->attach($zdjecie->getKey(), ['position' => $pozycja]);
        }
    }

    /** @param  list<string>  $lines */
    private function addIngredients(Recipe $recipe, array $lines): void
    {
        foreach ($lines as $position => $text) {
            RecipeIngredient::create([
                'recipe_id' => $recipe->getKey(),
                'ingredient_text' => $text,
                'position' => $position,
            ]);
        }
    }

    /** @param  list<string>  $lines */
    private function addSteps(Recipe $recipe, array $lines): void
    {
        foreach ($lines as $position => $instruction) {
            RecipeStep::create([
                'recipe_id' => $recipe->getKey(),
                'position' => $position,
                'instruction' => $instruction,
            ]);
        }
    }

    /**
     * Komunikat końcowy wypisuje TYLKO konta, pod którymi hasło demo
     * naprawdę działa — sprawdzone `Hash::check()` w `createUser()`.
     *
     * Wcześniej ta metoda podawała dwa adresy na sztywno i jeden z nich
     * (`basia@example.test`) nie działał, gdy `TrescZalazkowaSeeder` zdążył
     * utworzyć personę o tym imieniu. Człowiek dostawał gotowe dane do
     * logowania, wpisywał je i widział „nieprawidłowe hasło" — i nie miał
     * skąd wiedzieć, że wina nie jest jego.
     */
    private function komunikatKoncowy(string $emailModeratora): void
    {
        $logowalne = array_values(array_unique($this->logowalne));

        if ($logowalne === []) {
            // Seeder, który nie zostawia ŻADNEGO konta do zalogowania, jest
            // bezużyteczny do pracy nad wyglądem i do automatu dostępności.
            // Cisza w tym miejscu byłaby gorsza niż ostrzeżenie.
            $this->command?->warn('Dane demo gotowe, ale ŻADNE konto nie przyjmuje hasła demo. Wszystkie adresy demo były już zajęte przez persony treści zalążkowej (D-025).');

            return;
        }

        $this->command?->info('Dane demo gotowe. Hasło do wszystkich kont niżej: '.$this->hasloDemo());

        foreach ($logowalne as $email) {
            $rola = $email === $emailModeratora ? ' (moderator)' : '';
            $this->command?->line('  '.$email.$rola);
        }

        $zajete = array_values(array_diff($this->probowane, $logowalne));

        if ($zajete !== []) {
            $this->command?->line('');
            $this->command?->warn('Te adresy demo należą do person treści zalążkowej i NIE przyjmują hasła demo (D-025 — persony nie są logowalne):');

            foreach ($zajete as $email) {
                $this->command?->line('  '.$email);
            }
        }
    }
}
