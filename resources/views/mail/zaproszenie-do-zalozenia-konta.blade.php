{{--
    E-mail „Zakładanie konta w Kuking" — dla adresu BEZ konta (D-085).

    Budowa jak w `mail/link-do-logowania`: jedna kolumna, duży tekst, jeden
    przycisk, style inline (Gmail i Outlook wycinają <style> z <head>), zero
    obrazków i zero ikon bez podpisu.

    TA WIADOMOŚĆ MOŻE TRAFIĆ DO OSOBY, KTÓRA O NIC NIE PROSIŁA — i to jest
    jedyna taka wiadomość w serwisie. Ktoś mógł pomylić się przy przepisywaniu
    adresu albo wpisać go złośliwie. Stąd cztery rzeczy, których tu nie wolno
    ruszyć:

    1. ZERO ALARMU. Ani słowa o „próbie wejścia na Twoje konto", ani słowa
       o bezpieczeństwie i ani jednego wykrzyknika. Konta na tym adresie nie
       ma, więc nikomu nic nie grozi — a zdanie sugerujące zagrożenie, wysłane
       osobie, która o nic nie prosiła, jest samo w sobie szkodą. Wygląda też
       dokładnie jak phishing, przed którym ostrzega ją bank.
    2. ZERO AKCJI DO WYKONANIA dla kogoś, kto o to nie prosił. Żadnego
       „potwierdź", „odrzuć", „zgłoś nadużycie". Jedno zdanie: nic nie trzeba
       robić, nic się nie stało, i nikt nie zakłada konta bez jej udziału.
    3. WYJAŚNIENIE, SKĄD TA WIADOMOŚĆ SIĘ WZIĘŁA. Bez tego jest anonimowym
       linkiem od nieznajomego, a w naszej grupie znaczy to „nie klikaj".
    4. ZAPOWIEDŹ DRUGIEGO PRZYCISKU. Bez niej człowiek, który zobaczy ekran
       „Załóż konto", pomyśli, że coś poszło nie tak, i wróci do skrzynki. Ten
       drugi krok istnieje dlatego, że skanery odnośników w poczcie klikają
       linki przed człowiekiem (D-056) — ale to jest NASZ powód, nie jego, więc
       na ekranie zostaje sama zapowiedź, nie wykład.

    Bez gry słowem „kuKING" — D-009 zabrania jej w komunikatach technicznych.
--}}
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Zakładanie konta w Kuking</title>
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
                            Zakładanie konta w Kuking
                        </p>

                        <p style="margin:0 0 20px;">Dzień dobry,</p>

                        <p style="margin:0 0 20px;">
                            ktoś podał ten adres w Kuking, prosząc o wiadomość z linkiem do wejścia na konto.
                            Na tym adresie konta jeszcze nie ma — więc zamiast linku do wejścia wysyłamy link
                            do jego założenia. Jeśli to Ty — kliknij poniższy przycisk „Załóż konto w Kuking”.
                        </p>

                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px;">
                            <tr>
                                <td align="center" bgcolor="#BE3025" style="border-radius:14px;">
                                    <a href="{{ $linkUrl }}"
                                       style="display:inline-block;padding:16px 32px;min-height:48px;box-sizing:border-box;
                                              font-family:Arial,Helvetica,sans-serif;font-size:20px;font-weight:bold;
                                              color:#FFFFFF;text-decoration:none;">
                                        Załóż konto w Kuking
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:0 0 20px;font-size:18px;color:#555E53;">
                            Otworzy się strona Kuking z jednym przyciskiem „Załóż konto”. Kliknij go, a przejdziesz
                            do krótkiego formularza — adres e-mail będzie już w nim wpisany i potwierdzony,
                            więc zostanie imię, nazwa użytkownika i hasło.
                        </p>

                        <p style="margin:0 0 20px;font-size:18px;color:#555E53;">
                            Link działa {{ $waznoscTekst }}.
                        </p>

                        <p style="margin:0 0 20px;font-size:18px;color:#555E53;">
                            Jeśli to nie Ty — nie musisz nic robić. Nic się nie stało i nikt nie zakłada konta
                            na ten adres bez Twojego udziału.
                        </p>

                        <p style="margin:0 0 12px;font-size:18px;color:#555E53;">
                            Jeśli przycisk nie działa, skopiuj ten adres i wklej go w pasku przeglądarki:
                        </p>

                        <p style="margin:0 0 20px;font-family:Arial,Helvetica,sans-serif;font-size:16px;
                                  line-height:1.5;color:#555E53;word-break:break-all;">
                            {{ $linkUrl }}
                        </p>

                        <p style="margin:0;font-size:18px;color:#555E53;">
                            Coś tu nie gra? Napisz do nas na {{ config('kuking.community.contact_email') }} —
                            odpisuje człowiek.
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
