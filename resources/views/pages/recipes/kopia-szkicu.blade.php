{{--
    „Zrób kopię” własnego szkicu (#2507, V2, D-333 — paczka E). Ekran potwierdzenia
    zakresu: nic się nie zapisuje, dopóki nie klikniesz „Zrób kopię”. Zwykły formularz,
    bez skryptu. Klucz kopii = tożsamość tego wysłania (ponowienie nie dubluje).
--}}
<x-layout title="Zrób kopię szkicu" :noindex="true">
    <p><a class="btn btn-quiet" href="{{ route('recipes.drafts') }}">Wróć do szkiców</a></p>

    <h1>Zrób kopię szkicu</h1>
    <p>Szkic: <strong>{{ $szkic->title }}</strong>. Powstanie <strong>osobny, prywatny szkic</strong> do pracy nad drugim wariantem. Ten szkic zostaje dokładnie taki, jaki jest.</p>

    <x-error-summary />

    <section class="sekcja-strony" aria-labelledby="kopia-zakres">
        <h2 id="kopia-zakres">Co się skopiuje</h2>
        <ul>
            <li>tytuł (z dopiskiem „ — kopia”), opis, porcje, czasy i trudność;</li>
            <li>składniki ({{ $skladnikow }}) z grupami, uwagami, zamiennikami i oznaczeniem „bez ilości”;</li>
            <li>kroki ({{ $krokow }}) z minutnikami i nazwami etapów;</li>
            <li>rodzinne pochodzenie Twojej pracy (od kogo, opis, rok).</li>
        </ul>
        <h2 id="kopia-nie">Czego nie kopiujemy</h2>
        <ul>
            <li>zdjęć{{ $maZdjecia ? ' — ten szkic ma zdjęcia (główne, przy krokach albo skan kartki); w kopii ich nie będzie, dodasz je od nowa' : ' (ten szkic i tak ich nie ma)' }};</li>
            <li>oznaczenia alergenów i kosztu — ustalisz je od nowa dla wariantu;</li>
            <li>historii wersji, wykonań, komentarzy i powiadomień.</li>
        </ul>
        @if($jestAdaptacja)
            <p class="notice">Ten szkic jest Twoją wersją cudzego przepisu. Kopia zachowa podpis „Na podstawie przepisu…” — nie stanie się przepisem własnym.</p>
        @endif
        <p class="meta">Kopia powstanie z ostatnio zapisanego stanu szkicu ({{ $zapisanoO }}). Zmiany, które w otwartym kreatorze jeszcze się nie zapisały, nie wejdą do kopii. Kopię opublikujesz dopiero wtedy, gdy będzie się czymś różnić od pierwowzoru (składniki, kroki, porcje albo czasy).</p>
    </section>

    <form class="panel-formularza" method="POST" action="{{ route('recipes.drafts.copy.store', $szkic->getKey()) }}" novalidate>
        @csrf
        <input type="hidden" name="klucz_kopii" value="{{ $klucz }}">
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Zrób kopię</button>
            <a class="btn btn-secondary" href="{{ route('recipes.drafts') }}">Nie rób kopii</a>
        </div>
    </form>
</x-layout>
