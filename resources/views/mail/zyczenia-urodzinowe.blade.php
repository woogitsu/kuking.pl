{{--
    List z życzeniami urodzinowymi (issue #1755, etap c).
    Wychodzi wyłącznie za osobną zgodą, raz w roku, rano. Jedna kolumna,
    duży tekst, bez obrazków. Style inline, bo Gmail i Outlook usuwają
    <style> z <head>. Tekst życzeń składa `Urodziny::tekstZyczen()`.
--}}
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Wszystkiego dobrego z okazji urodzin</title>
</head>
<body style="margin:0;padding:0;background:#F3F4F1;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F3F4F1;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="max-width:600px;background:#FFFFFF;border:1px solid #DDE0D8;border-radius:24px;">
                <tr>
                    <td style="padding:32px 28px;font-family:Arial,Helvetica,sans-serif;font-size:19px;line-height:1.6;color:#151714;">
                        <p style="margin:0 0 20px;font-size:24px;line-height:1.4;font-weight:bold;color:#151714;">{{ $zyczenia }}</p>
                        <p style="margin:0 0 28px;">— {{ $gospodarz }} z Kuking</p>
                        <p style="margin:0;font-size:18px;color:#555E53;">
                            Dostajesz ten list, bo w ustawieniach urodzin zaznaczono zgodę na e-mail z życzeniami.
                            <a href="{{ $wypisz }}" style="color:#555E53;">Nie chcę więcej takich listów</a>
                            — jedno kliknięcie, bez logowania.
                            Datę i wybory zmienisz w <a href="{{ $ustawienia }}" style="color:#555E53;">ustawieniach urodzin</a>.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
