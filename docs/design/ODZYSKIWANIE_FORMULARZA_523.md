# Odbiór stanów odzyskiwania formularza — #523

## Zakres i źródło

Kontynuacja macierzy kompletności na main `4d3b5359c4d8372aa258f09e29b77efa9dbd6077`.
Nie jest ponownym audytem całej marki. Dotyczy rzeczywistych odpowiedzi 419/429,
treści komunikatów oraz lokalnego wyglądu trzech stanów: odzyskanie tekstu
w całości, częściowo i brak odzyskanych pól. Wersja poprawki: Alfa 0.23.

## Potwierdzone błędy i poprawka

- 429 zapewniał „nic nie przepadło” również po przerwaniu odzyskiwania pól.
  Pomoc zdjęcia zawierała drugie zapewnienie „brakuje tylko pliku”.
- Gdy pierwsze pole przekraczało limit odzyskiwania, 419 twierdził, że nie było
  nic do zapisania; 429 nie wyjaśniał braku odzyskanego tekstu.
- Przy zachowaniu całego tekstu zapewnienie dotyczy teraz wyłącznie tekstu.
  Wybór zdjęcia trzeba powtórzyć. Informacja o niepełnym odzyskaniu jest
  na początku, a nie dopiero pod formularzem.
- Ogląd rzeczywistego 419 ujawnił nakaz logowania na publicznym formularzu
  zgłoszenia. Wskazówka zależy teraz od middleware auth bieżącej trasy,
  zamiast niepełnej listy adresów. Publiczna droga pozostaje bez konta.

Zmieniono `resources/views/errors/419.blade.php` i
`resources/views/errors/429.blade.php`. Nie zmieniano CSRF, limitera,
autoryzacji, algorytmu odzyskiwania ani limitu 200000 znaków.
To poprawka informacji, nie zwiększenie pojemności odzyskiwania.
51 instrukcji po 4000 znaków mieści się w indywidualnych limitach kroków
przepisu, ale przekracza sumaryczny limit odzyskiwania; ten limit pozostaje
osobnym ograniczeniem. Nie wolno twierdzić, że poprawka eliminuje utratę pól.

## Regresja i rzeczywiste kontrole ujemne

`OdzyskanyTekstBezSprzecznychObietnicTest` używa rzeczywistych tras,
limitu wyczerpanego niepoprawnymi POST oraz odmowy CSRF. Nie renderuje
szablonu pod statusem 200. Sprawdza obszar main, zachowane i odrzucone pola,
token CSRF, ponowny wybór zdjęcia, Retry-After i brak zapisu przepisu.

Przed poprawką: trzy scenariusze błędne, trzy poprawne. Po poprawce wraz
z istniejącymi rodzinami testów tekstów, sekretów, limitu i idempotencji:
**45 testów / 273 asercje, sukces**. Pint: sukces. Kontrola dodatnia zachowuje
nakaz logowania dla gościa na chronionej trasie; publiczna droga go nie ma.

Siedem osobnych mutacji wykonywalnych źródeł Blade przywracało: zapewnienie
pełnego odzyskania na górze 429, zapewnienie przy zdjęciu oraz fałszywy
komunikat braku tekstu osobno w 419 i 429 oraz nakaz logowania na publicznej
trasie; kolejne usuwały potrzebny nakaz na chronionej trasie i przywracały
zapewnienie „Nic się nie zepsuło” przy braku odzyskanego tekstu.
Każda zakończyła się exit 1.
Kopie zapisano poza repo, przywrócono bajty i mtime; MD5 końcowych źródeł:

- 419: `f454ba86cd51da9ec762699bd62deef8`;
- 429: `ba96c6ffc352f320f7c8d851c485cb85`.

Przed każdą mutacją i po przywróceniu trzeba usuwać lokalny cache widoków.
Pierwsza seria pozostawiła skompilowany błędny szablon nowszy niż przywrócone
mtime źródła; dlatego ponowiono wszystkie kontrole i końcowy sukces.
Nie zmieniono reguł cache aplikacji ani testów w celu ukrycia tego błędu pomiaru.

## Przeglądarka — stan odbioru

Wykorzystano izolowaną bazę `kuking_audit429`, PostgreSQL na porcie 55439,
i lokalny serwer na porcie 8029. Nie wykonano takich prób na produkcji.
Przed poprawką rzeczywisty HTTP429 pokazał jednocześnie fałszywe zapewnienie
i ostrzeżenie o obcięciu. Obejrzano zrzut 320 px.

W Chromium zwykły POST z tej samej strony nie jest reprodukcją 419:
Laravel 13 uznaje nagłówek same-origin. Rzeczywisty HTTP419 uzyskano przez
natywny formularz z pustej karty, z błędnym tokenem, skierowany na istniejącą
lokalną trasę. Nie zmieniano middleware ani nie podstawiano odpowiedzi HTTP.
Nie utożsamiamy tego z naturalnym wygaśnięciem sesji użytkownika.

Lokalnie sprawdzono 144 konfiguracje: dwa rzeczywiste statusy, trzy stany,
szerokości 320/360/390/414/768/1440, oba motywy i tekst 100/140%.
Brak poziomego overflow, Inter i przyciski co najmniej 48 px. W 12 wariantach
320/140 wykonano Tab do wszystkich widocznych kontrolek main, sprawdzając
obecność fokusu i przecięcie kontrolki z obszarem okna. To nie jest nowy
pomiar kontrastu ani dowód pełnej widoczności wysokiego textarea.
Po końcowym uproszczeniu zdania 429 powtórzono 24 konfiguracje pustego
odzyskiwania oraz dwa przejścia Tab.

Prawdziwy zoom 200% przez rozszerzenie chrome.tabs.setZoom: osiem wariantów
częściowego odzyskania obu statusów, obu motywów i tekstu 100/140%.
Potwierdzono zoom 2, innerWidth 320 przy oknie 640 i devicePixelRatio 2,
brak overflow oraz cztery przejścia Tab. To nie jest emulacja rozmiaru fontu.
Nie rozszerza tego wyniku na pozostałe stany ani klawiaturę ekranową.

Obejrzano reprezentatywne zrzuty 419/429, częściowego i zerowego odzyskania,
mobile 320/140 oraz desktop 1440. Nie deklarujemy oglądu każdej konfiguracji.
Wyniki dotyczą lokalnej aplikacji; żadnych zgłoszeń nie wysłano na produkcji.

CI i wdrożenie wymagają osobnego odczytu dla końcowego commita.
Pełna identyfikacja pozostaje CZĘŚCIOWO odebrana.
