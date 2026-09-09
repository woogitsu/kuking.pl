<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Contact\Actions\PrzyjmijWiadomosc;
use App\Models\ContactMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * „Napisz do nas" — zwykła strona z formularzem, pod własnym adresem.
 *
 * DLACZEGO STRONA, A NIE DYMEK W ROGU
 * Bo AGENTS.md §5 mówi, że ważne funkcje działają bez JavaScriptu, a dymek,
 * który bez skryptu się nie otwiera, jest ozdobą udającą przycisk — dokładnie
 * to samo rozstrzygnięcie, co przy menu pod awatarem w `layout.blade.php`.
 * Człowiek, do którego nie dociągnął się skrypt (a to jest ta sama osoba,
 * której akurat coś nie działa i dlatego chce napisać), musi mieć drogę,
 * która działa z samego HTML-a. Dymek wolno kiedyś dołożyć — ale JAKO SKRÓT
 * DO TEGO ADRESU, nie zamiast niego.
 *
 * TO NIE JEST ZGŁOSZENIE TREŚCI. Skarga na cudzy wpis idzie przyciskiem
 * „Zgłoś" pod treścią (`ReportController`), a treść niezgodna z prawem —
 * formularzem z DSA art. 16 (`ZgloszenieNielegalnejTresciController`).
 * Rozdział jest twardy w trzech miejscach naraz: w schemacie (osobna tabela),
 * w panelu (osobny ekran) i na samym formularzu (blok „Chodzi o czyjś wpis?"
 * z linkami w obie strony). Bez tego ludzie zgłaszaliby sąsiada formularzem
 * technicznym, a awarię — kolejką moderacyjną, gdzie czeka na decyzję,
 * od której da się odwołać.
 *
 * KTO MOŻE PISAĆ: KAŻDY, TAKŻE BEZ KONTA — patrz uzasadnienie przy trasie
 * w `routes/web.php`. Ochroną jest limit zapytań, nie logowanie.
 */
class NapiszDoNasController extends Controller
{
    public function __construct(private readonly PrzyjmijWiadomosc $przyjmij) {}

