# KuKING.pl — audyt i poprawki v3.1

**Data:** 7 września 2026. **Materiał wejściowy:** `kukingdesignsystemv3.zip`. **Wynik:** osobna poprawiona paczka; oryginał nie został nadpisany.

## Ocena i kierunek

Obecny kierunek wizualny warto zachować. Ciepła czerwień, kremowe tła, wyraźna typografia i proste karty dają dobrą podstawę. Nie potrzeba kolejnego rebrandingu, gradientów ani dodatkowych ozdobników. Największy zysk dają czytelność, przewidywalne przyciski i brak rozjeżdżania się układu po powiększeniu tekstu.

Zachowano paletę, logo, zdjęcia, bazowy tekst 18 px, nazwy nawigacji i model makiet bez JavaScriptu. Nie ma założenia, że każdy użytkownik 50+ ma takie same potrzeby. Zmiany ułatwiają korzystanie także osobom słabiej widzącym, obsługującym stronę jedną ręką lub mniej pewnie korzystającym z internetu.

**Werdykt:** po poprawkach paczka jest lepszą podstawą do wdrożenia, lecz pozostaje systemem projektowym i zestawem statycznych makiet, a nie gotową, przetestowaną aplikacją.

## Zmiana przełącznika wyglądu

W ośmiu ekranach produktu w stopce jest teraz **ikona księżyca + „Ciemny” + wskaźnik włączenia**. Sama ikona nie musi być oczywista, dlatego pozostawiono krótki podpis. Cała etykieta jest klikalna. Kontrolka ma wspólną prawą krawędź z ostatnią akcją nagłówka, w tym z „Powiadomienia” w aplikacji. Jest wyśrodkowana pionowo w swoim wierszu. Na telefonie może zejść do osobnego wiersza, ale pozostaje po prawej i nie chowa się pod dolną nawigacją.

Podgląd przełącza kolory bez przeładowania przez natywne pole wyboru i CSS. Wybór nie jest trwale zapisywany. Sposób podłączenia do aplikacji opisuje [MOTYW-V3.1.md](07-wdrozenie/MOTYW-V3.1.md). Wariant SSR wymaga szczególnej ostrożności w kreatorze przepisu, aby nie stracić niezapisanych pól lub pliku.

## Potwierdzone problemy i wykonane poprawki

### A01 · P1 · Test kontrastu mógł niczego nie sprawdzać

W `01-fundamenty/kontrast.mjs` uruchomienie testu było uzależnione od `import.meta.main`. W dostępnym Node 22.16.0 ta właściwość nie działa: oryginalny skrypt zakończył się kodem 0 i **pustym wynikiem**. Nie oznaczało to pozytywnego testu. W gałęzi Node 22 funkcję wprowadzono w 22.18.0.

Zastosowano przenośne rozpoznanie pliku głównego. Palety nie są już ręcznymi kopiami: skrypt czyta rzeczywiste deklaracje z `tokens.css`, odrzuca brakujące/niepoprawne kolory, a generator JSON korzysta z tego samego źródła. Wykonano prawdziwy test 70 par. Oddzielny test z celowo zepsutym kolorem sprawdza, czy walidator rzeczywiście zwraca błąd.

### A02 · P1 · Poziome przewijanie przy tekście 200%

W oryginale wykryto 12 wadliwych scenariuszy z 624: sześć kombinacji strony/szerokości/skali, każda w dwóch motywach. Dotyczyły galerii komponentów, tablicy, przepisu i pierwszego kroku kreatora, głównie przy 320, 768 i 1024 px.

Przyczyny obejmowały minimalne szerokości elementów flex/grid, długie podpisy oraz zbyt sztywne dzieci nagłówków. Dodano `min-width: 0`, kontrolowane zawijanie długich słów i bezpieczne kolumny `minmax(0, ...)`. Nie maskowano błędu przez globalne `overflow-x: hidden`. Tokeny i JSON obsługują dodatkowo `data-text-scale="200"`. Utwardzono też siatkę strony powitalnej przy zmianach szerokości.

