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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

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
 *
 * ILE TO KOSZTUJE ZAPYTAŃ — I DLACZEGO TO JEST TU OPISANE (issue #286)
 * Przekierowanie do zdjęcia jest najczęściej wołaną trasą w całym serwisie:
 * jedna strona feedu to grubo ponad sto osobnych żądań HTTP
 * (`ZdjeciaLimitZapytanTest`, `config('kuking.limits.zdjecie')`), a każde
 * z nich przechodzi TĘ klasę. Zmierzone 10.09.2026 dla zwykłego widza:
 * **15 zapytań na jedno zdjęcie**, z czego 10 szło na dwukrotne zbudowanie
 * kompletnego grafu rodziców — pięć stałych `SELECT`-ów raz dla widza i te
 * same pięć drugi raz dla anonima, na potrzeby nagłówka `Cache-Control`.
 * Pomiar i rozbicie: `docs/research/2026-09-10-pomiar-zapytan-zdjecia.md`.
 *
 * Dwie rzeczy, które to zmieniły, i ani jedna z nich nie dotyka decyzji:
 *
 * 1. **Nie pytamy tabel, które tego zdjęcia nawet nie wspominają.** Jedno
 *    zapytanie `UNION` (`tabeleWskazujaceNaZdjecie()`) mówi, w których
 *    tabelach w ogóle stoi ten identyfikator; wiersze rodziców czytamy
 *    wyłącznie z tych tabel. Zamiast pięciu `SELECT`-ów zawsze mamy
 *    `1 + liczba tabel, które to zdjęcie naprawdę mają` — dla zwykłego
 *    zdjęcia wpisu dwa, dla zdjęcia osieroconego jeden.
 * 2. **Graf rodziców budujemy RAZ na żądanie** (`rozstrzygnij()`), a `Gate`
 *    pytamy osobno dla widza i osobno dla anonima na tych samych wierszach.
 *
 * CZEGO TU NIE MA I NIE MA BYĆ: PAMIĘCI PODRĘCZNEJ DECYZJI.
 * Współdzielone są WIERSZE RODZICÓW w obrębie jednego żądania, nie
 * odpowiedź „wolno / nie wolno". Każde pytanie idzie przez `Gate`, czyli
 * przez te same Policy, które pilnują stron HTML — więc blokada, prywatność
 * przepisu, decyzja moderacyjna i status autora działają dalej, bo to
 * ONE, a nie ta klasa, są miejscem, w którym te reguły żyją. Cache decyzji
 * o widoczności zdjęcia jest w issue #286 świadomie ostatnim krokiem i wymaga
 * unieważniania przy czterech różnych zdarzeniach — nie wchodzi tu przy
 * okazji optymalizacji liczby zapytań.
 *
 * Jedno zapytanie `UNION` NIE JEST autoryzacją i nie zna widoczności. Pyta
 * wyłącznie „czy w tabeli X stoi ten identyfikator zdjęcia" — celowo BEZ
 * warunków na `deleted_at`, `visibility`, status autora czy blokadę. Wiersz
 * rodzica i tak wczytujemy potem Eloquentem, z jego globalnymi scope'ami
 * (`SoftDeletes`), a wpuszcza dopiero `Gate`. Gdyby to zapytanie próbowało
 * rozstrzygać widoczność samo, byłaby to ósma kopia reguły — dokładnie to,
 * czego ta klasa ma nie robić.
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
     * To samo co `self::ODWOLANIA`, tylko pogrupowane po TABELI.
     *
     * Jedna tabela to jeden rodzaj rodzica, nawet gdy wskazuje na `media`
     * dwiema kolumnami — `recipes` robi to zdjęciem głównym I skanem kartki,
     * a jest jednym przepisem i jedną Policy. Kolejność jest kolejnością,
     * w której pytamy `Gate`, i nie ma znaczenia dla wyniku: wpuszcza
     * KTÓRYKOLWIEK rodzic, więc suma jest ta sama niezależnie od kolejności.
     *
     * ROZJAZDU Z `ODWOLANIA` PILNUJE TEST
     * `AutoryzacjaZdjeciaJednymPrzejsciemTest::test_lista_tabel_nie_rozjechala_sie_z_odwolaniami`.
     * Tabela, która trafiłaby tylko do jednej z tych dwóch list, znaczy albo
     * zdjęcie bez rodzica (niewidoczne), albo tabelę pomijaną przy szukaniu
     * rodziców (też niewidoczne) — w obie strony błąd cichy.
     *
     * @var array<string, list<string>>
     */
    private const KOLUMNY_WSKAZUJACE = [
        'post_media' => ['media_id'],
        'cooked_event_media' => ['media_id'],
        'profiles' => ['avatar_media_id'],
        'recipes' => ['hero_media_id', 'source_scan_media_id'],
        'recipe_steps' => ['media_id'],
    ];

    /**
     * @param  User|null  $widz  `null` = niezalogowany. Zdjęcie publicznego
     *                           przepisu ma się otwierać bez konta.
     */
    public function moze(?User $widz, Media $zdjecie): bool
    {
        if (! $this->gotoweDoSerwowania($zdjecie)) {
            return false;
        }

        if ($this->wlascicielLubModerator($widz, $zdjecie)) {
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
     * Odpowiedź na OBA pytania `MediaController` w jednym przejściu po
     * rodzicach (issue #286).
     *
     * Wynik jest identyczny z dwoma osobnymi wywołaniami `moze()` — dla widza
     * i dla `null` — i ma tak zostać. Różnica jest wyłącznie w tym, że wiersze
     * rodziców czyta się raz: `Gate` dostaje oba pytania osobno, na tych samych
     * wierszach, bo odpowiedzi rozjeżdżają się w OBIE strony (zablokowany widz
     * nie zobaczy zdjęcia, które anonim zobaczy; autor zobaczy zdjęcie swojego
     * prywatnego przepisu, którego anonim nie zobaczy).
     *
     * Pętla przerywa się, gdy OBA pytania mają już odpowiedź „tak" — wtedy
     * żaden dalszy rodzic nie może niczego zmienić, bo wpuszcza którykolwiek.
     * Gdy którakolwiek odpowiedź jest jeszcze „nie", przechodzimy wszystkich
     * rodziców do końca, tak samo jak `moze()`.
     */
    public function rozstrzygnij(?User $widz, Media $zdjecie): DecyzjaOZdjeciu
    {
        if (! $this->gotoweDoSerwowania($zdjecie)) {
            return new DecyzjaOZdjeciu(dlaWidza: false, dlaAnonima: false);
        }

        // Gdy widzem JEST anonim, oba pytania są jednym pytaniem i nie ma
        // czego liczyć dwa razy. Dotąd tę skrótową regułę miał u siebie
        // `MediaController` (`$widz === null ? true : …`); stoi teraz razem
        // z resztą decyzji, bo to część odpowiedzi, a nie szczegół nagłówka.
        if ($widz === null) {
            $wynik = $this->moze(null, $zdjecie);

            return new DecyzjaOZdjeciu(dlaWidza: $wynik, dlaAnonima: $wynik);
        }

        // Anonim nie może być ani właścicielem, ani moderatorem, więc ta
        // skrótowa ścieżka dotyczy wyłącznie odpowiedzi dla widza.
        $dlaWidza = $this->wlascicielLubModerator($widz, $zdjecie);
        $dlaAnonima = false;

        foreach ($this->rodzice($zdjecie) as $rodzic) {
            if (! $dlaWidza && Gate::forUser($widz)->allows('view', $rodzic)) {
                $dlaWidza = true;
            }

            if (! $dlaAnonima && Gate::forUser(null)->allows('view', $rodzic)) {
                $dlaAnonima = true;
            }

            if ($dlaWidza && $dlaAnonima) {
                break;
            }
        }

        return new DecyzjaOZdjeciu(dlaWidza: $dlaWidza, dlaAnonima: $dlaAnonima);
    }

    /**
     * STAN INNY NIŻ `ready` TO ODMOWA DLA KAŻDEGO, RÓWNIEŻ DLA WŁAŚCICIELA
     * (AGENTS.md §7). Nie chodzi o autoryzację, tylko o to, co leży pod
     * spodem: dopóki `ProcessUploadedImage` nie przekodował pliku, w EXIF-ie
     * siedzi jeszcze pełna lokalizacja GPS kuchni. Wyjątek dla właściciela
     * wyglądałby niewinnie i byłby pierwszym krokiem do serwowania
     * oryginałów tą trasą.
     */
    private function gotoweDoSerwowania(Media $zdjecie): bool
    {
        return $zdjecie->isReady();
    }

    /**
     * Właściciel i moderator PRZED odpytaniem bazy o rodziców: zdjęcie
     * osierocone (wgrane i nieprzypięte jeszcze do niczego — normalny stan
     * w trakcie wypełniania formularza) musi być widoczne dla tego, kto je
     * właśnie wgrał, inaczej podgląd w kreatorze byłby pustą ramką.
     */
    private function wlascicielLubModerator(?User $widz, Media $zdjecie): bool
    {
        return $widz !== null
            && ($widz->getKey() === $zdjecie->owner_id || $widz->isModerator());
    }

    /**
     * Treści, do których to zdjęcie jest przypięte.
     *
     * Treść skasowana miękko (`Post`, `Recipe`) NIE jest tu rodzicem: globalny
     * scope `SoftDeletes` ją odfiltrowuje i tak ma być. Autor, który usunął
     * przepis, nie powinien zostawać z jawnym adresem jego zdjęcia. To jest
     * też powód, dla którego zapytanie rozpoznające tabele (niżej) nie
     * zastępuje wczytania rodzica Eloquentem: pivot `post_media` przeżywa
     * miękkie skasowanie wpisu, więc potwierdza tylko, że warto do tej tabeli
     * zajrzeć — o tym, czy rodzic w ogóle istnieje, decyduje dopiero model
     * z jego scope'ami.
     *
     * GENERATOR, NIE TABLICA — żeby wywołujący mógł przestać pytać, gdy już
     * wie (patrz `moze()` i `rozstrzygnij()`): rodzica, o którego nikt nie
     * zapytał, nie wczytujemy z bazy.
     *
     * @return iterable<Model>
     */
    private function rodzice(Media $zdjecie): iterable
    {
        $id = (string) $zdjecie->getKey();
        $tabele = $this->tabeleWskazujaceNaZdjecie($id);

        foreach (array_keys(self::KOLUMNY_WSKAZUJACE) as $tabela) {
            if (! in_array($tabela, $tabele, true)) {
                continue;
            }

            yield from $this->wczytajRodzicow($tabela, $id);
        }
    }

    /**
     * Jedno zapytanie odpowiadające na pytanie „w których tabelach w ogóle
     * stoi ten identyfikator zdjęcia" (issue #286).
     *
     * TO NIE JEST AUTORYZACJA i nie wolno jej tu dopisać. Zapytanie nie zna
     * widoczności, nie patrzy na `deleted_at`, blokady ani status autora —
     * odpowiada wyłącznie „warto zajrzeć do tej tabeli". Wpuszcza dopiero
     * `Gate` na wierszu wczytanym Eloquentem. Gdyby ten `UNION` zaczął
     * filtrować po widoczności, powstałaby ósma kopia reguły widoczności,
     * w miejscu, w którym nikt by jej nie szukał przy zmianie Policy.
     *
     * `UNION` (nie `UNION ALL`) — bo interesuje nas ZBIÓR tabel, nie liczba
     * trafień: zdjęcie przypięte do trzydziestu wpisów ma oddać jeden wiersz
     * `post_media`, nie trzydzieści.
     *
     * Nazwy tabel i kolumn są wstawiane do zapytania wprost, bez wiązania
     * parametrów — pochodzą wyłącznie z `self::KOLUMNY_WSKAZUJACE`, czyli ze
     * stałej w tym pliku, a nie z żądania. Identyfikator zdjęcia, jedyna
     * dana z zewnątrz, idzie normalnym parametrem.
     *
     * @return list<string>
     */
    private function tabeleWskazujaceNaZdjecie(string $id): array
    {
        $zapytanie = null;

        foreach (self::KOLUMNY_WSKAZUJACE as $tabela => $kolumny) {
            $czesc = DB::table($tabela)
                ->selectRaw("'".$tabela."' as tabela")
                ->where(function ($warunek) use ($kolumny, $id): void {
                    foreach ($kolumny as $kolumna) {
                        $warunek->orWhere($kolumna, $id);
                    }
                });

            $zapytanie = $zapytanie === null ? $czesc : $zapytanie->union($czesc);
        }

        if ($zapytanie === null) {
            throw new LogicException('DostepDoZdjecia::KOLUMNY_WSKAZUJACE jest puste — zdjęcie nie miałoby żadnego rodzica.');
        }

        // Zwykła pętla, a NIE `pluck('tabela')` na zapytaniu: `pluck()`
        // podmienia listę kolumn zapytania na tę jedną nazwę, a nasza
        // `tabela` nie jest kolumną w żadnej z tych tabel — jest stałą
        // z `selectRaw`. Po podmianie PostgreSQL odpowiedziałby błędem
        // „column tabela does not exist".
        $tabele = [];

        foreach ($zapytanie->get() as $wiersz) {
            $tabele[] = (string) $wiersz->tabela;
        }

        return $tabele;
    }

    /**
     * Wiersze rodziców z JEDNEJ tabeli, wczytane Eloquentem — czyli
     * z globalnymi scope'ami (`SoftDeletes`) i z modelem, który `Gate` umie
     * dopasować do Policy.
     *
     * Brak gałęzi `default` jest celowy: nowa tabela w `KOLUMNY_WSKAZUJACE`
     * bez wpisu tutaj ma wywalić żądanie GŁOŚNO, a nie po cichu pominąć
     * rodzica — cicha odmowa dostępu do własnego zdjęcia jest usterką, którą
     * zgłasza użytkownik, a nie test. Pilnuje tego
     * `ZdjeciaChronioneNieWyciekajaTest` (macierz widoku dla każdego z pięciu
     * rodzajów rodzica przechodzi tą metodą).
     *
     * @return list<Model>
     */
    private function wczytajRodzicow(string $tabela, string $id): array
    {
        return match ($tabela) {
            'post_media' => Post::query()
                ->whereHas('media', fn ($zapytanie) => $zapytanie->whereKey($id))
                ->get()
                ->all(),

            'cooked_event_media' => CookedEvent::query()
                ->whereHas('media', fn ($zapytanie) => $zapytanie->whereKey($id))
                ->get()
                ->all(),

            'profiles' => Profile::query()->where('avatar_media_id', $id)->get()->all(),

            // Nawias JAWNY, nie `where(...)->orWhere(...)` na płasko.
            // `Recipe` ma `SoftDeletes`, więc do zapytania dokleja się jeszcze
            // `deleted_at is null` — bez tego nawiasu wystarczyłaby jedna
            // zmiana kolejności warunków w Laravelu, żeby skasowany przepis
            // zaczął po cichu wystawiać swój skan kartki.
            'recipes' => Recipe::query()
                ->where(fn ($zapytanie) => $zapytanie
                    ->where('hero_media_id', $id)
                    ->orWhere('source_scan_media_id', $id))
                ->get()
                ->all(),

            'recipe_steps' => RecipeStep::query()->where('media_id', $id)->get()->all(),
        };
    }
}
