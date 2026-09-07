<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Notifications\Actions\NotifyUser;
use App\Domain\Recipes\Actions\SnapshotRecipeVersion;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Models\Tag;
use App\Models\TagPromotion;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
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

        $moderator = $this->createUser('moderacja@example.test', 'moderacja', 'Moderacja Kuking', [
            'bio' => 'Konto zespołu Kuking.',
        ], role: User::ROLE_MODERATOR);

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
        $this->wpisZKilkomaZdjeciami(
            $basia,
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
            'source_person' => 'babci Zofii',
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

        // „Ugotowałem” — najważniejsze zdarzenie w produkcie
        $notify = app(NotifyUser::class);

        $wykonanie = CookedEvent::create([
            'user_id' => $ania->getKey(),
            'recipe_id' => $rosol->getKey(),
            'note' => 'Zrobiłam w sobotę, żeby w niedzielę tylko podgrzać. Rzeczywiście wyszedł klarowny.',
            'changes_note' => 'Dałam pół selera zamiast całego, bo dzieci nie lubią.',
            'would_make_again' => true,
            'perceived_difficulty' => 'easy',
            'actual_minutes' => 260,
            'cooked_at' => now()->subDays(2),
        ]);

        $notify->handle($basia, Notification::TYPE_COOKED, $ania, [
            'recipe_id' => $rosol->getKey(),
            'recipe_title' => $rosol->title,
            'recipe_slug' => $rosol->slug,
            'cooked_event_id' => $wykonanie->getKey(),
            'has_photo' => false,
        ]);

        CookedEvent::create([
            'user_id' => $marek->getKey(),
            'recipe_id' => $rosol->getKey(),
            'note' => 'Klasyka. Zrobiłem dokładnie tak, jak napisane, i nie ma o czym dyskutować.',
            'would_make_again' => true,
            'perceived_difficulty' => 'easy',
            'actual_minutes' => 250,
            'cooked_at' => now()->subDay(),
        ]);

        Comment::create([
            'author_id' => $ania->getKey(),
            'recipe_id' => $rosol->getKey(),
            'body' => 'Pani Basiu, a można dać kaczkę zamiast kury?',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        $this->command?->info('Dane demo gotowe. Zaloguj się jako basia@example.test / haslo-testowe-123');
        $this->command?->info('Konto moderatora: moderacja@example.test / haslo-testowe-123');
        $this->command?->line('Moderator: '.$moderator->email);
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
        $tagi = [];

        foreach (['zupy', 'chleb na zakwasie', 'szybkie obiady'] as $nazwa) {
            $tagi[$nazwa] = Tag::create([
                'name' => $nazwa,
                'normalized_name' => Tag::znormalizujNazwe($nazwa),
                'slug' => Tag::slugDlaNazwy($nazwa),
                'status' => Tag::STATUS_ACTIVE,
            ]);
        }

        $rosol->tags()->attach($tagi['zupy']->getKey(), ['position' => 0]);
        $chleb->tags()->attach($tagi['chleb na zakwasie']->getKey(), ['position' => 0]);
        $nalesniki->tags()->attach($tagi['szybkie obiady']->getKey(), ['position' => 0]);

        // Jeden tag promowany — to jest lista, którą czyta onboarding i szyna
        // na stronie głównej, więc bez niej te dwa ekrany też mierzyłyby pustkę.
        TagPromotion::create([
            'tag_id' => $tagi['zupy']->getKey(),
            'position' => 1,
            'note' => 'Zupa na listopad — najłatwiejszy tydzień w całym kalendarzu.',
        ]);

        // Ktoś MUSI obserwować tag, żeby ekran „Twoje tagi" miał co pokazać
        // poza stanem pustym, i żeby `TagFeed` nie był pusty dla tego konta.
        $ania->followedTags()->syncWithoutDetaching([
            $tagi['zupy']->getKey() => ['created_at' => now()],
        ]);
        $basia->followedTags()->syncWithoutDetaching([
            $tagi['chleb na zakwasie']->getKey() => ['created_at' => now()],
        ]);
    }

    private function createUser(string $email, string $username, string $displayName, array $profile = [], string $role = User::ROLE_USER): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'password' => Hash::make('haslo-testowe-123'),
                'status' => User::STATUS_ACTIVE,
                'role' => $role,
                'locale' => 'pl',
                'text_scale' => 100,
                'age_confirmed_at' => now(),
                'email_verified_at' => now(),
            ],
        );

        Profile::firstOrCreate(
            ['user_id' => $user->getKey()],
            array_merge(['username' => $username, 'display_name' => $displayName], $profile),
        );

        return $user->refresh();
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
}
