<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Post;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Czy ten widz może zobaczyć BAJTY tego zdjęcia (audyt W7-02).
 *
 * PO CO TO W OGÓLE POWSTAŁO
 * Do tej pory adresem zdjęcia był adres pliku w buckecie z własną domeną CDN.
 * Taki adres nikogo o nic nie pyta: kto raz go skopiował, otwierał zdjęcie
 * także po zablokowaniu, po cofnięciu obserwowania i po przełączeniu przepisu
 * na prywatny. Najgorszy przypadek nazwał audyt wprost —
 * `recipes.source_scan_media_id`, czyli skan odręcznej kartki z nazwiskami
 * i adresami rodziny.
 *
 * ZDJĘCIE NIE ZNA SWOJEJ WIDOCZNOŚCI i nie będzie jej znało: nie ma na nim
 * kolumny `visibility` i dobrze, bo byłaby to siódma kopia tej samej reguły.
 * Widoczność zdjęcia to widoczność TREŚCI, do której jest przypięte.
 *
 * NAJSZERSZY RODZIC WYGRYWA
 * To samo zdjęcie może być jednocześnie zdjęciem głównym publicznego przepisu
 * i zdjęciem w prywatnym wpisie. Gdyby wygrywał rodzic najwęższy, publiczny
 * przepis pokazywałby pustą ramkę tylko dlatego, że autor wrzucił to samo
 * zdjęcie gdzieś jeszcze. Przepuszczamy więc, gdy KTÓRYKOLWIEK rodzic
 * przepuszcza — bo przez tego rodzica te bajty i tak są jawne.
 *
 * TA KLASA NIE POWTARZA ANI JEDNEGO WARUNKU WIDOCZNOŚCI.
 * Woła istniejące Policy przez `Gate`. To jest cały sens jej istnienia:
 * powtarzającą się przyczyną błędów w tym repozytorium jest „reguła istnieje
 * poprawnie w jednej warstwie, a druga implementuje ją inaczej" (patrz
 * `CaddySpojnyZNaglowkamiLaravelaTest`). Gdyby stały tu własne `match
 * ($visibility)`, blokada albo ban autora naprawiony w `RecipePolicy` nie
 * naprawiałby się w zdjęciach — i nikt by tego nie zauważył, bo wyciek
 * zdjęcia nie wywala żadnego testu.
 *
 * Dlatego brakujące Policy DOPISUJEMY (`RecipeStepPolicy`, `ProfilePolicy`),
 * delegując do rodzica, zamiast wpisywać tutaj warunek „krok widać wtedy,
 * kiedy przepis".
 */
final class DostepDoZdjecia
{
    /**
     * Odwołania do `media`, które ta klasa umie sprawdzić.
     *
     * MUSI POKRYWAĆ CAŁE `KasujZdjecie::ODWOLANIA` i pilnuje tego test
     * (`ZdjeciaChronioneNieWyciekajaTest::test_kazde_odwolanie_do_zdjecia_ma_tu_swojego_rodzica`).
     * Nowa tabela wskazująca na `media`, o której ta klasa nie wie, znaczy
     * zdjęcie bez rodzica — czyli niewidoczne dla wszystkich poza właścicielem.
     * To jest awaria „po bezpiecznej stronie", ale nadal awaria, i lepiej,
     * żeby wyszła z testu niż ze zgłoszenia użytkownika.
     *
     * @var list<array{0: string, 1: string}>
     */
    public const ODWOLANIA = [
        ['post_media', 'media_id'],
        ['cooked_event_media', 'media_id'],
        ['profiles', 'avatar_media_id'],
        ['recipes', 'hero_media_id'],
        ['recipes', 'source_scan_media_id'],
        ['recipe_steps', 'media_id'],
    ];

    /**
     * @param  User|null  $widz  `null` = niezalogowany. Zdjęcie publicznego
     *                           przepisu ma się otwierać bez konta.
     */
    public function moze(?User $widz, Media $zdjecie): bool
    {
        // STAN INNY NIŻ `ready` TO ODMOWA DLA KAŻDEGO, RÓWNIEŻ DLA WŁAŚCICIELA
        // (AGENTS.md §7). Nie chodzi o autoryzację, tylko o to, co leży pod
        // spodem: dopóki `ProcessUploadedImage` nie przekodował pliku, w EXIF-ie
        // siedzi jeszcze pełna lokalizacja GPS kuchni. Wyjątek dla właściciela
        // wyglądałby niewinnie i byłby pierwszym krokiem do serwowania
        // oryginałów tą trasą.
        if (! $zdjecie->isReady()) {
            return false;
        }

        // Właściciel i moderator PRZED odpytaniem bazy o rodziców: zdjęcie
        // osierocone (wgrane i nieprzypięte jeszcze do niczego — normalny stan
        // w trakcie wypełniania formularza) musi być widoczne dla tego, kto je
        // właśnie wgrał, inaczej podgląd w kreatorze byłby pustą ramką.
        if ($widz !== null && ($widz->getKey() === $zdjecie->owner_id || $widz->isModerator())) {
            return true;
        }

        foreach ($this->rodzice($zdjecie) as $rodzic) {
            if (Gate::forUser($widz)->allows('view', $rodzic)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Treści, do których to zdjęcie jest przypięte — po jednej na każde
     * odwołanie z `self::ODWOLANIA`.
     *
     * Treść skasowana miękko (`Post`, `Recipe`) NIE jest tu rodzicem: globalny
     * scope `SoftDeletes` ją odfiltrowuje i tak ma być. Autor, który usunął
     * przepis, nie powinien zostawać z jawnym adresem jego zdjęcia.
     *
     * @return list<Model>
     */
    private function rodzice(Media $zdjecie): array
    {
        $id = $zdjecie->getKey();

        return [
            ...Post::query()
                ->whereHas('media', fn ($zapytanie) => $zapytanie->whereKey($id))
                ->get()
                ->all(),

            ...CookedEvent::query()
                ->whereHas('media', fn ($zapytanie) => $zapytanie->whereKey($id))
                ->get()
                ->all(),

            ...Profile::query()->where('avatar_media_id', $id)->get()->all(),

            // Nawias JAWNY, nie `where(...)->orWhere(...)` na płasko.
            // `Recipe` ma `SoftDeletes`, więc do zapytania dokleja się jeszcze
            // `deleted_at is null` — bez tego nawiasu wystarczyłaby jedna
            // zmiana kolejności warunków w Laravelu, żeby skasowany przepis
            // zaczął po cichu wystawiać swój skan kartki.
            ...Recipe::query()
                ->where(fn ($zapytanie) => $zapytanie
                    ->where('hero_media_id', $id)
                    ->orWhere('source_scan_media_id', $id))
                ->get()
                ->all(),

            ...RecipeStep::query()->where('media_id', $id)->get()->all(),
        ];
    }
}
