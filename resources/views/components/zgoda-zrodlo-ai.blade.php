{{--
    INFORMACJA PRZED ZGODĄ NA WYSŁANIE TEKSTU STRONY ALBO STRON PDF DO MODELU
    I POLE ZGODY (D-300 pkt 9, issue #2031).

    Jedyne źródło tej treści. Używają go formularz „Przepis ze strony
    internetowej” (`import-adres`) i „Przepis z pliku PDF” (`import-pdf`) —
    żaden z nich nie ma własnej wersji tekstu. Blok z atrybutem
    `data-informacja-zrodlo-ai` porównuje test
    `tests/Feature/Import/ZgodaPrzedTekstemZrodlaTest.php`.

    TO NIE JEST ZGODA „ODCZYT AI” Z ZDJĘCIEM KARTKI (`x-zgoda-odczyt-ai`).
    Tamta jest trwała i dotyczy zdjęcia. Ta dotyczy jednego wysłania i znika
    po żądaniu; każda zgoda mówi to wprost drugiej.

    Zmiana faktów (odbiorca, miejsce, zakres, retencja, skutek) = podbij
    `App\Domain\Zgody\InformacjaTekstuZrodlaAi::WERSJA`. Fakty zgodne z D-300
    i `docs/legal/projekty/POLITYKA_ODCZYT_AI.md`.

    Props:
    - `zrodlo` — `url` (tekst strony) albo `pdf` (obrazy stron skanu).
--}}
@props(['zrodlo' => 'url'])
@php
    $pdf = $zrodlo === 'pdf';
@endphp
<div data-informacja-zrodlo-ai="{{ \App\Domain\Zgody\InformacjaTekstuZrodlaAi::WERSJA }}" class="stack">
    <h2 class="mt-0">Zgoda na wysłanie do OpenAI (tylko ten jeden raz)</h2>
    @if($pdf)
        <p class="mt-0">
            Plik z tekstem odczytamy u siebie i nic nie wyślemy. Jeśli to <strong>skan, w którym nie ma tekstu</strong>,
            odczyta go komputer firmy <strong>OpenAI</strong> w USA — ale tylko za Twoją zgodą.
        </p>
        <ul class="stack-tight">
            <li>Wyślemy <strong>obrazy stron tego pliku</strong>, bez danych zapisanych w pliku, bez Twojego imienia, adresu e-mail i adresu IP.</li>
            <li>Wysyłamy wszystko, co widać na tych stronach. Jeśli są tam czyjeś dane — nazwisko, telefon, adres — <strong>usuń te strony z pliku przed dodaniem</strong>.</li>
        </ul>
    @else
        <p class="mt-0">
            Jeśli strona nie ma danych przepisu zapisanych w sposób, który odczytamy u siebie, jej tekst
            może odczytać komputer firmy <strong>OpenAI</strong> w USA — ale tylko za Twoją zgodą.
        </p>
        <ul class="stack-tight">
            <li>Wyślemy <strong>sam tekst tej strony</strong> (najwyżej 12 tysięcy znaków), bez adresu strony, zdjęć, komentarzy czytelników, Twojego imienia, adresu e-mail i adresu IP.</li>
            <li>Komputer wskaże tylko, które wiersze to tytuł, składniki i kroki. Tekst szkicu złożymy u siebie z oryginału.</li>
            <li>Jeśli strona ma dane przepisu, odczytamy je u siebie i nic nie wyślemy.</li>
        </ul>
    @endif
    <ul class="stack-tight">
        <li>Wynik trafi tylko do Twojego prywatnego szkicu. Nic się nie opublikuje, dopóki nie sprawdzisz tekstu i nie klikniesz „Opublikuj”.</li>
        <li><strong>Ta zgoda dotyczy tylko tego jednego wysłania.</strong> Nie zapisujemy jej na później i nie zastępuje zgody na zdjęcia kartek — tamta jest osobna i tu niczego nie odblokowuje.</li>
        <li>Bez zaznaczenia pola nic nie wysyłamy. Powiemy wtedy, co zrobić, albo przepis wpiszesz ręcznie.</li>
        <li>Jak długo OpenAI przechowuje wysłane dane, wynika z jej warunków, nie z naszych ustaleń. Prosimy firmę, by nie zapisywała odpowiedzi do późniejszego pobrania. Więcej: <a href="{{ route('privacy') }}">polityka prywatności</a>.</li>
    </ul>
</div>

<input type="hidden" name="{{ \App\Domain\Zgody\InformacjaTekstuZrodlaAi::POLE }}" value="{{ \App\Domain\Zgody\InformacjaTekstuZrodlaAi::WERSJA }}">
<label class="field"><input type="checkbox" name="zgoda_ai" value="1" @checked(old('zgoda_ai'))>
    @if($pdf)
        Zgadzam się, by w razie potrzeby wysłać obrazy stron tego pliku do OpenAI w USA w celu odczytania przepisu.
    @else
        Zgadzam się, by w razie potrzeby wysłać tekst tej strony do OpenAI w USA w celu wyznaczenia części przepisu.
    @endif
    Ta zgoda dotyczy tylko tego wysłania.
</label>
