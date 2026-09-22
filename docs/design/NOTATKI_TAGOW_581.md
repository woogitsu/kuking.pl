# Niezależne formularze notatek tagów — #581

Stan: poprawka robocza, niewdrożona. Podstawa: `2b26853` (PR #641).

## Problem i zmiana

Po wysłaniu notatki dłuższej niż 200 znaków przy jednym z promowanych tagów
formularze współdzieliły `old('note')`, błąd oraz identyfikator pola.
Powodowało to wyświetlanie błędnie wpisanej notatki także przy sąsiednim tagu.

Formularz przekazuje teraz `_wiersz`, a istniejący komponent `x-field`
otrzymuje `:wiersz`. Wykorzystuje to istniejący mechanizm izolacji formularzy;
nie zmienia kontrolera, uprawnień, limitów ani zapisu danych.

Ogląd ujawnił też podwójne oznaczenie opcjonalności. Etykieta brzmi teraz
„Notatka”; dopisek o nieobowiązkowym polu nadal dodaje wspólny komponent.

## Wykonane kontrole lokalne

- `NotatkiTagowFormularzeNiezalezneTest`: 2 testy, 52 asercje, oba kierunki
  błędnego zapisu. Identyfikator wysyłanego wiersza pochodzi z rzeczywistego
  GET formularza. Kontrola obejmuje zachowanie tekstu, osobne identyfikatory
  i etykiety, błąd przy właściwym polu, odnośnik podsumowania i niezmienione
  zapisane notatki.
- Po zmianie etykiety ponownie wykonano regresję oraz istniejące rodziny
  `TagiPromowaneAdminTest` i `TagiPromowaneZListyGospodarzaTest`: łącznie
  23 testy, 106 asercji — wynik dodatni. Pint bez dalszych zmian.
- Kontrole ujemne prawdziwego Blade: osobne usunięcie `_wiersz` oraz
  `:wiersz` powoduje porażkę. Po każdej próbie przywrócono bajty i mtime,
  sprawdzono MD5, wyczyszczono skompilowane widoki i ponownie uzyskano
  wynik dodatni. [Dowód](evidence/tag-notes581/negative-controls.json).
  Po zmianie etykiety powtórzono obie mutacje na końcowym Blade:
  [dowód końcowy](evidence/tag-notes581/negative-controls-final.json).
- Lokalna przeglądarka na prototypie tej samej poprawki: dwa rzeczywiste
  błędne PUT, przekierowanie 302 i GET, zachowanie tekstu oraz błędu tylko
  przy wysłanym tagu; kliknięcie podsumowania przenosi fokus do pola.
  Pełne migawki pięciu tabel potwierdziły brak zmiany danych domenowych.
  Walidację serwera wywołano żądaniem HTTP; nie jest to test natywnego
  ograniczenia długości pola w przeglądarce.
- Niezależny przegląd kodu i regresji: brak blockerów; potwierdzone zachowanie
  osobnych ścieżek zmiany kolejności, dodawania i zdejmowania promocji.
- Wstępny pomiar przeglądarkowy: 320 i 1440 px, oba motywy, tekst 140%,
  oba kierunki wysłania formularza (8 przypadków). Bez poziomego overflow;
  po kliknięciu podsumowania aktywne jest właściwe pole. Obejrzano zrzuty
  320 px w ciemnym motywie i 1440 px w jasnym motywie. Ten przebieg poprzedzał
  usunięcie powtórzenia w etykiecie; nie zastępuje końcowej pełnej macierzy.
- Po poprawieniu etykiety: 48 przypadków (320, 360, 390, 414, 768 i 1440 px;
  oba motywy; tekst 100% i 140%; oba formularze). Wszystkie przeszły realny
  błędny PUT/GET i kontrolę niezmienności danych. Pole po kliknięciu błędu
  mieści się w viewport; w zapisanej geometrii żaden z 48 przypadków nie
  nachodzi na górny pasek. Zweryfikowano faktyczny motyw i skalę w DOM.
  [Pomiar](evidence/tag-notes581/local-matrix.json). Ponowny ogląd desktopu
  potwierdził pojedynczy dopisek opcjonalności.
- Rzeczywisty zoom 200% (`chrome.tabs.setZoom/getZoom`): cztery przypadki,
  oba motywy i oba formularze, tekst 140%, CSS 320×900 i DPR 2. Wszystkie
  przeszły z niezmienionymi danymi. Aktywne pole pozostaje widoczne,
  niezasłonięte nagłówkiem, z widocznym fokusem. Obejrzano zrzuty jasnego
  i ciemnego układu. [Pomiar](evidence/tag-notes581/zoom200.json).
  Początkowy pomiar błędnie sprawdzał punkty poza zaokrąglonymi narożnikami
  pola (promień 12 px). Diagnostyka potwierdziła trafienie w rodzica;
  poprawiony test bada środek i środki czterech krawędzi oraz pełne granice
  pola i nakładanie nagłówka. Nie zmieniano CSS aplikacji dla zaliczenia testu.

Testy używały wyłącznie lokalnych baz PostgreSQL na porcie 55439.
Kopie przed mutacjami zachowano poza repozytorium. Raporty nie zawierają
sesji, poświadczeń ani pełnych wierszy użytkowników.

## Pozostałe warunki dostarczenia

- Końcowy przegląd dowodów. Przygotowana wersja: Alfa 0.54.
- Zwykły hook, PR, wymagane CI i potwierdzenie wdrożenia.

Ten pakiet nie zamyka całości #581 ani macierzy marki #492.
