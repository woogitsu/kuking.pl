<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Actions;

use App\Domain\Media\ZdjeciaDoPrzypiecia;
use App\Domain\Notifications\Actions\NotifyUser;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * "Ugotowałem" — zapis realnego wykonania przepisu.
 *
 * To jest najcenniejsze zdarzenie w całym Kuking i jednocześnie najmilszy
 * moment dla autora przepisu. Dlatego:
 *
 *  - powiadomienie autora jest OBOWIĄZKOWĄ częścią tej operacji, nie dodatkiem;
 *  - nie wymagamy zdjęcia ani żadnego pola — wystarczy sam fakt ugotowania;
 *  - ta sama osoba może zrobić to dowolnie wiele razy dla tego samego przepisu.
 *
 * TRZY PRZYPADKI, W KTÓRYCH POWIADOMIENIE MIMO TO NIE POWSTAJE — WYPISANE,
 * BO SŁOWO „ZAWSZE" BEZ WYPISANYCH GRANIC JEST NIESPRAWDZALNE
 * ---------------------------------------------------------------------
 * Wszystkie trzy odcina `NotifyUser`, żeby nie trzeba było o nich pamiętać
 * w dwunastu miejscach, i wszystkie trzy są zmierzone w
 * `tests/Feature/UgotowalemZawszePowiadamiaAutoraTest.php`, każdy z kontrolą
 * dodatnią obok:
 *
 *  1. AUTOR UGOTOWAŁ WŁASNY PRZEPIS. `RecipePolicy::cook()` na to pozwala
 *     (ludzie gotują swoje przepisy i chcą mieć ślad), ale wiadomość o
 *     własnej akcji nie niesie żadnej informacji.
 *  2. KONTO AUTORA JEST ZAMKNIĘTE — `banned`, `pending_delete` albo `erased`
 *     (`User::mozeCzytac()`). Przy dwóch pierwszych wykonanie i tak nie
 *     powstaje, bo przepis takiego konta jest niewidoczny; przy `erased`
 *     powstaje i ZOSTAJE (to dorobek kucharza), a powiadomienia nie ma, bo
 *     nie ma komu go przeczytać. ZAWIESZENIE TU NIE WCHODZI: zawieszony
 *     autor powiadomienie dostaje.
 *  3. MIĘDZY AUTOREM A KUCHARZEM JEST BLOKADA, w którąkolwiek stronę — ale
 *     wtedy `Gate::denies('cook')` wyżej i tak nie dopuszcza wykonania, więc
 *     ten warunek w `NotifyUser` jest dla tej ścieżki drugą linią, nie
 *     pierwszą.
 *
 * Czego na tej liście NIE MA i mieć nie ma: ustawienia użytkownika. Jedyna
 * zgoda, jaką człowiek tu przestawia, dotyczy TYGODNIOWEGO LISTU
 * (`users.wants_weekly_digest`) i powiadomień w serwisie nie dotyka.
 *
 * JEDNO WYSŁANIE FORMULARZA TO JEDNO WYKONANIE I JEDNO POWIADOMIENIE (ADR
 * `docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md`, wariant A3).
 *
 * Zmierzone przed zmianą: dwa kliknięcia „Wyślij" dawały dwa wiersze i DWA
 * powiadomienia u autora przepisu. Wykonanie da się usunąć — powiadomienia
 * nie da się cofnąć, a licznik „ugotowali to 4 osoby" przestawał znaczyć
 * „cztery osoby".
 *
 * OSTATNI PUNKT LISTY WYŻEJ ZOSTAJE NIENARUSZONY (D-005, „obowiązuje,
 * nienaruszalne"). Klucz wysłania to tożsamość FORMULARZA, nie przepisu:
 * drugie prawdziwe gotowanie przychodzi z nowego formularza, więc z nowym
 * kluczem, i zapisuje się normalnie. Zakazane jest wyłącznie policzenie
 * jednego wysłania dwa razy. Okno czasowe „ta sama treść w ciągu N sekund"
 * NIE zostało wybrane właśnie dlatego, że przy pustym wykonaniu (a pola tu są
 * wszystkie opcjonalne) degenerowałoby się do `(user_id, recipe_id)`, czyli
 * do tego, czego D-005 zakazuje (ADR §3.3).
 */
final class RecordCookedEvent
{
    /**
     * Jedno zdanie na KAŻDY brak dostępu — także ten, który pojawił się
     * w trakcie wysyłania formularza (#2017). Nie mówi, czego zabrakło:
     * patrz komentarz przy sprawdzeniu Policy w `handle()`.
     */
    private const ODMOWA = 'Nie można dodać wykonania do tego przepisu.';

    public function __construct(private readonly NotifyUser $notify) {}

    /**
     * @param  list<string>  $mediaIds
     * @param  string|null  $kluczWyslania  tożsamość TEGO wysłania formularza; `null` znaczy
     *                                      „nie wiemy, zapisuj normalnie" (ADR §4.3)
     */
    public function handle(
        User $cook,
        Recipe $recipe,
        ?string $note = null,
        array $mediaIds = [],
        ?bool $wouldMakeAgain = null,
        ?string $perceivedDifficulty = null,
        ?int $actualMinutes = null,
        ?string $changesNote = null,
        ?string $ip = null,
        ?string $kluczWyslania = null,
        ?string $wersjaPrzepisuId = null,
    ): CookedEvent {
        $zapisz = function (?string $klucz) use (
            $cook, $recipe, $note, $wouldMakeAgain, $perceivedDifficulty, $actualMinutes, $changesNote, $mediaIds, $ip, $wersjaPrzepisuId
        ): CookedEvent {
            return DB::transaction(function () use (
                $cook, $recipe, $note, $wouldMakeAgain, $perceivedDifficulty, $actualMinutes, $changesNote, $mediaIds, $klucz, $ip, $wersjaPrzepisuId
            ): CookedEvent {
                /*
                 * ZDJĘCIA WYBIERANE POD BLOKADĄ, W TEJ SAMEJ TRANSAKCJI
                 * (issue #285, D-083).
                 *
                 * Ta sama luka co w `PublishPost`: własność sprawdzana PRZED
                 * transakcją, zwykłym `SELECT`-em, a przypięcie kilka linijek
                 * dalej. Sprzątacz osieroconych zdjęć mieścił się w środku
                 * razem z kasowaniem plików, a `cooked_event_media.media_id`
                 * kasuje się kaskadowo — więc wykonanie zostawało bez zdjęcia
                 * i bez pliku. „Ugotowałem" jest w tym produkcie ważniejsze
                 * niż lajk, a zdjęcie z tego wykonania bywa jedynym, jakie
                 * ta osoba ma.
                 *
                 * STOI PRZED BLOKADĄ KONT I PRZEPISU NIŻEJ: kolejność
                 * `media` → `users` → `recipes` jest w tym repozytorium
                 * ustalona i zmierzona (D-103, komentarz w `PublishRecipe`).
                 */
                $ownedMedia = ZdjeciaDoPrzypiecia::zablokuj((string) $cook->getKey(), $mediaIds);

                /*
                 * DOSTĘP SPRAWDZANY POD BLOKADĄ, NA ŚWIEŻYM STANIE (#2017).
                 *
                 * Przedtem oba warunki niżej stały PRZED transakcją i czytały
                 * modele podane z zewnątrz: przepis wczytany przez kontroler,
                 * autora wczytanego przy jego `authorize()`, kucharza z sesji.
                 * Autor przełączał w tym czasie przepis na „tylko ja",
                 * moderacja go ukrywała albo banowała autora — a wykonanie
                 * i powiadomienie i tak się zapisywały. Treści przepisu to
                 * nie ujawniało, ale dopisywało do historii wykonanie, do
                 * którego ta osoba nie miała już prawa, i budziło autora
                 * wiadomością o przepisie, którego ona już nie widzi.
                 *
                 * Od tej linijki `$cook` i `$recipe` to wiersze odczytane POD
                 * BLOKADĄ (`zablokujStanDostepu()`), a nie modele z wejścia.
                 * Zmiana, która zatwierdziła się przed nami, jest więc już
                 * widoczna, a ta, która przyjdzie po nas, poczeka na koniec
                 * tej transakcji — razem z wykonaniem i powiadomieniem.
                 */
                $stan = $this->zablokujStanDostepu($cook, $recipe);

                if ($stan === null) {
                    throw new BladDlaCzlowieka(self::ODMOWA);
                }

                [$cook, $recipe] = $stan;

                /*
                 * AUTORYZACJA STOI TU, A NIE TYLKO W KONTROLERZE (audyt G12).
                 *
                 * `AGENTS.md` §7 mówi „UUID w adresie to nie autoryzacja —
                 * każde wejście przez Policy". Litera mówi o adresie, sens
                 * jest szerszy: o tym, kto może ugotować dany przepis,
                 * rozstrzyga `RecipePolicy`, a nie to, kto akurat wywołuje
                 * akcję. Wywołane wprost, `handle()` zapisywało kiedyś
                 * wykonanie cudzego przepisu `private` mimo
                 * `RecipePolicy::cook` na „nie" — reguła stała w JEDNYM
                 * miejscu zamiast w warstwie, do której sięgnie następne
                 * polecenie konsolowe, zadanie w kolejce albo import.
                 *
                 * Gate zastępuje kontrolę blokady, a nie stoi obok niej:
                 * `RecipePolicy::view` sprawdza blokadę po drodze, więc osobny
                 * warunek byłby drugą kopią tej samej reguły.
                 *
                 * Komunikat celowo nie mówi, CZEGO zabrakło. „Ten przepis jest
                 * prywatny" potwierdzałoby istnienie przepisu komuś, kto nie
                 * ma prawa o tym wiedzieć. Z tego samego powodu Gate stoi
                 * PRZED `isPublished()`: przepis ukryty przez moderację
                 * w trakcie wysyłania ma dać obcemu to samo zdanie, co każdy
                 * inny brak dostępu, a nie „nie ma jeszcze opublikowanego".
                 */
                if (Gate::forUser($cook)->denies('cook', $recipe)) {
                    throw new BladDlaCzlowieka(self::ODMOWA);
                }

                /*
                 * Policy wpuszcza autora na jego własny szkic, a moderatora na
                 * przepis ukryty przez moderację — wykonań żadnego z nich
                 * zapisywać nie chcemy.
                 */
                if (! $recipe->isPublished()) {
                    throw new BladDlaCzlowieka('Tego przepisu nie ma jeszcze opublikowanego.');
                }

                $event = new CookedEvent([
                    'user_id' => $cook->getKey(),
                    'recipe_id' => $recipe->getKey(),
                    'klucz_wyslania' => $klucz,
                    'note' => $note,
                    'would_make_again' => $wouldMakeAgain,
                    'perceived_difficulty' => $perceivedDifficulty,
                    'actual_minutes' => $actualMinutes,
                    'changes_note' => $changesNote,
                    'cooked_at' => now(),
                ]);

                // Wskaźnik na wersję (issue #2378) jest poza `$fillable`:
                // ustawia go wyłącznie ta akcja, po sprawdzeniu, że wersja
                // należy do TEGO przepisu.
                $event->forceFill(['recipe_version_id' => $this->wersjaWykonania($recipe, $wersjaPrzepisuId)])->save();

                $position = 0;

                foreach ($mediaIds as $mediaId) {
                    if (! in_array($mediaId, $ownedMedia, true)) {
                        continue;
                    }

                    $event->media()->attach($mediaId, ['position' => $position]);
                    $position++;
                }

                /*
                 * POWIADOMIENIE STOI W TEJ SAMEJ TRANSAKCJI, I TO NIE JEST
                 * KOSMETYKA (audyt zewnętrzny G04).
                 *
                 * Przedtem transakcja kończyła się na wierszu wykonania,
                 * a powiadomienie szło po niej. Jednorazowa awaria zapisu
                 * powiadomienia zostawiała więc wykonanie bez wiadomości —
                 * i, co gorsze, ponowienie tego samego formularza odbijało
                 * się o `cooked_events_one_per_klucz_wyslania`, znajdowało
                 * istniejące wykonanie i wychodziło PRZED powiadomieniem.
                 * Autor przepisu nie dowiadywał się nigdy, a kucharz nie
                 * miał jak tego naprawić.
                 *
                 * Docblock tej klasy mówi „powiadomienie autora jest
                 * OBOWIĄZKOWĄ częścią tej operacji, nie dodatkiem", a
                 * AGENTS.md §1 mówi „ZAWSZE powiadamia autora przepisu".
                 * Jedno wspólne `DB::transaction` jest jedynym sposobem,
                 * żeby to była prawda, a nie deklaracja.
                 *
                 * Wpis audytowy jest tu z tego samego powodu: audyt
                 * mówiący o wykonaniu, którego nie ma w bazie, jest gorszy
                 * niż brak wpisu.
                 */
                $this->notify->handle(
                    recipient: $recipe->author,
                    type: Notification::TYPE_COOKED,
                    actor: $cook,
                    data: [
                        'recipe_id' => $recipe->getKey(),
                        'recipe_title' => $recipe->title,
                        'recipe_slug' => $recipe->slug,
                        'cooked_event_id' => $event->getKey(),
                        'has_photo' => $event->media()->exists(),
                    ],
                );

                AuditLogEntry::record(
                    action: 'cooked_event.created',
                    actor: $cook,
                    subject: $event,
                    metadata: ['recipe_id' => $recipe->getKey()],
                    ip: $ip,
                );

                return $event;
            });
        };

        try {
            $event = $zapisz($kluczWyslania);
        } catch (UniqueConstraintViolationException $e) {
            if ($kluczWyslania === null) {
                // Bez klucza nie ma jak odbić się o
                // `cooked_events_one_per_klucz_wyslania` — to inne
                // ograniczenie i nie wolno go tu wyciszyć.
                throw $e;
            }

            // Indeks `cooked_events_one_per_klucz_wyslania` odbił wiersz: to
            // wysłanie już raz zapisało wykonanie. Zwracamy TO wykonanie
            // i nie powiadamiamy drugi raz.
            //
            // Wolno tak wyjść dopiero od naprawy G04. Skoro wiersz istnieje,
            // to znaczy, że jego transakcja się ZAKOŃCZYŁA — a w tej samej
            // transakcji stoi powiadomienie. Wcześniej „wiersz jest" nie
            // dowodziło niczego o powiadomieniu i właśnie tędy gubiła się
            // wiadomość do autora.
            $istniejace = $this->wykonanieZTegoWyslania($cook, $kluczWyslania);

            if ($istniejace !== null) {
                return $istniejace;
            }

            // Klucz zajęty, a wykonania nie widać (np. zostało w tym czasie
            // usunięte). Nie odmawiamy — zapisujemy bez klucza, z ryzykiem
            // duplikatu (ADR §4.3).
            $event = $zapisz(null);
        }

        return $event;
    }

    /**
     * Wersja przepisu, z której gotowano (issue #2378) — sam wskaźnik, bez
     * kopiowania treści.
     *
     * `$zFormularza` to identyfikator wersji, którą ekran „Ugotowałem"
     * pobrał razem z formularzem, czyli tę, którą człowiek miał przed oczami.
     * To dane od klienta, więc: wersja musi należeć do TEGO przepisu (cudzy
     * identyfikator nie przypina nikomu wersji innego przepisu). Brak albo
     * niepoprawny identyfikator (API, stary formularz) schodzi do najnowszej
     * wersji w chwili zapisu — najlepsze, co wiemy. Przepis bez żadnej wersji
     * (np. sprzed historii) daje `null`: „nie wiadomo".
     */
    private function wersjaWykonania(Recipe $recipe, ?string $zFormularza): ?string
    {
        if ($zFormularza !== null && Str::isUuid($zFormularza)) {
            $jest = RecipeVersion::query()
                ->where('recipe_id', $recipe->getKey())
                ->whereKey($zFormularza)
                ->exists();

            if ($jest) {
                return $zFormularza;
            }
        }

        $najnowsza = RecipeVersion::query()
            ->where('recipe_id', $recipe->getKey())
            ->orderByDesc('version_number')
            ->value('id');

        return $najnowsza === null ? null : (string) $najnowsza;
    }

    /**
     * Konta kucharza i autora oraz wiersz przepisu — odczytane POD BLOKADĄ
     * `FOR SHARE`, do końca bieżącej transakcji (#2017).
     *
     * CO TA BLOKADA USTAWIA W KOLEJCE. `RecipePolicy::cook` zależy od
     * czterech rzeczy i każda zmienia się przez jeden z tych trzech wierszy:
     *
     *  - widoczność, status (ukrycie, szkic) i miękkie usunięcie przepisu —
     *    `UPDATE recipes` albo `FOR UPDATE` na nim (`PublishRecipe`,
     *    `ZdejmijZUrzedu`, `RozstrzygnijZgloszenie`, `RestoreContent`);
     *  - status konta autora i kucharza (ban, zawieszenie, karencja
     *    usunięcia, rola) — `ZamekKonta` albo `UPDATE users`;
     *  - blokada i obserwowanie między nimi — `ZamekPary` bierze oba wiersze
     *    `users` `FOR UPDATE` (D-080), a tabeli `blocks`/`follows` nie da się
     *    zablokować, bo wiersza może jeszcze nie być.
     *
     * `FOR SHARE` jest w konflikcie z każdą z tych operacji, więc albo
     * zatwierdziła się przed nami i ją tu zobaczymy (PostgreSQL w READ
     * COMMITTED oddaje po czekaniu nową wersję wiersza, a każde następne
     * zapytanie ma świeży obraz), albo czeka na koniec tej transakcji —
     * razem z wykonaniem i powiadomieniem.
     *
     * DLACZEGO `FOR SHARE`, A NIE `FOR UPDATE`. Dwa wykonania tego samego
     * przepisu (albo tej samej osoby) nie mają powodu czekać jedno na
     * drugie, a `FOR SHARE` nie jest w konflikcie sam ze sobą. Blokada nie
     * jest też nigdzie w tej transakcji podnoszona: nic tu nie pisze do
     * `users` ani do `recipes` (wstawienia biorą na nich tylko `FOR KEY
     * SHARE` sprawdzenia klucza obcego, słabsze od tej), więc dwóch
     * kucharzy tego samego przepisu nie zakleszczy się na podnoszeniu
     * blokady współdzielonej.
     *
     * KOLEJNOŚĆ — TA SAMA CO W RESZCIE REPOZYTORIUM. Po `media`
     * (`ZdjeciaDoPrzypiecia` w `handle()`), potem `users`, potem `recipes`:
     * tak biorą je `PublishRecipe` (D-103) i `EraseAccountData` (konto →
     * treści). Dwa konta rosnąco po identyfikatorze, dwoma osobnymi
     * zapytaniami — ta sama reguła i ten sam powód co w `ZamekPary`.
     *
     * `null` = dostępu nie ma już na pewno (przepis usunięty albo zdjęty,
     * konto zniknęło, przepis zmienił autora w trakcie). Pozostałe powody
     * rozstrzyga Policy na zwróconych modelach.
     *
     * @return array{User, Recipe}|null
     */
    private function zablokujStanDostepu(User $cook, Recipe $recipe): ?array
    {
        $idKucharza = (string) $cook->getKey();
        $idAutora = (string) $recipe->author_id;

        $doZablokowania = array_values(array_unique([$idKucharza, $idAutora]));
        sort($doZablokowania, SORT_STRING);

        $konta = [];

        foreach ($doZablokowania as $id) {
            $konta[$id] = User::query()->whereKey($id)->sharedLock()->first();
        }

        // Miękko usunięty (zdjęty przez moderację) przepis nie wraca tu przez
        // domyślny zakres `SoftDeletes` — i ma nie wracać.
        $swiezy = Recipe::query()->whereKey($recipe->getKey())->sharedLock()->first();

        $kucharz = $konta[$idKucharza] ?? null;
        $autor = $konta[$idAutora] ?? null;

        if ($swiezy === null || $kucharz === null || $autor === null || (string) $swiezy->author_id !== $idAutora) {
            return null;
        }

        $swiezy->setRelation('author', $autor);

        return [$kucharz, $swiezy];
    }

    /**
     * Wykonanie zapisane z TEGO wysłania formularza — jeśli zostało zapisane.
     *
     * Zawężone do osoby, która gotowała, a nie zadane samemu kluczowi:
     * `klucz_wyslania` przychodzi z żądania, a UUID w żądaniu nie jest
     * autoryzacją (`AGENTS.md` §7).
     *
     * Publiczne, bo kontroler pyta o to PRZED zapisem zdjęć (issue #873).
     * Współbieżne żądania nadal rozstrzyga indeks UNIQUE, nie to pytanie.
     */
    public function wykonanieZTegoWyslania(User $cook, ?string $kluczWyslania): ?CookedEvent
    {
        if ($kluczWyslania === null) {
            return null;
        }

        return CookedEvent::query()
            ->where('user_id', $cook->getKey())
            ->where('klucz_wyslania', $kluczWyslania)
            ->first();
    }
}
