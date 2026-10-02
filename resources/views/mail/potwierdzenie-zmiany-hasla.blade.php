{{--
    E-mail „Hasło do Twojego konta zostało zmienione" (issue #2565) — po
    skutecznej zmianie w ustawieniach albo po zakończonym resecie linkiem.

    Ton „poważny" z docs/brand/COPY_STYLE.md: zero żartów, zero gry słowem
    kuKING, zero emoji. Bez rodzaju gramatycznego wobec czytającego. Bez
    tokenu, bez linku logującego, bez IP i urządzenia: oba odnośniki to
    zwykłe strony („Nie pamiętam hasła", „Napisz do nas"), nic nie wykonują.
--}}
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hasło do Twojego konta w Kuking zostało zmienione</title>
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
                            Hasło do Twojego konta zostało zmienione
                        </p>

                        <p style="margin:0 0 20px;">{{ $displayName ? $displayName.',' : 'Dzień dobry,' }}</p>

                        <p style="margin:0 0 20px;">
                            {{ $kiedy }} ustawiono nowe hasło do Twojego konta w Kuking
                            @if ($sposob === \App\Notifications\PotwierdzenieZmianyHasla::RESET_LINKIEM)
                                przez odnośnik z listu „Ustaw nowe hasło”.
                            @else
                                w ustawieniach konta.
                            @endif
                            Inne zalogowane urządzenia zostały wylogowane.
                        </p>

                        <p style="margin:0 0 20px;font-size:18px;color:#555E53;">
                            <strong>Jeśli to Twoja decyzja</strong> — nie musisz nic robić.
                        </p>

                        <p style="margin:0 0 24px;font-size:18px;color:#555E53;">
                            <strong>Jeśli to nie Twoja decyzja</strong> — ktoś obcy mógł mieć dostęp
                            do Twojego konta albo skrzynki pocztowej. Ustaw od razu nowe hasło przez
                            „Nie pamiętam hasła” i napisz do nas.
                        </p>

                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
                            <tr>
                                <td align="center" bgcolor="#BE3025" style="border-radius:14px;">
                                    <a href="{{ $linkOdzyskania }}"
                                       style="display:inline-block;padding:16px 32px;min-height:48px;box-sizing:border-box;
                                              font-family:Arial,Helvetica,sans-serif;font-size:20px;font-weight:bold;
                                              color:#FFFFFF;text-decoration:none;">
                                        Nie pamiętam hasła
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:0 0 12px;font-size:18px;color:#555E53;">
                            Jeśli przycisk nie działa, skopiuj ten adres i wklej go w pasku przeglądarki:
                        </p>

                        <p style="margin:0 0 20px;font-family:Arial,Helvetica,sans-serif;font-size:18px;
                                  line-height:1.5;color:#555E53;word-break:break-all;">
                            {{ $linkOdzyskania }}
                        </p>

                        <p style="margin:0;font-size:18px;color:#555E53;">
                            Napisz do nas: {{ $linkKontakt }} albo na {{ config('kuking.community.contact_email') }}.
                            Ten list nie zawiera hasła ani odnośnika do logowania.
                        </p>

                    </td>
                </tr>
            </table>

            <p style="max-width:600px;margin:20px auto 0;font-family:Arial,Helvetica,sans-serif;
                      font-size:18px;line-height:1.5;color:#555E53;">
                Kuking.pl — pokaż, co dziś {{ \App\Support\Forma::dla($profilAdresata ?? null, 'ugotowałaś', 'ugotowałeś', 'gotujesz') }}.
            </p>
        </td>
    </tr>
</table>
</body>
</html>
