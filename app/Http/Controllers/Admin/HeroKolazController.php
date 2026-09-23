<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Feed\HeroKolaz;
use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\HeroPick;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Wybór zdjęć do kolażu w hero strony powitalnej.
 *
 * Zgłoszenie właściciela: „dodaj funkcję w panelu admina by ustawiać te
 * zdjęcia spośród wszystkich publicznych od użytkowników".
 *
 * Ekran jest z tej samej rodziny co `/admin/kuking-na-dzis` i świadomie
 * trzyma się tamtej konwencji: jedna strona, lista świeżych wpisów, jedno
 * pole wyboru przy każdym zdjęciu, jeden przycisk „Zapisz" i osobna strefa
 * czyszczenia. Bez kreatora, bez sortowania po popularności i bez ani jednej
 * liczby, która podpowiadałaby wybór — gospodarz ma patrzeć na zdjęcia,
 * nie na słupki.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  BRAMKA WIDOCZNOŚCI JEST W DOMENIE, NIE W REGULE WALIDACJI
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Zapisujemy WYŁĄCZNIE to, co w chwili zapisu przepuszcza
 * `HeroKolaz::dopuszczZdjecia()` — czyli zdjęcia przy wpisach publicznych
 * i opublikowanych, od kont aktywnych, w stanie `ready`. Wszystko inne
 * wypada po cichu z zapisu i gospodarz dostaje o tym zdanie w komunikacie.
 *
 * Sama bramka zapisu NIE WYSTARCZA i nie ma wystarczać: wpis może zmienić
 * widoczność albo autor może zostać zawieszony PO wyborze. Dlatego ten sam
 * filtr liczy się drugi raz przy każdym wyświetleniu strony powitalnej
 * (`HeroKolaz::doKolazu()`). Bramka zapisu jest tu po to, żeby gospodarz nie
 * zapisał wyboru, który i tak nigdy się nie pokaże — ochroną przed wyciekiem
 * jest ten drugi filtr.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  `moderate` Z POLICY, NIE SAMO MIDDLEWARE
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Middleware grupy `/admin` pilnuje wejścia do panelu; o prawie do TEJ akcji
 * rozstrzyga `UserPolicy::moderate` — ten sam wzorzec co w pozostałych
 * kontrolerach panelu. Identyfikatory w formularzu to UUID-y cudzych zdjęć,
 * a UUID nie jest autoryzacją (AGENTS.md §7).
 */
class HeroKolazController extends Controller
{
    /**
     * Ile wpisów pokazujemy do wyboru. Liczba jak w `DailyBoardController`
     * (tam 40 wpisów z 7 dni) — tu nieco większa i BEZ ograniczenia czasem,
     * bo kolaż nie jest tablicą dnia: raz ustawiony ma stać tygodniami,
     * a najładniejsze zdjęcie w serwisie nie przestaje nim być po tygodniu.
     */
    private const KANDYDATOW = 60;

    public function __construct(private readonly HeroKolaz $kolaz) {}

