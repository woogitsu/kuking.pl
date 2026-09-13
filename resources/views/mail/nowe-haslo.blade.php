{{--
    E-mail „Ustaw nowe hasło” (issue #79).

    Ten list ma jedno zadanie, którego nie ma żaden inny: przekonać osobę,
    której bank i telewizja od lat powtarzają „nie klikaj w linki z maili”,
    że akurat ten link jest jej własny. Stąd trzy rzeczy, których nie ma
    w domyślnym liście Laravela:

    1. zdanie, SKĄD ten list się wziął i co się stanie, jeśli go zignoruje;
    2. pełny adres do przepisania, gdy przycisk nie działa — bez tego osoba
       z klientem pocztowym blokującym linki nie ma żadnej drogi dalej;
    3. adres kontaktowy Kuking, żeby dało się zapytać człowieka.

    Budowa jak w `mail/data-export-ready`: jedna kolumna, duży tekst, jeden
    przycisk, style inline (Gmail i Outlook wycinają <style> z <head>),
    zero obrazków i zero ikon bez podpisu.

    Bez gry słowem „kuKING" — D-009 zabrania jej w komunikatach technicznych.
--}}
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ustaw nowe hasło do Kuking</title>
</head>
<body style="margin:0;padding:0;background:#F3F4F1;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F3F4F1;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="max-width:600px;background:#FFFFFF;border:1px solid #DDE0D8;border-radius:24px;">
                <tr>
                    <td style="padding:32px 28px;font-family:Arial,Helvetica,sans-serif;font-size:19px;line-height:1.6;color:#151714;">

                        <p style="margin:0 0 20px;font-size:28px;line-height:1.3;font-weight:bold;color:#151714;">
                            Ustaw nowe hasło
                        </p>

                        <p style="margin:0 0 20px;">{{ $displayName ? $displayName.',' : 'Dzień dobry,' }}</p>

                        <p style="margin:0 0 20px;">
                            ktoś poprosił o nowe hasło do konta w Kuking założonego na ten adres.
                            Jeśli to Ty — kliknij przycisk poniżej i wpisz nowe hasło.
                        </p>

                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px;">
                            <tr>
                                <td align="center" bgcolor="#BE3025" style="border-radius:14px;">
                                    <a href="{{ $linkUrl }}"
                                       style="display:inline-block;padding:16px 32px;min-height:48px;box-sizing:border-box;
                                              font-family:Arial,Helvetica,sans-serif;font-size:20px;font-weight:bold;
                                              color:#FFFFFF;text-decoration:none;">
                                        Ustaw nowe hasło
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:0 0 20px;font-size:18px;color:#555E53;">
                            Link działa {{ $waznoscTekst }}. Potem trzeba poprosić o nowy:
                            na stronie logowania kliknij „Nie pamiętam hasła”.
                        </p>

                        <p style="margin:0 0 20px;font-size:18px;color:#555E53;">
                            Jeśli to nie Ty prosisz o zmianę hasła — nie trzeba nic robić.
                            Hasło zostaje takie, jakie było, a ten link niedługo przestanie działać.
                        </p>

                        <p style="margin:0 0 12px;font-size:18px;color:#555E53;">
                            Jeśli przycisk nie działa, skopiuj ten adres i wklej go w pasku przeglądarki:
                        </p>

                        <p style="margin:0 0 20px;font-family:Arial,Helvetica,sans-serif;font-size:16px;
                                  line-height:1.5;color:#555E53;word-break:break-all;">
                            {{ $linkUrl }}
                        </p>

                        <p style="margin:0;font-size:18px;color:#555E53;">
                            Jeśli nie możesz ustawić nowego hasła, napisz do nas na {{ config('kuking.community.contact_email') }}.
                        </p>

                    </td>
                </tr>
            </table>

            <p style="max-width:600px;margin:20px auto 0;font-family:Arial,Helvetica,sans-serif;
                      font-size:15px;line-height:1.5;color:#555E53;">
                Kuking.pl — pokaż, co dziś ugotowałeś.
            </p>
        </td>
    </tr>
</table>
</body>
</html>
