<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Analytics\ZapiszSygnal;
use App\Domain\Pantry\CoMamWDomu;
use App\Domain\Pantry\CoUgotuje;
use App\Domain\Pantry\DrugieOpakowanieProduktu;
use App\Domain\Pantry\Opakowanie;
use App\Domain\Pantry\PodpowiedziSkladnikow;
use App\Domain\Pantry\PriorytetZuzycia;
use App\Domain\Pantry\ZmienTerminProduktu;
use App\Domain\Zgody\PrzestawZgodeNaPrzypomnienieSpizarni;
use App\Models\PantryItem;
use App\Models\User;
use App\Models\WpisZgody;
use App\Support\Komunikat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * „Co mam w domu” i „Co ugotuję z tego, co mam” (V2, D-285).
 *
 * Kontroler jest cienki: reguły listy żyją w `CoMamWDomu`, dobór przepisów
 * w `CoUgotuje`. Lista jest prywatna — każda trasa pracuje na liście
 * zalogowanej osoby, a usunięcie produktu po identyfikatorze przechodzi
 * przez `PantryItemPolicy`.
 */
class PantryController extends Controller
{
    /** Klucz w sesji: sygnał `pantry_priority_viewed` leci najwyżej raz na sesję. */
    private const SESJA_PRIORYTET_WIDZIANY = 'pantry_priority_widziany';

    /** Klucz w sesji: sygnał `pantry_cook_priority_viewed` też leci najwyżej raz na sesję. */
    private const SESJA_DOBOR_PRIORYTET_WIDZIANY = 'pantry_cook_priority_widziany';

    public function index(Request $request, ZapiszSygnal $sygnaly): View
    {
        /** @var User $user */
        $user = $request->user();

        $produkty = $user->pantryItems()->with('secondPackage')->get();
        $dzis = PriorytetZuzycia::dzis();
        $grupy = PriorytetZuzycia::pogrupuj($produkty, $dzis);

        if ($grupy['pilne']->isNotEmpty() && ! $request->session()->has(self::SESJA_PRIORYTET_WIDZIANY)) {
            // Pomiar (#1903): ktoś zobaczył sekcję „Zużyj w pierwszej kolejności”.
            // Bez konta, bez nazw produktów i dat. RAZ NA SESJĘ: odświeżenie
            // strony nie nabija licznika (mierzymy osoby, nie wejścia).
            $request->session()->put(self::SESJA_PRIORYTET_WIDZIANY, true);
            $sygnaly->handle(null, ZapiszSygnal::PANTRY_PRIORITY_VIEWED);
        }

        return view('pages.pantry.index', [
            'produkty' => $produkty,
            'grupy' => $grupy,
            'dzis' => $dzis,
            'regula' => PriorytetZuzycia::regula(),
            'maksProduktow' => CoMamWDomu::MAKS_PRODUKTOW,
            'nowyProdukt' => $this->nowyProdukt($request, $produkty),
        ]);
    }

    public function store(Request $request, CoMamWDomu $lista): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $dane = $request->validate(
            ['nazwa' => ['required', 'string', 'max:'.CoMamWDomu::MAKS_ZNAKOW]],
            [
                'nazwa.required' => 'Wpisz nazwę produktu, na przykład „mąka” albo „jajka”.',
                'nazwa.string' => 'Wpisz nazwę produktu, na przykład „mąka” albo „jajka”.',
                'nazwa.max' => 'Skróć nazwę produktu do :max znaków. Wystarczy samo „mąka” albo „ser żółty”.',
            ],
        );

        $wynik = $lista->dodaj($user, (string) $dane['nazwa']);
        $nazwa = $wynik['produkt']->name;

        $odpowiedz = redirect()->route('pantry.index')->with($wynik['nowy']
            ? Komunikat::sukces("Dodano „{$nazwa}” do listy.")
            : Komunikat::informacja("„{$nazwa}” już jest na Twojej liście."));

