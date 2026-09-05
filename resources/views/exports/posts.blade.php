{{--
    Wpisy i wykonania „Ugotowałem” w jednym czytelnym pliku.

    Świadomie NIE dzielimy tego na plik per wpis: wpisów bywa kilkaset,
    a nikt nie chce przeglądać katalogu z 400 plikami. Przepisy dostają
    osobne pliki, bo przepis czyta się przy garnku i drukuje pojedynczo.
--}}
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Moje wpisy i wykonania — dane z Kuking</title>
    @include('exports.styles')
</head>
<body>
<div class="strona">

    <p class="powrot"><a href="index.html">&larr; Wróć do spisu treści</a></p>

    <div class="naglowek">
        <h1>Twoje wpisy i wykonania</h1>
        <p class="podpis">Wszystko po kolei, od najstarszego. Także wpisy prywatne.</p>
    </div>

    <h2>Wpisy ({{ count($posts) }})</h2>
    @if(count($posts) === 0)
        <p>Nie ma tu jeszcze żadnego wpisu.</p>
    @endif

    @foreach($posts as $post)
        <div class="karta">
            <p class="podpis">
                {{ $post['data'] ?? 'bez daty' }}
                @if($post['prywatny'])
                    · <span class="plakietka">tylko dla Ciebie</span>
                @elseif($post['szkic'])
                    · <span class="plakietka">szkic</span>
                @endif
            </p>

            @if($post['tresc'])
                <p>{{ $post['tresc'] }}</p>
            @endif

            @foreach($post['zdjecia'] as $photo)
                <img class="zdjecie" src="{{ $photo }}" alt="Zdjęcie z wpisu">
            @endforeach

            @if($post['przepis'])
                <p class="podpis">Wpis dotyczył przepisu: {{ $post['przepis'] }}</p>
            @endif

            @foreach($post['komentarze'] as $comment)
                <div style="margin-left:16px;border-left:3px solid var(--ramka);padding-left:16px;">
                    <p class="podpis"><strong>{{ $comment['autor'] }}</strong></p>
                    <p>{{ $comment['tresc'] }}</p>
                </div>
            @endforeach
        </div>
    @endforeach

    <h2>„Ugotowałem” — Twoje wykonania ({{ count($cooked) }})</h2>
    @if(count($cooked) === 0)
        <p>Nie ma tu jeszcze żadnego zapisanego wykonania.</p>
    @endif

    @foreach($cooked as $event)
        <div class="karta">
            <h3 style="margin-top:0;">{{ $event['przepis'] ?? 'Przepis usunięty z serwisu' }}</h3>
            <p class="podpis">
                {{ $event['data'] ?? 'bez daty' }}
                @if($event['autor']) · przepis od: {{ $event['autor'] }}@endif
            </p>

            @if($event['notatka'])
                <p>{{ $event['notatka'] }}</p>
            @endif

            @if($event['zmiany'])
                <p><strong>Co zmieniłam:</strong> {{ $event['zmiany'] }}</p>
            @endif

            @if($event['jeszcze_raz'] !== null)
                <p class="podpis">{{ $event['jeszcze_raz'] ? 'Zrobię jeszcze raz.' : 'Raczej nie powtórzę.' }}</p>
            @endif

            @foreach($event['zdjecia'] as $photo)
                <img class="zdjecie" src="{{ $photo }}" alt="Zdjęcie z wykonania">
            @endforeach
        </div>
    @endforeach

    @if(count($ownComments) > 0)
        <h2>Twoje komentarze ({{ count($ownComments) }})</h2>
        <p class="podpis">Komentarze, które napisałaś — także pod przepisami innych osób.</p>
        @foreach($ownComments as $comment)
            <div class="karta">
                <p class="podpis">{{ $comment['napisano'] ? \Illuminate\Support\Carbon::parse($comment['napisano'])->translatedFormat('j F Y') : '' }}
                    @if($comment['pod_czym']) · {{ $comment['pod_czym'] }}@endif</p>
                <p>{{ $comment['tresc'] }}</p>
            </div>
        @endforeach
    @endif

    <p class="stopka">
        Wróć do <a href="index.html">spisu treści</a>.
    </p>
</div>
</body>
</html>
