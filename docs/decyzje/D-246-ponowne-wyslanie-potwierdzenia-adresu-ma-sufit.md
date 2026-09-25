## D-246 — Ponowne wysłanie potwierdzenia adresu ma sufit na konto i własną klasę w puli (audyt 23.09, znalezisko 2; 23 września 2026)

> Numer: D-239..D-245 są zajęte na `main`, na gałęziach zdalnych albo
> w otwartych stanowiskach floty w dniu tej decyzji. D-246 to pierwszy wolny.

D-239 zostawiło to wprost jako „osobną decyzję, tutaj świadomie niepodjętą".
Audyt bezpieczeństwa scaleń z 23 września zmierzył, ile to kosztuje:
„Wyślij wiadomość jeszcze raz" stało w klasie `wejscie` (próg 0), a jedynym
limitem było `limits.verification_resend` = 6 na minutę, bez sufitu dobowego.
**Jedno niepotwierdzone konto zużywa całą dobową pulę (300 listów) w około
50 minut.** Po D-239 odmawia wtedy już sama aplikacja: do końca doby nikt nie
dostaje linku do logowania ani potwierdzenia rejestracji. Komentarz przy
`DziennyBudzetListow::dlaPotwierdzeniaAdresu()` twierdził przy tym, że wspólny
licznik „pilnuje, żeby jedno niepotwierdzone konto nie wypaliło puli całemu
serwisowi" — nie pilnował.

**Decyzja właściciela (23.09): osobny sufit dla ponowienia. Rejestracja
i logowanie linkiem zostają jak są.**

Dwie granice, bo są dwa różne zagrożenia:

- **Sufit dobowy na konto — 5 ponowień na dobę kalendarzową**
  (`kuking.poczta.ponowienie_potwierdzenia_na_dobe`,
  `KUKING_PONOWIENIE_POTWIERDZENIA_NA_DOBE`). Broni przed jednym kontem.
  Ten sam rząd co `login_link.limit_na_adres` (3 na godzinę), tylko na dobę, bo
  ten list nie jest drogą na konto: z konta korzysta się normalnie bez
  potwierdzonego adresu. Pierwszy list przy rejestracji się nie liczy. Licznik
  chodzi po identyfikatorze konta i dacie (bez adresu e-mail w `cache`), rusza
  atomowo (`RateLimiter::increment`) i oddaje miejsce, gdy list nie wyszedł.
- **Własna klasa `ponowienie` we wspólnej puli, próg 100**
  (`kuking.poczta.progi_wygaszania.ponowienie`, `KUKING_POCZTA_PROG_PONOWIENIE`).
  Broni przed wieloma kontami naraz: ponowienia razem gasną, gdy w puli zostaje
  100 listów, więc nie ruszą rezerwy dla pierwszego potwierdzenia rejestracji
  i logowania linkiem. 100 to znowu `rezerwa_transakcyjna` — ta sama obietnica
  co przy klasie `zwykla`, a nie nowa liczba. Osobna klasa zamiast dopisania do
  `zwykla`, bo właściciel może ją przesunąć bez ruszania przypomnienia hasła.
  `PodzialLimituPocztyTest` pilnuje, że `ponowienie` > `wejscie`.

Obie wartości mają wartość domyślną w `config/kuking.php`, więc **produkcja nie
potrzebuje żadnej nowej zmiennej środowiskowej**.

Do wyczerpania klasy `ponowienie` (200 listów) trzeba teraz 40 kont, a każde
z nich to osobna rejestracja pod `limits.register`.

**Komunikaty.** Po przekroczeniu sufitu konta ekran mówi, ile dodatkowych
wiadomości już wysłaliśmy, że kolejną można zamówić jutro po północy, że
z konta korzysta się normalnie bez potwierdzenia i gdzie odpisuje człowiek.
Po wygaszeniu klasy mówi, że skończyły się e-maile przeznaczone na ponowne
wysyłki (a nie „wszystkie e-maile" — rezerwa dla wejścia jeszcze jest).

### Czego ta zmiana nie robi

Nie zmienia rejestracji ani logowania linkiem: pierwsze potwierdzenie i link
nadal są w klasie `wejscie` i dzielą ostatnie 100 listów między siebie. Kto
zakłada dziesiątki kont, dalej może zjeść tę rezerwę samymi rejestracjami —
to jest granica `limits.register`, nie tej decyzji. Nie rusza też
`/nie-pamietam-hasla` (sufit na adres to nadal osobna decyzja z D-239).

📄 `app/Domain/Security/WyslijPotwierdzenieAdresu.php`,
`app/Domain/Security/WynikPonowieniaPotwierdzenia.php`,
`app/Domain/Security/DziennyBudzetListow.php`,
`app/Http/Controllers/Auth/EmailVerificationController.php`,
`config/kuking.php`,
`tests/Feature/SufitPonowieniaPotwierdzeniaTest.php`,
`tests/Feature/PodzialLimituPocztyTest.php`,
`tests/Feature/WspolnyLicznikPocztyTest.php`
