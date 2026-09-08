<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Moderation\Actions\ZglosNielegalnaTresc;
use App\Models\Recipe;
use App\Models\Report;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Publiczna droga zgłoszenia nielegalnej treści (DSA art. 16).
 *
 * DLACZEGO POZA LOGOWANIEM
 * Art. 16 wymaga mechanizmu dostępnego dla KAŻDEJ osoby i każdego podmiotu.
 * Zgłasza prawnik reprezentujący klienta, rodzic, który rozpoznał swoje
 * dziecko na cudzym zdjęciu, albo osoba, która znalazła tu swój skradziony
 * tekst. Żadna z nich nie ma konta w serwisie kulinarnym i nie ma powodu,
 * żeby je zakładać.
 *
 * Istniejący formularz „Zgłoś" ZOSTAJE za logowaniem i to jest w porządku —
 * on obsługuje nasze zasady („to jest spam", „to jest chamskie"), a nie
 * obowiązek z przepisu. To są dwie różne rzeczy i mieszanie ich kończy się
 * tym, że jedna z nich nie spełnia swojej roli.
 *
 * CZYM ZASTĘPUJEMY LOGOWANIE JAKO OCHRONĘ
 * Logowanie było tu jedyną barierą przed nadużyciem i dlatego droga prawna
 * w ogóle za nim stanęła. Zamiast niego: limit żądań z `config/kuking.php`,
 * wymagane pola (imię, uzasadnienie, oświadczenie o dobrej wierze) i to, że
 * zgłoszenie idzie do kolejki człowieka, a nie do automatu. Logowanie nie
 * może być ochroną, bo wyklucza tych, dla których ten mechanizm istnieje.
 */
class ZgloszenieNielegalnejTresciController extends Controller
{
    public function __construct(private readonly ZglosNielegalnaTresc $zglos) {}

    public function create(): View
    {
        return view('pages.zglos-nielegalna-tresc', [
            'reasons' => Report::REASONS,
            'kluczWyslania' => $this->kluczDlaFormularza(),
        ]);
    }

    /**
     * Klucz wysłania dla świeżo renderowanego formularza.
     *
     * `old()` pierwsze: ten formularz ma pięć pól wymaganych i długie
     * uzasadnienie, więc nieudana walidacja jest tu częstsza niż gdziekolwiek
     * indziej — a po niej klucz musi zostać ten sam.
     */
    private function kluczDlaFormularza(): ?string
    {
        // Wyłącznik awaryjny mechanizmu — `config/kuking.php`, sekcja
        // `formularze` (tam stoi całe uzasadnienie i skutek wyłączenia).
        if (! (bool) config('kuking.formularze.klucz_wyslania_wlaczony')) {
            return null;
        }

        $stary = old('klucz_wyslania');

        return is_string($stary) && Str::isUuid($stary) ? $stary : (string) Str::uuid7();
    }

