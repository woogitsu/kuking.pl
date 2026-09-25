<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Ukrycia\Actions\UkryjOsobe;
use App\Domain\Ukrycia\Actions\UkryjWpis;
use App\Domain\Ukrycia\Actions\ZmienUkrycie;
use App\Domain\Ukrycia\Ukrycia;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Hide;
use App\Models\Post;
use App\Models\Profile;
use App\Models\User;
use App\Support\Czas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * „Ukryj ten wpis", „Ukryj tę osobę" i lista „Ukryte" w Ustawieniach
 * (issue #1810, D-278).
 *
 * KAŻDY KOMUNIKAT MÓWI „TYLKO DLA CIEBIE". Słowo „ukryj" ma w tym serwisie
 * drugie znaczenie — moderacja ukrywa treść WSZYSTKIM. Bez tych trzech słów
 * człowiek mógłby pomyśleć, że schował komuś wpis przed całym serwisem.
 *
 * Po akcji `status_powrot` z „Cofnij" (nie znika sam), bez „czy na pewno" —
 * ukrycie jest odwracalne jednym kliknięciem, więc pytanie byłoby tylko
 * przeszkodą.
 */
class UkryciaController extends Controller
{
    public function __construct(
        private readonly UkryjWpis $ukryjWpis,
        private readonly UkryjOsobe $ukryjOsobe,
        private readonly ZmienUkrycie $zmien,
        private readonly Ukrycia $ukrycia,
    ) {}

    public function ukryjWpis(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('view', $post);

        try {
            $ukrycie = $this->ukryjWpis->handle($request->user(), $post);
        } catch (BladDlaCzlowieka $e) {
            return back()->withErrors(['ukrycie' => $e->getMessage()]);
        }

        return back()
            ->with('status', 'Ukryliśmy ten wpis tylko dla Ciebie '.$this->doKiedy($ukrycie).'. Inni widzą go jak dotąd.')
            ->with('status_powrot', [
                'akcja' => route('posts.unhide', $post),
                'etykieta' => 'Cofnij',
                'pola' => ['_method' => 'DELETE'],
            ]);
    }

    public function cofnijWpis(Request $request, Post $post): RedirectResponse
    {
        // Wiersz i tak należy do widza (szukany po parze), ale adres wskazuje
        // wpis — więc najpierw Policy (AGENTS.md §7). Wpis, którego widz już
        // nie widzi, przywróci z listy „Ukryte”.
        $this->authorize('view', $post);
        $this->zmien->cofnijWpis($request->user(), $post);

        return back()->with('status', 'Ten wpis znów widzisz.');
    }

    /**
     * Ekran wyboru przed ukryciem osoby: nazwa dosłownie, zakres, „Nie
     * powiadamiamy tej osoby" i droga do blokady albo zgłoszenia, gdy
     * chodzi o coś więcej niż nadmiar wpisów. GET + POST, bez JavaScriptu.
     */
    public function ekranOsoby(Request $request, string $username): View
    {
        $osoba = $this->osoba($username);
        $this->authorize('viewProfile', $osoba);
        abort_if($osoba->getKey() === $request->user()->getKey(), 404);

        return view('pages.ukrycia.osoba', [
            'osoba' => $osoba,
            'obserwuje' => $request->user()->isFollowing($osoba),
            'juzUkryta' => $this->ukrycia->osobaUkryta($request->user(), $osoba),
            'wroc' => $this->bezpiecznyPowrot((string) url()->previous()),
            'dni' => (int) config('kuking.ukrycia.dni'),
            'ostrzezenie' => $this->ukrycia->ostrzezenieOSkali($request->user()),
        ]);
    }

    public function ukryjOsobe(Request $request, string $username): RedirectResponse
    {
        $osoba = $this->osoba($username);
        $this->authorize('viewProfile', $osoba);
        abort_if($osoba->getKey() === $request->user()->getKey(), 404);

        if ($request->filled('oczekiwany_id') && (string) $request->input('oczekiwany_id') !== (string) $osoba->getKey()) {
            return back()->withErrors(['ukrycie' => 'Ta nazwa użytkownika należy teraz do innej osoby. Odśwież stronę i spróbuj ponownie.']);
        }

        try {
            $ukrycie = $this->ukryjOsobe->handle($request->user(), $osoba);
        } catch (BladDlaCzlowieka $e) {
            return back()->withErrors(['ukrycie' => $e->getMessage()]);
        }

        return redirect()->to($this->bezpiecznyPowrot((string) $request->input('wroc')))
            ->with('status', 'Ukryliśmy tę osobę tylko dla Ciebie '.$this->doKiedy($ukrycie).'. Nie powiadamiamy jej o tym.')
            ->with('status_powrot', [
                'akcja' => route('social.unhide', $osoba->profile->username),
                'etykieta' => 'Cofnij',
                'pola' => ['_method' => 'DELETE', 'oczekiwany_id' => (string) $osoba->getKey()],
            ]);
    }

    public function cofnijOsobe(Request $request, string $username): RedirectResponse
    {
        $osoba = $this->osoba($username);

        if ($request->filled('oczekiwany_id') && (string) $request->input('oczekiwany_id') !== (string) $osoba->getKey()) {
            return back()->withErrors(['ukrycie' => 'Ta nazwa użytkownika należy teraz do innej osoby. Odśwież stronę i spróbuj ponownie.']);
        }

        $this->zmien->cofnijOsobe($request->user(), $osoba);

        return back()->with('status', 'Wpisy tej osoby znów widzisz.');
    }

    /** Ustawienia → „Ukryte": osobno wpisy i osoby, data końca, dwie akcje. */
    public function lista(Request $request): View
    {
        $widz = $request->user();
        $wszystkie = Hide::query()
            ->aktywne()
            ->where('user_id', $widz->getKey())
            ->with(['post.author.profile', 'hiddenUser.profile'])
            ->orderByRaw('hidden_until ASC NULLS LAST')
            ->orderByDesc('created_at')
            ->get();

        return view('pages.settings.ukryte', [
            'wpisy' => $wszystkie->whereNotNull('post_id')->values(),
            'osoby' => $wszystkie->whereNotNull('hidden_user_id')->values(),
            'ostrzezenie' => $this->ukrycia->ostrzezenieOSkali($widz),
        ]);
    }

    public function zostaw(Request $request, Hide $hide): RedirectResponse
    {
        $this->authorize('update', $hide);
        $this->zmien->zostawNaStale($hide);

        return back()->with('status', 'Zostaje ukryte tylko dla Ciebie, dopóki tego nie zmienisz.');
    }

    public function przywroc(Request $request, Hide $hide): RedirectResponse
    {
        $this->authorize('delete', $hide);
        $this->zmien->przywroc($hide);

        return back()->with('status', 'Przywrócone. Znów to zobaczysz.');
    }

    private function doKiedy(Hide $ukrycie): string
    {
        return $ukrycie->hidden_until === null
            ? 'na stałe'
            : 'do '.Czas::data($ukrycie->hidden_until, 'j F Y');
    }

    private function osoba(string $username): User
    {
        $profil = Profile::poNazwie($username);
        abort_if($profil === null, 404);

        return $profil->user;
    }

    /**
     * Powrót tylko na własny adres: ścieżka od `/`, nie `//` (inny host).
     * Wszystko inne — Start.
     */
    private function bezpiecznyPowrot(string $adres): string
    {
        $sciezka = parse_url($adres, PHP_URL_PATH);
        $host = parse_url($adres, PHP_URL_HOST);
        $nasz = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($sciezka) || ! str_starts_with($sciezka, '/') || str_starts_with($sciezka, '//')
            || ($host !== null && $host !== $nasz && $host !== request()->getHost())
            || str_contains($sciezka, '/ukryj')) {
            return route('home');
        }

        $zapytanie = parse_url($adres, PHP_URL_QUERY);

        return $sciezka.(is_string($zapytanie) && $zapytanie !== '' ? '?'.$zapytanie : '');
    }
}
