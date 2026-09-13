{{--
    E-mail „Ktoś próbował wejść na Twoje konto kontem Facebooka" (issue #259).

    Idzie na adres konta, które u nas JEST, po tym jak wejście kontem
    Facebooka zostało ODMÓWIONE (D-098). Właściciel konta nie dowiadywał się
    o takiej próbie w ogóle — odmowę widzi tylko ten, kto ją wywołał.

    Ton: poziom „poważny" z docs/brand/COPY_STYLE.md — zero żartów, zero gry
    słowem kuKING, zero emoji.

    Kolejność zdań jest przemyślana i jest odwrotna niż odruch: najpierw
    „nic się nie stało i nikt nie wszedł", potem „jeśli to Ty — oto droga",
    na końcu „jeśli to nie Ty". Zaczęcie od ostrzeżenia straszyłoby kogoś,
    kto po prostu sam kliknął nie ten przycisk — a to będzie większość
    odbiorców tego listu.

    ŻADEN PRZYCISK W TYM LIŚCIE NICZEGO NIE ŁĄCZY. Powód stoi w klasie
    `App\Notifications\ProbaWejsciaKontemFacebooka` i jest najważniejszym
    zdaniem tej funkcji: odnośnik „to ja, połącz konta" zamieniłby ten list
    w narzędzie przejęcia konta.
--}}
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ktoś próbował wejść na Twoje konto w Kuking kontem Facebooka</title>
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
                            Ktoś próbował wejść na Twoje konto kontem Facebooka
                        </p>

                        <p style="margin:0 0 20px;">{{ $displayName ? $displayName.',' : 'Dzień dobry,' }}</p>

                        <p style="margin:0 0 24px;">
                            <strong>Nikt nie wszedł i nic się nie zmieniło.</strong> Ktoś kliknął
                            „Wejdź kontem Facebooka", a adres e-mail z tamtego konta na Facebooku
                            jest taki sam jak adres Twojego konta w Kuking. Nie wpuściliśmy go —
                            Facebook nie mówi nam, czy ta skrzynka naprawdę należy do osoby
                            siedzącej przed ekranem, a my nie zgadujemy przy wejściu na konto.
                        </p>

                        <p style="margin:0 0 12px;font-size:18px;color:#555E53;">
                            <strong>Jeśli to Ty</strong> — nic złego się nie stało, po prostu
                            ta droga jeszcze u Ciebie nie działa. Zaloguj się tak jak zwykle:
                            hasłem albo przez wiadomość z przyciskiem do zalogowania. Potem wejdź
                            w Ustawienia → Bezpieczeństwo i kliknij „Połącz konto Facebooka".
                            Od następnego razu wejdziesz jednym kliknięciem.
                        </p>

                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:16px 0 28px;">
                            <tr>
                                <td align="center" bgcolor="#BE3025" style="border-radius:14px;">
                                    <a href="{{ $linkLogowania }}"
                                       style="display:inline-block;padding:16px 32px;min-height:48px;box-sizing:border-box;
                                              font-family:Arial,Helvetica,sans-serif;font-size:20px;font-weight:bold;
                                              color:#FFFFFF;text-decoration:none;">
                                        Zaloguj się
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:0 0 20px;font-size:18px;color:#555E53;">
                            <strong>Jeśli to nie Ty</strong> — Twoje konto jest bezpieczne
                            i nie musisz nic robić. Ktoś obcy mógł wpisać Twój adres w swoim
                            koncie na Facebooku; do Kuking go to nie wpuszcza. Gdyby taka
                            wiadomość przychodziła do Ciebie raz za razem, napisz do nas.
                        </p>

                        <p style="margin:0 0 12px;font-size:18px;color:#555E53;">
                            Jeśli przycisk nie działa, skopiuj ten adres i wklej go w pasku przeglądarki:
                        </p>

                        <p style="margin:0 0 20px;font-family:Arial,Helvetica,sans-serif;font-size:16px;
                                  line-height:1.5;color:#555E53;word-break:break-all;">
                            {{ $linkLogowania }}
                        </p>

                        <p style="margin:0;font-size:18px;color:#555E53;">
                            Masz pytanie albo coś tu wygląda niepokojąco? Napisz do nas na
                            {{ config('kuking.community.contact_email') }}. Odpisuje człowiek.
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
