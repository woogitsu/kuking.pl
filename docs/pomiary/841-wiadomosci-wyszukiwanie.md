# Pomiar #841 — odnajdywanie wcześniejszych wiadomości „Napisz do nas”

**Status: przygotowany pomiar, nie funkcja.** Issue #841 (P3) mówi wprost:
„Najpierw pomiar”, a brak częstej potrzeby oznacza odłożenie pomysłu. Ten
dokument i narzędzia w repozytorium dają pierwszą połowę pomiaru (koszt
i powodzenie na danych syntetycznych) oraz dokładną instrukcję na drugą połowę
(rzeczywista potrzeba, którą może zmierzyć tylko właściciel na produkcji).
Kod wyszukiwarki w panelu **nie został dodany** — to zależy od wyniku poniżej.

Data: 29 września 2026. Baza odniesienia: gałąź `claude/paczka-c` (`102ae5a95`).

## Co jest w repozytorium

| Plik | Po co |
|---|---|
| `scripts/pomiar-841.php` | Zakłada N syntetycznych wiadomości w lokalnej bazie i mierzy trzy zadania z issue: obecną drogę (przewijanie po 25, najstarsze na górze) kontra wąski wariant „Znajdź wiadomość”. Liczy zapytania na żądanie (przez `paginate()` i eager load jak w kontrolerze), czas, powodzenie, `EXPLAIN (ANALYZE, BUFFERS)` i sprawdza ucieczkę `%` i `_`. |
| `scripts/pomiar-841-powroty.sql` | Zapytanie dla właściciela: ile razy ktoś wrócił do sprawy już załatwionej. **Same agregaty**, transakcja tylko do odczytu, żadnej treści, adresów ani identyfikatorów. |
| `tests/Feature/SkryptPomiaru841OdmawiaPozaBazaPomiarowaTest.php` | Skrypt czyści własne tabele przed założeniem danych, więc test pilnuje bezpiecznika: odmowa (kod wyjścia 1) poza bazą `kuking_pomiar_841*`. |

Skrypt nie profiluje aplikacji, niczego w niej nie loguje i nie zapisuje fraz —
w wyniku są tylko liczby i identyfikatory syntetycznych wierszy. Odmawia pracy
na produkcji, na hoście innym niż lokalny i na bazie o innej nazwie.

Uruchomienie:

```bash
createdb kuking_pomiar_841
DB_DATABASE=kuking_pomiar_841 php artisan migrate --force
DB_DATABASE=kuking_pomiar_841 php scripts/pomiar-841.php 100 7 > wynik-841.json
```

Argumenty: liczba wiadomości (domyślnie 100) i liczba powtórzeń (domyślnie 7).
Dane są deterministyczne (ziarno 841): 12 kont, 40% wiadomości od gości z adresem,
stany nowa / w trakcie / załatwiona, cele wyszukiwania rozłożone równomiernie
w kolejce i jedna wiadomość z dosłownymi `100%` i `plik_zdjecia.jpg`.

## Wyniki lokalne (PostgreSQL 18.6)

Środowisko sesji: 4 rdzenie dzielone z innymi agentami, więc **czasy w
milisekundach szumią** (to samo zapytanie na 100 wiadomościach mierzyło 5–18 ms
w kolejnych przebiegach). Wnioskuj z rzędów wielkości, planów i powodzenia,
nie z ułamków milisekundy. To pomiar lokalny na danych syntetycznych, nie ogląd
produkcji.

### Obecna droga: przewijanie listy

Ile stron po 25 (najstarsze na górze, zakładka „Wszystkie”, czyli gdy nie
pamiętasz stanu) trzeba obejrzeć, by dojść do wiadomości. Rozkład celów:
równomiernie w kolejce.

| Wiadomości | Stron do celu (średnio) | Stron do celu (najwyżej) | Zapytań na stronę |
|---:|---:|---:|---:|
| 100 | 2,6 | 4 | 6 |
| 1 000 | 21 | 37 | 6 |
| 10 000 | 201 | 361 | 6 |

Przy 100 wiadomościach cel jest w ciągu 4 stron; przy zakładce właściwego stanu
w tych danych zawsze na pierwszej. Retencja (12 miesięcy od załatwienia)
ogranicza liczbę wierszy, ale to, ile ich naprawdę jest, wie tylko produkcja.

### Trzy zadania z issue — wąski wariant

Powodzenie = właściwa wiadomość jest na pierwszej stronie wyniku. Czas
= mediana z 7 przebiegów całego żądania (count + select + eager load).
`EXPLAIN` = czas wykonania głównego zapytania.

**A. Fragment treści** (5 celów, każdy raz z ogonkami, raz bez):