### A03 · P1 · Nieprawidłowe formularze zdjęć

Główne formularze kroków 1 i 3 zawierały pola plików, ale nie miały `enctype="multipart/form-data"`. Dodano właściwe kodowanie. Pole nazwy przepisu ma teraz `required`. To poprawia kontrakt HTML, ale **nie uruchamia wysyłania do nieistniejącego backendu**. Walidacja plików, rozmiaru i uprawnień pozostaje zadaniem aplikacji.

### A04 · P1 · Boczny zapis szkicu nie obejmował pól

W każdym z trzech kroków boczny przycisk „Zapisz szkic” był w osobnym pustym formularzu. Przeniesienie tego wzorca do aplikacji powodowałoby wysłanie bez edytowanych danych. Główne formularze dostały identyfikatory, a boczne przyciski wskazują je atrybutem `form`. Obie akcje zapisu mają rozpoznawalny znacznik `akcja=szkic` i `formnovalidate`, aby dało się zachować nieukończony przepis.

### A05 · P2 · Dodawanie/usuwanie przenosiło do następnego kroku

Przyciski dodawania lub usuwania składnika dziedziczyły adres kolejnego kroku; podobny problem dotyczył dodawania kroku przygotowania. Nadano im `formaction` bieżącego kroku i `formnovalidate`. To intencja dla SSR, nie implementacja dynamicznego dodawania w statycznej paczce. Główne „Dalej” i „Opublikuj” pozostają osobnymi działaniami. Pytania grupujące radio w kroku 1 otrzymały `fieldset` i `legend`.

### A06 · P2 · Niespójna stopka i długi przełącznik

Stopka i nagłówek nie miały wspólnego wyrównania akcji. Nowa kontrolka jest po prawej; zgrano maksymalne szerokości i wcięcia w każdym progu. Poprawiono także osobne reguły strony publicznej. Klawiatura pokazuje wyraźny obrys, a czytnik może rozpoznać nazwę i stan natywnej kontrolki.

### A07 · P2 · Przyklejone elementy konkurowały o miejsce

Przyklejony nagłówek może mieć różną wysokość po zawinięciu tekstu. Boczna nawigacja i szyna nie uwzględniały tego niezawodnie. W v3.1 górny nagłówek przewija się z dokumentem. Panel składników może pozostać przyklejony na wysokim ekranie, natomiast na niskim przestaje być przyklejony. Rekomendacje na Start przewijają się razem ze stroną, bez osobnego wąskiego scrolla. To świadomy kompromis: mniej stale widocznych akcji u góry, więcej miejsca na czytanie.

### A08 · P2 · Nieczytelne podpisy w podglądzie marki

W `06-marka/znak/podglad-znaku.html` znaleziono sześć przypadków białego tekstu 16 px z kontrastem około 4,00:1 lub 4,16:1. Próg dla zwykłego tekstu wynosi 4,5:1. Podpisy otrzymały czytelny podkład z tokenów; nie zmieniano barw logo ani próbek.

### A09 · P2 · Wysoki kontrast systemowy

Usunięto wymuszanie oryginalnych kolorów przez `forced-color-adjust: none` w badanych komponentach. Fokus przycisków i przełącznika nie polega już wyłącznie na cieniu, który może zniknąć w trybie wymuszonych barw. Emulacja Chromium potwierdziła obrys 3 px. Nie zastępuje to testu na fizycznym Windows z czytnikiem.

### A10 · P3 · Zbyt małe metadane i linki

