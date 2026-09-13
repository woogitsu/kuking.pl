{{--
    Tygodniowe podsumowanie (issue #11, docs/DECISIONS.md D-057).

    BUDOWA JAK W `mail/data-export-ready` I `mail/nowe-haslo` — świadomie ta
    sama, nie „podobna": jedna kolumna, tabela zamiast siatki, tekst 19 px,
    style INLINE (Gmail i Outlook wycinają <style> z <head>), przyciski jako
    komórka tabeli z tłem, a nie jako <button>. Outlook na Windowsie renderuje
    pocztę silnikiem Worda — flexbox, grid i zewnętrzne arkusze po prostu
    w nim nie istnieją, a `min-height` na <a> nie działa, więc wysokość
    przycisku robi `padding`.

    ZERO OBRAZKÓW — i to jest decyzja, nie zapomnienie (D-057).
    Issue #11 prosiło o „alt teksty przy zdjęciach", zakładając, że zdjęcia
    w liście będą. Nie ma ich, bo `App\Models\Media::url()` prowadzi na trasę
    `media.show`, która sprawdza uprawnienia PATRZĄCEGO — a klient pocztowy
    jest niezalogowany. Zdjęcia albo by się nie pokazały (pusta ramka
    w każdym liście), albo trzeba by dla poczty poluzować kontrolę dostępu do
    cudzych zdjęć. Pierwsze jest brzydkie, drugie jest wyciekiem. Do tego
    większość klientów pocztowych i tak blokuje obrazki domyślnie, więc list
    MUSI działać bez nich — a skoro musi, to niech po prostu takim będzie.

    ZERO EMOJI, najwyżej jeden wykrzyknik, zero gry słowem „kuKING"
    (docs/brand/COPY_STYLE.md §4 i D-009 — to nie jest ekran na żarty,
    tylko list, który ktoś czyta rano przy kawie).
--}}
@php
    /** @var \App\Domain\Digest\TrescDigestu $tresc */
    $czcionka = "font-family:Arial,Helvetica,sans-serif;";
    $sekcja = 'margin:32px 0 12px;font-size:15px;line-height:1.4;font-weight:bold;'
        ."letter-spacing:0.06em;text-transform:uppercase;color:#555E53;font-family:Arial,Helvetica,sans-serif;";
    $pozycja = 'margin:0 0 18px;font-size:19px;line-height:1.6;color:#151714;';
    $cichy = 'margin:0 0 20px;font-size:17px;line-height:1.6;color:#555E53;';
@endphp
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Podsumowanie tygodnia w Kuking</title>
</head>
<body style="margin:0;padding:0;background:#F3F4F1;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F3F4F1;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="max-width:600px;background:#FFFFFF;border:1px solid #DDE0D8;border-radius:24px;">
                <tr>
                    <td style="padding:32px 28px;{{ $czcionka }}font-size:19px;line-height:1.6;color:#151714;">

                        <p style="margin:0 0 24px;font-size:19px;">Dzień dobry, {{ $imie }},</p>

                        @if($tresc->wykonania !== [])
                            {{--
                                BLOK OSOBISTY NA SAMEJ GÓRZE — issue #11 pkt 2
                                i docs/product/RETENTION_LOOPS.md §4.3.
                                Otwieralność bierze się z „to o mnie", nie
                                z „to ciekawe", a „Ugotowałem" jest najsilniejszą
                                rzeczą, jaka może się tu komuś przydarzyć
                                (AGENTS.md §1).
                            --}}
                            <p style="{{ $sekcja }}">Ktoś ugotował z Twojego przepisu</p>

                            @foreach($tresc->wykonania as $wykonanie)
                                <p style="{{ $pozycja }}">
                                    <strong>{{ $wykonanie->user?->displayName() }}</strong>
                                    &mdash; {{ $wykonanie->recipe?->title }}.
                                    @if(filled($wykonanie->note))
                                        <br>
                                        <span style="color:#555E53;">„{{ \Illuminate\Support\Str::limit($wykonanie->note, 180) }}”</span>
                                    @endif
                                </p>

                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
                                    <tr>
                                        <td align="center" bgcolor="#BE3025" style="border-radius:14px;">
                                            {{--
                                                „Podziękuj" — dokładnie ta sama nazwa co przycisk
                                                na ekranie „Komuś wyszło" (docs/product/RETENTION_LOOPS.md
                                                §4, uwaga na początku rozdziału). Inna nazwa w liście
                                                i na ekranie to dwa różne działania w oczach człowieka.
                                            --}}
                                            <a href="{{ route('cooked.show', ['cookedEvent' => $wykonanie->getKey()]) }}"
                                               style="display:inline-block;padding:14px 28px;box-sizing:border-box;
                                                      font-family:Arial,Helvetica,sans-serif;font-size:19px;font-weight:bold;
                                                      color:#FFFFFF;text-decoration:none;">
                                                Podziękuj
                                            </a>
                                        </td>
                                    </tr>
                                </table>
                            @endforeach
                        @endif

                        @if($tresc->ileNowychObserwujacych > 0)
                            <p style="{{ $sekcja }}">Nowe osoby przy Twoim gotowaniu</p>

                            <p style="{{ $pozycja }}">
                                @foreach($tresc->nowiObserwujacy as $osoba)
                                    <strong>{{ $osoba->displayName() }}</strong>@if(! $loop->last), @endif
                                @endforeach
                                @php($pozostali = $tresc->ileNowychObserwujacych - count($tresc->nowiObserwujacy))
                                @if($pozostali > 0)
                                    i jeszcze {{ $pozostali }}
                                    {{ \App\Support\Odmiana::rzeczownik($pozostali, 'osoba', 'osoby', 'osób') }}
                                @endif
                                &mdash; od tego tygodnia widzą, co gotujesz.
                            </p>
                        @endif

                        @if($tresc->wpisyObserwowanych !== [])
                            <p style="{{ $sekcja }}">Co pokazali ludzie, których obserwujesz</p>

                            @foreach($tresc->wpisyObserwowanych as $wpis)
                                <p style="{{ $pozycja }}">
                                    <strong>{{ $wpis->author?->displayName() }}</strong>
                                    @if(filled($wpis->body))
                                        &mdash; {{ \Illuminate\Support\Str::limit($wpis->body, 140) }}
                                    @elseif($wpis->recipe !== null)
                                        &mdash; {{ $wpis->recipe->title }}
                                    @endif
                                    <br>
                                    <a href="{{ route('posts.show', ['post' => $wpis->getKey()]) }}"
                                       style="color:#BE3025;">Zobacz</a>
                                </p>
                            @endforeach
                        @endif

                        @if($tresc->pytanieGospodarza !== null)
                            {{--
                                JEDNO ZDANIE OD GOSPODARZA NA KOŃCU, nie na
                                początku: docs/product/RETENTION_LOOPS.md §4.3
                                („sezon jako szept, nie baner"). Odpowiedź na
                                tego maila czyta człowiek i to jest napisane
                                niżej wprost.
                            --}}
                            <p style="margin:32px 0 0;font-size:19px;line-height:1.6;">
                                {{ $tresc->pytanieGospodarza }}
                            </p>
                        @endif

                        <p style="margin:28px 0 0;font-size:19px;">
                            Dobrego tygodnia,<br>
                            {{ $gospodarz }}
                        </p>

                        <hr style="border:0;border-top:1px solid #DDE0D8;margin:28px 0;">

                        <p style="{{ $cichy }}">
                            Piszę raz w tygodniu i tylko wtedy, gdy jest o czym.
                            Na tę wiadomość można po prostu odpowiedzieć &mdash; czytam wszystkie odpowiedzi.
                        </p>

                        {{--
                            STOPKA WYPISANIA — gotowy napis z docs/brand/COPY_STYLE.md §6
                            („Nie chcesz tych wiadomości? Wyłącz je jednym kliknięciem.
                            Bez pytań."). Odnośnik jest PODPISANY i działa BEZ LOGOWANIA
                            (issue #11 pkt 6). Pełny adres jest też wypisany słownie niżej,
                            bo część klientów pocztowych blokuje odnośniki, a wtedy jedyną
                            drogą wyjścia jest przepisanie adresu do przeglądarki.
                        --}}
                        <p style="margin:0 0 12px;font-size:17px;line-height:1.6;color:#555E53;">
                            Nie chcesz tych wiadomości?
                            <a href="{{ $wypisz }}" style="color:#BE3025;font-weight:bold;">Wyłącz je jednym kliknięciem</a>.
                            Bez pytań.
                        </p>

                        <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:14px;
                                  line-height:1.5;color:#555E53;word-break:break-all;">
                            {{ $wypisz }}
                        </p>

                    </td>
                </tr>
            </table>

            <p style="max-width:600px;margin:20px auto 0;font-family:Arial,Helvetica,sans-serif;
                      font-size:15px;line-height:1.5;color:#555E53;">
                Kuking.pl &mdash; pokaż, co dziś ugotowałeś.
            </p>
        </td>
    </tr>
</table>
</body>
</html>
