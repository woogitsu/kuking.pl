<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Konteksty adresów komentarzy dla CAŁEJ strony powiadomień naraz.
 *
 * CO BYŁO ZEPSUTE (zmierzone na `main`, `PowiadomieniaOKomentarzachBezWachlarzaZapytanTest`)
 * `Notification::urlDoKomentarza()` (issue #759) liczyło wszystko per wiersz:
 * odbiorcę (`$this->user`), komentarz, treść nadrzędną (`subject()`), korzeń
 * wątku przy odpowiedzi oraz DWA zapytania na widoczność i pozycję korzenia.
 * Pięć zapytań na wiersz: **16 zapytań przy 2 powiadomieniach i 106 przy 20**.
 * Strona mieści trzydzieści. To jest koszt PRODUKCYJNY, na ekranie, na który
 * zalogowany człowiek wchodzi najczęściej ze wszystkich.
 *
 * DLACZEGO NIE DA SIĘ TEGO ZAPISAĆ PRZY PUBLIKACJI
 * Numer strony zależy od tego, ile wątków przed tym konkretnym jest WIDOCZNYCH
 * DLA ODBIORCY w chwili kliknięcia — pełne uzasadnienie stoi przy
 * `Notification::urlDoKomentarza()`. Liczymy więc dalej przy wyświetleniu,
 * tylko raz na stronę zamiast raz na wiersz.
 *
 * DLACZEGO FILTR I KOLEJNOŚĆ BIERZEMY Z RELACJI, A NIE PISZEMY OD NOWA
 * `Post::comments()` / `Recipe::comments()` / `CookedEvent::comments()`
 * (`whereNull('parent_id')`, `status=published`, `oldest()->orderBy('id')`)
 * plus `Comment::scopeWidoczneDla()` — to są warunki, według których kontroler
 * RENDERUJE stronę wątku. Druga, ręcznie przepisana kopia rozjechałaby się
 * przy pierwszej zmianie i dawałaby adres do strony, na której tego wątku nie
 * ma. Dlatego każda podkwerenda niżej powstaje z `$tresc->comments()
 * ->widoczneDla($odbiorca)` i dokłada do niej WYŁĄCZNIE numerowanie wierszy.
 *
 * DLACZEGO `row_number()`, A NIE `count()` NA WIERSZ
 * Pozycja korzenia to dokładnie numer wiersza w tej samej kolejności, w której
 * kontroler stronicuje. Jedno okno liczy ją dla wszystkich korzeni tej treści,
 * a złączenie `union all` po treściach mieści całą stronę w JEDNYM zapytaniu.
 * Zewnętrzne `where id in (…)` pilnuje, żeby z bazy wróciło tyle wierszy, ile
 * jest powiadomień — nie tyle, ile jest komentarzy pod popularnym wpisem.
 */
final class KontekstyKomentarzy
{
    /**
     * Wpisuje konteksty w podane powiadomienia. Po tym wywołaniu
     * `adresDocelowy()` na tych wierszach nie dotyka już bazy.
     *
     * @param  iterable<Notification>  $powiadomienia
     */
    public static function przypisz(iterable $powiadomienia, User $odbiorca): void
    {
        $lista = [];

        foreach ($powiadomienia as $powiadomienie) {
            $lista[] = $powiadomienie;
        }

        $konteksty = self::dla($lista, $odbiorca);

        foreach ($lista as $powiadomienie) {
            if (! self::dotyczyKomentarza($powiadomienie)) {
                continue;
            }

            $powiadomienie->przypiszKontekstKomentarza($konteksty[(string) $powiadomienie->getKey()] ?? null);
        }
    }

    /**
     * @param  list<Notification>  $powiadomienia
     * @return array<string, KontekstKomentarza>
     */
    public static function dla(array $powiadomienia, User $odbiorca): array
    {
        // 1. Identyfikatory komentarzy — tylko z wierszy, które o komentarzu mówią.
        $komentarzeWierszy = [];

        foreach ($powiadomienia as $powiadomienie) {
            if (! self::dotyczyKomentarza($powiadomienie)) {
                continue;
            }

            $id = ($powiadomienie->data ?? [])['comment_id'] ?? null;

            if (is_string($id) && $id !== '') {
                $komentarzeWierszy[(string) $powiadomienie->getKey()] = $id;
            }
        }

        if ($komentarzeWierszy === []) {
            return [];
        }

        // 2. Komentarze wraz z treścią nadrzędną i korzeniem wątku — komplet
        //    dociągnięć jest STAŁY, niezależny od liczby powiadomień.
        //    `parent` starczy zamiast całej ścieżki: wątki są jednopoziomowe
        //    (`Comment::replies()` wisi wprost na korzeniu).
        $komentarze = Comment::query()
            ->with(['post', 'recipe', 'cookedEvent', 'parent'])
            ->whereKey(array_values(array_unique($komentarzeWierszy)))
            ->get()
            ->keyBy(fn (Comment $komentarz): string => (string) $komentarz->getKey());

        // 3. Grupowanie korzeni po treści — jedna podkwerenda na treść.
        /** @var array<string, array{tresc: Post|Recipe|CookedEvent, korzenie: array<string, true>}> $grupy */
        $grupy = [];
        /** @var array<string, array{komentarz: Comment, tresc: Post|Recipe|CookedEvent, korzen: Comment}> $wiersze */
        $wiersze = [];

        foreach ($komentarzeWierszy as $powiadomienieId => $komentarzId) {
            $komentarz = $komentarze->get($komentarzId);

            if ($komentarz === null) {
                continue;
            }

            $tresc = $komentarz->subject();

            if ($tresc === null) {
                continue;
            }

            $korzen = $komentarz->parent_id === null ? $komentarz : $komentarz->parent;

            if ($korzen === null) {
                continue;
            }

            $kluczTresci = $tresc::class.':'.$tresc->getKey();
            $grupy[$kluczTresci] ??= ['tresc' => $tresc, 'korzenie' => []];
            $grupy[$kluczTresci]['korzenie'][(string) $korzen->getKey()] = true;

            $wiersze[$powiadomienieId] = ['komentarz' => $komentarz, 'tresc' => $tresc, 'korzen' => $korzen];
        }

        if ($wiersze === []) {
            return [];
        }

        $pozycje = self::pozycjeKorzeni($grupy, $odbiorca);

        // 4. Złożenie kontekstów. Brak korzenia w wyniku znaczy „niewidoczny
        //    dla TEGO odbiorcy" — tak samo jak dawne `exists()` na fałsz.
        $konteksty = [];

        foreach ($wiersze as $powiadomienieId => $wiersz) {
            $korzenId = (string) $wiersz['korzen']->getKey();
            $widoczny = array_key_exists($korzenId, $pozycje);

            $konteksty[$powiadomienieId] = new KontekstKomentarza(
                komentarz: $wiersz['komentarz'],
                tresc: $wiersz['tresc'],
                korzenWidoczny: $widoczny,
                // „Ugotowałem" nie stronicuje komentarzy
                // (`CookedEventController::show()` ładuje je wszystkie naraz),
                // więc numer strony nie ma tam sensu i nie powstaje.
                pozycjaKorzenia: $widoczny && ! ($wiersz['tresc'] instanceof CookedEvent)
                    ? $pozycje[$korzenId]
                    : null,
            );
        }

        return $konteksty;
    }

    /**
     * Pozycje WIDOCZNYCH korzeni (licząc od zera) — jedno zapytanie na całą
     * stronę. Korzeń niewidoczny dla tego odbiorcy po prostu nie wraca.
     *
     * @param  array<string, array{tresc: Post|Recipe|CookedEvent, korzenie: array<string, true>}>  $grupy
     * @return array<string, int>
     */
    private static function pozycjeKorzeni(array $grupy, User $odbiorca): array
    {
        $zapytanie = null;

        foreach ($grupy as $grupa) {
            $wewnetrzne = $grupa['tresc']->comments()->widoczneDla($odbiorca)->toBase();

            // Kolejność przenosimy z `order by` do okna: to ta sama kolejność
            // (`oldest()->orderBy('id')`), ale jako numerowanie wierszy.
            // Sortowanie podkwerendy byłoby po niej pracą bez odbiorcy.
            $wewnetrzne->orders = null;
            $wewnetrzne->columns = null;
            $wewnetrzne->selectRaw(
                'comments.id as id, row_number() over (order by comments.created_at, comments.id) - 1 as pozycja',
            );

            $czesc = DB::query()
                ->fromSub($wewnetrzne, 'korzenie')
                ->select('korzenie.id', 'korzenie.pozycja')
                ->whereIn('korzenie.id', array_keys($grupa['korzenie']));

            $zapytanie = $zapytanie === null ? $czesc : $zapytanie->unionAll($czesc);
        }

        if (! $zapytanie instanceof QueryBuilder) {
            return [];
        }

        $pozycje = [];

        foreach ($zapytanie->get() as $wiersz) {
            $pozycje[(string) $wiersz->id] = (int) $wiersz->pozycja;
        }

        return $pozycje;
    }

    private static function dotyczyKomentarza(Notification $powiadomienie): bool
    {
        return in_array($powiadomienie->type, [Notification::TYPE_COMMENT, Notification::TYPE_REPLY], true);
    }
}
