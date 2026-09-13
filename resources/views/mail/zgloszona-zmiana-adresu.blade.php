{{--
    E-mail „Ktoś prosi o zmianę adresu e-mail Twojego konta" (issue #195) —
    idzie na STARY, wciąż obowiązujący adres.

    To jest jedyne ostrzeżenie, jakie dostanie osoba, której konto ktoś
    właśnie próbuje przejąć. Ton: poziom „poważny" z docs/brand/COPY_STYLE.md
    — zero żartów, zero gry słowem kuKING, zero emoji.

    Kolejność zdań jest przemyślana: najpierw „nic się nie zmieniło", potem
    „jeśli to Ty", na końcu „jeśli to nie Ty". Odwrotna kolejność zaczynałaby
    list od straszenia kogoś, kto po prostu sam poprosił o zmianę.
--}}
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ktoś prosi o zmianę adresu e-mail Twojego konta w Kuking</title>
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
                            Ktoś prosi o zmianę adresu Twojego konta
                        </p>

                        <p style="margin:0 0 20px;">{{ $displayName ? $displayName.',' : 'Dzień dobry,' }}</p>

                        <p style="margin:0 0 20px;">
                            z konta w Kuking wysłano prośbę o przeniesienie go na inny adres
                            e-mail: <strong>{{ $nowyAdresSkrot }}</strong>. Pokazujemy ten adres
                            w skrócie celowo — po pierwszej literze i domenie poznasz, czy to
                            Twoja skrzynka.
                        </p>

                        <p style="margin:0 0 24px;">
                            <strong>Na razie nic się nie zmieniło.</strong> Twoje konto nadal
                            działa na tym adresie: tu przychodzą wiadomości, tym adresem się
                            logujesz i tym odzyskujesz hasło.
                        </p>

                        <p style="margin:0 0 12px;font-size:18px;color:#555E53;">
                            <strong>Jeśli to Ty</strong> — nic nie musisz tutaj robić. Wysłaliśmy
                            list z odnośnikiem na tamten nowy adres; adres zmieni się dopiero po
                            kliknięciu w niego. Prośba jest ważna do {{ $waznyDo }}.
                        </p>

                        <p style="margin:0 0 20px;font-size:18px;color:#555E53;">
                            <strong>Jeśli to nie Ty</strong> — ktoś obcy mógł dostać się do
                            Twojego konta. Zmień hasło. Zmiana hasła od razu unieważnia tę prośbę
                            i wylogowuje wszystkie inne urządzenia.
                        </p>

                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px;">
                            <tr>
                                <td align="center" bgcolor="#BE3025" style="border-radius:14px;">
                                    <a href="{{ $linkHaslo }}"
                                       style="display:inline-block;padding:16px 32px;min-height:48px;box-sizing:border-box;
                                              font-family:Arial,Helvetica,sans-serif;font-size:20px;font-weight:bold;
                                              color:#FFFFFF;text-decoration:none;">
                                        Zmień hasło
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:0 0 12px;font-size:18px;color:#555E53;">
                            Jeśli przycisk nie działa, skopiuj ten adres i wklej go w pasku przeglądarki:
                        </p>

                        <p style="margin:0 0 20px;font-family:Arial,Helvetica,sans-serif;font-size:16px;
                                  line-height:1.5;color:#555E53;word-break:break-all;">
                            {{ $linkHaslo }}
                        </p>

                        <p style="margin:0;font-size:18px;color:#555E53;">
                            Jeśli nie pamiętasz hasła albo nie rozpoznajesz tej prośby, napisz do nas na
                            {{ config('kuking.community.contact_email') }}.
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
