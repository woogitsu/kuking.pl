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
       i że nikt bez dostępu do tej skrzynki na konto nie wejdzie.

    Bez gry słowem „kuKING" — D-009 zabrania jej w komunikatach technicznych.
--}}
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Twój link do zalogowania w Kuking</title>
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
                            Zaloguj się w Kuking
                        </p>

                        <p style="margin:0 0 20px;">{{ $displayName ? $displayName.',' : 'Dzień dobry,' }}</p>

                        <p style="margin:0 0 20px;">
                            ktoś poprosił o link do zalogowania się na konto w Kuking założone na ten adres.
                            Jeśli to Ty — kliknij poniższy przycisk „Zaloguj mnie w Kuking”. Hasła nie trzeba wpisywać.
                        </p>

                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px;">
                            <tr>
                                <td align="center" bgcolor="#2F6B3A" style="border-radius:8px;">
                                    <a href="{{ $linkUrl }}"
                                       style="display:inline-block;padding:16px 32px;min-height:48px;box-sizing:border-box;
                                              font-family:Arial,Helvetica,sans-serif;font-size:20px;font-weight:bold;
                                              color:#FFFFFF;text-decoration:none;">
                                        Zaloguj mnie w Kuking
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:0 0 20px;font-size:18px;color:#5C5347;">
                            Otworzy się strona Kuking z jednym przyciskiem „Zaloguj mnie”. Kliknij go —
                            i już będziesz w środku. Możesz to zrobić na tym samym telefonie albo na komputerze,
                            to bez znaczenia.
                        </p>

                        <p style="margin:0 0 20px;font-size:18px;color:#5C5347;">
                            Link działa {{ $waznoscTekst }} i tylko raz. Potem trzeba poprosić o nowy:
                            na stronie logowania kliknij „Wyślij mi link do zalogowania”.
                        </p>

                        <p style="margin:0 0 20px;font-size:18px;color:#5C5347;">
                            Jeśli to nie Ty prosisz o zalogowanie — nie trzeba nic robić. Hasło do konta
                            zostaje takie, jakie było, a ten link niedługo przestanie działać.
                            Nikt bez dostępu do tej skrzynki nie wejdzie na Twoje konto.
                        </p>

                        <p style="margin:0 0 12px;font-size:18px;color:#5C5347;">
                            Jeśli przycisk nie działa, skopiuj ten adres i wklej go w pasku przeglądarki:
                        </p>

                        <p style="margin:0 0 20px;font-family:Arial,Helvetica,sans-serif;font-size:16px;
                                  line-height:1.5;color:#5C5347;word-break:break-all;">
                            {{ $linkUrl }}
                        </p>

                        <p style="margin:0;font-size:18px;color:#5C5347;">
                            Coś tu nie gra? Napisz do nas na {{ config('kuking.community.contact_email') }} —
                            odpisuje człowiek.
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
