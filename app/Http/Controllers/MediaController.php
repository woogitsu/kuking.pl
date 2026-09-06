<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Media\DostepDoZdjecia;
use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serwowanie zdjęcia: pytamy Policy, potem przekierowujemy na podpisany
 * adres w buckecie (audyt W7-02).
 *
 * DLACZEGO PRZEKIEROWANIE, A NIE STRUMIEŃ
 * Bajty nie mają przechodzić przez PHP: jedno zdjęcie z feedu to kilkaset
 * kilobajtów, a jedna strona feedu potrafi ich mieć kilkadziesiąt. Proces PHP
 * zajęty przepisywaniem obrazka to proces, który nie obsługuje nikogo innego.
 *
 * Wariantu „serwer oddaje plik po nagłówku od aplikacji" (`X-Accel-Redirect`)
 * NIE MA I NIE BĘDZIE: przed PHP stoi Caddy, nie nginx, a Caddy takiego
 * mechanizmu nie zna. Stąd 302 na adres podpisany kluczem S3, ważny kilka
 * minut — konfiguracja `kuking.media.signed_url_minutes`.
 *
 * ODMOWA TO 404, NIE 403
 * I ma wyglądać dokładnie tak samo jak zdjęcie, którego nie ma. 403 na cudzym
 * zdjęciu odpowiada na pytanie, którego nikt nie miał prawa zadać: „czy taki
 * plik istnieje". Przy skanie odręcznej kartki z nazwiskami sama odpowiedź
 * „istnieje, ale nie dla ciebie" jest już informacją. Ten sam wzorzec co
 * `Settings\DataSettingsController::download`.
 *
 * TRASA JEST PUBLICZNA (poza `auth`) — zdjęcie publicznego przepisu musi się
 * otwierać bez konta, tak jak sam przepis. Ochroną nie jest logowanie, tylko
 * `DostepDoZdjecia` plus limit zapytań z `config/kuking.php`.
 */
class MediaController extends Controller
{
    public function __construct(private readonly DostepDoZdjecia $dostep) {}

    public function show(Request $request, Media $media, string $wariant): Response
    {
        $widz = $request->user();

        // KOLEJNOŚĆ MA ZNACZENIE. Najpierw pytanie „czy ten widz w ogóle ma
        // prawo do tych bajtów", dopiero potem cokolwiek o pliku. Odwrotna
        // kolejność zamieniłaby czas odpowiedzi w kanał informacyjny:
        // „istnieje" odpowiadałoby wolniej niż „nie istnieje".
        abort_unless($this->dostep->moze($widz, $media), 404);

        $wybrany = $media->wariantDoSerwowania($wariant);

        abort_if($wybrany === null, 404);

        // Czy to zdjęcie zobaczyłby ktoś NIEZALOGOWANY. To jest jedyne
        // pytanie, które rozstrzyga o nagłówku cache: odpowiedź wspólną dla
        // wszystkich wolno trzymać we wspólnym cache, odpowiedź zależną od
        // tego, kto pyta — nie wolno nigdzie. Zdjęcie przepisu „followers"
        // z `public, max-age` w cache Cloudflare byłoby tym samym wyciekiem,
        // który ta zmiana naprawia, tylko o warstwę wyżej.
        $publiczne = $widz === null
            ? true
            : $this->dostep->moze(null, $media);

        $dysk = Storage::disk($media->variantsDisk());

        $naglowki = $publiczne
            ? [
                // Krótko, nie „na rok". Widoczność treści potrafi się zmienić
                // w każdej chwili — autor przełącza przepis na prywatny, ktoś
                // kogoś blokuje, moderator ukrywa wpis. `max-age` jest górnym
                // ograniczeniem na to, jak długo taka zmiana może nie dojść
                // do skutku.
                'Cache-Control' => 'public, max-age='.$this->sekundyCache(),
            ]
            : [
                // `no-store`, nie samo `private`: `private` pozwala jeszcze
                // przeglądarce zapisać plik na dysku, a to jest dokładnie ten
                // rodzaj kopii, który zostaje po wylogowaniu na współdzielonym
                // komputerze.
                'Cache-Control' => 'private, no-store',
            ];

        if (! $dysk->providesTemporaryUrls()) {
            // DYSK LOKALNY — praca lokalna i testy. Nie ma tam ani S3, ani
            // podpisów, więc jedyną drogą jest oddanie pliku przez PHP.
            // Na produkcji ta gałąź nie działa: `r2_publiczne` to sterownik
            // `s3`, który podpisy ma. Zostawiamy ją mimo to, bo alternatywą
            // byłoby „zdjęcia nie wyświetlają się na dysku lokalnym", czyli
            // środowisko deweloperskie różniące się od produkcji akurat
            // w miejscu, którego nikt by wtedy nie testował.
            abort_unless($dysk->exists($wybrany['klucz']), 404);

            return $dysk->response($wybrany['klucz'], headers: $naglowki);
        }

        return redirect()->away(
            $dysk->temporaryUrl($wybrany['klucz'], now()->addMinutes($this->minutyWaznosci())),
            302,
            $naglowki,
        );
    }

    private function minutyWaznosci(): int
    {
        return max(1, (int) config('kuking.media.signed_url_minutes'));
    }

    /**
     * Jak długo wolno trzymać w cache SAMO PRZEKIEROWANIE.
     *
     * KRÓCEJ NIŻ ŻYJE PODPIS, I TO NIE JEST OSTROŻNOŚĆ NA ZAPAS.
     *
     * Przeglądarka cache'uje odpowiedź 302 razem z jej nagłówkiem `Location`,
     * czyli razem z konkretnym, już podpisanym adresem. Gdyby `max-age`
     * równał się ważności podpisu, klient, który dostał przekierowanie
     * w pierwszej sekundzie okna, mógłby użyć go ponownie w ostatniej —
     * i poszedłby po adres ważny jeszcze przez chwilę albo już wygasły.
     * Objaw: pusta ramka zamiast zdjęcia, znikająca po odświeżeniu, czyli
     * najgorszy rodzaj usterki — taki, którego nie da się powtórzyć na
     * żądanie.
     *
     * Połowa okna daje każdemu przekierowaniu wyjętemu z cache co najmniej
     * tyle samo czasu życia, ile już przeżyło. Pilnuje tego test
     * `ZdjeciaChronioneNieWyciekajaTest`, i pilnuje REGUŁY (krócej niż
     * podpis), nie tej konkretnej liczby.
     */
    private function sekundyCache(): int
    {
        return max(1, intdiv(60 * $this->minutyWaznosci(), 2));
    }
}
