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

        $this->unfollowUser->handle($request->user(), $target);

        return back()->with('status', 'Nie obserwujesz już '.$target->displayName().'.');
    }

    public function block(Request $request, string $username): RedirectResponse
    {
        $target = $this->findUser($username);

        try {
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

        $this->unblockUser->handle($request->user(), $target, $request->ip());

        return back()->with('status', 'Blokada zdjęta.');
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
                $query->withExists(['followers as obserwowany' => fn ($f) => $f->where('users.id', $widzId)]);
            })
            ->orderByPivot('created_at', 'desc')
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
