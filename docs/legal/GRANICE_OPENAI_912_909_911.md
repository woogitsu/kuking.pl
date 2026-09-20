# Granice OpenAI i atomowość komentarzy — #912, #909, #911

Gałąź `gpt/openai-granice`, baza `4c811cc7bff365fb8f86d87eabac93b7738a45cd`.
Stanowisko `C:\Users\matma\Documents\kuking-flota\gpt-openai-granice`.

## Zakres i decyzje właściciela

20.09.2026 właściciel zatwierdził wspólną ochronę zdjęć wpisów oraz awatarów.
Potwierdził też: „otwarte uzupełniaj, zamknięte zostaw”. Nie wznawiamy spraw
odrzuconych ani zamkniętych po edycji; nie dokładamy wersjonowania zgłoszeń.

## Zmierzone samodzielnie przed poprawkami

- Czysty kod: 27 istniejących testów moderacji, 79 asercji, zielone.
- #912: syntetyczne historyczne metadane z samym `large`, brak klucza `thumb`
  oraz `thumb` deklarujący 320 × 240 przy innych bajtach wysyłały JPEG
  **2048 × 1536** do atrapy HTTP. Obie drogi: wpis oraz rzeczywiste zadanie
  `PrzeanalizujAwatar`. Bajty dekodowano z przechwyconego żądania `data:`.
  Przeszły też 321 × 240 i 240 × 321. Dziesięć czerwonych przypadków.
  Brak pliku i uszkodzone bajty już zatrzymywały wysyłkę. Kontrola dodatnia:
  rzeczywisty WebP 320 × 240 dał dwa żądania JPEG 320 × 240.
- #909: zakończono analizę neutralnego komentarza (jedno HTTP, zero spraw),
  następnie edytowano go przez endpoint. Nowego zadania nie było — test
  czerwony na braku dispatch. Po poprawce wykonano przechwycone zadanie:
  aktualny tekst dotarł do atrapy i lokalny numer telefonu utworzył sprawę.
- #911: wyjątek w `Notification::creating`, po potwierdzeniu zmiany komentarza
  w PostgreSQL, zostawiał `deleted_at` albo `body_removed_at`. Obie czerwienie
  miały oczekiwaną przyczynę. Drugie usunięcie z odpowiedzią dawało dwa
  powiadomienia zamiast jednego — trzecia czerwień.
- Otwarte zgłoszenie nie przyjmowało nowego sygnału po edycji; test czerwony
  na braku powodu z numerem telefonu. Przyjęto ten sam mechanizm uzupełniania,
  który istnieje już na gałęzi `gpt/moderacja-ai`.
- Pierwszy test szybkich edycji miał niepoprawną asercję kształtu HTTP
  (`input` zamiast `input[0].text`). To błąd testu, nie usterka produktu.

## Implementacja

`OcenaModelem` wybiera tylko dokładny wariant `thumb`. Wymiary bada z bajtów
przed dekodowaniem i jeszcze raz na zakodowanym JPEG przed utworzeniem URI.
Każdy bok ma najwyżej **320 px**, niezależnie od konfiguracji generatora
wariantów i deklarowanych metadanych. Większa miniatura jest pomijana;
nie pobieramy zastępczego `large` ani oryginału. Odmowa braku miniatury lub
przekroczenia wymiaru zostawia stały komunikat bez identyfikatorów i treści.
Tekst oraz lokalne sygnały nadal mogą być analizowane.

Zmiana tekstu komentarza zleca istniejące `PrzeanalizujTresc` po zatwierdzeniu.
Identyczny tekst po przycięciu nie zleca zadania. Każda rzeczywista edycja ma
własne zadanie; wszystkie czytają aktualny tekst podczas wykonania. Test
pierwszego oczekującego zadania i dwóch edycji potwierdza trzy oceny ostatniej
wersji. Nie obiecujemy dokładnie jednego wywołania dostawcy.

`OznaczDoPrzegladu.php` przejęto z `gpt/moderacja-ai` na SHA
`906f19a96d98643305157e4f9ace4f33c0fce8f4`, bez drugiego mechanizmu scalania.
W tej gałęzi tryb uzupełnienia włączono dla komentarzy. Otwarte sprawy zbierają
unikalne powody; zamknięte pozostają bez zmian. Istniejący limit opisu
2000 znaków i indeks jednej sprawy pozostają. **Przy scalaniu obu gałęzi
zachować również rozdzielenie zadań i bramkę dostępu z gpt/moderacja-ai** —
nie przywracać całego starego `PrzeanalizujTresc` z tej gałęzi.

`DeleteComment` zamyka zmianę komentarza i należne powiadomienie w jednej
transakcji. Pod blokadą wiersza ponownie sprawdza Policy i znacznik
`deleted_at`/`body_removed_at`. Te istniejące pola zabezpieczają powtórzenie,
również po skasowaniu powiadomienia przez retencję. Wyjątek cofa całość.
Ponowienie po awarii zachowuje oryginalny cytat i powód. Reguły odbiorców
`NotifyUser` pozostają bez zmian. Odpowiedzi nie są usuwane.

