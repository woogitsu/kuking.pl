{{--
    „Kopia tego przepisu” (#2531, V2, decyzja właściciela z 2.10.2026).
    Ekran przed pobraniem: mówi, CO będzie w pliku, CZEGO w nim nie będzie i jak
    wczytać przepis z powrotem. Niczego nie tworzy — pobranie to osobny POST
    (zwykły formularz, bez JavaScriptu). Przepis należy do zalogowanej osoby
    (`RecipePolicy::exportCopy`); nic tu nie jest publiczne.
--}}
<x-layout title="Kopia przepisu" :noindex="true">
    <div class="stack kolumna-czytania">
        <p class="mb-0"><a href="{{ route('recipes.show', $przepis->slug) }}">Wróć do przepisu</a></p>

        <h1>Kopia przepisu „{{ $przepis->title }}”</h1>

        <p>
            Pobierzesz mały plik ZIP z tym jednym przepisem. Możesz go przeczytać bez internetu,
            zachować na swoim komputerze albo później wczytać do Kuking jako własny, prywatny szkic.
        </p>

        <section aria-labelledby="kopia-zawiera">
            <h2 id="kopia-zawiera">Co będzie w pliku</h2>
            <ul>
                <li>Nazwa i krótki opis przepisu.</li>
                <li>Składniki: {{ $liczbaSkladnikow }} (z grupami, uwagami, zamiennikami i zaznaczeniem „Bez ilości”).</li>
                <li>Kroki przygotowania: {{ $liczbaKrokow }} (z nazwami etapów i minutnikami).</li>
                @if($maPochodzenie)
                    <li>To, skąd masz ten przepis: od kogo, historia przepisu, rok w rodzinie i adres źródła, jeśli je wpisano.</li>
                @endif
                <li>Strona <strong>przepis.html</strong> do czytania i druku oraz krótka instrukcja po polsku.</li>
            </ul>
        </section>

        <section aria-labelledby="kopia-nie-zawiera">
            <h2 id="kopia-nie-zawiera">Czego w pliku nie będzie</h2>
            <ul>
                <li>
                    <strong>Zdjęć</strong>
                    @if($maZdjecia)
                        — ten przepis ma zdjęcia{{ $maSkan ? ' i skan z zeszytu' : '' }}, ale zostaną w Kuking, a w kopii będzie sam tekst.
                    @else
                        — kopia zawiera sam tekst.
                    @endif
                </li>
                <li>Komentarzy innych osób, informacji o tym, kto ten przepis ugotował, i wcześniejszych wersji.</li>
                <li>Danych Twojego konta: profilu, adresu e-mail, zgód, zeszytów i innych przepisów.</li>
            </ul>
        </section>

        <section aria-labelledby="kopia-wczytanie">
            <h2 id="kopia-wczytanie">Jak wczytać przepis z powrotem</h2>
            <p>
                W <a href="{{ route('settings.data.import') }}">Ustawieniach, w „Wczytaj swoją paczkę”</a> wybierz ten plik ZIP.
                Najpierw zobaczysz podgląd, a przepis wróci jako prywatny szkic dopiero po Twoim kliknięciu.
                Wczytujemy tekst: nazwę, opis, składniki z grupami, uwagami i zamiennikami oraz kroki z etapami.
                Jeśli masz już taki przepis, podgląd to pokaże i nie wczyta go drugi raz.
            </p>
        </section>

        <form method="POST" action="{{ route('recipes.copy.download', $przepis->slug) }}" novalidate>
            @csrf
            <button class="btn btn-primary" type="submit">Pobierz kopię tego przepisu</button>
        </form>

        <p class="meta">
            Plik przygotowujemy w chwili pobrania i nie zostaje on na naszym serwerze ani pod żadnym stałym adresem.
            Pełną paczkę wszystkich swoich danych zamówisz w <a href="{{ route('settings.data') }}">Ustawieniach, w „Twoje dane”</a>.
        </p>
    </div>
</x-layout>
