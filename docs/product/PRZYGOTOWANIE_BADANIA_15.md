# Przygotowanie badania #15 — zakres i dowody

20 września 2026; stanowisko `gpt-testy-50plus`, gałąź `gpt/testy-50plus`.
Punkt wyjścia: `4c811cc7bff365fb8f86d87eabac93b7738a45cd`.

## Stan przed zmianą — własny odczyt i pomiar

- `git status --short`: puste wyjście przed pierwszą edycją; gałąź i SHA
  zgodne z zadaniem. Nie zmieniano repozytorium kanonicznego.
- Przeczytano zasady floty i lokalne AGENTS.md, dokumenty produktu, zakresu,
  UX, roadmapę, istotne reguły marki i istniejące materiały #15.
- Odczytano bieżące #15 przez `gh issue view 15 --repo woogitsu/kuking.pl`
  (treść i komentarze). Status OPEN, kryterium nadal obejmuje 13 odbytych
  sesji. Istniejący protokół ma 10 zadań, bez osobnego operacyjnego kryterium
  sukcesu dla każdego; zaleca realne adresy i nagrania. Dodatek z 14 września
  uzupełnia część kryteriów, ale nie daje jednego ośmiozadaniowego przebiegu.
- Odczyt #858 z komentarzem właściciela ujawnił aktualną decyzję o filtrze
  i doładowaniu. Na naszym SHA widok `pages/settings/tags.blade.php` nadal
  renderuje pełną `choice-grid`, bez filtra. To odczyt źródła, nie ogląd
  produkcji ani twierdzenie o zawartości aktualnego main innych stanowisk.
- Przed edycją dokumentacji wykonano `SearchTest` na niezmienionych źródłach:
  **12 PASS, 34 asercje, 3,39 s**. Runtime przygotowany dostarczonym skryptem;
  `testuj.sh gpt-testy-50plus --filter SearchTest`, PostgreSQL
  `127.0.0.1:55439`, baza `kuking_flota_gpt-testy-50plus`, użytkownik `kuking`.
  Test wykonuje rzeczywiste zapytania PostgreSQL i żądania HTTP; nie dowodzi
  samodzielnego wyszukania przez człowieka ani sprawności wyszukiwarki na
  produkcji. Nie zmieniono kodu w celu uzyskania wyniku.

## Co dostarczono

- [Protokół R1](TESTY_Z_UZYTKOWNIKAMI.md): osiem celów, stany startowe,
  kryteria ustalone przed sesją, czas, pomoc, reset zależności, hipotezy,
  interpretacja i decyzje właściciela.
- [Karty do czytania](KARTY_ZADAN_15.md): same cele, bez kryteriów prowadzącego.
- [Karta](KARTA_BADANIA_15.md): przygotowanie rundy, dane ćwiczeniowe, próba
  techniczna, notatka sesji, zestawienie i porównanie po zmianie.
- Wskazanie aktualnej instrukcji w standardzie UX i historycznym dodatku.
  Dawny dodatek zachowano jako materiał, z wyraźną adnotacją o zakresie R1.

Przegląd spójności: osiem nagłówków T1–T8, wszystkie lokalne odnośniki w
sześciu zmienionych dokumentach istnieją (21 odnośników); tekst dziewięciu kart
jest zgodny z ośmioma zadaniami protokołu (T4 ma dwa etapy); `git diff --check` bez błędów.
Przejrzano ręcznie powiązania: cel → stan → kryterium → odpowiedni wiersz
karty. T4 ma dwa etapy i jawne reguły łącznego wyniku. Powrót przez ponowne
wyszukanie nie jest nazywany porażką celu tylko dlatego, że badacz oczekiwał
użycia zeszytu; droga jest zapisana osobno. Brak danych i wycofanie nie są
wliczane do mianownika ukończenia.

Kontrola rachunku protokołu na **fikcyjnym przykładzie, nie danych badania**:
5 zaplanowanych prób, 2 S + 1 H + 1 N + 1 X = 2/4 samodzielnie przy czterech
ważnych próbach, a X osobno. T4a=N i T4b=S po przygotowaniu zapisu przez
prowadzącego daje T4=N, nie S. Żaden z tych przykładów nie jest sesją z ludźmi.

