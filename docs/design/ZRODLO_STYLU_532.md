# Aktualne źródło stylu w indeksach — #532

Dwa indeksy wejściowe nadal wskazywały historyczne paczki jako nadrzędne:
README katalogu design nazywał kit-v2 obowiązującym wyglądem, a indeks v3.1
nadawał uploads pierwszeństwo przy każdej wątpliwości. Nie wynikało to
z aktualnej konstytucji ani późniejszych decyzji właściciela.

Dodano na początku obu indeksów aktualną hierarchię: AGENTS, konstytucja
marki i decyzje, z odsyłaczami do audytu paczki, zachowanego ZIP i macierzy
odbioru. Dawne wskazania i pomiary oznaczono historycznie. Oryginalne
uploads, kity i ZIP pozostają zachowane. Nie jest to zmiana aplikacji ani
nowy projekt wizualny; zakres uzupełnia #516, który nie obejmował tych
indeksów. Nie oznacza pełnego odbioru identyfikacji.

## Wykonane sprawdzenia

InstrukcjeChroniaSrodowiskoTest uruchomiono na aktualnym pliku canonical,
przez absolutną ścieżkę Windows zamontowaną w WSL. PHPUnit bez konfiguracji
Laravel, z autoloaderem istniejących zależności: PHP8.4.24, PHPUnit12.5.34.
Nie uruchamiano bazy, aplikacji, mediów ani pełnego zestawu PHP. Native
nie synchronizowano ani nie modyfikowano.

Pierwszy wynik przed wzmocnieniem kolejności sekcji: **5 testów /49 asercji PASS**. Pint wskazanego testu: PASS, bez formatowania.

Cztery rzeczywiste kontrole ujemne zmieniały dokumenty canonical:

| Zmiana źródła | Wykryta asercja |
|---|---|
| Usunięcie odsyłacza konstytucji z aktualnej sekcji indeksu design | ZRODLO_STYLU_KONSTYTUCJA |
| To samo w indeksie v3.1 | ZRODLO_STYLU_KONSTYTUCJA |
| Powrót starego nadrzędnego nagłówka kitu | ZRODLO_STYLU_STARY_KIT |
| Powrót nadrzędności oryginału uploads | ZRODLO_STYLU_STARE_UPLOADS |

Każda zakończyła rzeczywisty proces kodem1 i oczekiwaną asercją. Po każdej
przywrócono kopię z dysku poza repo, potwierdzono MD5 oraz dokładny mtime_ns
i powtórzono dodatni test. Kopiowanie i odtwarzanie metadanych wykonał
Python Windows, zachowując precyzję czasu plików tego systemu.

Przywrócone MD5:
- indeks design: `800fbce7f0d41149080e11e05d50d68c`;
- indeks v3.1: `39de16d87d74fb5b600effcc28a61d01`.

Kopie źródeł i surowe logi poza repo: `C:\Users\matma\AppData\Local\Temp\kuking532-negatywy-ge_p4ykd`.
Szczegóły mtime i wyników: `output/negatywy532.json`.

Po integracji sprawdzono również linki i opisane trasy dokumentacji:
**3 testy / 41 asercji PASS**. Źródła canonical i kopię wykonawczą
porównano bajtowo (1934 pliki). Powyższe wyniki nie potwierdzają jeszcze
wysyłki, CI ani scalenia tego sprostowania.


## Uzupełnienie po niezależnym review

Review wykrył, że poprzednia kontrola obecności sekcji i linków mogła
zaakceptować aktualne źródło umieszczone dopiero po archiwach. Test sprawdza
teraz obecność obu nagłówków i ich rzeczywistą kolejność w każdym indeksie.

Piąty rzeczywisty negatyw przeniósł całą sekcję Aktualne źródło stylu na
koniec indeksu design, zachowując jej linki. Proces zakończył się kodem1
z asercją ZRODLO_STYLU_KOLEJNOSC. Odtworzono zewnętrzną kopię, MD5 oraz
dokładny mtime_ns. Dodatni baseline i rerun po przywróceniu: **5 testów /55
asercji PASS**. Pint końcowego testu: PASS. Łącznie wykonano pięć negatywów.

Szczegóły piątej kontroli: `output/negatyw532-kolejnosc.json`; kopia i logi
poza repo: `C:\Users\matma\AppData\Local\Temp\kuking532-kolejnosc-fftbaw08`. Nie zmieniano native ani bazy.