        // Identyfikator dodanego produktu: lista pokaże link „Ustaw termin”.
        return $wynik['nowy'] ? $odpowiedz->with('pantry_nowy', (string) $wynik['produkt']->getKey()) : $odpowiedz;
    }

    public function edit(Request $request, string $pantryItem): View|Response
    {
        $produkt = PantryItem::query()->with('secondPackage')->find($pantryItem);

        if ($produkt === null) {
            return $this->brakProduktu();
        }

        $this->authorize('update', $produkt);

        // `?opakowanie=drugie` otwiera drugie opakowanie (istniejące albo nowe,
        // puste); każda inna wartość to pierwsze (#2568).
        $cel = $request->query('opakowanie') === Opakowanie::DRUGIE ? Opakowanie::DRUGIE : Opakowanie::PIERWSZE;
        $opakowania = Opakowanie::zProduktu($produkt);
        $opakowanie = $cel === Opakowanie::DRUGIE ? ($opakowania[1] ?? null) : $opakowania[0];

        return view('pages.pantry.termin', [
            'produkt' => $produkt,
            'cel' => $cel,
            'opakowanie' => $opakowanie,
            'opakowania' => $opakowania,
            'lata' => ZmienTerminProduktu::lataDoWyboru(null, $opakowanie?->expires_on?->year),
            'szybkie' => ZmienTerminProduktu::SZYBKIE,
            'rodzaje' => PriorytetZuzycia::RODZAJE,
            'stan' => $opakowanie !== null ? PriorytetZuzycia::opisStanu($opakowanie) : 'Drugiego opakowania jeszcze nie ma. Wpisz jego termin i ilość, a zostanie dodane. Pierwsze opakowanie zostaje bez zmian.',
            // Szybki przycisk bez rodzaju kończy się błędem; jego data wraca
            // na listy, żeby wystarczyło zaznaczyć rodzaj.
            'dataZPrzycisku' => $request->old('_formularz') === 'termin'
                ? ZmienTerminProduktu::dataZaPrzyciskiem($request->old('za'))
                : null,
            'przyciskZPrzed' => $request->old('_formularz') === 'termin' && is_string($request->old('za'))
                ? (ZmienTerminProduktu::SZYBKIE[$request->old('za')] ?? null)
                : null,
        ]);
    }

    public function update(Request $request, string $pantryItem, ZmienTerminProduktu $zmiana, DrugieOpakowanieProduktu $drugie, ZapiszSygnal $sygnaly): RedirectResponse|Response
    {
        $produkt = PantryItem::query()->with('secondPackage')->find($pantryItem);

        if ($produkt === null) {
            return $this->brakProduktu();
        }

        $this->authorize('update', $produkt);

        // Pola tekstowe i liczbowe jako surowe wartości: walidacja zdarzeń
        // (nieistniejąca data, brak rodzaju, za długa ilość) jest w akcji
        // domenowej i mówi po polsku, co poprawić.
        $dane = $request->only(['rodzaj', 'termin_dzien', 'termin_miesiac', 'termin_rok', 'za', 'wyczysc', 'ilosc', 'mrozone']);

        $cel = $request->input('opakowanie') === Opakowanie::DRUGIE ? Opakowanie::DRUGIE : Opakowanie::PIERWSZE;
        $opakowaniaPrzed = Opakowanie::zProduktu($produkt);
        $przed = $cel === Opakowanie::DRUGIE ? ($opakowaniaPrzed[1] ?? null) : $opakowaniaPrzed[0];
        $terminPrzed = [$przed?->expires_on?->toDateString(), $przed?->expiry_kind];

        try {
            $zapisano = $cel === Opakowanie::DRUGIE
                ? $drugie->zapisz($produkt, $dane, $this->tekst($request, 'opakowanie_id'))
                : $zmiana->handle($produkt, $dane, null, $this->tekst($request, 'odcisk'));
        } catch (ValidationException $e) {
            throw $e->redirectTo($cel === Opakowanie::DRUGIE
                ? route('pantry.edit', ['pantryItem' => $produkt, 'opakowanie' => $cel])
                : route('pantry.edit', $produkt));
        }

        if (! $zapisano) {
            return $this->brakProduktu();
        }

        $produkt = $produkt->fresh(['secondPackage']) ?? $produkt;
        $opakowania = Opakowanie::zProduktu($produkt);
        $po = $cel === Opakowanie::DRUGIE ? ($opakowania[1] ?? null) : $opakowania[0];
        $nazwa = $produkt->name;
        $ktore = count($opakowania) > 1 && $po !== null ? ' ('.mb_strtolower($po->etykieta()).')' : '';

        // Sygnał tylko gdy termin NAPRAWDĘ się zmienił (nowy albo inny): zapis
        // samej ilości czy „mrożone” z tym samym terminem niczego nie mierzy.
        if ($po?->expires_on !== null
            && [$po->expires_on->toDateString(), $po->expiry_kind] !== $terminPrzed) {
            $sygnaly->handle(null, ZapiszSygnal::PANTRY_EXPIRY_SET);
        }

        $komunikat = $po?->expires_on !== null
            ? "Zapisano termin: {$nazwa}{$ktore}, do ".PriorytetZuzycia::dataSlownie($po->expires_on).'.'
            : "Zapisano: {$nazwa}{$ktore}, bez terminu.";

        return redirect()->route('pantry.index')->with(Komunikat::sukces($komunikat));
    }

    public function przypomnienie(Request $request, PrzestawZgodeNaPrzypomnienieSpizarni $zgoda): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $dane = $request->validate(
            ['original_pantry_reminder' => ['required', 'in:0,1']],
            ['original_pantry_reminder.*' => 'Odśwież stronę „Co mam w domu” i wybierz ponownie.'],
        );

        // Stan z chwili otwarcia formularza (jak w ustawieniach prywatności, #879):
        // otwarty wczoraj nie zapisze człowieka z powrotem na list, z którego
        // wypisał się odnośnikiem. Przy rozjeździe nie zapisujemy niczego.
        if ((bool) $user->fresh()->wants_pantry_reminder !== ($dane['original_pantry_reminder'] === '1')) {
            return redirect()->route('pantry.index')->with(Komunikat::blad(
                'Ustawienie sobotniego przypomnienia zmieniło się od otwarcia tej strony. Sprawdź je poniżej i wybierz ponownie.',
            ));
        }

        $chce = $request->boolean('wants_pantry_reminder');
        $zgoda->handle($user, $chce, WpisZgody::ZRODLO_USTAWIENIA);

        return redirect()->route('pantry.index')->with(Komunikat::sukces($chce
            ? 'Włączono sobotnie przypomnienie. List przyjdzie w sobotę rano, jeśli będzie co na nim wymienić.'
            : 'Wyłączono sobotnie przypomnienie.'));
    }

    public function destroy(Request $request, PantryItem $pantryItem): RedirectResponse
    {
        $this->authorize('delete', $pantryItem);

        $nazwa = $pantryItem->name;
        $pantryItem->delete();

        return redirect()->route('pantry.index')->with(Komunikat::sukces("Usunięto „{$nazwa}” z listy."));
    }

    /**
     * Usuwa JEDNO opakowanie produktu z dwoma opakowaniami (#2568); drugie
     * zostaje. Cały produkt usuwa `destroy()`. Autoryzacja ta sama co przy
     * usuwaniu produktu — decyduje właściciel wiersza, nie adres.
     */
    public function destroyOpakowanie(Request $request, PantryItem $pantryItem, DrugieOpakowanieProduktu $drugie): RedirectResponse
    {
        $this->authorize('delete', $pantryItem);

        $cel = $this->tekst($request, 'opakowanie') ?? '';
        $nazwa = $pantryItem->name;

        $wynik = $drugie->usun($pantryItem, $cel, $this->tekst($request, 'odcisk'));

        return redirect()->route('pantry.index')->with(match ($wynik) {
            DrugieOpakowanieProduktu::USUNIETO => Komunikat::sukces("Usunięto jedno opakowanie: „{$nazwa}”. Drugie opakowanie zostało na liście."),
            DrugieOpakowanieProduktu::JEDYNE => Komunikat::informacja("To jedyne opakowanie produktu „{$nazwa}”. Jeśli już go nie masz, usuń cały produkt z listy."),
            DrugieOpakowanieProduktu::ZMIENILO_SIE => Komunikat::blad(DrugieOpakowanieProduktu::BLAD_ZMIENILO_SIE),
            default => Komunikat::informacja("Tego opakowania („{$nazwa}”) już nie ma na liście. Niczego nie usunęliśmy."),
        });
    }

    public function podpowiedzi(Request $request, PodpowiedziSkladnikow $podpowiedzi): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $dane = $request->validate([
            'q' => ['required', 'string', 'min:'.PodpowiedziSkladnikow::MIN_ZNAKOW, 'max:'.PodpowiedziSkladnikow::MAKS_ZNAKOW],
        ]);

        return response()->json(['podpowiedzi' => $podpowiedzi->dla((string) $dane['q'], $user)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function coUgotuje(Request $request, CoUgotuje $dobor, ZapiszSygnal $sygnaly): View
    {
        /** @var User $user */
        $user = $request->user();

        // Głębszy OFFSET jest kosztowny; ostatnia dostępna strona zaczyna się tutaj.
        // Widok nie może proponować adresu, który ponownie sprowadzi tu człowieka.
        $maksOd = 10_000;
        $od = max(0, min($maksOd, (int) $request->query('od', '0')));
        $najpierwTermin = $request->query('najpierw') === 'termin';
        $wynik = $dobor->dla($user, $od, CoUgotuje::NA_STRONE, $najpierwTermin);

        if ($najpierwTermin && $od === 0 && ! $request->session()->has(self::SESJA_DOBOR_PRIORYTET_WIDZIANY)) {
            // Pomiar (#1903): ktoś otworzył przepisy w trybie „najpierw to, co się psuje”.
            // RAZ NA SESJĘ, jak `pantry_priority_viewed`: odświeżenie strony
            // nie nabija licznika (mierzymy osoby, nie wejścia).
            $request->session()->put(self::SESJA_DOBOR_PRIORYTET_WIDZIANY, true);
            $sygnaly->handle(null, ZapiszSygnal::PANTRY_COOK_PRIORITY_VIEWED);
        }

        return view('pages.pantry.co-ugotuje', [
            ...$wynik,
            'od' => $od,
            'nastepne' => $od + CoUgotuje::NA_STRONE,
            'granicaPrzegladania' => $od >= $maksOd,
            'najpierwTermin' => $najpierwTermin,
            'regula' => $najpierwTermin ? CoUgotuje::REGULA_NAJPIERW_TERMIN : CoUgotuje::REGULA,
        ]);
    }

    /** @param  Collection<int, PantryItem>|\Illuminate\Database\Eloquent\Collection<int, PantryItem>  $produkty */
    private function nowyProdukt(Request $request, $produkty): ?PantryItem
    {
        $id = $request->session()->get('pantry_nowy');

        return is_string($id) ? $produkty->firstWhere('id', $id) : null;
    }

    private function tekst(Request $request, string $pole): ?string
    {
        $wartosc = $request->input($pole);

        return is_string($wartosc) && $wartosc !== '' ? $wartosc : null;
    }

    /** Produkt zniknął (usunięty równolegle, z innego okna): 404 z komunikatem po polsku. */
    private function brakProduktu(): Response
    {
        return response()->view('pages.pantry.brak-produktu', [], 404);
    }
}
