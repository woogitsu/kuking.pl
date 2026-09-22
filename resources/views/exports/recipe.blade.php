{{--
    Jeden przepis, jeden plik. Otwiera się dwuklikiem, bez internetu.

    Zdjęcia są podlinkowane ścieżką relatywną (`../zdjecia/...`), więc działają
    dopóki katalogi leżą obok siebie — czyli także po skopiowaniu paczki
    na pendrive'a albo wydrukowaniu przez „Ctrl+P”.
--}}
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $recipe->title }} — mój przepis z Kuking</title>
    @include('exports.styles')
</head>
<body>
<div class="strona">

    <p class="powrot"><a href="../index.html">&larr; Wróć do spisu treści</a></p>

    <div class="naglowek">
        <h1>{{ $recipe->title }}</h1>
        @if($recipe->status !== 'published')
            <p><span class="plakietka">To był szkic — nigdy nie został opublikowany</span></p>
        @elseif($recipe->visibility !== 'public')
            <p><span class="plakietka">Przepis widoczny tylko dla wybranych osób</span></p>
        @endif
        <p class="podpis">
            @if($recipe->published_at)
                Opublikowany {{ \App\Support\Czas::data($recipe->published_at, 'j F Y') }}.
            @elseif($recipe->created_at)
                Zapisany {{ \App\Support\Czas::data($recipe->created_at, 'j F Y') }}.
            @endif
            {{ $recipe->attributionLine() }}
        </p>
    </div>

    @if($heroPhoto)
        <img class="zdjecie" src="{{ $heroPhoto }}" alt="{{ $recipe->title }}">
    @endif

    @if($recipe->summary)
        <p>{{ $recipe->summary }}</p>
    @endif

    <p class="fakty">
        @if($recipe->servings)<span>Porcje: {{ rtrim(rtrim(number_format($recipe->servings, 1, ',', ' '), '0'), ',') }}</span>@endif
        @if($recipe->prep_minutes)<span>Przygotowanie: {{ $recipe->prep_minutes }} min</span>@endif
        @if($recipe->cook_minutes)<span>Gotowanie: {{ $recipe->cook_minutes }} min</span>@endif
        @if($recipe->difficultyLabel())<span>Trudność: {{ $recipe->difficultyLabel() }}</span>@endif
    </p>

    @if($recipe->ingredients->isNotEmpty())
        <h2>Składniki</h2>
        {{--
            Grupy („Ciasto”, „Farsz”) układa `App\Domain\Recipes\GrupySkladnikow`
            — ten sam kod, co na stronie przepisu. Wcześniej stała tu druga
            kopia tej reguły: nagłówek pojawiał się przy KAŻDEJ zmianie
            wartości, więc lista z przeplotem („Ciasto, Farsz, Ciasto”)
            dawała w pliku dwa nagłówki „Ciasto”, a na stronie jeden.
            Eksport ma pokazywać ten sam przepis, co serwis — to jest kopia
            własnych danych człowieka, a nie druga wersja przepisu.

            Nagłówek grupy jest tu prawdziwym `<h3>` pod `<h2>Składniki`,
            a nie pogrubioną pozycją listy: pogrubione `<li>` udaje nagłówek
            dla oka i nie istnieje dla czytnika ekranu, a przy okazji liczy
            się jako składnik, którego nie ma w żadnej kuchni.
        --}}
        @foreach(\App\Domain\Recipes\GrupySkladnikow::ulozyc($recipe->ingredients) as $grupa)
            @if($grupa['nazwa'] !== null)
                <h3>{{ $grupa['nazwa'] }}</h3>
            @endif
            <ul class="skladniki">
                @foreach($grupa['skladniki'] as $item)
                    {{-- Dokładnie tak, jak człowiek to wpisał („2 szklanki mąki”). --}}
                    <li>
                        {{ $item->ingredient_text }}
                        @if($item->note)<span class="podpis"> — {{ $item->note }}</span>@endif
                    </li>
                @endforeach
            </ul>
        @endforeach
    @endif

    @if($recipe->steps->isNotEmpty())
        <h2>Jak to zrobić</h2>
        <ol class="kroki">
            @foreach($recipe->steps as $step)
                <li>
                    {{ $step->instruction }}
                    @if($step->timer_seconds)
                        <p class="podpis">Czas: {{ (int) round($step->timer_seconds / 60) }} min</p>
                    @endif
                    {{-- `position` w bazie liczy się od zera; człowiekowi
                         pokazujemy numery od jedynki. --}}
                    @if($stepPhotos[$step->getKey()] ?? null)
                        <img class="zdjecie" src="{{ $stepPhotos[$step->getKey()] }}" alt="Krok {{ $loop->iteration }}">
                    @endif
                </li>
            @endforeach
        </ol>
    @endif

    @if($recipe->source_note || $recipe->source_person || $recipe->source_url)
        <h2>Skąd ten przepis</h2>
        <div class="karta">
            {{-- „Skąd:" zamiast dawnego „Od:" — ta sama etykieta co przy polu
                 w formularzu i ten sam nagłówek co na stronie przepisu. Forma
                 z dwukropkiem jest odporna na odmianę: działa dla „od mamy",
                 dla „Nasze smaki" i dla „z gazety Przyjaciółka" jednakowo. --}}
            @if($recipe->source_person)<p>Skąd: {{ $recipe->source_person }}</p>@endif
            @if($recipe->family_since_year)<p>W rodzinie od {{ $recipe->family_since_year }} roku.</p>@endif
            @if($recipe->source_note)<p>{{ $recipe->source_note }}</p>@endif
            @if($recipe->source_url)<p>Źródło: {{ $recipe->source_url }}</p>@endif
        </div>
    @endif

    @if($scanPhoto)
        <h2>Skan z zeszytu</h2>
        <img class="zdjecie" src="{{ $scanPhoto }}" alt="Skan przepisu z zeszytu">
    @endif

    @if(count($comments) > 0)
        <h2>Co napisali inni</h2>
        <p class="podpis">
            Zapisujemy treść, datę i podpis, którym ta osoba się przedstawiała.
            Nie zapisujemy jej adresu e-mail — to nie są Twoje dane.
        </p>
        @foreach($comments as $comment)
            <div class="karta">
                <p class="podpis"><strong>{{ $comment['autor'] }}</strong>@if($comment['napisano']) · {{ \App\Support\Czas::data(\Illuminate\Support\Carbon::parse($comment['napisano']), 'j F Y') }}@endif</p>
                <p>{{ $comment['tresc'] }}</p>
                @foreach($comment['odpowiedzi'] as $reply)
                    <div style="margin-left:24px;border-left:3px solid var(--ramka);padding-left:16px;">
                        <p class="podpis"><strong>{{ $reply['autor'] }}</strong>@if($reply['napisano']) · {{ \App\Support\Czas::data(\Illuminate\Support\Carbon::parse($reply['napisano']), 'j F Y') }}@endif</p>
                        <p>{{ $reply['tresc'] }}</p>
                    </div>
                @endforeach
            </div>
        @endforeach
    @endif

    <p class="stopka">
        Ten przepis pochodzi z Twojej paczki z danymi z Kuking.pl.
        Wróć do <a href="../index.html">spisu treści</a>.
    </p>
</div>
</body>
</html>
