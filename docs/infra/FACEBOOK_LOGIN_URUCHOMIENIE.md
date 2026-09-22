# Wejście kontem Facebooka — indeks instrukcji

**Status: implementacja istnieje; odbiór wdrożenia zapisano 12 września 2026.**
Ten plik zastępuje historyczny plan przygotowawczy z 10 września. Nie jest
ponownym sprawdzeniem produkcji ani panelu Meta z 16 września.

Jedyną aktualną checklistą operacyjną jest
[DEPLOYMENT_RUNBOOK.md — KROK 8E](./DEPLOYMENT_RUNBOOK.md).
Nie utrzymujemy tutaj drugiej kopii instrukcji panelu Meta.

## Sprostowanie historycznych założeń (#618)

- Twierdzenie o braku kodu jest nieaktualne: integracja znajduje się m.in.
  w `app/Http/Controllers/Auth/FacebookLoginController.php`,
  `app/Facebook/KlientFacebook.php` i `app/Support/Facebook.php`.
- Właściciel zgłosił, że przed publikacją aplikacji Meta w trybie Live
  12 września przeszedł App Review. Dawne zapewnienie, że przegląd nie jest
  potrzebny, nie opisuje doświadczenia Kuking. Ten jeden przypadek nie
  określa wymagań ani czasu przeglądu każdej przyszłej aplikacji Meta.
- Dawny wybór zasad łączenia kont rozstrzyga [D-113](../DECISIONS.md):
  sam adres od Facebooka nie łączy istniejącego konta. Powiązanie istniejącego
  konta powstaje z jego ustawień po zalogowaniu; nowe konto wymaga naszego
  potwierdzenia adresu.
- Odbiór z 12 września rozdziela zgłoszenie właściciela od kontroli HTTP
  i jawnie pozostawia ograniczenie testu kontem spoza listy testerów.
  Obecność przycisku i poprawny healthcheck nie dowodzą pełnego logowania.

## Gdzie znaleźć aktualne instrukcje

| Zadanie | Miejsce w DEPLOYMENT_RUNBOOK.md |
|---|---|
| Panel Meta, wymagania przeglądu i tryb Live | 8E.1 |
| Dokładne adresy powrotu i ograniczenia środowisk preview | 8E.1 punkt 5 |
| Zmienne Railway i przekazanie ich do aplikacji | 8E.2 |
| Odbiór działania i ograniczenia dowodów | 8E.3 oraz datowany zapis na początku 8E |
| Brak adresu, istniejące konto, potwierdzenie i uprawnienia | 8E.4 oraz D-113 |
| Wyłączenie funkcji bez usuwania powiązań | 8E.5 |
| Utrzymanie wersji Graph API | 8E.6 |

Historyczne odwołania do §4.2, §4.4 i §12 tego pliku należy czytać jako
odwołania do 8E.1, a §7.1 — do D-113 i 8E.4. Dawne §10.1–10.2 nie zastępują
[obowiązującej polityki prywatności](../../resources/legal/polityka-prywatnosci.md)
ani osobnego zadania formalnego #8.

## Usunięcie danych a odebranie dostępu

Konfigurację instrukcji usunięcia danych opisuje 8E.1 punkt 4: wskazuje
publiczną stronę prywatności. Historyczny §9.4 proponował osobny Data Deletion
Callback; nie jest to instrukcja uruchomienia istniejącego endpointu.
Istniejący `/wejdz/facebook/odebranie-dostepu` obsługuje odebranie dostępu
aplikacji i nie oznacza usunięcia konta Kuking. Nie należy utożsamiać tych
operacji ani implementować dawnego szkicu przy okazji aktualizacji dokumentacji.

Pełny dawny materiał jest zachowany w historii Git tego pliku. Jego założenia
przygotowawcze, również cytowane w zleceniu z 10 września, nie są aktualnymi
instrukcjami wdrożenia.
