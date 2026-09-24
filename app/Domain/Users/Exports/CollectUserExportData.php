<?php

declare(strict_types=1);

namespace App\Domain\Users\Exports;

use App\Models\Collection;
use App\Models\Comment;
use App\Models\ContactMessageReply;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Zbiera CAŁĄ treść jednego konta w jedną tablicę — to zawartość `dane.json`.
 *
 * Dwie reguły, których nie wolno tu naruszyć:
 *
 * 1. **Wszystko, co należy do użytkownika, wchodzi do paczki** — także wpisy
 *    prywatne i szkice przepisów. Eksport, który pomija szkice, jest gorszy
 *    niż brak eksportu, bo daje złudzenie kompletności. Dlatego czytamy
 *    relacje BEZ filtrów `published`.
 *
 * 2. **Cudze dane osobowe NIE wchodzą do paczki.** Komentarz innej osoby pod
 *    wpisem użytkownika jest danymi TEJ osoby. Podajemy treść, datę i nazwę
 *    wyświetlaną — nigdy e-maila i nigdy identyfikatora konta. To samo dotyczy
 *    powiadomień: `data` z bazy przepisujemy przez listę dozwolonych kluczy,
 *    więc nowy klucz dodany kiedyś w kodzie NIE wycieknie tu przez przypadek.
 *
 * Klucze są po polsku, bo ten plik czyta człowiek, a nie tylko program
 * (AGENTS.md, sekcja 11). Format zostaje maszynowy — JSON, UTF-8, daty ISO 8601.
 */
final class CollectUserExportData
{
    /**
     * Klucze z `notifications.data`, które wolno przepisać do eksportu.
     *
     * Świadomie NIE ma tu `username` ani żadnego `*_id` — to identyfikatory
     * (cudze albo wewnętrzne), a paczka ma być czytelna, nie technicznie pełna.
     *
     * `excerpt` jest na tej liście dla typów, które niosą własny tekst
     * (np. wiadomość od moderacji). Dla `comment.created`/`comment.replied`
     * NIE bierze się z `data` — tam wycinek liczy się z AKTUALNEJ treści
     * komentarza przy budowaniu paczki (#758, D-229, `notifications()` niżej).
     */
    private const NOTIFICATION_DATA_KEYS = [
        'excerpt',
        'recipe_title',
        'recipe_slug',
        'has_photo',
        'url',
    ];

