# Koszt adresów powiadomień — #833

Pomiar własny, 20.09.2026. Baza gałęzi: `f8e6b444`, zawierająca żywe
wycinki z `flota/notyfikacja-zywa`. Runtime WSL:
`/home/mateusz/flota/gpt-n1-powiadomienia-run`, PostgreSQL
`127.0.0.1:55439`, własna baza `kuking_flota_gpt-n1-powiadomienia`.

## Pomiar przed i po

| Zakres | 2 powiadomienia | 12 powiadomień |
|---|---:|---:|
| Całe żądanie listy przed poprawką | 17 | 67 |
| Całe żądanie listy po poprawce | 8 | 8 |
| Same adresy po poprawce | 1 | 1 |
| Same żywe wycinki po poprawce | 1 | 1 |

Całe żądanie mierzy `PowiadomienieSledziTrescKomentarzaTest` przez
`DB::listen`, po przygotowaniu danych, od wejścia HTTP do gotowej odpowiedzi
Blade. Komentarze publikuje rzeczywista akcja `PublishComment`, każdy od
osobnej osoby. Kontrola dodatnia wymaga 12 istniejących powiadomień.
Na niezmienionym kodzie aplikacji asercja stałego kosztu oblała się:
**50 zamiast 0**, z odczytem **17 i 67** w komunikacie.

`AdresyPowiadomienZbiorczoTest` mierzy osobno rozwiązanie adresów, bez kosztu
przygotowania danych. Sprawdza także rzeczywistą mapę adresów, więc pusta
mapa albo pominięcie odczytu nie da pozornie zielonego pomiaru.

[pomiar cudzy: stanowisko notyfikacja-zywa, treść #833] Wcześniejsze 16 i 66
dotyczyło kodu przed dodaniem żywego wycinka. Różnica wobec własnego pomiaru
17 i 67 to jedno zbiorcze zapytanie wycinków. Nie przenosimy tamtego pomiaru
na obecną bazę gałęzi.

## Zmiana

`Notification::destinationUrls()` rozwiązuje adresy całej strony jednym
zapytaniem. Używa tego samego zakresu `Comment::widoczneDla()` co rozmowa,
korzenia wątku oraz porządku `(created_at, id)`. Zliczanie wcześniejszych
korzeni jest podzapytaniem SQL; PHP nie pobiera całych rozmów do pamięci.
Koszt pracy bazy nadal zależy od rozmiaru rozmów — stała liczba zapytań
nie jest obietnicą stałego czasu wykonania.

Kontroler przekazuje mapę widokowi. Pojedyncze kliknięcie korzysta z tej
samej implementacji, przeliczając stronę ponownie: między wyświetleniem
listy a kliknięciem mogły zmienić się blokady i widoczność.
Nie ma cache numerów stron ani zapisu adresów w bazie.

Wykonania „Ugotowałem” dostają samą kotwicę, ponieważ ich rozmowy nie są
stronicowane. Adresy nadrzędnych treści nadal tworzą metody `url()` modeli,
w tym adres pytania zamiast zwykłego wpisu oraz aktualny slug przepisu.
Brak komentarza, korzenia lub nadrzędnej treści zachowuje dotychczasowy
adres zapasowy. Odczyt listy i otwieranie nadal przechodzą istniejące
bramki widoczności; resolver nie zastępuje autoryzacji.

Strażnik kosztu żywego wycinka pozostał. Oczekiwany przyrost całej listy
zmienił się z 50 na 0, zgodnie z własnym pomiarem; osobny test wycinków
nadal wymaga dokładnie jednego zapytania.

## Weryfikacja

- `vendor/bin/pint`: 1161 plików, sformatowane dwa zmieniane pliki PHP.
- `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`: bez błędów.
- Filtr `Powiadomienie`: 74 testy, 307 asercji, zielone.
- `AdresyPowiadomienZbiorczoTest`: 3 testy, 17 asercji, zielone.
- Strażnik całej listy po dodaniu dokładnego pomiaru 8/8: zielony,
  5 asercji.
- Kontrola ujemna przez `scripts/kontrola-ujemna.sh`: zmiana porównania
  wcześniejszych korzeni z `<` na `>` powoduje błędną stronę i czerwień
  na oczekiwanym `komentarze=2`. Przebieg PASS → FAIL → PASS; przywrócenie
  źródła sprawdzone przez MD5 i czas modyfikacji.

Pierwszy pełny przebieg: 4403 zaliczone, 7 porażek. Nie jest miarodajnym
wynikiem całości: krótki test pomiarowy na początku nałożył się na pełny
przebieg na tej samej bazie. Sześć porażek wskazywało brak `posts.kind`.
Powtórzone osobno `AkcjeKomentarzaWJednymRzedzieTest` (6/6) oraz
`AnalitykaBezCiasteczekTest` (9/9) przeszły. Pełny przebieg powtórzono
szeregowo; jego wynik jest poniżej.

**Powtórzony przebieg szeregowy:** 4408 zaliczonych, 1 porażka,
83613 asercji, 348,10 s. Jedyna porażka to strażnik migracji opisany
poniżej. Użyto `testuj.sh gpt-n1-powiadomienia --compact
--exclude-filter=ProbaOdtworzeniaTest`. Ten jeden test pominięto zgodnie
z zasadami floty: korzysta ze wspólnej bazy próby odtworzenia.
Nie jest to w pełni zielony zestaw i raport go takim nie przedstawia.

**Odtworzona osobno przeszkoda z bazy gałęzi:**
`KazdaMigracjaMaWycofanieTest::test_zaden_down_nie_jest_pusty_ani_samym_komentarzem`
odrzuca `2026_09_23_120000_usun_zamrozone_wycinki_komentarzy.php`:
brak deklaracji `WYCOFANIE_NIC_NIE_ROBI`. Test i migracja są identyczne
z bazą `f8e6b444` (`git diff HEAD --` dla obu plików jest pusty).
Nie poprawiano cudzej migracji w zadaniu dotyczącym adresów powiadomień.

## Wycofanie i granice

Brak migracji i nowych zależności. Wycofanie commita przywraca poprzedni
koszt, bez zmiany danych. Nie wykonano pomiaru produkcyjnego, wdrożenia,
push ani PR — publikacją zarządza szeregowa kolejka floty.
Nie ma nowej decyzji produktowej wymagającej rozstrzygnięcia właściciela.
