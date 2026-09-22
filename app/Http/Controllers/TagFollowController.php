<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tags\Actions\UpdateTagFollows;
use App\Domain\Tags\TagFollowForm;
use App\Domain\Tags\TagFollowWindow;
use App\Http\Requests\TagSelection;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Obserwowanie tagów i ustawienia własnego konta (D-021). */
class TagFollowController extends Controller
{
    public function follow(Request $request, Tag $tag, UpdateTagFollows $follows): RedirectResponse
    {
        try {
            $follows->follow($request->user(), [$tag->getKey()]);
        } catch (ValidationException) {
            return back()->with('status', 'Tego tagu nie da się już obserwować. Wybierz inny tag.');
        }

        return back()->with('status', "Obserwujesz tag „{$tag->name}”.");
    }

    public function unfollow(Request $request, Tag $tag, UpdateTagFollows $follows): RedirectResponse
    {
        $follows->unfollow($request->user(), $tag->getKey());

        return back()->with('status', "Nie obserwujesz już tagu „{$tag->name}”.");
    }

    /**
     * Lista „Twoje tagi” — jedno okno listy naraz (#858).
     *
     * Całą pracę robi `TagFollowWindow`: co widać, co jedzie na serwer poza
     * widokiem i jak rośnie token. Tu zostaje tylko odczytanie tego, co
     * o oknie decyduje. Fraza i rozmiar okna mają DWA źródła, i kolejność
     * ma sens:
     *
     * - `old(...)` — po naciśnięciu „Pokaż pasujące” albo „Pokaż kolejne…”,
     *   a także po nieudanej walidacji zapisu. Tą drogą wraca też cały wybór,
     *   więc ani filtrowanie, ani doładowywanie nie gubi zaznaczeń;
     * - parametr adresu — żeby `/ustawienia/tagi?szukaj=zupa` dało się zapisać
     *   w zakładkach i wysłać komuś. Nic tą drogą nie wraca poza samą frazą,
     *   więc nie ma czego zgubić.
     *
     * Każde już obserwowane hasło musi dać się zdjąć, także niepromowane —
     * dlatego wszechświat listy jest sumą, a nie samą listą gospodarza (D-021).
     */
    public function edit(Request $request, TagFollowWindow $okno): View
    {
        $stare = session()->hasOldInput();

        return view('pages.settings.tags', $okno->zloz(
            $request->user(),
            self::tekst($stare ? old('szukaj') : $request->query('szukaj')),
            $stare ? old('ile') : $request->query('ile'),
            is_string(old('form_scope')) ? old('form_scope') : null,
            $stare ? (array) old('tags', []) : null,
            // Tylko naciśnięcie „Pokaż…” otwiera okno szerzej. Odmowa
            // walidacji pokazuje dokładnie to, co było widać przed wysłaniem
            // — uzasadnienie w `TagFollowWindow`.
            $stare && old('przeglada') === '1',
        ));
    }

    public function update(Request $request, TagFollowForm $forms, TagSelection $selection, UpdateTagFollows $follows): RedirectResponse
    {
        // Przeglądanie jedzie własną trasą (`przegladaj()` niżej) z własnym,
        // hojnym koszykiem limitera (#858, decyzja właściciela z 20.09.2026,
        // punkt 1) — przyciski „Pokaż…" wskazują ją przez `formaction`.
        // Gałąź zostaje jako siatka bezpieczeństwa: gdyby `formaction` z
        // jakiegoś powodu nie doszedł (stary zapisany HTML, formularz
        // wysłany ręcznie), przeglądanie nadal DZIAŁA — tyle że pod
        // budżetem zapisu, bo tu innego nie ma.
        $akcja = self::tekst($request->input('akcja'));
        if (in_array($akcja, ['filtruj', 'wiecej', 'wszystkie'], true)) {
            return $this->przegladajOdpowiedz($request, $akcja);
        }

        $scope = $forms->decode($request->user(), $request->input('form_scope'));
        if ($scope === null) {
            throw ValidationException::withMessages(['tags' => 'Sprawdź zaznaczenia w odświeżonym formularzu i zapisz ponownie.']);
        }
        // Limit wynika z rzeczywiście pokazanej listy, nie ogranicza liczby
        // obserwowań konta. Dopuszcza również pusty wybór.
        $selected = $selection->validate($request, count($scope['shown']));
        $follows->save($request->user(), $selected, $scope);

        return redirect()->route('settings.tags')->with('status', 'Zapisaliśmy Twoje tagi.');
    }

