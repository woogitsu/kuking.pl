# Dyktowanie dłuższych pól — etap 2 (#2377)

Decyzja właściciela z 1.10.2026: dyktowanie wszędzie, gdzie jest dłuższy
tekst. Etap 2 powstaje po paczce V, na osobnej gałęzi; nie rozszerza
przyjmowania danych przez serwer i nie dodaje usługi AI.

## Zakres

Przepisy (opis, historia, składniki, kroki), wpisy i pytania, notatki
„Ugotowałem”, komentarze, odpowiedzi i ich edycje, zeszyty i notatki przy
zapisach, opis profilu, kontakt, zgłoszenia, odwołania i uzasadnienia
moderacyjne. Powód przy operacji destrukcyjnej nadal wymaga osobnego
potwierdzenia; wstawienie tekstu nie wykonuje tej operacji.

`x-field` wymaga jawnego `dyktowanie`, `type="textarea"`, zalogowanej osoby
oraz braku `readonly` i `disabled`. Nowy host wskazuje dokładny identyfikator
pola, również w pętli. Nowe pola używają neutralnego „Wstaw do pola”.
Przy składnikach i krokach pozostają dotychczasowe hosty pierwszego etapu.
Bez API rozpoznawania mowy host jest pusty, więc nie ma martwego przycisku.
Ekrany odzyskiwania po 419/429 i pola tylko do odczytu nie dostają hosta.

## Mikrofon i prywatność

`ApplySecurityHeaders::TRASY_DYKTOWANIA` zawiera jawną listę nazw tras,
bez `admin.*` i innych wildcardów. Mikrofon dla własnej domeny jest
odblokowany tylko po zalogowaniu, na udanym HTML z GET. Gość, JSON,
przekierowanie i błąd mają `microphone=()`.

`PreventSharedSessionCache` daje zalogowanemu `private, no-store` i usuwa
trzy nagłówki cache CDN. Test rzeczywistego HTTP przy publicznym przepisie
sprawdza dwa warianty: gość ma cache brzegu bez mikrofonu, zalogowany ma
mikrofon bez cache. Dodatkowe testy oddzielają gościa i zalogowanego przy
wpisie, pytaniu, wykonaniu i publicznym zeszycie.

Przeglądarka obsługuje rozpoznawanie mowy na zasadach swojego dostawcy.
Kuking nie odbiera dźwięku ani osobnej transkrypcji. Podgląd, „Anuluj” i
jawne wstawienie do pola pozostają obowiązkowe. Tekst trafia do serwera
dopiero ze zwykłym formularzem. Polityka prywatności to drobna poprawka
wersji 2026-09-30; bieżący plik i archiwum mają identyczne bajty.

## Dowody i pozostały odbiór

- Lokalnie: 40/40 testów JS/DOM, składnia zmienionego PHP i Python, zgodny
  indeks decyzji, identyczny SHA256 polityki i archiwum.
- Cztery nowe mutacje nagłówków mają sprawdzone pojedyncze kotwice.
  To kontrola przygotowania mutacji, nie dowód wykonania testów PHP.
  CI ma wykazać błąd z markerami `DICTATION_AUTH_HEADER`,
  `DICTATION_GUEST_HEADER`, `DICTATION_NON_FORM_RESPONSE`,
  `DICTATION_OTHER_ROUTE`, następnie zieleń po przywróceniu kodu.
- Testy Blade sprawdzają jawny opt-in, cele i unikalne identyfikatory,
  readonly/disabled textarea oraz zachowanie tych atrybutów na input.
- Lokalnie pełny `check.sh` nie przechodzi bez vendor i PostgreSQL 18;
  nie uznajemy tego za zaliczony gate. Pełne CI pozostaje wymagane.
- Syntetyczny test nagłówków nie dowodzi podpisu linku odwołania ani
  faktycznego dostępu moderatora; tam obowiązują dotychczasowe middleware
  i Policy. Zmiana nie rozluźnia autoryzacji.
- Odbiór na rzeczywistych telefonach (Samsung/Chrome, iPhone/Safari),
  odmowa mikrofonu, słaba sieć i prawny przegląd rejestru przed publicznym
  startem pozostają w #2377 i #8. Sam zielony CI ich nie zastępuje.

## Wycofanie

Brak migracji i nowych kolumn. Można wycofać kod etapu 2 wraz z jawnie
włączonymi hostami i rozszerzeniem listy tras. Wpisane i wysłane już teksty
pozostają zwykłymi treściami formularzy; nie usuwa się ich przy rollbacku.
Dokumenty prawne należy wtedy dostosować do rzeczywistego zakresu funkcji,
z zachowaniem zgodności bieżącej wersji i jej archiwum.