## Pomiary przejęte — nie powtórzono ich tutaj

- [pomiar cudzy: #858, komentarz właściciela](https://github.com/woogitsu/kuking.pl/issues/858#issuecomment-5751034532):
  wysokość listy 100 tematów przy 320 px i tekście 200%: 27 515 px.
  Źródło samo odróżnia wysokość od problemu odnajdywania przez ludzi.
- [pomiar cudzy: raport #681](../design/FOTOGRAFICZNE_TAGI_681.md):
  geometria kart, zoom i emulowane wejścia. Nie dowód użyteczności.
- [pomiar cudzy: #473](WALIDACJA_WYBORU_ZESZYTU.md): walidacja wyboru
  zeszytu; [pomiar cudzy: #647](../design/TAGI_W_OPISIE_647.md):
  render wyników tagów. Zakres i ograniczenia opisane w mapie hipotez R1.

## Czego nie wykonano

Nie było kontaktów, rekrutacji, sesji, nagrań ani zbierania danych ludzi.
Nie przygotowano kont ani kompletu mediów do przyszłych sesji: wybór
odizolowanego środowiska i jego przygotowanie ma listę kontrolną w protokole.
Nie wykonano próby wszystkich zadań w przeglądarce ani oglądu produkcji.
Próba techniczna przez przyszłego prowadzącego jest wymagana przed spotkaniem;
jej pola w karcie są celowo puste. Automatyczny SearchTest jej nie zastępuje.

Nie zmieniano aplikacji, schematu, zasad produktu ani testów. Nie dodawano
regresji i mutacji kodu: nie jest to poprawka błędu aplikacji. Nie wykonano
push, PR, modyfikacji issue ani zmian na cudzych stanowiskach.

## Decyzje właściciela i wycofanie

Do wyboru przed sesjami: 5-osobowa runda rozpoznawcza czy harmonogram 13 sesji,
środowisko ćwiczeń, plan osobnych brakujących zadań #15 i ewentualne warianty
10/100 tagów. Warianty i koszty stoją w §7 protokołu. Decyzja o filtrze #858
nie jest otwierana ponownie. #15 pozostaje otwarte; dokument nie jest dowodem
spełnienia bramki przed betą.

Zmiana jest wyłącznie dokumentacyjna. Wycofanie: odwrócić lokalny commit
tej dokumentacji przez `git revert <SHA>` na właściwej gałęzi; bez operacji
na bazie i bez cofania cudzych commitów. Samo wycofanie przywróci starszą,
szerszą instrukcję — trzeba wtedy zachować jawne ograniczenia obecnego zadania.

## Kontrole końcowe

- Pint: **PASS, 1155 plików** w runtime stanowiska; tryb sprawdzenia bez
  przepisywania źródeł. Zmiana obejmuje wyłącznie Markdown.
- Domyślna suita PHP na własnym PostgreSQL: **4393 PASS, 83 692 asercje,
  517,15 s, kod wyjścia 0**. Polecenie skryptu floty:
  `testuj.sh gpt-testy-50plus --filter '^(?!.*ProbaOdtworzeniaTest)' --compact`.
  Pominięto `ProbaOdtworzeniaTest` zgodnie z jawnym wyjątkiem użytkownika:
  współdzieli bazę `kuking_zrodlo_proby_glowny`; nie próbowano jej używać.
  Pozostaje standardowe wyłączenie grupy `dwa-polaczenia` w phpunit.xml.
  Nie jest to wynik CI ani testów przeglądarkowych. Kod aplikacji i testów
  jest identyczny z bazowym SHA; późniejsze redakcje dotyczą tylko Markdown.
- Dwie próby przekazania złożonego filtra przez powłoki Windows/WSL zakończyły
  się błędem składni, nie wynikiem testu. Docelowy przebieg użył tymczasowego
  skryptu w naszym worktree, z filtrem przekazanym dosłownie. Skrypt usunięto
  po ukończeniu; nie zmieniano wspólnych narzędzi.