    /**
     * „Pokaż pasujące”, „Pokaż wszystkie tagi” i „Pokaż kolejne…” — to jest
     * PRZEGLĄDANIE, nie zapis. Żadna z tych dróg nie dotyka relacji.
     *
     * WŁASNA TRASA I WŁASNY KOSZYK LIMITERA (#858, decyzja właściciela
     * z 20.09.2026, punkt 1). Przyciski w widoku wskazują tę trasę przez
     * `formaction` — to zwykły atrybut HTML, nie JavaScript, więc D-053
     * zostaje spełnione przez nieobecność skryptu, nie przez obietnicę.
     * Formularz ma jedną akcję domyślną (zapis, `settings.tags.update`)
     * i trzy przyciski z własnym `formaction` na przeglądanie — Enter nadal
     * wyzwala PIERWSZY `submit` w dokumencie, niezależnie od tego, dokąd
     * on wysyła (patrz komentarz o kolejności przycisków w widoku).
     *
     * DLACZEGO TO JEDZIE TYM SAMYM FORMULARZEM, A NIE ODNOŚNIKIEM
     * Bo odnośnik zabiera ze sobą wyłącznie adres, a formularz zabiera cały
     * wybór. Gdyby doładowanie było odnośnikiem, każde naciśnięcie kasowałoby
     * zaznaczenia zrobione przed nim — czyli spełniałoby literę zgłoszenia
     * i łamało zasadę, że poprawne dane nigdy nie znikają.
     *
     * DLACZEGO PRZEKIEROWANIE, A NIE ODRYSOWANIE WIDOKU W MIEJSCU
     * Bo po odrysowaniu w miejscu adres w przeglądarce zostaje adresem
     * zapisu, a odświeżenie strony pyta „wysłać formularz ponownie?”.
     * Wybór wraca przez `withInput()`, czyli tą samą drogą, którą wraca po
     * nieudanej walidacji — jedna droga zamiast dwóch, które mogą się
     * rozjechać.
     */
    public function przegladaj(Request $request): RedirectResponse
    {
        $akcja = self::tekst($request->input('akcja'));
        if (! in_array($akcja, ['filtruj', 'wiecej', 'wszystkie'], true)) {
            // Ktoś trafił na tę trasę bez poprawnej wartości `akcja` — nie
            // ma czego przeglądać, więc traktujemy to jak zwykłe „pokaż
            // pierwszą porcję listy od nowa" (najbezpieczniejszy domyślnik).
            $akcja = 'filtruj';
        }

        return $this->przegladajOdpowiedz($request, $akcja);
    }

    private function przegladajOdpowiedz(Request $request, string $akcja): RedirectResponse
    {
        // Ta droga nie przechodzi przez `TagSelection`, bo niczego nie zapisuje
        // — ale wszystko, co tu przyjmiemy, ląduje we flashu sesji. Dlatego
        // obcinamy DŁUGOŚĆ, zanim cokolwiek zapiszemy: bez tego jedno żądanie
        // z dziesięcioma tysiącami pozycji pisze plik sesji za darmo.
        // Zapisowi i tak potem odmówi kontrola „Wybierz tagi z tej listy".
        return redirect()->route('settings.tags')->withInput([
            'form_scope' => mb_substr(self::tekst($request->input('form_scope')), 0, 100_000),
            'tags' => array_slice(
                array_values(array_filter((array) $request->input('tags', []), 'is_string')),
                0, 5_000,
            ),
            'szukaj' => $akcja === 'wszystkie'
                ? ''
                : mb_substr(self::tekst($request->input('szukaj')), 0, TagFollowWindow::MAKS_FRAZA),
            // Zmiana filtra zaczyna oglądanie od początku: po zawężeniu listy
            // do trzech pozycji „pokaż kolejne 20” nie ma czego pokazywać.
            'ile' => $akcja === 'wiecej'
                ? TagFollowWindow::nastepneOkno($request->input('ile'))
                : TagFollowWindow::PORCJA,
            // Odróżnia „człowiek nacisnął Pokaż…” od „wróciła odmowa
            // walidacji”. Nie jest polem formularza, więc nie da się go
            // wpisać przez pomyłkę; gdyby ktoś dopisał je sobie ręcznie,
            // dostanie to, co i tak dostaje przyciskiem obok.
            'przeglada' => '1',
        ]);
    }

    /** Pole formularza bywa tablicą — wtedy nie jest tekstem, tylko próbą. */
    private static function tekst(mixed $wartosc): string
    {
        return is_string($wartosc) ? $wartosc : '';
    }
}
