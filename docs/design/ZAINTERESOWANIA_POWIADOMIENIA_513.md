# Zainteresowania i zwykłe powiadomienia — #513

**Stan: rozpoczęta implementacja, przed odbiorem przeglądarkowym i kontrolami
ujemnymi. Nie scalać na podstawie tego dokumentu.** Alfa 0.20 jest wersją
przygotowywanej gałęzi, nie potwierdzoną produkcją.

Baza: main `34b4b61109ebd1e1c808066191609672cd5ae332`, PR #517 / Alfa 0.19.
Zakres i decyzja: D-212, konstytucja 1.8.

## Potwierdzony brak

Przed implementacją obejrzano oryginalny HTML ZIP i lokalny Laravel:
wybór zainteresowań miał wąski formularz zamiast dużych kafli, a zwykłe
powiadomienia umieszczały akcję pod treścią zamiast obok na szerokim ekranie.
Trzy opcjonalne kroki i pełne decyzje moderacyjne są funkcjami aplikacji,
których makieta nie zastępuje.

## Przygotowane zmiany

- Widok zainteresowań i `marka-onboarding.css`: duże kafle prawdziwych
  promowanych tagów, natywne checkboxy, zielone zaznaczenie, pomijanie.
  Instrukcja nie obiecuje zapełnienia strony głównej bez treści.
- Widok powiadomień i `marka-powiadomienia.css`: wydzielone pięć zwykłych
  typów; szeroki układ treści i formularza akcji. Pozostałe typy zachowują
  strukturę. Nie zmieniono kontrolerów ani mechanizmu odczytu.
- `KompozycjaZainteresowanTest.php`: przygotowany test rzeczywistego
  renderu całej listy promowanych tagów i pustego stanu.

## Do wykonania przed scaleniem

1. Sprawdzić wizualnie końcowy układ wobec ZIP, oba motywy, 320–1440 px,
   tekst 140%, powiększenie fontu i rzeczywisty zoom 200%.
2. Uzupełnić regresję kompozycji powiadomień oraz lokalne fixture:
   wszystkie pięć typów, brak aktora, odczytane/nowe, brak celu, pełna
   decyzja moderacji i droga odwołania, puste listy oraz paginacja.
3. Wykonać rzeczywiste wybory i POST lokalnie, klawiaturę, widoczny fokus,
   pełną treść i pomiary geometrii. Sprawdzić zachowanie wyboru bez JS.
4. Dodać przyrząd przeglądarkowy i integrację CI, z kontrolami ujemnymi
   prawdziwego CSS/Blade oraz kopią poza repo, MD5, mtime, odbudową
   i dodatnim pomiarem po przywróceniu. Nie zastępować geometrii samym DOM.
5. Pełne wymagane kontrole, niezależny review, zielony CI, dopiero potem
   scalenie i osobny odbiór Railway. Aktualizować ten status na podstawie
   rzeczywistych wyników, nie samej obecności kodu.

Nie zmieniano produkcyjnych danych ani ustawień użytkownika.

## Wykonane kontrole przygotowanej gałęzi

Lokalnie: Pint nowego testu — sukces; osiem wybranych rodzin regresji —
36 testów / 192 asercje, sukces; build Vite i 72 pary kontrastu — sukces.
Obejmowały zainteresowania, kopię onboardingu, promowane tagi, oznaczanie
powiadomień, brak celu, moderację, zapytania i odnośniki dokumentacji.
To nie jest pełny odbiór nowych kompozycji. Brak jeszcze przeglądarkowego
odbioru, testu geometrii powiadomień i wymaganych kontroli ujemnych.
Gałąź jest przeznaczona do zapisania jako draft PR, bez scalania.
