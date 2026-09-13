{{--
    Szkielet stron błędu, które NIE MOGĄ zależeć od reszty aplikacji
    (issue #81) — czyli 500 i 503.

    DLACZEGO NIE `x-layout`
    Layout serwisu pyta bazę: `auth()->user()`, licznik nieprzeczytanych
    powiadomień, nazwa profilu w nawigacji. Przy awarii bazy — czyli
    w najczęstszej przyczynie pięćsetki — renderowanie takiej strony samo
    rzuciłoby wyjątek, Laravel poddałby się i pokazał swoją angielską
    stronę awaryjną. Strona awarii nie może zależeć od tego, co właśnie padło.

    Z tego samego powodu nie ma tu `@vite` (arkusz mógłby się nie zbudować),
    nie ma `route()` i nie ma żadnego zapytania. Wszystko, czego ta strona
    potrzebuje, jest w tym jednym pliku.

    Kolory i skala tekstu przepisane z resources/css/tokens.css — to jest
    świadome powtórzenie, a nie przeoczenie: ten plik ma działać bez arkusza.

    DLACZEGO `<style nonce>`, A NIE ATRYBUTY `style=` (issue #107)
    Ta strona idzie po HTTP, więc obejmuje ją polityka bezpieczeństwa. Atrybutów
    `style=` żaden nonce nie obejmuje — działa on na ELEMENT `<style>` — więc
    dopóki były tutaj, `style-src` musiał trzymać `unsafe-inline` dla całego
    serwisu. Jeden blok `<style>` z podpisem załatwia to samo i nie wymaga
    zewnętrznego arkusza, czyli nie łamie zasady wyżej.

    Gdyby nonce z jakiegoś powodu nie powstał (awaria PRZED middleware, które
    go nadaje), blok idzie bez podpisu i przeglądarka go odrzuci. Strona
    wyrenderuje się wtedy bez stylów — szara, ale w pełni czytelna: nagłówek,
    akapity i link są zwykłym HTML-em. To jest gorszy wygląd, nie utrata treści.

    Zmienne: $tytul, $naglowek, $akapity (lista), $adresPowrotu (opcjonalny).
--}}
@php
    $nonce = \Illuminate\Support\Facades\Vite::cspNonce();
@endphp
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $tytul }} — Kuking</title>
    <style @if($nonce) nonce="{{ $nonce }}" @endif>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 24px 16px;
            background: #F3F4F1;
            color: #151714;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Sans", Arial, sans-serif;
            font-size: 1.125rem;
            line-height: 1.6;
        }

        main {
            max-width: 38rem;
            margin: 0 auto;
            padding: clamp(20px, 5vw, 40px);
            background: #FFFFFF;
            border: 1px solid #DDE0D8;
            border-radius: 24px;
            overflow-wrap: anywhere;
            box-shadow: 0 8px 24px rgba(21, 23, 20, .05);
        }

        .znak {
            margin: 0 0 2rem;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 1.25rem;
            font-weight: bold;
            letter-spacing: 0.01em;
            color: #BE3025;
        }

        h1 {
            margin: 0 0 1.5rem;
            font-size: 2rem;
            line-height: 1.25;
            color: #151714;
        }

        p {
            margin: 0 0 1.25rem;
        }

        .powrot {
            margin: 2rem 0 0;
        }

        /* 48 px wysokości i tekst obok ikony — ta sama reguła co w reszcie
           serwisu (docs/UX_50_PLUS.md). Strona awarii nie jest wyjątkiem:
           trafia na nią ktoś już zdenerwowany. */
        .powrot a {
            display: inline-block;
            scroll-margin-block: 8px;
            padding: 14px 20px;
            min-height: 48px;
            box-sizing: border-box;
            background: #BE3025;
            color: #FFFFFF;
            text-decoration: none;
            border-radius: 14px;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 1.125rem;
            font-weight: bold;
        }
        .powrot a:hover { background: #9D241B; }
        .powrot a:focus-visible { outline: 3px solid #155EEF; outline-offset: 4px; }
    </style>
</head>
<body>

<main>

    <p class="znak">KuKing.pl</p>

    <h1>{{ $naglowek }}</h1>

    @foreach($akapity as $akapit)
        <p>{{ $akapit }}</p>
    @endforeach

    @if(! empty($adresPowrotu))
        <p class="powrot">
            <a href="{{ $adresPowrotu }}">Spróbuj jeszcze raz</a>
        </p>
    @endif

</main>
</body>
</html>