Klasy `.meta`, `.karta-meta` i `.wiersz-czas` korzystają teraz z 16 px zamiast 15 px. Wyjątek 15 px pozostaje dla cichej, pomocniczej plakietki. Linki stopki oraz link komentarzy w stopce karty mają większy pionowy obszar aktywny. 48 px to przyjęty cel komfortu produktu, **nie opis minimalnego wymogu WCAG AA**; WCAG 2.2 AA ma próg 24×24 CSS px i określone wyjątki.

## Wyniki testów

| Kontrola | Przed poprawkami | Po poprawkach |
|---|---:|---:|
| Zbadane kombinacje układu | 624 | 624 |
| Poziome przewijanie dokumentu | 12 kombinacji | 0 |
| Wykryte błędy kontrastu tekstu na jednolitym tle | 6 | 0 |
| Brakujące względne pliki/kotwice HTML | 0 | 0 |
| Test tokenów w Node 22.16.0 | nie wykonywał kontroli | 70/70 par |
| Linki demonstracyjne `href="#"` | 103 | 98 |

Przełącznik: 8/8 testów myszy i 8/8 testów Spacji. Wyrównanie do nagłówka: 96/96 kombinacji. Wyniki dotyczą wyłącznie opisanego niżej zakresu.

Wyniki liczbowe i warunki wykonania: [08-audyt/WYNIKI.md](08-audyt/WYNIKI.md). Dane surowe: katalog `08-audyt/wyniki/`. Sprawdzono 13 pełnych dokumentów HTML. Pominięto jako samodzielne strony sprite ikon i demonstrację urządzeń z osadzonymi ramkami; paczka ma łącznie 15 plików HTML.

Macierz układu: 8 szerokości (320, 390, 768, 1024, 1279, 1280, 1440, 1920 px), 3 skale tekstu (100%, 150%, 200%) i 2 motywy, czyli **624 kombinacje**. Osobno sprawdzono kliknięcie i Spację dla ośmiu przełączników, 96 kombinacji wyrównania stopki oraz widoczność ponad dolną nawigacją. Wybrane widoki obejrzano na zrzutach.

### Ograniczenia i znaczenie wyników

Testowano Chromium i systemowy font zastępczy. Paczka deklaruje Inter, ale nie dostarcza jego pliku. Nie dodano fontów do wydania. Po podłączeniu fontu w aplikacji trzeba powtórzyć testy, ponieważ zmienią się szerokości tekstu.

Środowisko blokuje nawigację przeglądarki do `file://` i lokalnego serwera. Dlatego załadowano rzeczywisty HTML przez Playwright `set_content`, a odwołania do lokalnego CSS i obrazów zastąpiono ich treścią **wyłącznie w narzędziu testowym**. Pliki dostarczone użytkownikowi zachowują strukturę i lokalne odwołania. Nie przetestowano w tym środowisku zwykłej nawigacji sieciowej, obsługi POST, cookies, Laravel/Tailwind build, Safari, Firefox ani rzeczywistego NVDA/VoiceOver.

Skala tekstu wykorzystuje zmienną produktu, nie jest kompletnym testem natywnego powiększenia przeglądarki. Automatyczny pomiar kontrastu obejmuje rozpoznane teksty na jednolitym nieprzezroczystym tle i osobno 70 par tokenów. Nie obejmuje wszystkich gradientów, zdjęć, nakładek, stanów ani ikon. Pomiar rozmiarów dotyczy wymienionych w skrypcie klas, nie każdego możliwego celu dotykowego. **To nie jest certyfikat pełnej zgodności WCAG ani badanie z użytkownikami.**

## Co nadal wymaga pracy przed uruchomieniem serwisu

**P1 — połączenie z aplikacją.** Po korekcie pozostaje 98 linków `href="#"` w całej paczce, w tym w galeriach. Nie są to 98 potwierdzonych usterek działającego serwisu: to także demonstracyjne akcje bez endpointów. Trzeba przypisać rzeczywiste trasy wyszukiwania, profilu, obserwowania, pomocy i dokumentów. Formularze kierujące POST do `.html` są kontraktem makiety, nie gotowym zapisem. Link „O Kuking” z pięciu ekranów aplikacji już prowadzi do dostarczonej podstrony.