| Wariant | Fraza | 100 wiad. | 1 000 | 10 000 | Powodzenie |
|---|---|---:|---:|---:|---|
| `message ILIKE '%f%'` | z ogonkami | 5,8 ms | 5,3 ms | 61 ms | 5/5 |
| `message ILIKE '%f%'` | bez ogonków | 0,6 ms | 1,6 ms | 38 ms | **0/5** |
| `kuking_normalize(message) LIKE` | z ogonkami | 4,6 ms | 9,9 ms | 177 ms | 5/5 |
| `kuking_normalize(message) LIKE` | bez ogonków | 5,8 ms | 9,7 ms | 237 ms | 5/5 |

Wszystkie warianty to skan sekwencyjny jednej tabeli (bez indeksu). Wykonanie
głównego zapytania: 0,1–0,6 ms przy 100, 1–4 ms przy 1 000 i 25–115 ms przy
10 000. Wniosek: człowiek wpisuje „zoltko” szukając „żółtko”, więc wariant
wrażliwy na ogonki gubi wszystko — tak samo jak w wyszukiwarce przepisów
(patrz `App\Support\FrazaWyszukiwania`); wariant znormalizowany jest 2–4 razy
wolniejszy, ale przy realnym rzędzie wielkości nadal niezauważalny.

**B. Wcześniejsza wiadomość konta:**

| Wariant | 100 | 1 000 | 10 000 | Znalezione |
|---|---:|---:|---:|---|
| po adresie konta (dokładnie, `lower(email) =`) | 6,5 ms | 6,1 ms | 28 ms | wszystkie |
| po nazwie profilu (fragment, `ILIKE`) | 5,0 ms | 5,6 ms | 36 ms | wszystkie |

**C. Wiadomość gościa po adresie:**

| Wariant | 100 | 1 000 | 10 000 | Znalezione |
|---|---:|---:|---:|---|
| dokładny adres (`lower(contact_email) =`) | 2,7 ms | 3,3 ms | 26 ms | 1/1 |
| fragment adresu (`ILIKE`) | 4,4 ms | 5,7 ms | 36 ms | 1/1 |

Konto po adresie wymaga złączenia z `users` (adres zalogowanego jest na koncie,
nie w `contact_messages` — patrz `ContactMessage::adresDoOdpowiedzi()`).