Przeczytano `e89f28a5` i `gpt/eksport:output/EKSPORT-819-825-RAPORT.md`.
**[pomiar cudzy: opis commita e89f28a5 oraz raport gpt/eksport]** — ich wyników
nie użyto jako dowodu poprawności tej gałęzi. Zastosowano zasadę wspólnego
zapisu i trwałego znacznika. Tu skutkiem ubocznym jest INSERT w tej samej
bazie, a nie poczta: nie potrzeba nowego joba, outboxa ani nowej tabeli.

## Ograniczenia i wycofanie

Wszystkie pomiary lokalne: WSL, runtime
`/home/mateusz/flota/gpt-openai-granice-run`, właściciel bazy `kuking`,
baza `kuking_flota_gpt-openai-granice`, wyłącznie `127.0.0.1:55439`.
Obrazy i konta są syntetyczne, HTTP i poczta są atrapami. Test przeplotu
używa starego modelu na jednym połączeniu — nie dowodzi współbieżności
osobnych procesów ani zachowania przy zabiciu procesu systemowego.

Nie wykonano żądań do prawdziwego OpenAI, korespondencji, push, PR, CI,
wdrożenia ani operacji na danych produkcyjnych. Nie policzono zdjęć bez
miniatury na produkcji: zakres pracy ogranicza połączenia PostgreSQL do
lokalnego portu 55439. Dlatego **nie stwierdzamy incydentu produkcyjnego**
ani jego skali. Agregowany audyt produkcyjnych metadanych pozostaje osobną
pracą z dostępem produkcyjnym. Sąsiednie logowanie wyjątku awatara nie jest
objęte poprawką wymiarów.

Nie zmieniono schematu. Wycofanie: odwrócić lokalne commity tej poprawki;
przed cofnięciem ochrony zdjęć wyłączyć ocenę obrazów lub wyczyścić klucz
modelu, aby nie przywrócić znanej drogi wysyłki dużego obrazu. Powiadomień
ani zgłoszeń nie kasować. Odwrócenie zmian komentarzy przywraca opisane
luki; nie jest zalecanym sposobem obsługi awarii dostawcy.
## Końcowe sprawdzenia

- Pełny przebieg po głównych poprawkach: **4417 testów, 83 766 asercji,
  380,11 s**, wszystkie zielone. Pominięto wyłącznie `ProbaOdtworzeniaTest`
  na jawne polecenie właściciela (współdzielona baza próby odtworzenia).
  Pełny log pozostaje lokalnie w `output/granice-pelne-testy.txt`.
- Następnie rozszerzono brzegi: wadliwy typ `thumb`, mały zamiennik, ślad
  odmowy przy braku pliku, komentarz zastąpiony śladem usunięcia. Cztery
  czerwienie pokazały wyjątek typu, dwie brakujące wiadomości loggera
  i HTTP z usuniętego komentarza. Poprawiono te ścieżki, bez zmiany Media
  dla pozostałych konsumentów. Końcowe testy własne: **28 / 96 asercji**.
- Po tych uzupełnieniach: **100 testów regresyjnych, 326 asercji**, zielone;
  pełnego przebiegu nie powtarzano. PHPStan całego projektu oraz Pint
  (1158 plików) bez uwag. PHPStan najpierw wykrył niepoprawnie opisane
  odwołanie do szpiega loggera w nowym teście; użyto wyniku `Log::spy()`.
- Sześć fizycznych kontroli: wymiar, zakaz zamiennika, zlecenie po edycji,
  transakcja usunięcia, znacznik powtórzenia, uzupełnianie otwartej sprawy.
  Każda: **PASS → FAIL z oczekiwanej przyczyny → PASS**. Każda ma niezależne
  potwierdzenie przywrócenia MD5 i mtime po zakończeniu przyrządu.
  Dowody: [wyniki kontroli](granice-openai-dowody/wyniki.json).
- Nie budowano assetów ani nie wykonywano testów przeglądarkowych: brak
  zmian widoków, stylów i skryptów interfejsu. Nie uruchamiano migracji
  wycofujących: schemat nie zmienia się.

Nie pozostała decyzja produktowa blokująca te poprawki. Przed publikacją
kolejka floty musi uwzględnić wspólny plik `OznaczDoPrzegladu` i zmiany
`PrzeanalizujTresc` na obu gałęziach. Audyt agregatów produkcyjnych pozostaje
osobnym zleceniem — wynik lokalny nie rozstrzyga skali historycznego ryzyka.
## Lokalne commity implementacji

- `32d5e9d93a615805ec0e83d4c90ca995cdb3dada` — granica zdjęć #912,
  wraz z zatwierdzoną ochroną awatarów.
- `7b40b509459bb93f24b69130dc9646774c13c469` — #909 i #911,
  testy edycji, atomowości oraz ponowienia.

Raport, decyzje i dowody zapisano w kolejnym lokalnym commicie.