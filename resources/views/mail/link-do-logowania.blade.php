{{--
    E-mail „Twój link do zalogowania" (issue #25, D-056).

    Budowa jak w `mail/nowe-haslo`: jedna kolumna, duży tekst, jeden przycisk,
    style inline (Gmail i Outlook wycinają <style> z <head>), zero obrazków
    i zero ikon bez podpisu.

    CZTERY RZECZY, KTÓRYCH TU NIE WOLNO RUSZYĆ:

    1. zdanie, SKĄD ten list się wziął — bo osoba 60+ słyszy od banku i od
       telewizji „nie klikaj w linki z maili", i ma prawo się zawahać;
    2. informacja, że po kliknięciu będzie JESZCZE JEDEN przycisk. Bez niej
       człowiek, który zobaczy ekran „Zaloguj mnie", pomyśli, że coś poszło
       nie tak, i wróci do listu. Ten drugi krok istnieje dlatego, że skanery
       antywirusowe w poczcie potrafią kliknąć link przed człowiekiem
       (D-056) — ale to jest NASZ powód, nie jego, więc na ekranie zostaje
       tylko zapowiedź, nie wykład;
    3. pełny adres do przepisania, gdy przycisk nie działa — bez tego osoba
       z klientem pocztowym blokującym odnośniki nie ma drogi dalej;
    4. zdanie dla kogoś, kto o link NIE PROSIŁ. Ten list, w odróżnieniu od
       reszty, można dostać przez cudzą pomyłkę w adresie albo przez czyjeś
       działanie — i wtedy trzeba powiedzieć wprost, że nic nie trzeba robić
       i że samo zamówienie linku nie zmienia hasła.

    Bez gry słowem „kuKING" — D-009 zabrania jej w komunikatach technicznych.
--}}
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Twój link do zalogowania w Kuking</title>
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
                            Zaloguj się w Kuking
                        </p>

                        <p style="margin:0 0 20px;">{{ $displayName ? $displayName.',' : 'Dzień dobry,' }}</p>

                        <p style="margin:0 0 20px;">
                            ktoś poprosił o link do zalogowania się na konto w Kuking założone na ten adres.
                            Jeśli to Ty — kliknij poniższy przycisk „Zaloguj mnie w Kuking”. Hasła nie trzeba wpisywać.
                        </p>

                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px;">
                            <tr>
                                <td align="center" bgcolor="#BE3025" style="border-radius:14px;">
                                    <a href="{{ $linkUrl }}"
                                       style="display:inline-block;padding:16px 32px;min-height:48px;box-sizing:border-box;
                                              font-family:Arial,Helvetica,sans-serif;font-size:20px;font-weight:bold;
                                              color:#FFFFFF;text-decoration:none;">
                                        Zaloguj mnie w Kuking
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:0 0 20px;font-size:18px;color:#555E53;">
                            Otworzy się strona Kuking. Kliknij „Zaloguj mnie”, żeby wejść na konto.
                            Możesz użyć telefonu albo komputera.
                        </p>

                        <p style="margin:0 0 20px;font-size:18px;color:#555E53;">
                            Link działa {{ $waznoscTekst }} i tylko raz. Potem trzeba poprosić o nowy:
                            na stronie logowania kliknij „Wyślij mi link do zalogowania”.
                        </p>

                        <p style="margin:0 0 20px;font-size:18px;color:#555E53;">
                            Jeśli to nie Ty prosisz o zalogowanie — nie trzeba nic robić. Hasło do konta
                            zostaje takie, jakie było, a ten link niedługo przestanie działać.
                        </p>

                        <p style="margin:0 0 12px;font-size:18px;color:#555E53;">
                            Jeśli przycisk nie działa, skopiuj ten adres i wklej go w pasku przeglądarki:
                        </p>

                        <p style="margin:0 0 20px;font-family:Arial,Helvetica,sans-serif;font-size:16px;
                                  line-height:1.5;color:#555E53;word-break:break-all;">
                            {{ $linkUrl }}
                        </p>

                        <p style="margin:0;font-size:18px;color:#555E53;">
                            Jeśli nie możesz się zalogować, napisz do nas na {{ config('kuking.community.contact_email') }}.
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
