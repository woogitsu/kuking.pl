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
    nie ma `route()` i nie ma żadnego zapytania. Style są inline, żeby strona
    wyglądała jak Kuking nawet wtedy, gdy nic innego nie działa.

    Kolory i skala tekstu przepisane z resources/css/tokens.css — to jest
    świadome powtórzenie, a nie przeoczenie: ten plik ma działać bez CSS-a.

    Zmienne: $tytul, $naglowek, $akapity (lista), $adresPowrotu (opcjonalny).
--}}
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $tytul }} — Kuking</title>
</head>
<body style="margin:0;padding:0;background:#FAF6F0;color:#2B241D;
             font-family:Georgia,'Times New Roman',serif;font-size:19px;line-height:1.6;">

<main style="max-width:38rem;margin:0 auto;padding:3rem 1.25rem;">

    <p style="margin:0 0 2rem;font-family:Arial,Helvetica,sans-serif;font-size:1.25rem;
              font-weight:bold;letter-spacing:0.01em;color:#B3401F;">
        KuKing.pl
    </p>

    <h1 style="margin:0 0 1.5rem;font-size:2rem;line-height:1.25;color:#2B241D;">
        {{ $naglowek }}
    </h1>

    @foreach($akapity as $akapit)
        <p style="margin:0 0 1.25rem;">{{ $akapit }}</p>
    @endforeach

    @if(! empty($adresPowrotu))
        <p style="margin:2rem 0 0;">
            <a href="{{ $adresPowrotu }}"
               style="display:inline-block;padding:0.9rem 1.75rem;min-height:48px;box-sizing:border-box;
                      background:#B3401F;color:#FFFFFF;text-decoration:none;border-radius:8px;
                      font-family:Arial,Helvetica,sans-serif;font-size:1.125rem;font-weight:bold;">
                Spróbuj jeszcze raz
            </a>
        </p>
    @endif

</main>
</body>
</html>