**Metaznaki `%` i `_`** (kryterium: nie dopasowywać po cichu wildcardów):
fraza „%” bez ucieczki dopasowuje **wszystkie** wiadomości (100, 1 000
i 10 000, czyli tyle, ile w bazie). Z `FrazaWyszukiwania::doLike()` dopasowuje
dokładnie jedną (tę z literalnym „100%”); fraza „_” również dokładnie jedną. Ta sama funkcja już chroni
wyszukiwarkę przepisów (#753).

### Wniosek techniczny (ostrożny)

Koszt SQL **nie jest** powodem, by tego nie robić: na 100 wiadomościach każdy
wariant to ułamki milisekundy, a na 10 000 (rząd wielkości ponad to, co da się
przechować przy 12-miesięcznej retencji jednoosobowej obsługi) dziesiątki
milisekund bez żadnego indeksu. Indeks nie jest potrzebny, migracja też nie.
Jeżeli feature powstanie, to jako zwykłe zapytanie na `contact_messages`, bez
nowego silnika, embeddingów ani AI. **To nie odpowiada na pytanie, czy jest
potrzebny** — o tym decydują dwa kroki właściciela niżej.

## Krok właściciela 1 — rzeczywista liczba powrotów (produkcja, odczyt)

Wymaga produkcji, więc nie został wykonany. Zapytanie
`scripts/pomiar-841-powroty.sql` liczy, ile razy ta sama osoba (konto albo ten
sam adres gościa) napisała ponownie po tym, jak jej wcześniejsza wiadomość
została załatwiona, z podziałem na odstęp (do 7 dni, 8–30, 31–90, ponad 90).

```bash
psql "$DATABASE_URL" -X -f scripts/pomiar-841-powroty.sql
```

- Zapytanie działa w transakcji `READ ONLY` i kończy `ROLLBACK`. Nic nie zapisuje.
- Wynik to jeden wiersz liczb. **Do raportu wklej tylko ten wiersz.** Nie
  kopiuj treści wiadomości, adresów ani identyfikatorów kont.
- To jest **dolne oszacowanie**: konto i gość z tym samym adresem liczą się
  osobno, konto wymazane (`user_id` = NULL) jest niewidoczne, a załatwione
  wiadomości znikają po 12 miesiącach. Nie wydłużaj retencji na potrzeby pomiaru
  (zakaz z issue).
- Ten pomiar mówi, ile było powrotów, nie ile z nich obsługa zaczęła od
  szukania starej sprawy. To drugie da tylko krótka notatka obsługi z kilku
  tygodni („dziś szukałam starej wiadomości: tak/nie”) — bez treści.

**Proponowana reguła decyzji** (do zatwierdzenia przez właściciela, nie
ustalona w issue): jeśli `powrotow_razem` z ostatnich 90 dni to pojedyncze
sztuki miesięcznie, pomysł zostaje odłożony (`P3`, zamknięcie z opisem
„brak częstej potrzeby”). Jeśli powroty są co tydzień, przejść do kroku 2.

## Krok właściciela 2 — czas człowieka (lokalnie, na danych syntetycznych)

Czasu człowieka przy czytaniu listy skrypt nie zmierzy. Na lokalnej instancji
z bazą `kuking_pomiar_841` (dane z `pomiar-841.php 100`), jako moderator
`operator@pomiar841.example.test`, obsługa robi ze stoperem trzy zadania
z issue na obecnym ekranie `/admin/wiadomosci` (bez wyszukiwania): znaleźć
wiadomość po fragmencie („żółtko”, „łódeczka”, „źródełko”), wcześniejszą
wiadomość konta `konto1@pomiar841.example.test`, wiadomość gościa
`gosc8@pomiar841.example.test`. Zapisać czas i czy się udało (w tabeli
poniżej). To odpowiada na pytanie „czy przy 100 wiadomościach przewijanie
w ogóle boli”; z powyższych liczb wynika, że to najwyżej 4 strony.

| Zadanie | Osoba | Czas | Udało się? | Uwagi (bez treści prywatnej) |
|---|---|---|---|---|
| A. po fragmencie | | | | |
| B. po koncie | | | | |
| C. po adresie gościa | | | | |

## Jeśli potrzeba się potwierdzi — zakres wąskiego wariantu

Nie budować bez decyzji właściciela po kroku 1. Zakres z issue, uzupełniony
o to, co ustalił pomiar:

1. **Jedno pole „Znajdź wiadomość”** w `pages/admin/wiadomosci.blade.php`,
   z jasnym opisem zakresu, zachowaniem wybranego stanu (zakładki), przyciskiem
   „Szukaj we wszystkich stanach” i „Wyczyść”. Zwykła kolejka bez frazy zostaje
   najstarsza-na-górze.
2. **Zapytanie**: `kuking_normalize(message) LIKE` z `FrazaWyszukiwania::doLike()`
   (ogonki i metaznaki — patrz wyżej), adres gościa dokładny po `lower()`;
   konto po `users.email`. Rozstrzygnąć: fragment czy dokładny adres.
3. **Autoryzacja** bez zmian: `ContactMessagePolicy::viewAny` dla panelu.
   Wyniki nie mogą pokazywać treści usuniętych ani wymazanych danych (konto
   wymazane nie ma adresu — `adresDoOdpowiedzi()`).
4. **Walidacja i limit**: fraza `string`, `max:` np. 120 (jak `SearchQuery::MAX_PHRASE_LENGTH`),
   nieprawidłowy typ (`?q[]=`) daje komunikat po polsku, nie 500; paginacja
   `withQueryString()` zachowuje frazę i stan, zmiana frazy zeruje stronę.
5. **Prywatność frazy — decyzja przed wyborem GET.** Fraza może zawierać e-mail
   i prywatną treść. W kodzie aplikacji nie znalazłem logowania pełnego URL ani
   analityki w middleware (odczyt `app/Http/Middleware`, `app/Providers`,
   `config`); to **odczyt kodu, nie przegląd logów**. Adres z parametrem
   zostaje jednak w logach HTTP platformy i przeglądarki. Krok właściciela:
   sprawdzić w Railway i Cloudflare, czy dostępowe logi HTTP przechowują
   pełny URL z query string i jak długo; jeśli tak, rozważyć wyszukiwanie
   przez POST z przekierowaniem do adresu bez frazy albo skrócić retencję tych logów.
   Nie dodawać rejestracji surowych zapytań.
6. **Test regresyjny z fizyczną kontrolą ujemną**: dopasowanie treści,
   konta i gościa, brak wyników, polskie znaki, długi tekst, nieprawidłowy typ,
   paginacja z filtrem, dosłowne `%` i `_`, brak wycieku wymazanych danych.
   Zmiana widoczna dla obsługi: wpis w `CHANGELOG.md` i akapit w
   `resources/nowosci/tresc.md`.

Nie dodawać: CRM, numerów spraw, importu skrzynki (D-045/D-058), osobnego
silnika wyszukiwania, embeddingów ani AI.

## Czego ten pomiar nie sprawdza

- Realnego wolumenu i rzeczywistej częstości powrotów (krok 1).
- Czasu człowieka przy przewijaniu i czytaniu (krok 2).
- Zachowania w przeglądarce, dostępności ekranu z nowym polem (nie istnieje).
- Rozmowy z obsługą.
- Czasów na PostgreSQL 18 na Railway — tu lokalny 18.6 na współdzielonym CPU.