**P1 — dane i komunikaty.** Potwierdzić zapis szkicu i powrót do niego, zachowanie zdjęcia między krokami, walidację serwera, prywatność przepisu oraz błędy sieci. Komunikaty „Szkic zapisany” lub „Nic nie zginie” mogą pojawiać się dopiero, kiedy aplikacja rzeczywiście zapewnia taki stan. Nie zweryfikowano bezpieczeństwa backendu ani prawdziwych danych kont.

**P2 — informacja po wybraniu zdjęcia.** Ukryte pole pliku nie daje czytelnego, trwałego potwierdzenia wyboru w samym interfejsie makiety. W aplikacji pokazać nazwę lub miniaturę, przycisk „Zmień zdjęcie” i zrozumiały limit. Bez JavaScriptu informacja może pochodzić z odpowiedzi serwera po zapisie kroku. Nie udawać drag-and-drop, gdy nie jest obsługiwane.

**P2 — test z odbiorcami.** Proponuję krótki test z 5–8 osobami o różnym doświadczeniu cyfrowym: znalezienie przepisu, odczytanie składników, dodanie zdjęcia, zapis nieukończonego przepisu, powrót i zmiana wyglądu. To propozycja badania, nie badanie wykonane w tym audycie. Warto zweryfikować znaczenie etykiet „Moje”, „Zeszyt” i „Świeżo z Kuking”, zamiast zakładać, że są zrozumiałe.

**P3 — mniej rozpraszania.** W gotowej aplikacji pierwszeństwo powinny mieć wyszukiwanie, przepis i zapis, a rekomendacje pozostawać pomocnicze. Nie dodawać następnych pływających przycisków, zbędnych animacji i akcji dostępnych wyłącznie po najechaniu.

## Jak obejrzeć i odtworzyć

Odwołania do folderów i dokumentów w tym raporcie dotyczą zawartości rozpakowanego ZIP v3.1.

Rozpakować cały ZIP i otworzyć `index.html`; nie przenosić pojedynczego HTML bez folderów CSS i obrazów. Ekran Start: `03-szablony/tablica.html`. Przełącznik znajduje się na dole. Podgląd motywu wymaga przeglądarki obsługującej `:has()`.

Polecenia budowy i kontroli, uruchamiane z katalogu paczki:

```sh
node podglad/zbuduj-podglad.mjs
node 01-fundamenty/zbuduj-tokeny.mjs
node 01-fundamenty/kontrast.mjs
node 07-wdrozenie/sprawdz-paczke.mjs
python 08-audyt/audit.py . /tmp/kuking-audit
python 08-audyt/interaction.py . /tmp/kuking-interaction
```

Skrypty Pythona wymagają BeautifulSoup4, Playwright i Chromium; `CHROMIUM_PATH` może wskazać plik wykonywalny. Szczegóły podaje `08-audyt/WYNIKI.md`. Starszy `07-wdrozenie/sprawdz-uklad.mjs` zachowano jako test pomocniczy; nie był źródłem raportowanych 624 pomiarów i ma węższą macierz.

## Źródła zasad

Wyniki dotyczące kodu wynikają z dostarczonej paczki i wykonanych testów. Zasady odniesienia: [W3C: starsi użytkownicy](https://www.w3.org/WAI/older-users/developing/), [Resize Text](https://www.w3.org/WAI/WCAG22/Understanding/resize-text.html), [Contrast Minimum](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html), [Target Size Minimum](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html), [Switch Pattern](https://www.w3.org/WAI/ARIA/apg/patterns/switch/), [Node 22.18.0](https://nodejs.org/en/blog/release/v22.18.0), [MDN: form enctype](https://developer.mozilla.org/en-US/docs/Web/API/HTMLFormElement/enctype).
