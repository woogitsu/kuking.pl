{{--
    To jest plik, który człowiek faktycznie otworzy. `dane.json` jest dla
    programów; ten spis treści jest dla osoby, która chce zobaczyć swoje
    przepisy i zdjęcia — dzisiaj albo za dziesięć lat, bez internetu.
--}}
@php
    /**
     * Polska odmiana po liczbie: „1 wpis”, „2 wpisy”, „5 wpisów”.
     * Bez tego spis treści wita użytkowniczkę zdaniem „2 wpisów”,
     * co od razu zdradza, że tekst pisała maszyna.
     */
    $odmiana = function (int $n, string $jeden, string $kilka, string $wiele): string {
        $mod10 = $n % 10;
        $mod100 = $n % 100;

        if ($n === 1) {
            return $jeden;
        }

        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return $kilka;
        }

        return $wiele;
    };
@endphp
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Moje dane z Kuking — spis treści</title>
    @include('exports.styles')
</head>
<body>
<div class="strona">

    <div class="naglowek">
        <h1>Twoje dane z Kuking</h1>
        <p class="podpis">
            Paczka przygotowana {{ \App\Support\Czas::data($generatedAt, 'j F Y, H:i') }}
            @if($displayName) dla {{ $displayName }}@endif.
        </p>
    </div>

    <div class="karta">
        <p class="mb-0">
            To jest kopia wszystkiego, co zapisałaś w Kuking: przepisy, wpisy,
            zdjęcia i notatki. Możesz to trzymać na swoim komputerze i czytać
            <strong>bez internetu</strong> — także wtedy, gdyby Kuking kiedyś
            przestał istnieć. Nic tutaj nie wymaga zakładania konta.
        </p>
    </div>

    <h2>Twoje przepisy ({{ count($recipes) }})</h2>
    @if(count($recipes) === 0)
        <p>W tej paczce nie ma jeszcze żadnego przepisu.</p>
    @else
        <ul class="spis">
            @foreach($recipes as $recipe)
                <li>
                    <a href="przepisy/{{ $recipe['plik'] }}">{{ $recipe['tytul'] }}</a>
                    @if($recipe['szkic'])
                        <span class="plakietka">szkic</span>
                    @endif
                    @if($recipe['data'])
                        <br><span class="podpis">{{ $recipe['data'] }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    <h2>Twoje wpisy i wykonania</h2>
    <ul class="spis">
        <li>
            <a href="wpisy.html">Wpisy „co dziś ugotowałam” i notatki z gotowania</a>
            <br><span class="podpis">
                {{ $postCount }} {{ $odmiana($postCount, 'wpis', 'wpisy', 'wpisów') }},
                {{ $cookedCount }} {{ $odmiana($cookedCount, 'zapisane wykonanie', 'zapisane wykonania', 'zapisanych wykonań') }}
            </span>
        </li>
    </ul>

    <h2>Zdjęcia ({{ $photoCount }})</h2>
    {{--
        Katalog `zdjecia/` powstaje w paczce TYLKO wtedy, gdy jest do niego
        co włożyć — ZipArchive nie tworzy pustych katalogów. Bez tego warunku
        człowiek bez ani jednego zdjęcia dostawał zaproszenie „Otwórz katalog
        ze zdjęciami" prowadzące do miejsca, którego w paczce nie ma:
        przeglądarka pokazywała wtedy „nie znaleziono pliku".

        Usterka wychodzi WYŁĄCZNIE u kogoś, kto nie dodał jeszcze zdjęcia —
        czyli u osoby najświeższej w serwisie, i to w pliku, który ma być
        dowodem, że jej dane są bezpieczne. Żaden test tego nie łapał, bo
        wszystkie budowały eksport z gotowymi zdjęciami.
    --}}
    @if($photoCount > 0)
        <p>
            Wszystkie Twoje zdjęcia leżą w katalogu <strong>zdjecia</strong>, obok tego pliku.
            Nazwa każdego pliku zaczyna się od daty, więc łatwo je posortować —
            na przykład <em>2027-03-14-rosol.webp</em>.
        </p>
        <p><a href="zdjecia/">Otwórz katalog ze zdjęciami</a></p>
    @else
        <p>
            Nie masz jeszcze w Kuking żadnego zdjęcia, więc w tej paczce nie ma
            katalogu ze zdjęciami. Kiedy dodasz pierwsze i poprosisz o paczkę
            ponownie, znajdziesz je tutaj.
        </p>
    @endif

    <h2>Pliki techniczne</h2>
    <ul class="spis">
        <li>
            <a href="dane.json">dane.json</a>
            <br><span class="podpis">Wszystkie dane w formacie, który zrozumie inny serwis albo program.
            To jest plik na przeniesienie danych, nie do czytania.</span>
        </li>
        <li>
            <a href="CZYTAJ-TO-NAJPIERW.txt">CZYTAJ-TO-NAJPIERW.txt</a>
            <br><span class="podpis">Krótkie wyjaśnienie, co jest w środku i jak to otworzyć.</span>
        </li>
    </ul>

    <p class="stopka">
        Paczkę przygotował Kuking.pl na Twoją prośbę
        (RODO art. 15 i 20 — prawo dostępu do danych i prawo do ich przeniesienia).
        Komentarze innych osób są tu z treścią, datą i podpisem — bez ich adresów e-mail.
    </p>
</div>
</body>
</html>
