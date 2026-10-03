<?php

declare(strict_types=1);

namespace App\Domain\Comments;

use App\Domain\Notifications\CelPowiadomienia;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use App\Support\KursorListy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * „Rozmowy, w których piszę” w „Moje” (#2432) — prywatna lista wątków, do
 * których osoba dopisała komentarz lub odpowiedź.
 *
 * ZASADY, KTÓRE TU STOJĄ
 *  - Wątek (korzeń z odpowiedziami) występuje RAZ, pod OSTATNIĄ własną
 *    wypowiedzią, od najnowszej; remis czasu rozstrzyga `id`.
 *  - Do listy wchodzi tylko to, co osoba zobaczy na zwykłym ekranie rozmowy:
 *    opublikowana, nieusunięta wypowiedź (bez śladu „Komentarz usunięty.”),
 *    pod korzeniem widocznym dla niej (`Comment::widoczneDla()`: blokada,
 *    konto autora, status). Wypowiedzi ukrytej przez moderację tu nie ma —
 *    wyjątki dostępu autora do niej (odwołanie) zostają na ekranie zgłoszeń.
 *  - SAM `author_id` I `widoczneDla()` NIE WYSTARCZAJĄ: komentarz dziedziczy
 *    dostęp do treści, pod którą stoi. Dlatego wpis, przepis i „Ugotowałem”
 *    przechodzą przez swoje Policy (`view`) przy KAŻDYM zbudowaniu listy,
 *    a kontekst (tytuł, osoba, fragment) powstaje dopiero po tej bramce.
 *    Niedostępna rozmowa znika bez śladu — bez licznika i bez „ukrytej”
 *    pozycji.
 *  - Adres (strona korzeni, porcja odpowiedzi, kotwica) liczy ten sam kod co
 *    „Zobacz” przy powiadomieniu (`CelPowiadomienia::adresyKomentarzy()`);
 *    nic nie jest zapisywane, więc po drodze nic się nie rozjeżdża.
 *  - Lista idzie porcjami po kursorze, bez wczytywania całej historii.
 *    Wiersze odrzucone przez Policy są pomijane i dobierane z dalszych, więc
 *    porcja jest pełna, o ile coś jeszcze zostało.
 *  - Zero powiadomień, obserwowania wątku i liczników.
 */
final class MojeRozmowy
{
    public const NA_STRONE = 20;

    /** Ile wierszy pobieramy naraz do sprawdzenia Policy i ile razy co najwyżej. */
    private const PACZKA = 40;

    private const MAKS_PACZEK = 5;

    private const WZORZEC_KURSORA = '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z)_([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/';

    public function __construct(private readonly CelPowiadomienia $cel) {}

    /**
     * @param  string|null  $kursor  wartość parametru z adresu; zły kształt lub data = pierwsza porcja
     */
    public function porcja(User $osoba, ?string $kursor = null): StronaRozmow
    {
        $od = self::odczytajKursor($kursor);
        $zebrane = [];
        $wyczerpano = false;
        $ostatniKursor = $od;

        for ($i = 0; $i < self::MAKS_PACZEK && count($zebrane) <= self::NA_STRONE; $i++) {
            $wiersze = $this->kandydaci($osoba, $ostatniKursor, self::PACZKA);

            if ($wiersze === []) {
                $wyczerpano = true;

                break;
            }

            foreach ($this->dostepne($osoba, $wiersze) as $wiersz) {
                $zebrane[] = $wiersz;
            }

            $ostatni = $wiersze[array_key_last($wiersze)];
            $ostatniKursor = [(string) $ostatni->kursor_czas, (string) $ostatni->id];

            if (count($wiersze) < self::PACZKA) {
                $wyczerpano = true;

                break;
            }
        }

        $maWiecej = count($zebrane) > self::NA_STRONE || ! $wyczerpano;
        // Gdy pobrane wiersze nie dały pełnej porcji (same odrzucone przez
        // Policy), kolejna porcja startuje za OSTATNIM SPRAWDZONYM wierszem,
        // nie za ostatnią widoczną pozycją — inaczej ten sam zakres byłby
        // sprawdzany w kółko.
        $kursorNastepny = count($zebrane) > self::NA_STRONE || $ostatniKursor === null
            ? null
            : $ostatniKursor[0].'_'.$ostatniKursor[1];
        $zebrane = array_slice($zebrane, 0, self::NA_STRONE);

        $adresy = $this->cel->adresyKomentarzy(array_map(fn (object $w): string => (string) $w->id, $zebrane), $osoba);

        $pozycje = [];
        foreach ($zebrane as $wiersz) {
            $adres = $adresy[(string) $wiersz->id] ?? null;

            // Bez adresu nie obiecujemy rozmowy, do której nie da się wejść.
            if ($adres === null) {
                continue;
            }

            $pozycje[] = new PozycjaRozmowy(
                idKomentarza: (string) $wiersz->id,
                jestOdpowiedzia: $wiersz->parent_id !== null,
                fragment: Str::limit(trim((string) $wiersz->body), 160),
                kontekst: (string) $wiersz->kontekst,
                data: Carbon::parse((string) $wiersz->created_at),
                adres: $adres,
            );
        }

        $nastepny = null;
        if ($maWiecej) {
            $ostatni = $zebrane === [] ? null : $zebrane[array_key_last($zebrane)];
            $nastepny = $kursorNastepny ?? ($ostatni !== null ? $ostatni->kursor_czas.'_'.$ostatni->id : null);
        }

        return new StronaRozmow($pozycje, $nastepny);
    }

    /**
     * Kursor z adresu (`czas_id`) albo `null` przy złym kształcie lub dacie — zły
     * kursor to pierwsza porcja, nigdy błąd ani zapytanie z cudzą wartością.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function odczytajKursor(?string $kursor): ?array
    {
        if ($kursor === null || preg_match(self::WZORZEC_KURSORA, $kursor, $m) !== 1) {
            return null;
        }

        // Własny format `czas_id` zostaje; wspólna reguła kalendarza chroni
        // PostgreSQL przed datą pasującą do wzorca, ale niemożliwą.
        if (! KursorListy::czasPasuje($m[1])) {
            return null;
        }

        return [$m[1], $m[2]];
    }

    /**
     * Ostatnia widoczna własna wypowiedź KAŻDEGO wątku, od najnowszej.
     * `DISTINCT ON` wybiera ją w bazie (indeks `comments_author_idx`), a
     * kursor działa dopiero po wyborze — inaczej wcześniejsza wypowiedź tego
     * samego wątku wypłynęłaby drugi raz na dalszej stronie.
     *
     * @param  array{0: string, 1: string}|null  $po
     * @return list<object>
     */
    private function kandydaci(User $osoba, ?array $po, int $ile): array
    {
        $widoczneKorzenie = Comment::query()
            ->whereNull('comments.parent_id')
            ->widoczneDla($osoba)
            ->select('comments.id');

        $ostatnie = Comment::query()
            ->join('comments as root', 'root.id', '=', DB::raw('coalesce(comments.parent_id, comments.id)'))
            ->widoczneDla($osoba)
            ->where('comments.author_id', $osoba->getKey())
            ->whereNull('comments.body_removed_at')
            ->whereNull('root.deleted_at')
            ->whereIn('root.id', $widoczneKorzenie)
            ->selectRaw('distinct on (root.id) comments.id, comments.parent_id, comments.post_id, comments.recipe_id, comments.cooked_event_id, comments.body, comments.created_at, '
                ."to_char(comments.created_at at time zone 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') as kursor_czas")
            ->orderBy('root.id')
            ->orderByDesc('comments.created_at')
            ->orderByDesc('comments.id')
            ->toBase();

        $zapytanie = DB::query()->fromSub($ostatnie, 't');

        if ($po !== null) {
            $zapytanie->whereRaw('(t.created_at, t.id) < (?::timestamptz, ?::uuid)', [$po[0], $po[1]]);
        }

        return $zapytanie
            ->orderByDesc('t.created_at')
            ->orderByDesc('t.id')
            ->limit($ile)
            ->get()
            ->all();
    }

    /**
     * Wiersze, których treść osoba może dziś otworzyć (Policy `view` wpisu,
     * przepisu albo wykonania), z gotowym kontekstem. Treści ładowane po
     * jednym zapytaniu na rodzaj, bez zapytania na wiersz.
     *
     * @param  list<object>  $wiersze
     * @return list<object>
     */
    private function dostepne(User $osoba, array $wiersze): array
    {
        $idWpisow = $idPrzepisow = $idWykonan = [];
        foreach ($wiersze as $w) {
            if ($w->post_id !== null) {
                $idWpisow[] = $w->post_id;
            } elseif ($w->recipe_id !== null) {
                $idPrzepisow[] = $w->recipe_id;
            } elseif ($w->cooked_event_id !== null) {
                $idWykonan[] = $w->cooked_event_id;
            }
        }

        $wpisy = $idWpisow === [] ? collect() : Post::query()
            ->with(['author.profile', 'recipe.author', 'media'])
            ->whereIn('id', array_unique($idWpisow))->get()->keyBy(fn (Post $p): string => (string) $p->getKey());
        $przepisy = $idPrzepisow === [] ? collect() : Recipe::query()
            ->with('author.profile')
            ->whereIn('id', array_unique($idPrzepisow))->get()->keyBy(fn (Recipe $r): string => (string) $r->getKey());
        $wykonania = $idWykonan === [] ? collect() : CookedEvent::query()
            ->with(['user.profile', 'recipe.author'])
            ->whereIn('id', array_unique($idWykonan))->get()->keyBy(fn (CookedEvent $c): string => (string) $c->getKey());

        $bramka = Gate::forUser($osoba);
        $wynik = [];

        foreach ($wiersze as $w) {
            $kontekst = null;

            if ($w->post_id !== null) {
                $wpis = $wpisy->get((string) $w->post_id);
                if ($wpis !== null && $bramka->allows('view', $wpis)) {
                    $kontekst = $wpis->kind === Post::KIND_QUESTION
                        ? 'Pytanie: '.$wpis->title
                        : 'Wpis osoby '.($wpis->author?->displayName() ?? 'Użytkownik Kuking');
                }
            } elseif ($w->recipe_id !== null) {
                $przepis = $przepisy->get((string) $w->recipe_id);
                if ($przepis !== null && $bramka->allows('view', $przepis)) {
                    $kontekst = 'Przepis: '.$przepis->title;
                }
            } elseif ($w->cooked_event_id !== null) {
                $wykonanie = $wykonania->get((string) $w->cooked_event_id);
                if ($wykonanie !== null && $bramka->allows('view', $wykonanie)) {
                    // Tytuł przepisu tylko wtedy, gdy osoba może przepis dziś
                    // zobaczyć (blokada z jego autorem nie ma przeciekać tytułem).
                    $przepisWykonania = $wykonanie->recipe;
                    $tytulPrzepisu = $przepisWykonania !== null && $bramka->allows('view', $przepisWykonania)
                        ? ': '.$przepisWykonania->title
                        : '';
                    $kontekst = 'Ugotowane przez '.($wykonanie->user?->displayName() ?? 'Użytkownik Kuking').$tytulPrzepisu;
                }
            }

            if ($kontekst === null) {
                continue;
            }

            $w->kontekst = $kontekst;
            $wynik[] = $w;
        }

        return $wynik;
    }
}
