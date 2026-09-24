# Dostęp i odzyskiwanie: #902, #910, #903

Stanowisko: `gpt-ugotowalem-dostep`, gałąź `gpt/ugotowalem-dostep`.
Baza źródła: `4c811cc7bff365fb8f86d87eabac93b7738a45cd`.
Pomiary własne: 20 września 2026, lokalny runtime WSL, PostgreSQL
`127.0.0.1:55439`, baza `kuking_flota_gpt-ugotowalem-dostep`, właściciel
połączenia `kuking`. Bez zmian produkcji.

## Pomiar przed poprawką

Na nietkniętym kodzie aplikacji istniejące `CommentEditTest` i
`CookingModeTest`: 22 testy, 77 asercji, wszystkie poprawne.
Nowe regresje, uruchomione PRZED zmianą implementacji:

- `UgotowalemDostepTest`: 2 porażki. Oba ekrany zwracają 200 i pokazują link
  do formularza, choć bezpośredni GET formularza zawieszonego konta daje 403.
  Gość nie ma linku, aktywne konto ma link.
- `OdzyskaniePoprawkiKomentarzaTest`: 4 porażki odzyskiwania tekstu, kontrola
  cudzej/usuniętej treści przechodzi. Komentarz i odpowiedź, bezpośrednio po
  900 sekundach oraz błąd walidacji po 899 sekundach z GET-em po 900 sekundach.
- `GotowanieOdPoczatkuTest`: brak przycisku resetu przy odhaczeniu; POST na
  proponowaną trasę zwraca 404. Odhaczanie i zachowanie postępu działają.

