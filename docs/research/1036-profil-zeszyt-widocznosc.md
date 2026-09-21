# Profil i zeszyt nie ujawniają niedostępnego przepisu — #1036

## Zakres

Zapowiedź ma publiczną widoczność wpisu, ale dostęp do jej treści określa
powiązany przepis. Profil stosuje `zWidocznymPrzepisem()` we wspólnym helperze
wpisów: lista, statystyki, lata archiwum oraz tagi i zdjęcia szyny odpowiadają
na to samo pytanie. Zeszyt stosuje tę bramkę przed paginacją.

Obie powierzchnie sprawdzają również dostępność autora **przepisu**. Autor
wpisu nie musi być autorem wskazanego przepisu. Zbanowane i kasowane konto
nie wraca przez wpis innej osoby; zachowany dorobek `erased` pozostaje
dostępny zgodnie z istniejącym `dostepnyJakoAutor()`.

Relacje `recipe` i `recipe.heroMedia` są ładowane zbiorczo, z kolumnami
widoczności i kluczem zdjęcia (D-178). Brak zmian schematu, zapisów zeszytu,
notatek, polityk prywatności oraz potwierdzeń usuwania.

## Pomiar lokalny

Baza źródłowa: `65327e69ddc3423279c7324ff20639ead64c6610`.
PostgreSQL: własna baza zadania na `127.0.0.1:55439`.

- `ProfilIZeszytNieUjawniajaPrzepisuTest`: 23 testy, 273 asercje.
- Z regresjami `ZeszytPrzyjmujeWpisyTest`, `ZeszytPaginacjaObuListTest`
  i `ZeszytZapisanychWpisowWydajnoscTest`: 40 testów, 531 asercji.
- Pint: trzy zmienione pliki PHP poprawne.
- Istniejące testy widoczności profilu, jego liczników, zdjęć szyny,
  lat archiwum i zakładki wykonań: dodatkowe 21 testów, 242 asercje.
- Osobne usunięcie `zWidocznymPrzepisem()` z każdego kontrolera:
  PASS → FAIL z komunikatem `WYCIEK_1036` → PASS. Przyrząd potwierdził
  jedną podmianę oraz przywrócenie MD5 i czasu pliku.
- Osobne wyłączenie bramki dostępności autora przepisu w każdym kontrolerze:
  PASS → FAIL (`WYCIEK_1036`, scena z różnymi autorami) → PASS.

Testy wykonują żądania HTTP, liczą wyrenderowane karty i sprawdzają brak
tytułu, sluga oraz identyfikatora zdjęcia w HTML. Każda ujemna scena zachowuje
widoczną publiczną kartę. Osobno sprawdzają autora, obserwującego, gościa,
zwykły wpis, zachowaną notatkę, filtrowanie przed paginacją i stałą liczbę
zapytań o przepisy/zdjęcia przy wzroście z dwóch do siedmiu kart.

Nie uruchomiono pełnego zestawu ani CI; publikacja pozostaje zadaniem
szeregowej kolejki. Brak pomiaru na produkcji.

## Integracja i wycofanie

Zmiana nie zastępuje `gemini-profil` (zakładka wykonań), `flota/tag941`
(strona tagu) ani `flota/scal-zeszyt-775` (operacje zeszytu). Przy scalaniu
trzeba zachować zmiany wszystkich trzech obszarów. Nie kopiować całych
starszych kontrolerów nad nowsze wersje.

Rollback kodu nie wymaga migracji ani usuwania danych, ale przywraca wyciek.
Bezpieczniej poprawić naprzód niż przywrócić poprzednie zapytania.
