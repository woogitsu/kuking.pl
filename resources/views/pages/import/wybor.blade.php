<x-layout title="Skąd jest przepis?" :noindex="true">
    {{--
        CZTERY DUŻE PRZYCISKI JEDEN POD DRUGIM (V2, D-298, projekt §6.1).

        Wyłączone źródło NIE MA przycisku (D-053: bez martwych przycisków) —
        nie pokazujemy czegoś, co powie „niedostępne” dopiero po kliknięciu.
        „Wpiszę sam” jest ZAWSZE i tej samej wielkości co pozostałe: awaria
        albo wyłączenie importu nie zamyka drogi do dodania przepisu.
    --}}
    <h1>Skąd jest przepis?</h1>
    <p class="mb-6">Wybierz, jak chcesz go dodać. Każdy przepis zaczyna się jako prywatny szkic — nic się nie opublikuje bez Twojego kliknięcia.</p>

    <div class="stack">
        @if($zdjecie)
            <a class="kafel-akcji" href="{{ route('import.zdjecie') }}">
                <h2 class="mt-0">Przepisz z kartki lub zeszytu</h2>
                <p class="mb-0">Zrób zdjęcie, a komputer odczyta pismo. Potem sprawdzisz każdą linijkę ze zdjęciem obok.</p>
            </a>
        @endif

        @if($url)
            <a class="kafel-akcji" href="{{ route('import.url.create') }}">
                <h2 class="mt-0">Wklej adres strony</h2>
                <p class="mb-0">Przepis zapiszemy dla Ciebie, prywatnie.</p>
            </a>
        @endif

        @if($pdf)
            <a class="kafel-akcji" href="{{ route('import.pdf.create') }}">
                <h2 class="mt-0">Dodaj plik PDF</h2>
                <p class="mb-0">Przepis z pliku zapiszemy jako Twój prywatny szkic.</p>
            </a>
        @endif

        <a class="kafel-akcji" href="{{ route('recipes.create') }}">
            <h2 class="mt-0">Wpiszę sam</h2>
            <p class="mb-0">Składniki i przygotowanie, własnymi słowami. Możesz zapisać szkic i wrócić później.</p>
        </a>
    </div>
</x-layout>
