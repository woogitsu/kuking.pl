# Odbiór V — 3 października 2026

## Zakres

Cztery istniejące zgłoszenia: #2847 (jedna migawka zakupów w ZIP),
#2872 (otwarte pytanie usunięcia listy i działający fokus po odmowie),
#2873 (Anuluj wraca do wybranej listy), #2875 (błąd historii przepisu
prowadzi do istniejącej grupy wyboru). Baza to odebrane N–U i paczka
P1 kont; ich wydanie ma pierwszeństwo. Nowe poprawki #2880/#2881/#2787
nie należą do tej zamrożonej paczki.

## Kontrole root na wspólnej kopii

Izolowany Linux, PostgreSQL 18+, jawny własny host 127.0.0.1, port 55488,
rola kuking_pg18_owner i własne bazy kuking_test_v20261003 oraz
kuking_race_repo_v. Kod odbioru: 504372ea7fbb86ae9f27da1cec3755e4738cb183.

- Całe wybrane klasy HTTP, eksportu, historii i strażników: 163 testy,
  7731 asercji PASS.
- Cztery zarejestrowane kontrole ujemne #2872/#2873/#2875 rzeczywiście
  oblały właściwe markery, przywróciły źródła i ponownie przeszły.
  Kotwica #2872 odczytuje aktualną klasę confirm zakupy-potwierdzenie.
- #2847: rzeczywisty ZIP w przeplocie dwóch połączeń, 1 test i 26 asercji
  PASS; mutant osobnych odczytów oblał ZIP własnym markerem, dokładne
  bajty i mtime odtworzone, test ponownie zielony.
- Budowanie assetów PASS; pełny PHPStan bez błędów; czyste drzewo i SHA
  po zakończeniu wszystkich mutacji.

Próba pierwszego wywołania czterech etykiet odmówiła przed mutacjami:
transport powłoki zamienił polskie znaki w argumentach. Poprawne wywołanie
odczytało dokładne etykiety z kodu w UTF-8; powyższy wynik pochodzi z niego.

## Dostępność i ograniczenia

Agent sprawdził rzeczywisty Laravel HTML w Chromium: otwarte pytanie,
odnośnik podsumowania, Enter/Tab i aktualną liczbę pozycji; przy 320 CSS px
z dużym tekstem przycisk mieści się i nie dzieli słów na pojedyncze litery.
Fixture potwierdzeń ma też ten scenariusz w automatycznym teście CI.
Pełny lokalny automat Windows zatrzymał się przed Playwright na błędzie
Node/undici; nie zaliczamy go. Przeglądarka historii korzystała z HTML
wyrenderowanego przez Laravel, nie z pełnego logowania HTTP.
Telefony, ręczne 200% zoom i pilot 50+ pozostają do odbioru.

## Wydanie i wycofanie

Pełny zwykły pre-push oraz pełne CI dokładnego heada i przegląd PR są
jeszcze wymagane. Nie jest to potwierdzenie wdrożenia. Po integracji:
jeden końcowy PR z CI i CodeQL, zielony push main, trzy Railway SUCCESS
dokładnego SHA, zgodne /wydanie i /health. Dopiero wtedy rozstrzygamy
zamknięcie issues według wszystkich kryteriów. Brak zmian schematu,
kosztu i zgód. Revert kodu przywraca opisane błędy; nie cofamy danych
ani wyborów człowieka.