    public function create(Request $request): View
    {
        return view('pages.napisz-do-nas', [
            'rodzaje' => ContactMessage::RODZAJE,
            'sciezka' => $this->sciezkaZFormularzaAlboReferera($request),
            'kluczWyslania' => $this->kluczDlaFormularza(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $zalogowany = $request->user();

        $dane = $request->validate([
            'kind' => ['required', 'string', 'in:'.implode(',', array_keys(ContactMessage::RODZAJE))],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            // Adres jest NIEOBOWIĄZKOWY także dla gościa — patrz komentarz
            // przy polu w widoku. Wymóg adresu odsiałby dokładnie te osoby,
            // które chcą tylko powiedzieć, że coś nie działa, i nie oczekują
            // rozmowy; ich zdanie jest tak samo warte przeczytania.
            //
            // Dla zalogowanego pola w formularzu NIE MA (adres jest na
            // koncie), ale reguła zostaje ta sama zamiast `prohibited`:
            // podstawiona wartość ma zostać po cichu POMINIĘTA (robi to
            // `PrzyjmijWiadomosc`), a nie zamienić poprawnie napisaną
            // wiadomość w błąd walidacji, którego nikt nie zrozumie.
            'contact_email' => ['nullable', 'email:rfc', 'max:255'],
            'page_path' => ['nullable', 'string', 'max:300'],
        ], [
            'kind.required' => 'Zaznacz, czego dotyczy wiadomość — jedno z trzech pól wyżej.',
            'kind.in' => 'Zaznacz, czego dotyczy wiadomość — jedno z trzech pól wyżej.',
            'message.required' => 'Napisz, o co chodzi. Wystarczy jedno zdanie.',
            'message.min' => 'Napisz trochę więcej — z kilku słów nie zgadniemy, co się stało.',
            'message.max' => 'To jest dłuższe, niż zmieści się w jednej wiadomości. Skróć tekst albo napisz do nas na '
                .config('kuking.community.contact_email').'.',
            'contact_email.email' => 'Ten adres e-mail wygląda na niepełny. Sprawdź, czy nie brakuje kropki albo znaku @.',
        ]);

        $wiadomosc = $this->przyjmij->handle(
            rodzaj: $dane['kind'],
            tresc: $dane['message'],
            autor: $zalogowany,
            email: $dane['contact_email'] ?? null,
            sciezka: $this->oczyscSciezke($dane['page_path'] ?? null),
            kluczWyslania: $this->kluczZZadania($request),
        );

        return redirect()
            ->route('kontakt.potwierdzenie')
            ->with('kontakt_odpowiedz_na', $wiadomosc->adresDoOdpowiedzi());
    }

    public function confirmation(Request $request): View
    {
        return view('pages.napisz-do-nas-potwierdzenie', [
            // Adres, na który odpiszemy — żeby człowiek od razu zobaczył,
            // czy podał ten, który czyta. Sesja, nie parametr adresu: to jest
            // jego dana osobowa i nie ma czego szukać w pasku przeglądarki
            // ani w logu serwera.
            'odpowiedzNa' => $request->session()->get('kontakt_odpowiedz_na'),
        ]);
    }

    /**
     * Skąd człowiek przyszedł — do wpisania w ukryte pole formularza.
     *
     * `old()` NAJPIERW, bo po nieudanej walidacji nagłówek `Referer` wskazuje
     * już na sam formularz, a nie na stronę, na której coś nie działało.
     * Bez tego informacja o miejscu awarii ginęła przy pierwszej literówce
     * w adresie e-mail.
     */
    private function sciezkaZFormularzaAlboReferera(Request $request): ?string
    {
        $stara = old('page_path');

        if (is_string($stara) && $stara !== '') {
            return $this->oczyscSciezke($stara);
        }

        return $this->oczyscSciezke($request->headers->get('referer'));
    }

    /**
     * Sama ścieżka z NASZEGO serwisu — nic więcej.
     *
     * Trzy rzeczy odpadają tutaj świadomie:
     *
     *  - adres z CUDZEGO serwisu (ktoś przyszedł z wyszukiwarki albo
     *    z Facebooka) — to jest informacja o człowieku, nie o naszej
     *    awarii, i nie ma powodu, żeby leżała w naszej bazie;
     *  - parametry zapytania — potrafią nieść frazę wyszukiwania, czyli
     *    zdanie napisane przez człowieka w zupełnie innym celu;
     *  - fragment po `#`.
     *
     * Zostaje ścieżka w rodzaju `/przepisy/rosol-babci`. Dokładnie tyle,
     * ile potrzeba, żeby odtworzyć awarię.
     */
    private function oczyscSciezke(?string $adres): ?string
    {
        if (! is_string($adres) || trim($adres) === '') {
            return null;
        }

        $adres = trim($adres);

        // Wartość z ukrytego pola przychodzi już jako sama ścieżka.
        // Wartość z `Referer` — jako pełny adres. Obsługujemy oba, ale
        // pełny adres musi być NASZ.
        if (str_starts_with($adres, '/')) {
            $sciezka = parse_url($adres, PHP_URL_PATH);
        } else {
            $host = parse_url($adres, PHP_URL_HOST);

            if ($host === null || $host !== parse_url((string) config('app.url'), PHP_URL_HOST)) {
                return null;
            }

            $sciezka = parse_url($adres, PHP_URL_PATH);
        }

        if (! is_string($sciezka) || $sciezka === '') {
            return null;
        }

        return Str::limit($sciezka, 297, '…');
    }

    /**
     * Klucz wysłania dla świeżo renderowanego formularza (D-027).
     *
     * `old()` pierwsze: po nieudanej walidacji klucz musi zostać TEN SAM,
     * inaczej poprawione wysłanie liczyłoby się jako druga wiadomość.
     */
    private function kluczDlaFormularza(): ?string
    {
        // Wyłącznik awaryjny mechanizmu — `config/kuking.php`, sekcja
        // `formularze`. Ta sama bramka co w pozostałych formularzach.
        if (! (bool) config('kuking.formularze.klucz_wyslania_wlaczony')) {
            return null;
        }

        $stary = old('klucz_wyslania');

        return is_string($stary) && Str::isUuid($stary) ? $stary : (string) Str::uuid7();
    }

    /**
     * Klucz wysłania z żądania. Wartość niebędąca UUID-em schodzi do `null`,
     * czyli do „przyjmij normalnie" — nigdy do odmowy. Odmowa zamknęłaby
     * jedyną drogę kontaktu osobie, której akurat coś nie działa.
     */
    private function kluczZZadania(Request $request): ?string
    {
        if (! (bool) config('kuking.formularze.klucz_wyslania_wlaczony')) {
            return null;
        }

        $klucz = $request->input('klucz_wyslania');

        return is_string($klucz) && Str::isUuid($klucz) ? $klucz : null;
    }
}
