{{--
    Sobotnie przypomnienie o produktach do zużycia (#1903, D-333).
    Wychodzi wyłącznie za osobną zgodą, raz w tygodniu, rano, i tylko gdy jest
    co wymienić. Jedna kolumna, duży tekst, bez obrazków. Style inline, bo
    Gmail i Outlook usuwają <style> z <head>. Bez słów „świeże”, „bezpieczne”.
--}}
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Produkty do zużycia w najbliższych dniach</title>
</head>
<body style="margin:0;padding:0;background:#F3F4F1;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F3F4F1;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="max-width:600px;background:#FFFFFF;border:1px solid #DDE0D8;border-radius:24px;">
                <tr>
                    <td style="padding:32px 28px;font-family:Arial,Helvetica,sans-serif;font-size:19px;line-height:1.6;color:#151714;">
                        <p style="margin:0 0 16px;font-size:24px;line-height:1.4;font-weight:bold;color:#151714;">Produkty do zużycia w najbliższych dniach</p>
                        <p style="margin:0 0 16px;">
                            Na Twojej liście „Co mam w domu” te produkty mają termin, który minął albo upływa w ciągu {{ $dni }} {{ $dni === 1 ? 'dnia' : 'dni' }}.
                            Produkty po terminie „Należy zużyć do” nie trafiają do tego listu ani do propozycji gotowania.
                        </p>
                        <ul style="margin:0 0 20px;padding-left:22px;">
                            @foreach($pozycje as $pozycja)
                                <li style="margin:0 0 10px;">
                                    <strong>{{ $pozycja['nazwa'] }}</strong>@if($pozycja['ilosc']) ({{ $pozycja['ilosc'] }})@endif<br>
                                    {{ $pozycja['termin'] }}. {{ $pozycja['stan'] }}
                                </li>
                            @endforeach
                        </ul>
                        @if($reszta > 0)
                            <p style="margin:0 0 20px;">I jeszcze {{ $reszta }} {{ \App\Support\Odmiana::rzeczownik($reszta, 'produkt', 'produkty', 'produktów') }} na liście.</p>
                        @endif
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px;">
                            <tr>
                                <td align="center" bgcolor="#BE3025" style="border-radius:14px;">
                                    <a href="{{ $przepisy }}"
                                       style="display:inline-block;padding:16px 32px;min-height:48px;box-sizing:border-box;
                                              font-family:Arial,Helvetica,sans-serif;font-size:20px;font-weight:bold;
                                              color:#FFFFFF;text-decoration:none;">
                                        Zobacz, co ugotować
                                    </a>
                                </td>
                            </tr>
                        </table>
                        <p style="margin:0;font-size:18px;color:#555E53;">
                            Dostajesz ten list raz w tygodniu, w sobotę, bo na stronie „Co mam w domu” zaznaczono zgodę na sobotnie przypomnienie.
                            W liście są nazwy produktów z Twojej listy — jeśli skrzynkę czyta ktoś jeszcze, możesz się wypisać.
                            <a href="{{ $wypisz }}" style="color:#555E53;">Nie chcę więcej takich listów</a>
                            — bez logowania.
                            Listę i ustawienia znajdziesz na stronie <a href="{{ $lista }}" style="color:#555E53;">Co mam w domu</a>.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
