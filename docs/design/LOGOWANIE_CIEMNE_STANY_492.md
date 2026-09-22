# Logowanie linkiem — ciemne stany pośrednie #492

Uzupełnienie wcześniejszego odbioru: `output/492-login-receipt/ODBIOR.md`.
Pełny raport lokalny: `output/492-login-dark-receipt/ODBIOR.md`.

Źródła uruchomionej aplikacji:
`609328b53b6e87d99fbf178f46dbb2157feeeed7`.
Porównanie z main `c6bed3e64d15e55f7540d48abee6a75cd22bbb64` nie wykazało
zmian w logowaniu linkiem, widokach, CSS/JS ani routes/web.php. Cała
aplikacja nie jest identyczna: różnice obejmują inne obszary oraz wersję.

## Wynik: 4/6 oraz osobny odbiór 2/2

Pierwszy udany przebieg odebrał potwierdzenie wysłania wiadomości i ekran
ważnego linku przy 390 i 1440 px: **4/6 układów**. Motyw gościa wybrano
rzeczywistym formularzem aplikacji i potwierdzono przez `data-theme`.
Każdy z czterech zrzutów otwarto i obejrzano; tekst i przyciski czytelne,
bez poziomego overflow.

Logowanie zakończyło się poprawnie, ale konto miało własny jasny motyw.
Harness zakończył wtedy pomiar i zamknął kontekst. Nie był to defekt
aplikacji ani dowód ciemnego sukcesu; nie oznaczono tego przebiegu 6/6.

W osobnym dozwolonym przebiegu wykonano normalne logowanie linkiem,
a następnie rzeczywistym przełącznikiem zmieniono wygląd zalogowanego
konta na ciemny. Dopiero wtedy odebrano `/home` przy 390 i 1440 px:
**2/2 sukcesu**, oba zrzuty obejrzane, bez poziomego overflow. Zalogowana
nawigacja, powitanie i główne akcje były widoczne. Sukces oznacza tutaj
zalogowany Start po zmianie wyglądu, nie osobną stronę potwierdzenia.

## Izolacja i kolejność

Wyłącznie lokalny runtime i własna baza odbioru PostgreSQL na porcie55439,
UTC; wiadomości przechwytywał lokalny SMTP. Bez produkcji i zewnętrznych
wiadomości. Nie resetowano limitów, nie zmieniano tożsamości ani IP.

Pierwsza próba trafiła na istniejący limit z instrukcją 12 minut przerwy.
Zrzut zapisano 2026-09-17T22:38:24.3371621Z; czas samej odpowiedzi HTTP
nie został zachowany. Wznowiono po konserwatywnym terminie22:50:24.337Z,
czekając odcinkami najwyżej60s bez żądań logowania.

- Wznowienie: odpowiedź302 zaobserwowana2026-09-17T22:50:34.764Z,
  Date22:50:34GMT, bez Retry-After. Odebrane4/6.
- Osobny odbiór sukcesu: odpowiedź302 zaobserwowana22:51:57.109Z,
  Date22:51:57GMT, bez Retry-After. Odebrane2/2 po zmianie motywu konta.

Poprawne logowanie potwierdzono przejściem na `/home`, zużyciem tokenu
i przyrostem liczby autoryzowanych sesji w własnej bazie. Nie powtarzano
historycznych prób jednorazowości ani wygaśnięcia. Kontrola nie obejmuje
klientów pocztowych, fizycznych urządzeń ani pełnego audytu WCAG.

## Dowody

Sanitizowane pomiary: [stany wysłania i potwierdzenia](evidence/login-dark492/intermediate.json)
oraz [osobny sukces](evidence/login-dark492/success.json).
JSON nie zawiera tokenów, cookies, poświadczeń ani stanu przeglądarki.
PNG pozostawiono wyłącznie w lokalnym raporcie: pokazują lokalny adres
lub dane syntetycznego konta, dlatego nie włączono ich do repozytorium.