    public function edit(Request $request): View
    {
        $this->authorize('moderate', User::class);

        return view('pages.admin.kolaz-powitalny', [
            'kandydaci' => $this->kolaz->kandydaci(self::KANDYDATOW),
            'wybrane' => HeroPick::query()
                ->orderBy('position')
                ->orderBy('id')
                ->pluck('media_id')
                ->map(fn ($id) => (string) $id)
                ->all(),
            'slotow' => HeroKolaz::SLOTOW,
            'podglad' => $this->kolaz->doKolazu(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('moderate', User::class);

        $data = $request->validate([
            'zdjecia' => ['nullable', 'array', 'max:'.HeroKolaz::SLOTOW],
            'zdjecia.*' => ['uuid'],
        ], [
            'zdjecia.max' => 'Wybierz najwyżej '.HeroKolaz::SLOTOW.' zdjęcia. Kolaż ma tyle miejsc.',
        ]);

        /** @var list<string> $zadane */
        $zadane = array_values(array_unique(array_map('strval', $data['zdjecia'] ?? [])));

        $dopuszczone = $this->kolaz->dopuszczZdjecia($zadane);

        $moderator = $request->user();

        // TRANSAKCJA I PRZECHWYCENIE ZDERZENIA — ten sam wzorzec i ten sam
        // powód co w `Admin\DailyBoardController::update()`: gospodarz
        // klika „Zapisz" drugi raz, bo strona wolno się ładuje, i dwa niemal
        // jednoczesne żądania mogą przepleść się tak, że oba wykonają DELETE,
        // a potem oba spróbują wstawić TO SAMO zdjęcie. Drugi INSERT zderza
        // się wtedy z UNIQUE (`hero_picks.media_id`).
        //
        // W PostgreSQL zderzenie z UNIQUE unieważnia CAŁĄ otaczającą
        // transakcję, więc samo `try/catch` nic by nie dało — zagnieżdżone
        // `DB::transaction()` Laravel zamienia na SAVEPOINT i wycofuje
        // wyłącznie ten jeden INSERT.
        DB::transaction(function () use ($zadane, $dopuszczone, $moderator): void {
            HeroPick::query()->delete();

            $pozycja = 0;

            // Kolejność Z FORMULARZA, nie z wyniku bramki: gospodarz widzi
            // listę w jednym porządku i pozycje w kolażu mają za nim iść.
            foreach ($zadane as $mediaId) {
                if (! isset($dopuszczone[$mediaId])) {
                    continue;
                }

                try {
                    DB::transaction(function () use ($mediaId, $dopuszczone, $pozycja, $moderator): void {
                        HeroPick::create([
                            'media_id' => $mediaId,
                            'post_id' => $dopuszczone[$mediaId],
                            'position' => $pozycja,
                            'curator_id' => $moderator?->getKey(),
                        ]);
                    });
                } catch (UniqueConstraintViolationException) {
                    // Konkurencyjne żądanie zapisało dokładnie to zdjęcie.
                    // Dla człowieka to wciąż jeden zapis — kończymy cicho.
                }

                $pozycja++;
            }
        });

        AuditLogEntry::record(
            action: 'hero_kolaz.updated',
            actor: $moderator,
            metadata: [
                'zapisanych' => count($dopuszczone),
                'odrzuconych' => count($zadane) - count($dopuszczone),
            ],
            ip: $request->ip(),
        );

        return back()->with('status', $this->komunikat(count($dopuszczone), count($zadane) - count($dopuszczone)));
    }

    /** Czyści wybór — kolaż wraca do doboru automatycznego. */
    public function destroy(Request $request): RedirectResponse
    {
        $this->authorize('moderate', User::class);

        HeroPick::query()->delete();

        AuditLogEntry::record(
            action: 'hero_kolaz.cleared',
            actor: $request->user(),
            metadata: [],
            ip: $request->ip(),
        );

        return back()->with('status', 'Wyczyszczone. Kolaż dobierze zdjęcia sam — najnowsze publiczne, najpierw po jednym od osoby, a w razie potrzeby po dwa.');
    }

    private function komunikat(int $zapisanych, int $odrzuconych): string
    {
        $odrzucone = $odrzuconych > 0
            ? " {$odrzuconych} pominęliśmy — te wpisy przestały być publiczne albo konto autora nie jest już aktywne."
            : '';

        if ($zapisanych === 0) {
            return 'Nic nie zostało wybrane. Kolaż dobierze zdjęcia sam.'.$odrzucone;
        }

        $brakuje = HeroKolaz::SLOTOW - $zapisanych;

        $uzupelnienie = $brakuje > 0
            ? " Brakujące {$brakuje} dobierzemy automatycznie z najnowszych publicznych zdjęć."
            : '';

        return "Zapisane. W kolażu stoi {$zapisanych} z wybranych zdjęć.".$uzupelnienie.$odrzucone;
    }
}
