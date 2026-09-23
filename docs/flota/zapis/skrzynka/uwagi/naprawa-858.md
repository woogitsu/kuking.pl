# Uwagi audytu — praca `naprawa-858` (#858 i sąsiednie), odzysk `390e7640`

## 2026-09-20 20:42Z — uwagi audytu, NIE polecenie

Pełny kontekst: `_wspolne/skrzynka/meldunki/AUDYT-2026-09-20-2042.md`.

Audytowałem **nie gałąź z `do-pchniecia.txt`** — tamta nie istnieje — tylko
odzyskaną pracę w `C:\Users\matma\Documents\kuking-flota\naprawa-858-ODZYSK`,
gałąź `naprawa-858`, commit `390e7640` na `4c811cc7`: **52 pliki, 9141 wstawek**.

### Z14 — ZNALEZISKO POZYTYWNE: odmowa 429 jest prawdziwa, nie atrapowana

To było pytanie postawione wprost przez koordynatora. Odpowiedź: **dowód jest
z prawdziwej odmowy limitera.**

`tests/Feature/TagiPrzegladanieOsobnyLimiterTest.php`,
`test_429_przy_przegladaniu_nie_kasuje_zaznaczonych_tagow`:

1. Test **nie podstawia** `RateLimiter` ani nie wyłącza middleware. Wysyła
   to samo żądanie w pętli do 400 razy i przerywa, **gdy aplikacja naprawdę
   odpowie 429** — tą samą drogą, co reszta limitera w tej bazie.
2. Ma **kontrolę metody pomiaru**:
   `assertStatus(429, 'Test nie wymusił prawdziwej odmowy 429 — kontrola metody pomiaru zawiodła.')`
   — czyli test, który nie zdążył wywołać odmowy, oblewa zamiast po cichu przejść.
   To jest dokładnie ochrona przed „skan, który nic nie znalazł, PRZECHODZI”.
3. Zaznaczenie jest jawnie **niezapisane**: w ładunku stoi
   `'tags' => [$zupa->getKey()]` z komentarzem „Człowiek zdążył zaznaczyć zupę,
   zanim przefiltrował na »ciasto« — to zaznaczenie NIE jest jeszcze zapisane”,
   a na końcu `assertFalse($user->fresh()->isFollowingTag($zupa))`.
4. Dopiero mając to, asertuje `assertSee((string) $zupa->getKey())`
   oraz `assertSee('Wyślij jeszcze raz')`.

Punkt 3 jest tu kluczowy i dlatego to uznaję za mocny dowód: gdyby zaznaczenie
było już w bazie, zobaczenie jego identyfikatora na ekranie nie dowodziłoby
niczego. Tak jak jest — identyfikator może pochodzić **wyłącznie** z odtworzenia
tego, co człowiek wysłał. Twarda granica zlecenia („poprawne dane użytkownika
nigdy nie znikają”) jest dotrzymana i **udowodniona właściwą metodą**.

Osobno: `test_zapis_nadal_odbija_sie_o_trzydziesci_na_dziesiec_minut` pilnuje,
że rozdzielenie limitera przeglądania od zapisu **nie rozluźniło** limitu zapisu
(`assertStatus(429, 'Limit zapisu zniknął albo się rozluźnił — ma zostać przy 30/10.')`).
To kontrola z drugiej strony i bardzo dobrze, że jest.

### Czego szukałem i NIE znalazłem

- **Punkt 1 — czerwień przed poprawką: JEST, i to zacommitowana.**
  `docs/research/tagi-filtr-2026-09-20/czerwien-przed-poprawka.txt`, 187 wierszy.
  To najlepsze pokrycie punktu 1, jakie widziałem w obu przebiegach audytu —
  dowód nie tylko istnieje, ale **przeżył awarię stanowiska**, bo jest w commicie.
- **Punkt 2:** w commicie są też `kontrole-dodatkowe.txt`, `baseline-*`,
  `browser-*.json`, `pelne-testy-koniec.txt` — dowody, nie deklaracje.

### Czego NIE sprawdziłem — proszę nie czytać tego jako odbioru

Obejrzałem tę pracę pod kątem pytania koordynatora o 429 i pod kątem punktu 1.
**Nie audytowałem pozostałych 50 plików** tego commita: `UpdateTagFollows`,
`TagFollowWindow`, `TagSelection`, `OdzyskiwalneDane`, zmian w `OnboardingController`
i `TagFollowController` ani nowego CSS. To zakres na osobny przebieg.

### Rzecz pilniejsza niż powyższe — ta praca nie jest w żadnej kolejce

`naprawa/858-limiter` figuruje w `pchniete.txt`, ale **nie została pchnięta** —
dziennik kolejki o 20:23:01Z: `FETCH FAIL — brak galezi w repozytorium kanonicznym, pomijam`.
Gałęzi o tej nazwie nie ma ani lokalnie, ani na origin. Odzyskana praca żyje
wyłącznie w `naprawa-858-ODZYSK` jako `390e7640` i **nie ma jej w `do-pchniecia.txt`**.
Bez decyzji koordynatora nikt jej nie wypchnie. Szczegóły w Z12 i Z13 meldunku.
