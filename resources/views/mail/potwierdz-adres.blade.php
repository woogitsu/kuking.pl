{{--
    E-mail „Potwierdź swój adres e-mail” (issue #79).

    Pierwszy list z serwisu. Ma tłumaczyć, PO CO to potwierdzenie — bo
    „zweryfikuj adres” bez powodu brzmi jak formalność wymyślona przez
    system, a nie jak coś, co jest komuś potrzebne.

    Powód jest prawdziwy i wart napisania wprost: bez potwierdzonego adresu
    nie da się odzyskać hasła ani pobrać własnych danych.

    Budowa i styl jak w `mail/nowe-haslo` i `mail/data-export-ready`.
--}}
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Potwierdź swój adres e-mail w Kuking</title>
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
                            Potwierdź swój adres e-mail
                        </p>

                        <p style="margin:0 0 20px;">{{ $displayName ? $displayName.',' : 'Dzień dobry,' }}</p>

                        <p style="margin:0 0 20px;">
                            konto w Kuking jest już gotowe. Została jedna rzecz: potwierdź,
                            że ta skrzynka należy do Ciebie. Wystarczy jedno kliknięcie.
                        </p>

                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px;">
                            <tr>
                                <td align="center" bgcolor="#BE3025" style="border-radius:14px;">
                                    <a href="{{ $linkUrl }}"
                                       style="display:inline-block;padding:16px 32px;min-height:48px;box-sizing:border-box;
                                              font-family:Arial,Helvetica,sans-serif;font-size:20px;font-weight:bold;
                                              color:#FFFFFF;text-decoration:none;">
                                        Potwierdź adres
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:0 0 20px;font-size:18px;color:#555E53;">
                            Potwierdzony adres jest potrzebny do dwóch rzeczy: żeby dało się
                            odzyskać hasło, gdyby kiedyś wyleciało z głowy, i żeby dało się
                            pobrać kopię swoich przepisów i zdjęć.
                        </p>

                        <p style="margin:0 0 20px;font-size:18px;color:#555E53;">
                            Link działa {{ $waznoscTekst }}. Jeśli minie — w Kuking, na stronie
                            „Potwierdź e-mail”, poprosisz o nowy.
                        </p>

                        <p style="margin:0 0 12px;font-size:18px;color:#555E53;">
                            Jeśli przycisk nie działa, skopiuj ten adres i wklej go w pasku przeglądarki:
                        </p>

                        <p style="margin:0 0 20px;font-family:Arial,Helvetica,sans-serif;font-size:18px;
                                  line-height:1.5;color:#555E53;word-break:break-all;">
                            {{ $linkUrl }}
                        </p>

                        <p style="margin:0;font-size:18px;color:#555E53;">
                            Jeśli to nie Ty zakładasz konto w Kuking — nie trzeba nic robić.
                            Bez potwierdzenia to konto nic z tym adresem nie zrobi.
                            Możesz też napisać do nas na {{ config('kuking.community.contact_email') }}.
                        </p>

                    </td>
                </tr>
            </table>

            <p style="max-width:600px;margin:20px auto 0;font-family:Arial,Helvetica,sans-serif;
                      font-size:18px;line-height:1.5;color:#555E53;">
                Kuking.pl — pokaż, co dziś ugotowałeś.
            </p>
        </td>
    </tr>
</table>
</body>
</html>
