# Stan wiadomości w panelu — powiązanie błędu z polem (#581)

Status: poprawka lokalna, niewysłana i niewdrożona; przygotowana Alfa 0.55. Gałąź zaktualizowana bez konfliktów do `4b824c146f24d4bdb60ce6e952a6384b51727f85` po scaleniu PR #642.

## Problem i zakres

Po wysłaniu brakującego albo nieznanego statusu wiadomości serwer odrzuca zapis. Podsumowanie błędów prowadziło do `#f-status`, ale formularz nie zawierał takiego elementu. Zwykły wybór poprawnego statusu nie wywołuje tej ścieżki.

Pierwszy przycisk wyboru otrzymuje docelowy identyfikator. Przy błędzie wszystkie trzy przyciski wskazują ten sam komunikat przez `aria-describedby` i mają `aria-invalid`. Zachowano kontroler, uprawnienia, zapis notatki, retencję i obsługę odpowiedzi.

## Dowody lokalne

- Końcowy plik `StanWiadomosciCelBleduTest.php`, po Pint: 48 testów / 233 asercje wraz z rodzinami panelu, odpowiedzi, retencji i podsumowań błędów. PostgreSQL na 55439, izolowana baza testowa; żadne wiadomości nie zostały wysłane.
- Regresja obejmuje rzeczywisty POST i GET po przekierowaniu, brak zmiany rekordu po błędzie, powiązanie komunikatu z polami, zachowaną notatkę, zdrowy formularz oraz poprawny zapis.
- Trzy fizyczne kontrole ujemne Blade: usunięcie celu, powiązania komunikatu albo jego identyfikatora powodowało porażkę; po przywróceniu każdorazowo 3 testy / 49 asercji przechodziły. Kopia poza repo, potwierdzone MD5 i mtime.
- Przeglądarka: 24 konfiguracje (320/360/390/414/768/1440, oba motywy, tekst 100/140%). Cztery scenariusze myszy i klawiatury oraz dwa rzeczywiste pomiary zoomu 200%, CSS 320×900, DPR 2, tekst 25,2 px po końcowym GET.
- Logowanie lokalne hasłem i TOTP. Porównanie dziewięciu tabel przed i po błędnych żądaniach nie wykazało zmian danych. Ogląd reprezentatywnych zrzutów wykonano lokalnie.
- Niezależny przegląd kodu i dowodów nie wykazał blokera; uwagi do pomiaru powiększenia i zamykania kontekstu zostały poprawione. Recenzent nie wykonywał osobnego oglądu zrzutów.

Dowody: `docs/design/evidence/contact-status581/`. Test końcowej nazwy i formatowania powtórzono 17 września 2026; log roboczy `output/panel581/contact-final-named-test.log`.

## Pozostało

Końcowe sprawdzenie różnicy, zwykły hook, PR i wymagane CI, wdrożenie oraz możliwy odbiór zalogowanej produkcji. Nie wykonywano prób na fizycznym telefonie ani zmian danych produkcyjnych. Ten pakiet nie zamyka całego #581 ani #492.