To są własne żądania przez aplikację i PostgreSQL, nie przejęte wyniki z issues.
Opisy źródłowe #902/#903 deklarowały brak pomiaru HTTP; #910 zawierało jedynie
[pomiar cudzy: issue #910] granicę Policy 899/900/901 s z atrapami modeli.
Wyników tych nie używamy jako dowodu HTTP.

## Wprowadzone zachowanie

### #902

Oba zaproszenia do „Ugotowałem” pytają `RecipePolicy::cook`, tak jak formularz.
Autoryzacja serwera nie została osłabiona. Gość zachowuje zaproszenie do konta,
zawieszona osoba nadal czyta przepis i kroki.

### #910

Odmowa zapisu po czasie pozostaje statusem HTTP 403, lecz własna poprawka
wraca w czytelnym polu tylko do odczytu. Instrukcja mówi, żeby skopiować tekst,
zachować go lub wkleić do nowego komentarza. Nic nie publikuje się automatycznie.
Blade escapuje tekst, w tym znaczniki zamykające textarea.

Oddzielna zdolność `CommentPolicy::recoverExpiredEdit` sprawdza autorstwo,
aktywność konta, widoczność rodzica, stan komentarza i upływ czasu. Nie jest
zgodą na zapis. Po walidacji identyfikator autoryzowanego komentarza jest
przekazywany jednorazowo w sesji, a tekst pochodzi z `old('body')`. Dzięki temu
wygaśnięcie czasu przed kolejnym GET-em nie chowa ostatniej poprawki razem
z formularzem edycji. Blok jest nad paginowanym wątkiem, nie wewnątrz pętli.

### #903

Właściciel jawnie zatwierdził w tej rozmowie proponowane zachowanie.
Przycisk jest widoczny przy istniejących odhaczeniach. Natywne `details`
pokazuje pytanie, „Zostaw odhaczenia” oraz osobny przycisk potwierdzenia.
POST z CSRF, limitem `cooking_krok` i `RecipePolicy::view` czyści jeden klucz
sesji i wraca na pierwszy krok. GET nie resetuje. Nie ma migracji.

Zawieszone konto nie dostaje nowego przycisku resetu, ponieważ obecna globalna
blokada zapisu odrzuciłaby tę akcję. Rozstrzygnięcie, czy zawieszenie powinno
obejmować prywatny postęp gotowania, pozostaje osobnym pytaniem poniżej.

## Osobne znalezisko: szerszy wzorzec zawieszenia

Próbnik `PomiarZawieszenia902Test.php` jest zachowany obok raportu, poza
standardowym zestawem regresji: dokumentuje wadę, nie utrwala jej jako kontraktu.

| Akcja | Widok | Odmowa |
|---|---|---|
| Wyślij komentarz pod wpisem | 200, przycisk widoczny | `PostPolicy::comment` false; zapis zatrzymuje globalna blokada konta |
| Obserwuj na profilu aktywnej osoby | 200, formularz widoczny | `UserPolicy::follow` false; zapis zatrzymuje globalna blokada konta |
| Zapisuję na stronie przepisu | 200, formularz widoczny | POST 302 z błędem sesji `konto` |
| Oznacz krok jako zrobiony | 200, formularz widoczny | POST 302 z błędem sesji `konto` |

Odczyt kodu wskazuje ponadto nieosłonięte `follow` w liście znajomości
i komponencie tablicy osób. Tych dwóch miejsc nie zaliczamy do pomiaru HTTP.
Nie jest to kompletny audyt wszystkich ekranów ustawień i publikacji.

Nie poprawiono tych ścieżek w ramach #902. Warianty dla właściciela:

1. Ukryć pozostałe niedozwolone akcje, pozostawiając obecną blokadę zapisu.
   Koszt: zmiany wskazanych widoków i macierze aktywne/zawieszone/gość.
2. Osobno dopuścić prywatne czynności zawieszonej osoby (zeszyt, odhaczenia,
   reset), zachowując zakaz publikacji i obserwowania. Koszt większy: jawna
   decyzja o zakresie kary, zmiana wyjątków middleware, testy każdej trasy.

## Współdzielony plik i wycofanie

Przed zmianą przeczytano commity gałęzi `flota/gotowanie`: `62e9b777`,
`c1421f82`, `593a7ab9`, `fc026237`, `47c89c11`, `77b6d71a`.
Nie przenoszono ich do tej gałęzi. Wspólny plik to
`resources/views/pages/recipes/cooking.blade.php`; nasze zmiany dotyczą
końca widoku i zaproszenia „Ugotowałem”, a tamte składników i minutnika.
Nie zmieniano JavaScriptu, Wake Locka, minutnika ani identyfikatorów kroków.

Wycofanie: odwrócić lokalne commity tego zadania w kolejce integracyjnej.
Nie ma migracji ani trwałych zmian schematu. Odhaczeń już świadomie
usuniętych przez użytkownika nie da się odtworzyć przez cofnięcie kodu.
Wersja 0.68 jest lokalnym podbiciem z D-134; przy integracji wielu gałęzi
koordynator musi rozstrzygnąć ewentualną kolizję numeracji.

## Granice

Nie wykonano pushu, nie otwarto PR-a, nie uruchomiono zdalnego CI ani nie
zmieniono danych produkcyjnych. `ProbaOdtworzeniaTest` pominięto zgodnie
z jawnym wyjątkiem właściciela dotyczącym współdzielonej bazy próby.

## Weryfikacja końcowa

Wszystkie poniższe wyniki są pomiarami własnymi:

- Końcowy wspólny przebieg sześciu klas (trzy nowe regresje,
  `CommentEditTest`, `CookingModeTest`, `KazdaTrasaZIdentyfikatoremPodPolicyTest`):
  **44 testy, 896 asercji, PASS** na PostgreSQL 55439.
- Pełny zestaw z pominięciem wyłącznie `ProbaOdtworzeniaTest`: 4402 PASS,
  1 FAIL, 83767 asercji. Porażka dotyczyła naszej nowej trasy
  `cooking.restart`, której brakowało w macierzy autoryzacji. Rejestr
  uzupełniono; jego wszystkie 9 testów przechodzi także w końcowym przebiegu.
  Po tej zmianie testów nie powtarzano całego zestawu. Nie deklarujemy
  pełnego zielonego przebiegu po ostatniej zmianie.
- Cztery kontrole mutacyjne: oba zaproszenia #902, tekst #910, reset #903.
  Każda przeszła PASS → FAIL z oczekiwaną przyczyną → PASS. Pliki JSON
  są obok raportu. Narzędzie zapisuje JSON przed końcowym trapem, dlatego
  pole `przywrocenie` zawiera „nie wykonane”; terminal potwierdził następnie
  zgodność MD5 i mtime po przywróceniu. Nie poprawiano ręcznie tych JSON-ów.
- Próbnik szerszego zawieszenia: PASS, 22 asercje dokumentujące cztery
  niedostępne akcje. To pomiar obecnej usterki, nie test docelowego kontraktu.
- `vendor/bin/pint`: PASS, wszystkie 11 zmienionych/dodanych plików PHP.
- `npm run build`: PASS, w tym 20 testów Node i 72 sprawdzenia kontrastu.
- Ogląd lokalnego HTML otrzymanego z rzeczywistych odpowiedzi aplikacji:
  ekran odzyskiwania przy 320 px, bez JavaScriptu, z czytelnym polem tekstu.
  To statyczny ogląd z prawdziwym CSS, nie pełny przepływ E2E. Automatyzacja
  interakcji przeglądarki przekroczyła limit czasu; nie zaliczono kopiowania
  klawiaturą, otwarcia potwierdzenia resetu ani powiększenia 200%.
  Zachowanie resetu i anulowania zmierzono żądaniami HTTP w testach.

Oprzyrządowanie: wrapper kontroli ujemnej czyści skompilowane Blade przed
pomiarem (przywrócenie mtime może pozostawić skompilowaną mutację) i ogranicza
wydruk błędu. Omija to zaobserwowany SIGPIPE w obecnym skrypcie kontroli,
który używa `printf | grep -q` pod `pipefail`. Nie zmieniano wspólnego skryptu.