    /** @return array<string, mixed> */
    public function handle(User $user, ExportPhotoPlan $photos, Carbon $generatedAt): array
    {
        $user->loadMissing('profile.avatar');

        return [
            'o_tym_pliku' => [
                'serwis' => 'Kuking.pl',
                'wygenerowano' => $generatedAt->toIso8601String(),
                'format' => 'JSON, kodowanie UTF-8, daty w formacie ISO 8601',
                // „WSZYSTKIE" BYŁO O JEDNO SŁOWO ZA DUŻO (#492).
                //
                // Zdanie obiecywało komplet, a paczka kompletem nie jest i nie
                // udaje nim być w żadnym innym miejscu. Wersje przepisów,
                // obserwowane tagi, dziennik zgód i połączone konta, które
                // #492 wymieniał tu jako pominięte, weszły do paczki w #953.
                // Poza nią zostaje to, co `InwentarzDanychKonta` oznacza
                // jako `NA_ZADANIE` (wypisane w `kategorie_poza_paczka`),
                // więc słowo „wszystkie" dalej byłoby nieprawdą. Granicę
                // dotyczącą cudzych treści nazywa `czego_nie_zawiera` niżej.
                'co_zawiera' => 'Treści tego konta — także wpisy prywatne i szkice przepisów.',
                // Dwie granice, obie mierzone, obie nazwane wprost. Druga
                // dołączyła po pomiarze do #492: paczka stosowała ją od
                // początku, ale nie mówiła o niej w żadnym swoim pliku.
                // Człowiek, który odłożył czterdzieści cudzych przepisów
                // „na kiedyś", dostawał plik wyglądający na kompletny —
                // a dowiadywał się o brakach dopiero wtedy, gdy Kuking już
                // nie istnieje i nie ma dokąd po nie wrócić. To jest
                // dokładnie ta sama zasada co przy zdjęciach w drodze
                // (issue #113): paczka, która WYGLĄDA na kompletną, a nie
                // jest, jest gorsza od paczki mówiącej o swoich brakach.
                //
                // Samej granicy tu NIE zmieniamy i zmieniać nie wolno:
                // cudzy przepis jest daną osoby, która go napisała
                // (patrz `collections()` niżej). Zmienia się wyłącznie to,
                // czy paczka o niej mówi.
                //
                // TO POLE JEST BEZWARUNKOWE — i tu jest inaczej niż
                // w `index.html`, gdzie to samo zdanie stoi pod warunkiem
                // niepustego zeszytu. Różnica jest zamierzona. `index.html`
                // czyta CZŁOWIEK i opisuje mu, co w TEJ paczce jest, więc
                // zdanie o cudzych przepisach przy pustym zeszycie opisuje
                // nieobecne. `czego_nie_zawiera` czyta PROGRAM i jest opisem
                // REGUŁY eksportu, nie zawartości tego jednego archiwum —
                // dokładnie jak `zdjec_jeszcze_w_przygotowaniu`, które też
                // jest zawsze, także gdy wynosi zero. Klucz pojawiający się
                // tylko czasem zmuszałby czytający program do zgadywania,
                // czy granicy nie ma, czy paczkę zbudowała starsza wersja.
                // Trzecia granica dołożona po #692, po TEJ SAMEJ stronie
                // opisanej niżej linii co dwie poprzednie — i z tego samego
                // powodu. To jest REGUŁA eksportu, nie cecha tego jednego
                // archiwum: zdjęcie odrzucone albo skasowane nie wejdzie do
                // ŻADNEJ paczki, także przyszłej, niezależnie od tego, czy
                // akurat to konto ma dziś takie zdjęcie. Ile ich jest w TEJ
                // paczce, mówią dwa liczniki niżej; czego paczka nie niesie
                // NIGDY, mówi to zdanie. Gdyby stało pod warunkiem, program
                // czytający paczkę konta bez odrzuconych musiałby zgadywać,
                // czy reguły nie ma, czy tylko nie było czego liczyć.
                //
                // Zakresu danych to nie rusza: nie dokładamy ani jednego
                // zdjęcia, ani powodu odrzucenia, ani identyfikatora.
                'czego_nie_zawiera' => 'Danych kontaktowych innych osób. Komentarze innych ludzi mają treść, datę i nazwę wyświetlaną autora, bez adresu e-mail i bez identyfikatora konta. '
                    // #953: poświadczenia i dane wydawane tylko na żądanie.
                    // Lista tych drugich, z powodami, stoi w `kategorie_poza_paczka`.
                    .'Nie ma tu hasła, kodów weryfikacji dwuetapowej ani żadnych kluczy do logowania — nie wydajemy ich nikomu. Nie ma też danych wymienionych w „kategorie_poza_paczka”: każda ma tam powód, a wydajemy je na Twoją prośbę (patrz „jak_uzyskac_pozostale”). Tak samo na prośbę wydajemy wewnętrzne notatki moderacji i obsługi Twoich wiadomości. '
                    .'Nie ma tu też pełnej treści cudzych przepisów odłożonych do zeszytu: z każdego z nich jest tytuł, autor, Twoja notatka i data zapisania, bez składników, kroków i zdjęć — bo to są dane osób, które te przepisy napisały. '
                    .'Nie ma też przepisów ani wpisów z zeszytu, których ich autorzy już Ci nie pokazują — każdy zeszyt podaje tylko, ile takich pozycji jest, bez tytułów, autorów i Twoich notatek. '
                    .'Nie ma tu również zdjęć, których nie udało się przygotować do pokazania w serwisie, ani zdjęć skasowanych — te nie wejdą do żadnej paczki, także późniejszej.',
                // Paczka realizuje art. 15 RAZEM z drogą na żądanie, nie sama (#953).
                'podstawa_prawna' => 'RODO art. 15 (dostęp do danych) i art. 20 (przenoszenie danych). Kopię z art. 15 dopełniają dane z „kategorie_poza_paczka”, wydawane na prośbę.',
                // Pole jest ZAWSZE, także gdy wynosi zero. Klucz pojawiający
                // się tylko przy brakach zmusiłby program czytający paczkę do
                // zgadywania, czy zera nie ma, bo braków nie było, czy dlatego,
                // że paczkę zbudowała starsza wersja serwisu (issue #113).
                'zdjec_jeszcze_w_przygotowaniu' => $photos->stillProcessingCount(),
                // Oba pola ZAWSZE, także gdy wynoszą zero — ta sama reguła
                // i to samo uzasadnienie co wiersz wyżej (issue #113).
                //
                // Osobno, a nie w jednej sumie, bo to są dwa różne fakty
                // o koncie i dwa różne zdania dla człowieka: odrzucone
                // wolno wgrać jeszcze raz, skasowane są skasowane.
                // Program, który chce tylko „ile brakuje", doda je sobie;
                // program, który dostałby sumę, nie rozdzieli jej nigdy.
                'zdjec_odrzuconych_przy_przygotowaniu' => $photos->rejectedCount(),
                'zdjec_skasowanych' => $photos->deletedCount(),
                // #953 — rozstrzygnięcia z `InwentarzDanychKonta`, nie lista
                // przepisana z palca: nowa pozycja `NA_ZADANIE` pojawi się tu
                // sama. Pole jest ZAWSZE, bo opisuje regułę, nie to konto.
                'kategorie_poza_paczka' => InwentarzDanychKonta::kategoriePozaPaczka(),
                'jak_uzyskac_pozostale' => 'Napisz do nas przez formularz '.route('kontakt').' albo na adres '.config('kuking.community.contact_email').' z adresu przypisanego do konta. Odpowiemy najpóźniej w ciągu miesiąca.',
                // Art. 15 ust. 1 lit. a–h i ust. 2: cele, odbiorcy, okresy,
                // prawa. To są informacje o przetwarzaniu, nie dane — mają
                // jedno aktualne miejsce, więc paczka do niego odsyła.
                'informacje_o_przetwarzaniu' => 'Cele i podstawy przetwarzania, odbiorców, okresy przechowywania, źródła danych i Twoje prawa opisuje polityka prywatności: '.route('privacy'),
            ],
            'konto' => $this->account($user),
            'profil' => $this->profile($user, $photos),
            'przepisy' => $this->recipes($user, $photos),
            'wpisy' => $this->posts($user, $photos),
            'ugotowalem' => $this->cookedEvents($user, $photos),
            'moje_komentarze' => $this->ownComments($user),
            'kolekcje' => $this->collections($user),
            'obserwuje' => $this->people($user->following()->with('profile')->get()),
            'obserwuja_mnie' => $this->people($user->followers()->with('profile')->get()),
            'zablokowane_osoby' => $this->people($user->blocking()->with('profile')->get()),
            'powiadomienia' => $this->notifications($user),
            'zdjecia' => $this->photos($photos),
            // Kategorie dopisane w #953 — każda odpowiada wpisowi `EKSPORT`
            // w `InwentarzDanychKonta`, pilnuje tego test inwentarza.
            'wersje_przepisow' => $this->recipeVersions($user),
            'obserwowane_tagi' => $this->followedTags($user),
            'dziennik_zgod' => $this->consentLog($user),
            'polaczone_konta' => $this->externalIdentities($user),
            'aktywne_sesje' => $this->activeSessions($user),
            'zmiana_adresu_email' => $this->pendingEmailChanges($user),
            'wyslane_podsumowania_tygodnia' => $this->digestSends($user),
            'zamowione_paczki' => $this->dataExports($user),
            'zdarzenia_w_serwisie' => $this->productSignals($user),
            'wiadomosci_do_serwisu' => $this->contactMessages($user),
            'moje_zgloszenia' => $this->ownReports($user),
            'decyzje_moderacji' => $this->moderationDecisions($user),
            'odwolania' => $this->appeals($user),
        ];
    }

