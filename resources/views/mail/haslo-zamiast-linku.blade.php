{{--
    E-mail „Wejście na konto — najpierw hasło" (issue #317).

    Trafia do osoby, która kliknęła „Wyślij mi link do zalogowania" i czeka
    na przycisk wpuszczający ją na konto. Dostaje przycisk do USTAWIENIA
    HASŁA, bo na tym adresie wisi konto, którego adresu nikt nigdy nie
    potwierdził. Pełne uzasadnienie mechanizmu:
    `App\Domain\Security\WyslijOdzyskanieKonta`.

    Kolejność zdań w tej wiadomości jest wyborem, nie przypadkiem:

    1. NAJPIERW „to nie pomyłka". Człowiek widzi nagłówek o haśle, choć prosił
       o zalogowanie — i to jest pierwsza rzecz, którą trzeba mu wytłumaczyć,
       zanim zdąży uznać, że strona się pomyliła.
    2. POTEM „dlaczego", jednym zdaniem o adresie, którego nikt nie
       potwierdził.
    3. DOPIERO POTEM przycisk i to, co się po nim stanie.
    4. NA KOŃCU obietnica, że następnym razem będzie zwyczajnie — bo bez niej
       ta droga wygląda na zepsutą na stałe.

    Czego tu NIE MA i czego nie wolno dopisać:

    - ANI SŁOWA O NAPASTNIKU. Nie wiemy, czy ktoś próbował cokolwiek przejąć:
      konto bez potwierdzonego adresu bierze się równie dobrze z rejestracji
      porzuconej w połowie. Zdanie o zagrożeniu wysłane osobie 60+, która
      prosiła tylko o zalogowanie, kończy się porzuceniem serwisu, a nie
      czujnością (ten sam powód co w `mail/zaproszenie-do-zalozenia-konta`).
    - ANI SŁOWA O MECHANICE. „Sesje", „token", „weryfikacja adresu" to nasz
      słownik, nie jej.
    - ANI JEDNEGO ZDANIA OBWINIAJĄCEGO ODBIORCĘ. Nic się tu nie stało z jego
      winy.
    - ŻADNEJ FORMY ZAKŁADAJĄCEJ RODZAJ (COPY_STYLE §2) — zdania są
      przebudowane, a nie wypisane w dwóch wariantach.
    - SŁOWA „LIST". To jest poczta w kopercie; tu chodzi o wiadomość e-mail
      (zgłoszenie właściciela, `DrzwiWejsciowePrawdaTest`).

    Budowa jak w `mail/nowe-haslo`: jedna kolumna, duży tekst, jeden przycisk,
    style inline (Gmail i Outlook wycinają <style> z <head>), zero obrazków
    i zero ikon bez podpisu.

    Bez powitania po imieniu — nazwa z profilu należy do osoby, która to konto
    założyła, a cała ta wiadomość istnieje dlatego, że nie wiemy, czy to ta
    sama osoba, która ją czyta.

    Bez gry słowem „kuKING" — D-009 zabrania jej w komunikatach technicznych.
--}}
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Wejście na konto w Kuking — najpierw ustaw hasło</title>
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
                            Najpierw ustaw hasło
                        </p>

                        <p style="margin:0 0 20px;">Dzień dobry,</p>

                        <p style="margin:0 0 20px;">
                            ktoś poprosił o wiadomość z linkiem do zalogowania na konto w Kuking założone
                            na ten adres. Wysyłamy co innego: przycisk, którym ustawia się hasło.
                            To nie pomyłka.
                        </p>

                        <p style="margin:0 0 20px;">
                            Konto na tym adresie już jest, ale nikt nigdy nie potwierdził, że ten adres
                            do niego należy. Dopóki tego potwierdzenia nie ma, wpuszczenie jednym
                            kliknięciem mogłoby być wpuszczeniem na cudze konto — a tego nie zrobimy.
                        </p>

                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px;">
                            <tr>
                                <td align="center" bgcolor="#BE3025" style="border-radius:14px;">
                                    <a href="{{ $linkUrl }}"
                                       style="display:inline-block;padding:16px 32px;min-height:48px;box-sizing:border-box;
                                              font-family:Arial,Helvetica,sans-serif;font-size:20px;font-weight:bold;
                                              color:#FFFFFF;text-decoration:none;">
                                        Ustaw hasło i wejdź na konto
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:0 0 20px;font-size:18px;color:#555E53;">
                            Otworzy się strona Kuking, na której wpisujesz nowe hasło. Od tej chwili
                            konto należy do Ciebie, a poprzednie hasło — jeśli ktoś je znał — przestaje
                            działać.
                        </p>

                        <p style="margin:0 0 20px;font-size:18px;color:#555E53;">
                            Ustawienie hasła potwierdzi adres e-mail. Przy kolejnym logowaniu możesz
                            poprosić o link do wejścia na konto.
                        </p>

                        <p style="margin:0 0 20px;font-size:18px;color:#555E53;">
                            Przycisk działa {{ $waznoscTekst }}. Potem trzeba poprosić o nowy: na stronie
                            logowania kliknij „Wyślij mi link do zalogowania”.
                        </p>

                        <p style="margin:0 0 12px;font-size:18px;color:#555E53;">
                            Jeśli przycisk nie działa, skopiuj ten adres i wklej go w pasku przeglądarki:
                        </p>

                        <p style="margin:0 0 20px;font-family:Arial,Helvetica,sans-serif;font-size:18px;
                                  line-height:1.5;color:#555E53;word-break:break-all;">
                            {{ $linkUrl }}
                        </p>

                        <p style="margin:0;font-size:18px;color:#555E53;">
                            Jeśli konto na tym adresie powstało bez Twojej wiedzy albo nie możesz ustawić hasła,
                            napisz do nas na {{ config('kuking.community.contact_email') }}.
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
