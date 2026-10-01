<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Sharing\KartaZKodemQr;
use App\Models\Profile;
use App\Models\Recipe;
use App\Support\KanonicznyAdresStrony;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * „Karta z kodem QR” publicznego przepisu albo profilu (#2349, F10).
 *
 * AUTORYZACJA (AGENTS.md §7: UUID ani slug w adresie to nie autoryzacja)
 * 1. zwykła Policy oglądającego (`view` / `viewProfile`) — blokada, konto
 *    zbanowane itd. dają 403 jak na samej stronie treści;
 * 2. potem bramka „czy to zobaczy GOŚĆ” (`KartaZKodemQr`) — karta jest do
 *    rozdawania osobom bez konta, więc treść niepubliczna daje 404, także
 *    autorowi. 404, nie 403: nie potwierdzamy, że taki prywatny przepis jest.
 *
 * Adres w kodzie powstaje z modelu, nie z żądania: `?druk=1`, `?utm_…` i inne
 * parametry strony karty nigdy nie trafiają do QR.
 */
class KartaQrController extends Controller
{
    public function __construct(private readonly KartaZKodemQr $karta) {}

    public function przepis(Request $request, string $recipe): View|RedirectResponse
    {
        $przepis = Recipe::where('slug', $recipe)->with('author.profile')->first();

        // Stary adres przepisu działa też dla karty (jak `RecipeController::show`):
        // 301 na aktualny slug. TA SAMA BRAMKA CO POD NOWYM ADRESEM, ale 404
        // zamiast 403 — `Location` zawiera slug z tytułu, więc przepis
        // niedostępny dla oglądającego albo nieoglądalny dla gościa (karta
        // jest do rozdawania) nie może się zdradzić przekierowaniem.
        if ($przepis === null) {
            $przekierowanie = DB::table('recipe_slug_redirects')->where('slug', $recipe)->first();
            abort_if($przekierowanie === null, 404);

            $cel = Recipe::query()->with('author.profile')->findOrFail($przekierowanie->recipe_id);
            abort_unless(Gate::forUser($request->user())->allows('view', $cel), 404);
            abort_unless($this->karta->przepisDostepny($cel), 404);

            return redirect()->route('recipes.qr-card', $cel->slug, 301);
        }

        $this->authorize('view', $przepis);
        abort_unless($this->karta->przepisDostepny($przepis), 404);

        $adres = $this->karta->adresPrzepisu($przepis);

        return view('pages.karta-qr', [
            'tytul' => $przepis->title,
            'rodzaj' => 'przepis',
            'adres' => $adres,
            'kodSvg' => $this->karta->kodSvg($adres),
            'wrocUrl' => route('recipes.show', $przepis->slug),
            'adresKarty' => route('recipes.qr-card', $przepis->slug),
        ]);
    }

    public function profil(Request $request, string $username): View
    {
        $profil = Profile::query()
            ->whereRaw('lower(username) = ?', [mb_strtolower($username)])
            ->with('user')
            ->firstOrFail();

        $this->authorize('viewProfile', $profil->user);
        abort_unless($this->karta->profilDostepny($profil), 404);

        // Canonical strony karty z zapisaną pisownią nazwy, po autoryzacji.
        KanonicznyAdresStrony::ustawSciezke($request, route('profile.qr-card', $profil->username, false));

        $adres = $this->karta->adresProfilu($profil);

        return view('pages.karta-qr', [
            'tytul' => $profil->display_name,
            'rodzaj' => 'profil',
            'adres' => $adres,
            'kodSvg' => $this->karta->kodSvg($adres),
            'wrocUrl' => route('profile.show', $profil->username),
            'adresKarty' => route('profile.qr-card', $profil->username),
        ]);
    }
}