    /**
     * Klucz wysłania z żądania. Wartość niebędąca UUID-em schodzi do `null`,
     * czyli do „przyjmij normalnie" — nigdy do odmowy. Odmowa zamknęłaby
     * drogę, którą DSA art. 16 nakazuje udostępnić każdemu.
     */
    private function kluczZZadania(Request $request): ?string
    {
        // Wyłącznik awaryjny — TA SAMA bramka, co przy renderowaniu formularza.
        // Bez niej wyłącznik działa tylko w połowie: karta otwarta PRZED
        // przełączeniem nadal niesie klucz w DOM-ie i odsyła go, więc częściowy
        // indeks dalej obowiązuje — dokładnie w tej awarii, dla której ten
        // wyłącznik istnieje. `config/kuking.php` obiecuje, że po wyłączeniu
        // „kolumna dostaje NULL"; ta linijka jest tym, co tę obietnicę dowozi.
        if (! (bool) config('kuking.formularze.klucz_wyslania_wlaczony')) {
            return null;
        }

        $klucz = $request->input('klucz_wyslania');

        return is_string($klucz) && Str::isUuid($klucz) ? $klucz : null;
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // ANI IMIĘ, ANI ADRES NIE SĄ WYMAGANE. Art. 16 ust. 2 lit. c
            // zwalnia z podania danych zgłaszającego przy zgłoszeniach
            // dotyczących przestępstw z art. 3-7 dyrektywy 2011/93/UE —
            // i zwalnia z podania DANYCH, nie tylko adresu e-mail. Do
            // 7 września 2026 reguła była tu w połowie: brak adresu wolno,
            // brak nazwiska nie (pomiar: `docs/decyzje/DSA_POMIAR.md`).
            //
            // Wymóg nazwiska zamykał drogę prawną dokładnie w najcięższych
            // sprawach: przy społeczności z jednej okolicy człowiek
            // zgłaszający kogoś z rodziny albo z sąsiedztwa nie podpisze się
            // nazwiskiem. Formularz mówi o tym wprost przy obu polach.
            'notifier_name' => ['nullable', 'string', 'max:120'],
            'notifier_email' => ['nullable', 'email:rfc', 'max:255'],
            'target_url' => ['required', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'in:'.implode(',', array_keys(Report::REASONS))],
            'illegality_explanation' => ['required', 'string', 'min:20', 'max:5000'],
            'good_faith' => ['accepted'],
        ], [
            'notifier_email.email' => 'Ten adres e-mail wygląda na niepełny. Sprawdź, czy nie brakuje kropki albo znaku @.',
            'target_url.required' => 'Wklej adres strony, na której jest ta treść.',
            'reason.required' => 'Wybierz, czego dotyczy zgłoszenie.',
            'illegality_explanation.required' => 'Napisz, dlaczego uważasz tę treść za niezgodną z prawem. Bez tego nie możemy jej ocenić.',
            'illegality_explanation.min' => 'Napisz trochę więcej — jedno zdanie wystarczy, ale musimy wiedzieć, o co chodzi.',
            'good_faith.accepted' => 'Zaznacz oświadczenie na dole formularza.',
        ]);

        [$typ, $id] = $this->rozpoznajAdres($data['target_url']);

        $zgloszenie = $this->zglos->handle(
            imie: $data['notifier_name'] ?? null,
            email: $data['notifier_email'] ?? null,
            adres: $data['target_url'],
            uzasadnienie: $data['illegality_explanation'],
            powod: $data['reason'],
            typCelu: $typ,
            idCelu: $id,
            kluczWyslania: $this->kluczZZadania($request),
        );

        $numer = $zgloszenie->numer_sprawy;

        return redirect()->route('zglos.nielegalna.potwierdzenie')->with('numer', $numer);
    }

    public function confirmation(Request $request): View
    {
        return view('pages.zglos-nielegalna-tresc-potwierdzenie', [
            'numer' => $request->session()->get('numer'),
        ]);
    }

    /**
     * Próba rozpoznania, czego dotyczy wklejony adres.
     *
     * NIEUDANA PRÓBA NIE JEST BŁĘDEM. Ktoś wkleja link z pamięci albo ze
     * zrzutu ekranu, treść mogła już zniknąć, adres może być z innego serwisu.
     * Zgłoszenie i tak musi zostać przyjęte — odmowa, bo nie rozpoznaliśmy
     * adresu, byłaby odmówieniem mechanizmu, który przepis nakazuje
     * udostępnić. Moderator zobaczy wtedy sam adres i poradzi sobie.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function rozpoznajAdres(string $adres): array
    {
        $sciezka = parse_url(trim($adres), PHP_URL_PATH);

        if (! is_string($sciezka)) {
            return [null, null];
        }

        $segmenty = array_values(array_filter(explode('/', $sciezka)));

        if (count($segmenty) < 2) {
            return [null, null];
        }

        [$pierwszy, $drugi] = $segmenty;

        return match ($pierwszy) {
            'przepis', 'przepisy' => ['recipe', $this->idPrzepisu($drugi)],
            'wpis', 'wpisy' => ['post', $this->uuidAlbo($drugi)],
            'ugotowane' => ['cooked_event', $this->uuidAlbo($drugi)],
            default => [null, null],
        };
    }

    private function idPrzepisu(string $segment): ?string
    {
        // Adres przepisu niesie slug, nie UUID — trzeba go przetłumaczyć.
        return Recipe::query()->where('slug', $segment)->value('id')
            ?? $this->uuidAlbo($segment);
    }

    private function uuidAlbo(string $segment): ?string
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $segment) === 1
            ? $segment
            : null;
    }
}