    /** @return array<string, mixed> */
    private function account(User $user): array
    {
        return [
            'email' => $user->email,
            'konto_utworzone' => $this->date($user->created_at),
            'email_potwierdzony' => $this->date($user->email_verified_at),
            'wiek_potwierdzony' => $this->date($user->age_confirmed_at),
            'status_konta' => $user->status,
            'jezyk' => $user->locale,
            'rozmiar_tekstu_procent' => $user->text_scale,
            'chce_podsumowania_tygodnia' => (bool) $user->wants_weekly_digest,
            'usuniecie_konta_zgloszone' => $this->date($user->delete_requested_at),
            // Znacznik ostatniej wizyty (issue #114/#115) — dana osobowa
            // tak samo jak reszta tego bloku, więc wchodzi do paczki RODO
            // z tego samego powodu co `usuniecie_konta_zgloszone` wyżej.
            // Nadpisywany throttlowanym middlewarem
            // (`App\Domain\Analytics\ZanotujOstatniaWizyte`), nie logiem —
            // paczka pokazuje tu wyłącznie NAJNOWSZĄ znaną wartość.
            'ostatnio_widziany' => $this->date($user->ostatnio_widziany_at),
            'stan_zachety_instalacji' => $user->pwa_prompt_state,
            // Kolumny `users` dopisane w #953 — `InwentarzDanychKonta::KOLUMNY_KONTA`.
            'rola' => $user->role,
            'status_konta_do' => $this->date($user->status_expires_at),
            'motyw' => $user->theme,
            'wspomnienia_wlaczone' => (bool) $user->memories_enabled,
            'ostatnie_podsumowanie_tygodnia_wyslano' => $this->date($user->weekly_digest_sent_at),
            'zakres_usuniecia' => $user->delete_scope,
            'dane_wymazane' => $this->date($user->data_erased_at),
            // Sam fakt i data włączenia — sekret i kody zapasowe nie wychodzą.
            'weryfikacja_dwuetapowa_od' => $this->date($user->two_factor_confirmed_at),
            'konto_zmienione' => $this->date($user->updated_at),
        ];
    }

