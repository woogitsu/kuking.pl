{{--
    E-mail „Potwierdź nowy adres e-mail" (issue #195) — idzie na NOWY adres.

    Różni się od `mail/potwierdz-adres` jedną rzeczą, ale ważną: musi
    powiedzieć wprost, że DO KLIKNIĘCIA logowanie i odzyskiwanie hasła
    działają na STARYM adresie. Bez tego zdania człowiek, który nie znajdzie
    listu, uzna, że stracił dostęp do konta — i przestanie próbować.

    Budowa i styl jak w `mail/potwierdz-adres`.
--}}
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Potwierdź nowy adres e-mail w Kuking</title>
</head>
<body style="margin:0;padding:0;background:#FAF6F0;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#FAF6F0;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="max-width:600px;background:#FFFFFF;border:1px solid #E4DACB;border-radius:12px;">
                <tr>
                    <td style="padding:32px 28px;font-family:Georgia,'Times New Roman',serif;font-size:19px;line-height:1.6;color:#2B241D;">

                        <p style="margin:0 0 20px;font-size:28px;line-height:1.3;font-weight:bold;color:#2B241D;">
                            Potwierdź nowy adres e-mail
                        </p>

                        <p style="margin:0 0 20px;">{{ $displayName ? $displayName.',' : 'Dzień dobry,' }}</p>

                        <p style="margin:0 0 20px;">
                            ten adres ma od teraz służyć do logowania w Kuking i do odzyskiwania
                            hasła. Zanim go włączymy, potwierdź, że to Twoja skrzynka.
                        </p>

                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px;">
                            <tr>
                                <td align="center" bgcolor="#B3401F" style="border-radius:8px;">
                                    <a href="{{ $linkUrl }}"
                                       style="display:inline-block;padding:16px 32px;min-height:48px;box-sizing:border-box;
                                              font-family:Arial,Helvetica,sans-serif;font-size:20px;font-weight:bold;
                                              color:#FFFFFF;text-decoration:none;">
                                        Potwierdź nowy adres
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:0 0 20px;font-size:18px;color:#5C5347;">
                            Do tego czasu nic się nie zmienia: logujesz się i odzyskujesz hasło
                            starym adresem, tym samym co dotąd. Konto działa normalnie.
                        </p>

                        <p style="margin:0 0 20px;font-size:18px;color:#5C5347;">
                            Link działa do {{ $waznyDo }}. Jeśli minie ten termin, w Kuking,
                            na stronie „Adres e-mail" w ustawieniach, poprosisz o nowy.
                        </p>

                        <p style="margin:0 0 12px;font-size:18px;color:#5C5347;">
                            Jeśli przycisk nie działa, skopiuj ten adres i wklej go w pasku przeglądarki:
                        </p>

                        <p style="margin:0 0 20px;font-family:Arial,Helvetica,sans-serif;font-size:16px;
                                  line-height:1.5;color:#5C5347;word-break:break-all;">
                            {{ $linkUrl }}
                        </p>

                        <p style="margin:0;font-size:18px;color:#5C5347;">
                            Jeśli nie prosiłeś/aś o nic takiego — nie klikaj i nie musisz nic robić.
                            Bez kliknięcia ten adres nie zostanie z niczym powiązany. Możesz też
                            napisać do nas na {{ config('kuking.community.contact_email') }}.
                        </p>

                    </td>
                </tr>
            </table>

            <p style="max-width:600px;margin:20px auto 0;font-family:Arial,Helvetica,sans-serif;
                      font-size:15px;line-height:1.5;color:#5C5347;">
                Kuking.pl — pokaż, co dziś ugotowałeś.
            </p>
        </td>
    </tr>
</table>
</body>
</html>
