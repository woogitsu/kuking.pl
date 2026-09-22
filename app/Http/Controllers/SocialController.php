<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use App\Domain\Social\Actions\UnblockUser;
use App\Domain\Social\Actions\UnfollowUser;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SocialController extends Controller
{
    public function __construct(
        private readonly FollowUser $followUser,
        private readonly UnfollowUser $unfollowUser,
        private readonly BlockUser $blockUser,
        private readonly UnblockUser $unblockUser,
    ) {}

    public function follow(Request $request, string $username): RedirectResponse
    {
        $target = $this->findUser($username);

        // SPRAWDZENIE TOŻSAMOŚCI PRZED `authorize()`, NIE PO NIM.
        //
        // Gdy nazwa zmieniła właściciela, Policy pyta o OSOBĘ, na którą nikt
        // nie patrzył — i przy koncie zawieszonym odpowiedziałaby 403
        // „to konto jest niedostępne". Człowiek zobaczyłby wtedy błąd o cudzym
        // koncie zamiast prawdy o swoim formularzu. Najpierw więc mówimy, co
        // się naprawdę stało, a dopiero potem pytamy, czy wolno.
        try {
            $this->assertToTaSamaOsoba($request, $target);
        } catch (BladDlaCzlowieka $e) {
            return back()->withErrors(['follow' => $e->getMessage()]);
        }

        $this->authorize('follow', $target);

        try {
            $followed = $this->followUser->handle($request->user(), $target);
        } catch (BladDlaCzlowieka $e) {
            return back()->withErrors(['follow' => $e->getMessage()]);
        }

        return back()->with('status', $followed
            ? 'Obserwujesz '.$target->displayName().'. Nowe wpisy pojawią się na Twojej stronie głównej.'
            : 'Już obserwujesz tę osobę.');
    }

    public function unfollow(Request $request, string $username): RedirectResponse
    {
        $target = $this->findUser($username);

        try {
            // ODOBSERWOWANIE TEŻ, CHOĆ WYGLĄDA NIEGROŹNIE.
            //
            // „Przestań obserwować" nie tworzy niczego, więc łatwo uznać, że
            // trafienie w cudze konto nic tu nie kosztuje. Kosztuje: żądanie
            // pod starą nazwą CICHO KASUJE relację z osobą, którą widz
            // obserwuje naprawdę i świadomie — tę, która akurat zajęła
            // zwolnioną nazwę. Człowiek klika „przestań obserwować Anię",
            // dostaje „Nie obserwujesz już Ani" i traci z feedu Basię.
            $this->assertToTaSamaOsoba($request, $target);
        } catch (BladDlaCzlowieka $e) {
            return back()->withErrors(['follow' => $e->getMessage()]);
        }

        $this->unfollowUser->handle($request->user(), $target);

        return back()->with('status', 'Nie obserwujesz już '.$target->displayName().'.');
    }

    public function block(Request $request, string $username): RedirectResponse
    {
        $target = $this->findUser($username);

        try {
            $this->assertToTaSamaOsoba($request, $target);
            $this->blockUser->handle($request->user(), $target, $request->ip());
        } catch (BladDlaCzlowieka $e) {
            return back()->withErrors(['block' => $e->getMessage()]);
        }

        return redirect()->route('home')->with('status',
            'Zablokowano '.$target->displayName().'. Nie zobaczycie już wzajemnie swoich treści.',
        );
    }

    public function unblock(Request $request, string $username): RedirectResponse
    {
        $target = $this->findUser($username);

        try {
            $this->assertToTaSamaOsoba($request, $target);
            $this->unblockUser->handle($request->user(), $target, $request->ip());
        } catch (BladDlaCzlowieka $e) {
            return back()->withErrors(['block' => $e->getMessage()]);
        }

        // #791: `UnblockUser` świadomie NIE przywraca obserwowania (patrz
        // komentarz w tej klasie) — automatyczny powrót do obserwowania
        // byłby niespodzianką w prywatności. Ale bez słowa o tym w komunikacie
        // człowiek klika „Zdejmij blokadę”, oczekuje powrotu do stanu sprzed
        // konfliktu i dowiaduje się o różnicy dopiero wtedy, gdy zauważy,
        // że w swoim feedzie znów nie widzi tej osoby.
        return back()->with('status',
            'Blokada zdjęta. Możecie znów widzieć swoje treści, ale obserwowanie się nie wznawia samo — jeśli chcesz znów obserwować tę osobę, wejdź na jej profil i kliknij „Obserwuj”.',
        );
    }

    /**
     * Nazwa użytkownika w adresie formularza to NIE AUTORYZACJA (#793) —
     * może zmienić właściciela między chwilą, w której formularz się
     * wyrenderował, a chwilą, w której ktoś go wysłał.
     *
     * Username da się zwolnić (zmiana w Ustawieniach) i od razu ponownie
     * zająć: `UsernameNotTaken` sprawdza tylko AKTUALNE zajęcie, nie
     * historię. Stary, wciąż otwarty formularz pod `/@stara-nazwa/...` po
     * takiej zmianie trafia więc w kogoś INNEGO, niż widział człowiek, który
     * formularz otworzył — a ten człowiek nie ma jak się o tym dowiedzieć,
     * bo strona nie krzyczy błędem, tylko cicho robi coś innego, niż
     * pokazywała.
     *
     * OBEJMUJE WSZYSTKIE CZTERY AKCJE TEGO KONTROLERA, nie same blokady.
     * #793 zamknęło tę lukę tylko dla „Zablokuj”/„Zdejmij blokadę” i zostawiło
     * obserwowanie jako osobną decyzję o zakresie — bo relacja jest
     * odwracalna i nie zostawia śladu u drugiej strony. To prawda o WADZE,
     * nie o klasie błędu: identyfikator w formularzu dalej nie jest
     * autoryzacją, a „zaczynam obserwować obcego człowieka” to dokładnie ten
     * skutek, przed którym #793 broniło. Warunek jest jednolinijkowy
     * i wspólny, więc trzymanie połowy formularzy poza nim kosztowałoby
     * więcej niż objęcie ich wszystkich.
     *
     * Każdy formularz relacji/blokady nosi więc ukryte pole `oczekiwany_id`
     * z identyfikatorem osoby widzianej w chwili renderowania. Pole jest
     * OPCJONALNE (starsze wywołania API i istniejące testy go nie wysyłają —
     * Policy i tak broni samej akcji), ale kiedy jest obecne, MUSI się
     * zgadzać z osobą, którą naprawdę rozwiązuje dzisiejsza nazwa
     * użytkownika.
     *
     * Hurtowy odpowiednik tego pola stoi w `OnboardingController::saveFollows()`
     * (`oczekiwani[nazwa] => id`) — tamten formularz wskazuje osoby nazwami,
     * ale nie przez adres trasy, więc nie da się go obsłużyć tą metodą.
     */
    private function assertToTaSamaOsoba(Request $request, User $target): void
    {
        $oczekiwanyId = $request->input('oczekiwany_id');

        if ($oczekiwanyId === null) {
            return;
        }

        if ((string) $target->getKey() !== (string) $oczekiwanyId) {
            throw new BladDlaCzlowieka(
                'Ta nazwa użytkownika należy teraz do innej osoby. Odśwież stronę i spróbuj ponownie.',
            );
        }
    }

    /** Lista osób, które obserwują dany profil: /@{username}/obserwujacy */
    public function followers(Request $request, string $username): Response
    {
        return $this->connections($request, $username, 'followers', 'Obserwujący');
    }

    /** Lista osób, które dany profil obserwuje: /@{username}/obserwowani */
    public function following(Request $request, string $username): Response
    {
        return $this->connections($request, $username, 'following', 'Obserwowani');
    }

    /**
     * Wspólna logika obu list relacji.
     *
     * Blokada w KTÓRĄKOLWIEK stronę chowa osobę z listy — stąd
     * `hasBlockRelationWith()`, a nie sam `blocking()`. Blokada między
     * obserwującym a obserwowanym zwykle i tak kasuje follow (patrz
     * BlockUser), ale to nie zwalnia z filtrowania: na liście może się
     * znaleźć ktoś, kogo zablokował akurat OSOBA OGLĄDAJĄCA listę, a nie
     * właściciel profilu.
     */
    private function connections(Request $request, string $username, string $relation, string $title): Response
    {
        $target = $this->findUser($username);
        $this->authorize('viewProfile', $target);

        $viewer = $request->user();

        // FILTR BLOKAD W ZAPYTANIU, NIE W PHP.
        //
        // Wcześniej lista była paginowana, a dopiero potem odsiewana w PHP
        // przez `hasBlockRelationWith()`, czyli osobny `SELECT EXISTS`
        // na każdą osobę — dwadzieścia zapytań na stronę. Do tego widok pytał
        // `isFollowing()` per wiersz: razem około czterdziestu.
        //
        // Cichszy skutek był gorszy od tamtego: filtr działał PO paginacji,
        // więc licznik i liczba stron liczyły także osoby odfiltrowane. Strona
        // pokazywała siedemnaście osób i mówiła, że jest ich dwadzieścia,
        // a ostatnia strona potrafiła wyjść pusta. Licznik, który nie zgadza
        // się z listą, wygląda jak zepsuty serwis.
        /** @var LengthAwarePaginator $paginator */
        $paginator = $target->{$relation}()
            // KONTO ZAMKNIĘTE NIE MA PRAWA STAĆ NA LIŚCIE OSÓB.
            //
            // `UserPolicy::viewProfile()` daje 403 pod adresem tej osoby (chyba
            // że patrzy moderator), ale to zapytanie budowało listę bez tego
            // warunku — miało już filtr blokad, nie miało `dostepnyJakoAutor()`.
            // Skutek: karta z awatarem, wyświetlaną nazwą i linkiem do profilu,
            // który — kliknięty wprost — daje 403. Ta sama klasa błędu co
            // wpis zbanowanego autora w feedzie obserwowanych (commit 964b99c)
            // i co W5-08: konto mniej dostępne przez drzwi frontowe niż przez
            // okno. `ban()`/`markForDeletion()` nie kasują wierszy z `follows`,
            // więc bez tego warunku wiersz zostaje na liście na zawsze.
            //
            // `widocznyJakoOsoba()`, NIE `dostepnyJakoAutor()` — od D-022 te
            // dwie granice się rozjeżdżają. Zanonimizowany PRZEPIS konta
            // `erased` ma zostać widoczny; KARTA OSOBY z awatarem, linkiem
            // do profilu i przyciskiem „Obserwuj" — nie ma, bo obserwować
            // nie da się już nikogo (`UserPolicy::follow()` wymaga
            // `isActive()`), a lista obserwujących nie jest archiwum.
            ->widocznyJakoOsoba()
            ->with('profile.avatar')
            ->when($viewer !== null, function ($query) use ($viewer): void {
                $widzId = $viewer->getKey();

                $query->whereNotExists(function ($sub) use ($widzId): void {
                    $sub->selectRaw('1')
                        ->from('blocks')
                        ->where(function ($w) use ($widzId): void {
                            $w->where('blocks.blocker_id', $widzId)
                                ->whereColumn('blocks.blocked_id', 'users.id');
                        })
                        ->orWhere(function ($w) use ($widzId): void {
                            $w->whereColumn('blocks.blocker_id', 'users.id')
                                ->where('blocks.blocked_id', $widzId);
                        });
                });

                // „Czy widz obserwuje tę osobę" — jednym zapytaniem dla całej
                // strony zamiast jednego na wiersz. Widok czyta `obserwowany`.
                //
                // KWALIFIKACJA KOLUMNY JEST TU CAŁĄ ODPOWIEDZIĄ, NIE DETALEM
                // (#648). `followers` to relacja User→User, więc podzapytanie
                // sięga po tę samą tabelę co zapytanie zewnętrzne i Eloquent
                // MUSI ją w środku przemianować (`users as laravel_reserved_0`).
                // Po tej zamianie `users.id` wewnątrz podzapytania nie wskazuje
                // już obserwującego, tylko WIERSZ ZEWNĘTRZNY — czyli osobę
                // z listy. Warunek cicho zmieniał się w „czy ta osoba to widz,
                // i czy ktokolwiek ją obserwuje", więc bywał prawdziwy najwyżej
                // na jednym wierszu: własnym wierszu widza, który widok i tak
                // rysuje jako „To Ty". Skutek nie zostawiał ani jednego błędu,
                // a był całkowity: obie listy pokazywały „Obserwuj" przy każdej
                // osobie, także zaraz po udanym kliknięciu i po świeżym wejściu
                // na stronę.
                //
                // `follows.follower_id` to kolumna TABELI POŚREDNIEJ, której
                // Eloquent w tym podzapytaniu NIE przemianowuje; złączenie
                // przyrównuje ją do klucza osoby obserwującej, więc pytanie
                // wraca do „czy to WIDZ obserwuje tę osobę".
                //
                // NA CZYM TO STOI — ŻEBY NASTĘPNY CZYTELNIK NIE MUSIAŁ ZGADYWAĆ.
                // Podzapytanie ma własne `follows` o tej samej nazwie co tabela
                // pośrednia zapytania zewnętrznego i PRZYSŁANIA ją. Dopóki tak
                // jest, ten zapis znaczy to, co mówi. Gdyby `follows` dostało
                // kiedyś w środku alias, warunek związałby się z pivotem
                // zewnętrznym i wróciłby błąd TEJ SAMEJ KLASY, BEZ BŁĘDU SQL —
                // zmierzone. Zdegenerowałby się przy tym inaczej na każdej
                // liście: na `obserwujacy` dokładnie w #648 („osoba z listy to
                // widz"), a na `obserwowani` w „gospodarz to widz", czyli
                // jedną stałą odpowiedź dla całej strony. Regresja łapie obie.
                // Wariantem odpornym na taki alias jest
                // `whereKey($widzId)`: wstawia `laravel_reserved_N.id = ?`,
                // czyli wiąże się z aliasem wprost. Wybrano mimo to kolumnę
                // pivotu, bo mówi o KIERUNKU relacji, a `whereKey()` milczy
                // o nim zupełnie; cenę tego wyboru pilnuje regresja
                // `FollowListsTest`, nie ten komentarz.
                //
                // Nazwy relacji nie da się przy tym zamienić „razem z kolumną":
                // `following` + `follows.followed_id` to nie ta sama rzecz
                // napisana inaczej, tylko ODWRÓCONE pytanie („czy ta osoba
                // obserwuje widza"). Też zmierzone, też bez błędu SQL.
                $query->withExists(['followers as obserwowany' => fn ($f) => $f->where('follows.follower_id', $widzId)]);
            })
            ->orderByPivot('created_at', 'desc')
            ->orderByDesc('users.id')
            ->paginate(20)
            ->withQueryString();

        $profile = $target->profile;

        return response()->view('pages.profile.connections', [
            'title' => $title,
            'relation' => $relation,
            'profile' => $profile,
            'people' => $paginator,
        ])->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function findUser(string $username): User
    {
        $profil = Profile::poNazwie($username);

        abort_if($profil === null, 404);

        return $profil->user;
    }
}