    /** @return array<string, mixed>|null */
    private function profile(User $user, ExportPhotoPlan $photos): ?array
    {
        $profile = $user->profile;

        if ($profile === null) {
            return null;
        }

        return [
            'nazwa_uzytkownika' => $profile->username,
            'nazwa_wyswietlana' => $profile->display_name,
            'o_mnie' => $profile->bio,
            'okolica' => $profile->region,
            'w_czym_jestem_dobra' => $profile->speciality,
            'zdjecie_profilowe' => $photos->pathFor($profile->avatar_media_id),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function recipes(User $user, ExportPhotoPlan $photos): array
    {
        // Bez `published()` — szkic to też przepis użytkownika.
        // Sortujemy po dacie, którą użytkownik WIDZI w paczce (publikacji,
        // a dla szkicu — utworzenia), żeby „po kolei” zgadzało się z datami.
        $recipes = $user->recipes()
            ->with(['ingredients.ingredient', 'ingredients.unit', 'steps', 'comments.replies.author.profile', 'comments.author.profile'])
            ->orderByRaw('coalesce(published_at, created_at)')
            ->get();

        return $recipes->map(fn (Recipe $recipe): array => [
            'tytul' => $recipe->title,
            'adres_w_serwisie' => $recipe->slug,
            'plik_do_czytania' => 'przepisy/'.ExportFileNames::recipeFile($recipe),
            'krotki_opis' => $recipe->summary,
            'porcje' => $recipe->servings,
            'przygotowanie_minuty' => $recipe->prep_minutes,
            'gotowanie_minuty' => $recipe->cook_minutes,
            'trudnosc' => $recipe->difficulty,
            'widocznosc' => $recipe->visibility,
            'status' => $recipe->status,
            'skad_przepis' => $recipe->source_type,
            'skad_przepis_opis' => Recipe::SOURCE_LABELS[$recipe->source_type] ?? null,
            'zrodlo_adres' => $recipe->source_url,
            'od_kogo' => $recipe->source_person,
            'notatka_o_zrodle' => $recipe->source_note,
            'w_rodzinie_od_roku' => $recipe->family_since_year,
            'zdjecie_glowne' => $photos->pathFor($recipe->hero_media_id),
            'skan_zeszytu' => $photos->pathFor($recipe->source_scan_media_id),
            'utworzono' => $this->date($recipe->created_at),
            'opublikowano' => $this->date($recipe->published_at),
            'skladniki' => $recipe->ingredients->map(fn ($item): array => [
                'grupa' => $item->group_name,
                // `ingredient_text` to dokładnie to, co wpisał człowiek
                // („2 szklanki mąki”). Rozbite pola są obok, dla programów.
                'zapis' => $item->ingredient_text,
                'ile' => $item->quantity,
                'jednostka' => $item->unit?->name,
                'skladnik_ze_slownika' => $item->ingredient?->canonical_name,
                'uwaga' => $item->note,
            ])->all(),
            'kroki' => $recipe->steps->map(fn ($step): array => [
                // W bazie `position` liczy się od zera — w eksporcie numerujemy
                // kroki tak, jak czyta je człowiek: od jedynki.
                'numer' => $step->position + 1,
                'opis' => $step->instruction,
                'minutnik_sekundy' => $step->timer_seconds,
                'zdjecie' => $photos->pathFor($step->media_id),
            ])->all(),
            'ile_razy_ugotowany_przez_innych' => $recipe->cookedEvents()->count(),
            'komentarze' => $this->foreignComments($recipe->comments),
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function posts(User $user, ExportPhotoPlan $photos): array
    {
        // Bez `published()` i bez filtra widoczności — wpis prywatny należy
        // do użytkownika dokładnie tak samo jak publiczny.
        $posts = $user->posts()
            ->with(['media', 'recipe', 'tags', 'comments.replies.author.profile', 'comments.author.profile'])
            ->orderByRaw('coalesce(published_at, created_at)')
            ->get();

        return $posts->map(fn (Post $post): array => [
            // Pytanie może nie mieć opisu — wtedy tytuł jest całą wypowiedzią
            // autora (#832). Oba pola osobno, bez sklejania w `tresc`.
            'rodzaj' => $post->kind,
            'tytul' => $post->title,
            'tresc' => $post->body,
            'widocznosc' => $post->visibility,
            'status' => $post->status,
            'utworzono' => $this->date($post->created_at),
            'opublikowano' => $this->date($post->published_at),
            'dotyczy_przepisu' => $post->recipe?->title,
            'tagi' => $post->tags->map(fn ($tag): array => [
                'id' => $tag->getKey(),
                'nazwa' => $tag->name,
                'slug' => $tag->slug,
                'dodany_recznie' => $tag->pivot->dodany_recznie === true,
            ])->all(),
            'zdjecia' => $post->media
                ->map(fn ($photo) => $photos->pathFor((string) $photo->getKey()))
                ->filter()
                ->values()
                ->all(),
            'komentarze' => $this->foreignComments($post->comments),
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function cookedEvents(User $user, ExportPhotoPlan $photos): array
    {
        // `reorder` zamiast `orderBy`: relacja `cookedEvents()` ma już własne
        // sortowanie malejące, a dopisanie kolejnej kolumny by go nie zmieniło.
        $events = $user->cookedEvents()
            ->with(['media', 'recipe.author.profile', 'comments.replies.author.profile', 'comments.author.profile'])
            ->reorder('cooked_at')
            ->get();

        return $events->map(fn (CookedEvent $event): array => [
            'przepis' => $event->recipe?->title,
            'autor_przepisu' => $event->recipe?->author?->displayName(),
            'kiedy' => $this->date($event->cooked_at),
            'notatka' => $event->note,
            'zrobie_jeszcze_raz' => $event->would_make_again,
            'moja_ocena_trudnosci' => $event->perceived_difficulty,
            'ile_zajelo_minut' => $event->actual_minutes,
            'co_zmienilam' => $event->changes_note,
            'zdjecia' => $event->media
                ->map(fn ($photo) => $photos->pathFor((string) $photo->getKey()))
                ->filter()
                ->values()
                ->all(),
            'komentarze' => $this->foreignComments($event->comments),
        ])->all();
    }

    /**
     * Komentarze napisane PRZEZ użytkownika — także te pod cudzymi treściami.
     *
     * @return list<array<string, mixed>>
     */
    private function ownComments(User $user): array
    {
        $comments = Comment::query()
            ->where('author_id', $user->getKey())
            ->with(['post.author.profile', 'recipe.author.profile', 'cookedEvent.recipe', 'cookedEvent.user.profile'])
            ->orderBy('created_at')
            ->get();

        return $comments->map(function (Comment $comment) use ($user): array {
            $subject = $comment->subject();

            return [
                'tresc' => $comment->body,
                'napisano' => $this->date($comment->created_at),
                'status' => $comment->status,
                'pod_czym' => match (true) {
                    $subject instanceof Post => 'wpis: '.($subject->recipe?->title ?? mb_substr((string) $subject->body, 0, 60)),
                    $subject instanceof Recipe => 'przepis: '.$subject->title,
                    $subject instanceof CookedEvent => 'wykonanie przepisu: '.($subject->recipe?->title ?? '—'),
                    default => null,
                },
                'czyje_to_bylo' => $this->subjectOwnerName($subject, $user),
            ];
        })->all();
    }

    /**
     * Zeszyty — i OBIE rzeczy, które w nich stoją.
     *
     * Zeszyt przyjmuje dwa rodzaje pozycji, nie jeden: przepisy ORAZ wpisy
     * (migracja `2026_09_06_150000_collection_items_accept_posts`, akcja
     * `SavePostToCollection`, `docs/DATABASE.md` — „collection_items —
     * przepisy ORAZ wpisy"). Do 8 września paczka niosła wyłącznie przepisy
     * i robiła to po cichu: w `dane.json` nie było ani pozycji, ani liczby,
     * ani zdania o tym, że połowy zeszytu brakuje. Człowiek, który odłożył
     * czterdzieści cudzych zdjęć „na kiedyś", dostawał plik wyglądający na
     * kompletny — a taki jest gorszy niż brak eksportu.
     *
     * ZAPISANE WPISY PRZECHODZĄ PRZEZ TĘ SAMĄ GRANICĘ CO EKRAN ZESZYTU
     * `widoczneDla()` plus `dostepnyJakoAutor()` — dokładnie ten sam filtr,
     * który stoi w `CollectionController::show()`, i z tego samego powodu co
     * `visibleTo()` przy powiadomieniach niżej: **to jest ta sama granica,
     * nie druga jej wersja.** Zeszyt to pojemnik na CUDZE treści. Wpis
     * zapisany wtedy, gdy autor pokazywał go obserwującym, przestaje być
     * widoczny po zaprzestaniu obserwowania — a paczka ZIP zostaje na dysku
     * na zawsze i da się ją komuś wysłać. Wypisanie treści, której w serwisie
     * już nie widać, byłoby trwałym wyjęciem cudzego tekstu z jego ustawień.
     *
     * Własne wpisy przechodzą przez ten filtr ZAWSZE, także prywatne i szkice
     * (`Post::scopeWidoczneDla` zaczyna od `posts.author_id = widz`), a
     * `dostepnyJakoAutor()` stosujemy wyłącznie do CUDZYCH autorów — inaczej
     * właścicielka w karencji (`pending_delete`, paczkę wolno wtedy pobrać)
     * straciłaby w paczce własne pozycje. Nic swojego nikomu tu nie ubywa.
     *
     * ILE FILTR SCHOWAŁ — MÓWIMY WPROST, TAK JAK EKRAN
     * `wpisow_juz_niewidocznych` i `przepisow_juz_niewidocznych` są ZAWSZE,
     * także gdy wynoszą zero — ta sama
     * reguła co przy `zdjec_jeszcze_w_przygotowaniu` (issue #113): klucz
     * pojawiający się tylko przy brakach zmusza czytającego do zgadywania,
     * czy zera nie ma, bo braków nie było, czy dlatego, że paczkę zbudowała
     * starsza wersja serwisu. Ekran zeszytu mówi „ile, nie czego" — paczka
     * mówi to samo.
     *
     * ZAPISANE PRZEPISY — TA SAMA GRANICA (#1017)
     * Do 24 września przepisy ładowały się bez filtra: cudzy przepis zmieniony
     * na „Tylko ja", ukryty przez moderację, objęty blokadą albo należący do
     * zbanowanego lub zamykanego konta znikał z ekranu zeszytu, a w paczce
     * dalej stał tytuł i podpis autora. Teraz obie relacje przechodzą przez
     * `widoczneDla()` i `dostepnyJakoAutor()`. Własne przepisy (także
     * prywatne i szkice) przechodzą zawsze — również gdy konto właścicielki
     * jest w karencji, bo filtr autora dotyczy tylko cudzych przepisów. Pozycji w `collection_items`
     * NIE kasujemy: gdy autor znów udostępni przepis, wraca w następnej
     * paczce, tak jak na ekranie.
     *
     * Notatka przy niewidocznej pozycji też nie wychodzi — ani przy wpisie,
     * ani przy przepisie. Samotna notatka („bigos Zenka, mniej kminku")
     * potrafi zdradzić, czego dotyczyła, więc pozycja zostaje wyłącznie
     * w liczniku `przepisow_juz_niewidocznych`. Notatka nie ginie: leży dalej
     * w zeszycie i wraca razem z przepisem.
     *
     * CZEGO TU NIE MA: ZDJĘĆ Z ZAPISANYCH WPISÓW
     * `ExportPhotoPlan` chodzi wyłącznie po `$user->media()`, więc zdjęcie
     * z cudzego wpisu nie ma w paczce nazwy pliku i `pathFor()` zwróciłby dla
     * niego `null`. To jest wybór, nie przeoczenie: paczka RODO oddaje dane
     * TEJ osoby, a cudze zdjęcie nią nie jest — pobranie go do archiwum
     * użytkownika wyjęłoby je z serwisu na stałe.
     *
     * @return list<array<string, mixed>>
     */
    private function collections(User $user): array
    {
        $collections = $user->collections()
            ->with([
                // Ta sama bramka co przy wpisach niżej i co na ekranie
                // zeszytu (`CollectionController::show()`) — issue #1017.
                //
                // Własne pozycje omijają `dostepnyJakoAutor()`: w karencji
                // (`pending_delete`) paczkę wolno zamówić i pobrać, a wtedy
                // filtr autora wyciąłby właścicielce JEJ WŁASNY przepis
                // z notatką i policzył go jako „już Ci nie pokazują".
                'recipes' => fn ($zapytanie) => $zapytanie
                    ->widoczneDla($user)
                    ->where(fn ($q) => $q
                        ->where('recipes.author_id', $user->getKey())
                        ->orWhereHas('author', fn ($autor) => $autor->dostepnyJakoAutor()))
                    ->with('author.profile'),
                'posts' => fn ($zapytanie) => $zapytanie
                    ->widoczneDla($user)
                    ->where(fn ($q) => $q
                        ->where('posts.author_id', $user->getKey())
                        ->orWhereHas('author', fn ($autor) => $autor->dostepnyJakoAutor()))
                    ->with('author.profile'),
            ])
            // Liczba WSZYSTKICH zapisanych wpisów, bez filtra widoczności,
            // także skasowanych — tak jak `posts_total_count` na ekranie
            // zeszytu. Różnica między nią a liczbą wypisanych pozycji to
            // dokładnie to, co filtr schował — i to jest liczba, którą
            // paczka podaje.
            ->withCount(['posts as posts_total_count' => fn ($q) => $q->withTrashed()])
            // Liczba WSZYSTKICH zapisanych przepisów, także skasowanych —
            // tak liczy ekran zeszytu (`recipes_total_count`), więc przepis
            // usunięty przez autora też wychodzi tu jako brak, a nie znika.
            ->withCount(['recipes as recipes_total_count' => fn ($q) => $q->withTrashed()])
            ->orderBy('created_at')
            ->get();

        return $collections->map(fn (Collection $collection): array => [
            'nazwa' => $collection->name,
            'opis' => $collection->description,
            'widocznosc' => $collection->visibility,
            'domyslna' => (bool) $collection->is_default,
            'utworzono' => $this->date($collection->created_at),
            'przepisy' => $collection->recipes->map(fn (Recipe $recipe): array => [
                'tytul' => $recipe->title,
                'autor' => $recipe->author?->displayName(),
                'moja_notatka' => $recipe->pivot->note ?? null,
                'zapisano' => $this->date($recipe->pivot->created_at ?? null),
            ])->all(),
            'wpisy' => $collection->posts->map(fn (Post $post): array => [
                // Danie nie ma tytułu — jego treść JEST jego tożsamością,
                // więc skrócenie jej zostawiłoby pozycję nie do rozpoznania.
                // Pytanie ma tytuł i może nie mieć opisu, więc idzie też
                // tytuł (#832). Zakres jest ten sam co przy cudzych
                // komentarzach niżej: treść, data i nazwa wyświetlana.
                // Nigdy e-mail, nigdy identyfikator konta.
                'tytul' => $post->title,
                'tresc' => $post->body,
                'autor' => $post->author?->displayName() ?? 'Konto usunięte',
                'opublikowano' => $this->date($post->published_at),
                'moja_notatka' => $post->pivot->note ?? null,
                'zapisano' => $this->date($post->pivot->created_at ?? null),
            ])->all(),
            'przepisow_juz_niewidocznych' => max(
                0,
                (int) ($collection->recipes_total_count ?? 0) - $collection->recipes->count(),
            ),
            'wpisow_juz_niewidocznych' => max(
                0,
                (int) ($collection->posts_total_count ?? 0) - $collection->posts->count(),
            ),
        ])->all();
    }

    /**
     * Lista innych osób (obserwowani, obserwujący, zablokowani).
     *
     * Tylko nazwa wyświetlana i publiczna nazwa użytkownika — bez e-maila
     * i bez identyfikatora konta. Bez nazwy wyświetlanej lista byłaby
     * bezużyteczna („obserwujesz 34 osoby, nie powiemy które”).
     *
     * @param  iterable<User>  $users
     * @return list<array<string, mixed>>
     */
    private function people(iterable $users): array
    {
        $out = [];

        foreach ($users as $person) {
            $out[] = [
                'nazwa_wyswietlana' => $person->displayName(),
                'nazwa_uzytkownika' => $person->profile?->username,
            ];
        }

        return $out;
    }

    /**
     * Komentarze INNYCH osób pod treścią użytkownika.
     *
     * To jedyne miejsce, w którym do paczki trafiają cudze wypowiedzi.
     * Przepisujemy dokładnie trzy rzeczy: treść, datę i nazwę wyświetlaną.
     * Nic więcej — żadnego e-maila, żadnego identyfikatora.
     *
     * @param  iterable<Comment>  $comments
     * @return list<array<string, mixed>>
     */
    private function foreignComments(iterable $comments): array
    {
        $out = [];

        foreach ($comments as $comment) {
            $out[] = [
                'autor' => $comment->author?->displayName() ?? 'Konto usunięte',
                'tresc' => $comment->body,
                'napisano' => $this->date($comment->created_at),
                'odpowiedzi' => $this->foreignComments($comment->replies),
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function notifications(User $user): array
    {
        // `visibleTo()` TAK SAMO JAK NA EKRANIE — to jest ta sama granica,
        // nie druga jej wersja.
        //
        // Bez tego paczka niosła powiadomienia, których człowiek w serwisie
        // NIE WIDZI: od kont zbanowanych, od kont po prośbie o usunięcie,
        // od osób wzajemnie zablokowanych, oraz o komentarzach pod treścią,
        // która zniknęła. Razem z nimi wychodziło pole `excerpt` — 120
        // znaków CUDZEGO tekstu, w tym tekstu z konta, które prosiło
        // o usunięcie.
        //
        // Paczka RODO ma oddać człowiekowi to, co jego — nie wszystko, co
        // o nim leży w bazie. Powiadomienie ukryte w serwisie nie staje się
        // jego danymi przez to, że kiedyś powstał na nie wiersz.
        $notifications = $user->notifications()
            ->visibleTo($user)
            ->with('actor.profile')
            ->reorder('created_at')
            ->get();

        // WYCINEK KOMENTARZA JEST ZYWY - ISSUE #758, decyzja wlasciciela
        // z 20 wrzesnia 2026 (D-229). Paczka ma pokazywac dane, ktore DZIS
        // o kims trzymamy, a nie ich historyczna wersje: zamrozony wycinek
        // opisywalby stan, ktorego w bazie juz nie ma. Jedno zapytanie na
        // CALY eksport, nie jedno na powiadomienie - pozycji bywa tu wiecej
        // niz trzydziesci mieszczace sie na ekranie (D-196).
        $wycinki = Notification::zyweWycinkiKomentarzy($notifications);

        return $notifications->map(function ($notification) use ($wycinki): array {
            $data = is_array($notification->data) ? $notification->data : [];
            $szczegoly = array_intersect_key($data, array_flip(self::NOTIFICATION_DATA_KEYS));

            if (in_array($notification->type, Notification::TYPY_Z_WYCINKIEM_KOMENTARZA, true)) {
                // Zamrozony `excerpt` ze starych wierszy NIE wychodzi
                // z paczki nawet jako plan zapasowy: brak wycinka w mapie
                // znaczy "komentarz usuniety albo ukryty", czyli dokladnie
                // ten przypadek, w ktorym tresci pokazac nie wolno (#757).
                unset($szczegoly['excerpt']);

                $zywy = $wycinki[(string) $notification->getKey()] ?? null;

                if ($zywy !== null) {
                    $szczegoly['excerpt'] = $zywy;
                }
            }

            return [
                'rodzaj' => $notification->type,
                'kiedy' => $this->date($notification->created_at),
                'przeczytane' => $notification->read_at !== null,
                'od_kogo' => $notification->actor?->displayName(),
                'szczegoly' => $szczegoly,
            ];
        })->all();
    }

    /** @return list<array<string, mixed>> */
    private function photos(ExportPhotoPlan $plan): array
    {
        return $plan->photos()->map(fn ($photo): array => [
            'plik' => $plan->pathFor((string) $photo->getKey()),
            'opis_alternatywny' => $photo->alt_text,
            'wgrano' => $this->date($photo->created_at),
            'szerokosc' => $photo->width,
            'wysokosc' => $photo->height,
            'rozmiar_bajty' => $photo->bytes,
            'typ' => $photo->mime_type,
        ])->all();
    }

    /*
     * KATEGORIE DOPISANE W #953.
     *
     * Każda metoda niżej czyta WYMIENIONE kolumny (`select`), a nie cały
     * wiersz. To jest ta sama zasada co lista dozwolonych kluczy przy
     * powiadomieniach: kolumna dołożona kiedyś do tabeli — choćby token —
     * nie wyjdzie do paczki przez to, że ktoś zrobił `SELECT *`.
     */

    /**
     * Wcześniejsze wersje własnych przepisów (`SnapshotRecipeVersion`).
     *
     * Migawka to treść, którą ta osoba sama napisała, więc wychodzi cała.
     *
     * @return list<array<string, mixed>>
     */
    private function recipeVersions(User $user): array
    {
        return DB::table('recipe_versions')
            ->join('recipes', 'recipes.id', '=', 'recipe_versions.recipe_id')
            ->where('recipe_versions.editor_id', $user->getKey())
            ->orderBy('recipe_versions.created_at')
            ->orderBy('recipe_versions.version_number')
            ->get(['recipes.title', 'recipe_versions.version_number', 'recipe_versions.change_note', 'recipe_versions.created_at', 'recipe_versions.snapshot'])
            ->map(fn (object $wersja): array => [
                'przepis' => $wersja->title,
                'numer_wersji' => (int) $wersja->version_number,
                'notatka_o_zmianie' => $wersja->change_note,
                'zapisano' => $this->date($wersja->created_at),
                'tresc_wersji' => json_decode((string) $wersja->snapshot, true),
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function followedTags(User $user): array
    {
        return DB::table('tag_follows')
            ->join('tags', 'tags.id', '=', 'tag_follows.tag_id')
            ->where('tag_follows.user_id', $user->getKey())
            ->orderBy('tag_follows.created_at')
            ->get(['tags.name', 'tags.slug', 'tag_follows.created_at'])
            ->map(fn (object $tag): array => [
                'nazwa' => $tag->name,
                'slug' => $tag->slug,
                'obserwuje_od' => $this->date($tag->created_at),
            ])->all();
    }

    /**
     * Dziennik zgód (D-072) — każda decyzja osobno, także wycofania.
     *
     * @return list<array<string, mixed>>
     */
    private function consentLog(User $user): array
    {
        return DB::table('dziennik_zgod')
            ->where('user_id', $user->getKey())
            ->orderBy('wystapilo_at')
            ->orderBy('id')
            ->get(['cel', 'czynnosc', 'zrodlo', 'wersja_polityki', 'wystapilo_at'])
            ->map(fn (object $wpis): array => [
                'cel' => $wpis->cel,
                'czynnosc' => $wpis->czynnosc,
                'skad' => $wpis->zrodlo,
                'wersja_polityki' => $wpis->wersja_polityki,
                'kiedy' => $this->date($wpis->wystapilo_at),
            ])->all();
    }

    /**
     * Połączenia z kontem Google/Facebooka. Tabela nie trzyma tokenów
     * (polityka prywatności, sekcja o logowaniu), a `select` pilnuje, żeby
     * kolumna dołożona kiedyś obok nie wyszła tu sama.
     *
     * @return list<array<string, mixed>>
     */
    private function externalIdentities(User $user): array
    {
        return DB::table('tozsamosci_zewnetrzne')
            ->where('user_id', $user->getKey())
            ->orderBy('connected_at')
            ->get(['dostawca', 'identyfikator', 'connected_at', 'dostep_odebrany_at'])
            ->map(fn (object $polaczenie): array => [
                'dostawca' => $polaczenie->dostawca,
                'identyfikator_u_dostawcy' => $polaczenie->identyfikator,
                'polaczono' => $this->date($polaczenie->connected_at),
                'dostep_odebrany' => $this->date($polaczenie->dostep_odebrany_at),
            ])->all();
    }

    /**
     * Sesje w bazie: adres IP, przeglądarka i ostatnia aktywność.
     *
     * Bez identyfikatora sesji i bez `payload` — identyfikator JEST kluczem
     * do zalogowanej sesji, a payload to jej wewnętrzny stan (w tym token
     * CSRF). Adres IP i przeglądarka to dane tej osoby i pozwalają jej
     * zauważyć logowanie, którego nie rozpoznaje.
     *
     * @return list<array<string, mixed>>
     */
    private function activeSessions(User $user): array
    {
        return DB::table('sessions')
            ->where('user_id', $user->getKey())
            ->orderBy('last_activity')
            ->get(['ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $sesja): array => [
                'adres_ip' => $sesja->ip_address,
                'przegladarka' => $sesja->user_agent,
                'ostatnia_aktywnosc' => Carbon::createFromTimestamp((int) $sesja->last_activity)->toIso8601String(),
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function pendingEmailChanges(User $user): array
    {
        return DB::table('pending_email_changes')
            ->where('user_id', $user->getKey())
            ->orderBy('created_at')
            ->get(['new_email', 'created_at', 'expires_at'])
            ->map(fn (object $zmiana): array => [
                'nowy_adres' => $zmiana->new_email,
                'zgloszono' => $this->date($zmiana->created_at),
                'wazne_do' => $this->date($zmiana->expires_at),
            ])->all();
    }

    /** @return list<string|null> */
    private function digestSends(User $user): array
    {
        return DB::table('weekly_digest_sends')
            ->where('user_id', $user->getKey())
            ->orderBy('week_start')
            ->pluck('week_start')
            ->map(fn ($tydzien): ?string => $tydzien === null ? null : (string) $tydzien)
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function dataExports(User $user): array
    {
        return DB::table('data_exports')
            ->where('user_id', $user->getKey())
            ->orderBy('created_at')
            ->get(['status', 'created_at', 'completed_at', 'expires_at'])
            ->map(fn (object $paczka): array => [
                'status' => $paczka->status,
                'zamowiono' => $this->date($paczka->created_at),
                'gotowa' => $this->date($paczka->completed_at),
                'do_pobrania_do' => $this->date($paczka->expires_at),
            ])->all();
    }

    /**
     * Zdarzenia z zamkniętego słownika `product_signals` (retencja 90 dni).
     * `properties` z definicji nie niesie treści ani adresów (`ProductSignal`).
     *
     * @return list<array<string, mixed>>
     */
    private function productSignals(User $user): array
    {
        return DB::table('product_signals')
            ->where('user_id', $user->getKey())
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['signal_name', 'properties', 'occurred_at'])
            ->map(fn (object $sygnal): array => [
                'rodzaj' => $sygnal->signal_name,
                'szczegoly' => json_decode((string) $sygnal->properties, true) ?: [],
                'kiedy' => $this->date($sygnal->occurred_at),
            ])->all();
    }

    /**
     * Wiadomości z „Napisz do nas" i nasze odpowiedzi, które naprawdę wyszły.
     * Bez notatki obsługującego i bez tego, kto obsługiwał (`NA_ZADANIE`).
     *
     * @return list<array<string, mixed>>
     */
    private function contactMessages(User $user): array
    {
        $wiadomosci = DB::table('contact_messages')
            ->where('user_id', $user->getKey())
            ->orderBy('created_at')
            ->get(['id', 'kind', 'message', 'contact_email', 'page_path', 'status', 'created_at']);

        $odpowiedzi = DB::table('contact_message_replies')
            ->whereIn('contact_message_id', $wiadomosci->pluck('id'))
            ->where('status', ContactMessageReply::STATUS_WYSLANA)
            ->orderBy('sent_at')
            ->get(['contact_message_id', 'body', 'sent_at'])
            ->groupBy('contact_message_id');

        return $wiadomosci->map(fn (object $wiadomosc): array => [
            'rodzaj' => $wiadomosc->kind,
            'tresc' => $wiadomosc->message,
            'adres_do_odpowiedzi' => $wiadomosc->contact_email,
            'ze_strony' => $wiadomosc->page_path,
            'status' => $wiadomosc->status,
            'wyslano' => $this->date($wiadomosc->created_at),
            'nasze_odpowiedzi' => ($odpowiedzi[$wiadomosc->id] ?? collect())
                ->map(fn (object $odpowiedz): array => [
                    'tresc' => $odpowiedz->body,
                    'wyslano' => $this->date($odpowiedz->sent_at),
                ])->values()->all(),
        ])->all();
    }

    /**
     * Zgłoszenia, które ta osoba wysłała. Bez identyfikatora zgłoszonej
     * treści (to cudza treść) i bez notatki moderatora.
     *
     * @return list<array<string, mixed>>
     */
    private function ownReports(User $user): array
    {
        return DB::table('reports')
            ->where('reporter_id', $user->getKey())
            ->orderBy('created_at')
            ->get(['numer_sprawy', 'target_type', 'reason', 'details', 'illegality_explanation', 'notifier_name', 'notifier_email', 'status', 'created_at', 'resolved_at'])
            ->map(fn (object $zgloszenie): array => [
                'numer_sprawy' => $zgloszenie->numer_sprawy,
                'co_zgloszono' => $zgloszenie->target_type,
                'powod' => $zgloszenie->reason,
                'szczegoly' => $zgloszenie->details,
                'wyjasnienie_bezprawnosci' => $zgloszenie->illegality_explanation,
                'podpis_zglaszajacego' => $zgloszenie->notifier_name,
                'adres_zglaszajacego' => $zgloszenie->notifier_email,
                'status' => $zgloszenie->status,
                'wyslano' => $this->date($zgloszenie->created_at),
                'rozpatrzono' => $this->date($zgloszenie->resolved_at),
            ])->all();
    }

    /**
     * Decyzje moderacji dotyczące tej osoby — z treścią, którą jej pokazano
     * (`user_message`). Bez moderatora i bez jego notatki (`NA_ZADANIE`).
     *
     * @return list<array<string, mixed>>
     */
    private function moderationDecisions(User $user): array
    {
        return DB::table('moderation_actions')
            ->where('subject_user_id', $user->getKey())
            ->orderBy('created_at')
            ->get(['target_type', 'action', 'reason_code', 'user_message', 'created_at'])
            ->map(fn (object $decyzja): array => [
                'czego_dotyczy' => $decyzja->target_type,
                'decyzja' => $decyzja->action,
                'powod' => $decyzja->reason_code,
                'wiadomosc_dla_mnie' => $decyzja->user_message,
                'kiedy' => $this->date($decyzja->created_at),
            ])->all();
    }

    /**
     * Odwołania tej osoby. `decision_note` to uzasadnienie, które jej
     * wysłaliśmy (`NotifyAppealOutcome`), więc wychodzi; kto decydował — nie.
     *
     * @return list<array<string, mixed>>
     */
    private function appeals(User $user): array
    {
        return DB::table('appeals')
            ->where('user_id', $user->getKey())
            ->orderBy('created_at')
            ->get(['appellant', 'body', 'status', 'decision_note', 'decided_at', 'created_at'])
            ->map(fn (object $odwolanie): array => [
                'jako' => $odwolanie->appellant,
                'tresc' => $odwolanie->body,
                'status' => $odwolanie->status,
                'uzasadnienie_decyzji' => $odwolanie->decision_note,
                'wyslano' => $this->date($odwolanie->created_at),
                'rozpatrzono' => $this->date($odwolanie->decided_at),
            ])->all();
    }

    private function subjectOwnerName(Post|Recipe|CookedEvent|null $subject, User $user): ?string
    {
        $owner = match (true) {
            $subject instanceof Post, $subject instanceof Recipe => $subject->author,
            $subject instanceof CookedEvent => $subject->user,
            default => null,
        };

        if ($owner === null) {
            return null;
        }

        return $owner->getKey() === $user->getKey() ? 'moje' : $owner->displayName();
    }

    /**
     * Data w ISO 8601. Przyjmuje też łańcuch, bo kolumny z tabel pośrednich
     * (`collection_items.created_at`) nie przechodzą przez casty modelu.
     */
    private function date(mixed $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        if ($date instanceof Carbon) {
            return $date->toIso8601String();
        }

        try {
            return Carbon::parse((string) $date)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}
