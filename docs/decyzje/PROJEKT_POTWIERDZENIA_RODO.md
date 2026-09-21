# Projekt: minimalne potwierdzenie obsługi żądania usunięcia konta

**Stan: PROJEKT + SCHEMAT. Nic nie zapisuje, nic nie kasuje, nic nie przenosi.**
Ta gałąź zakłada tabelę `potwierdzenia_zadan_rodo` z ograniczeniami i testami.
Ścieżka zapisu, backfill istniejących wpisów `account.*`, skasowanie ich pełnych
kopii i automat retencji to **osobne kroki, które uruchamia właściciel** — lista
na końcu tego dokumentu.

Źródło wymagania: `docs/decyzje/OCENA_RETENCJI_ZEWNETRZNA.md` §C i §D,
`docs/decyzje/ADR_RETENCJE.md` §3.1, §5.1 i §10.

---

## 1. Czego ocena NIE prosi — i dlaczego to trzeba napisać osobno

Gałąź `flota/retencja-wyjatkow-audytu` (`b04e168c`) została wstrzymana, bo
zrobiła coś odwrotnego: dołożyła do `config/kuking.php` klucz sterujący listą
`AuditLogEntry::NIGDY_NIE_KASUJ`. `config/kuking.php` tłumaczy w tym samym pliku,
dlaczego tej listy tam nie ma: *„Trzymanie jej w configu […] otwierałoby drogę do
wykasowania dowodu wykonania RODO/DSA jedną zmianą wdrożeniową bez recenzji
kodu"*.

Ocena **nie prosi o skrócenie retencji tych wpisów.** Prosi o ich **zastąpienie**
czymś innym. To są dwie różne rzeczy i różnica jest całą treścią zadania:

| Co to znaczy | Skutek |
|---|---|
| Skrócić retencję wyjątków | Po X miesiącach dowód wykonania art. 17 **znika bez następcy** |
| Zastąpić wyjątki potwierdzeniem | Dowód **zostaje**, w formie bez `actor_id`, `ip_hash` i `metadata`, i dopiero **on** ma 36 miesięcy |

Dlatego w tej gałęzi **lista `NIGDY_NIE_KASUJ` jest nietknięta**, a test
`MinimalnePotwierdzenieRodoTest::test_lista_nigdy_nie_kasuj_jest_nienaruszona`
pilnuje jednocześnie jej treści i tego, że w configu nie pojawił się klucz nią
sterujący. Lista zniknie dopiero wtedy, gdy potwierdzenia naprawdę będą stać
w bazie — krok 6 listy na końcu.

## 2. Co ma zawierać potwierdzenie, a czego zawierać NIE MOŻE

§C wylicza zawartość wprost — po lewej cytat, po prawej realizacja:

| Ocena (§C) | Kolumna |
|---|---|
| losowy numer sprawy | `numer` |
| daty otrzymania i zakończenia | `otrzymano`, `zakonczono` |
| rodzaj żądania | `rodzaj` |
| wynik | `wynik` |
| zakres wykonania | `zakres` |
| wersja procedury | `wersja_procedury` |
| informacja o wyjątkach | `wyjatki` |
| minimalny sposób powiązania z wnioskodawcą | `numer` + warunkowo `konto_id` — §3 |

I wylicza zakaz: *„Nie kopiuj starego profilu, hasha hasła, fotografii ani całej
korespondencji"*. Do tej listy dokładam **`metadata jsonb`**, którego ocena nie
wymienia, bo nie zna schematu: to jest ta jedna kolumna, przez którą wszystkie
zakazane rzeczy wracają **bez migracji i bez recenzji schematu**. Dzisiejszy
`audit_log` ją ma. Nowa tabela nie ma i nie dostanie.

Zakazu pilnuje `test_tabela_nie_trzyma_zadnych_danych_z_profilu` — skanuje
`information_schema` po nazwach kolumn, z kontrolą dodatnią przed skanem
(skan, który nie widzi żadnej kolumny, przechodzi).

**Pełne uzasadnienie każdej kolumny z osobna** stoi w komentarzu migracji
`2026_09_21_100000_utworz_potwierdzenia_zadan_rodo.php` i w `docs/DATABASE.md`.
Nie powtarzam go tutaj, żeby dwie kopie się nie rozjechały.

## 3. Najtrudniejsza rzecz: powiązanie z wnioskodawcą

Ocena stawia trzy pytania i jedno ostrzeżenie: *„Ograniczony identyfikator,
również hasz lub HMAC e-maila, nie jest magiczną anonimizacją; oceń możliwość
odgadnięcia, ochronę klucza, dostęp oraz konieczność powiązania"*.

### 3.1 Cztery warianty i dlaczego trzy odpadają

**A. `konto_id` zawsze — ODRZUCONE.** `users` po wykonaniu żądania jest
**anonimizowany, nie kasowany** (`EraseAccountData`), a przy zakresie `minimum`
treści tej osoby zostają w serwisie widoczne pod tym samym `user_id` (D-022).
Stały `konto_id` związałby więc dowód usunięcia danych osobowych z całym
dorobkiem, który po koncie został — czyli dokładnie odtworzyłby to, co usunięcie
miało rozerwać.

**B. HMAC adresu e-mail — ODRZUCONE, i to jest odpowiedź na trzy pytania oceny.**

- *możliwość odgadnięcia*: przestrzeń adresów nie jest losowa. Kto ma klucz
  i podejrzewa konkretny adres, policzy jeden skrót i sprawdzi, czy stoi
  w tabeli. To czyni z rejestru **wyrocznię** „czy ta osoba usunęła konto",
  a więc ujawnia fakt, którego adres w bazie już nie ma;
- *ochrona klucza*: jedyny sekret, który mamy pod ręką, to `APP_KEY` —
  ten sam, którym `App\Support\Skrot::hmac` liczy `audit_log.ip_hash`.
  Komentarz tej klasy sam mówi, czego nie obiecuje: ochrona polega wyłącznie
  na tym, że klucz żyje w zmiennej środowiskowej. Osobny sekret tylko dla tego
  rejestru byłby lepszy, ale nie usuwa punktu pierwszego — **zmienia koszt
  ataku z zera na jeden wyciek zmiennej środowiskowej**;
- *konieczność powiązania*: nie jest konieczne. §C mówi: *„Najprostszy projekt
  może opierać się na numerze sprawy przekazanym również osobie i ograniczonej
  ewidencji; nie musi odtwarzać usuniętego konta"*.

**C. Brak jakiegokolwiek powiązania — ODRZUCONE.** Potwierdzenie, którego nie da
się przypisać do niczyjego żądania, nie jest dowodem dla osoby, tylko
statystyką. Człowiek, który pyta „czy MOJE konto zostało usunięte i w jakim
zakresie", nie dostałby odpowiedzi — a to jest główny adresat tego rejestru.

### 3.2 Wariant przyjęty: numer u człowieka, wskaźnik tylko póki konto żyje

1. **Podstawą jest `numer`** — losowy `RODO-XXXX-XXXX-XXXX` z alfabetu bez
   znaków mylących, 12 znaków ≈ 59 bitów. Człowiek dostaje go **w chwili
   przyjęcia żądania**, czyli zanim jego dane znikną. Baza nie trzyma niczego,
   z czego dałoby się ten numer odtworzyć — powiązanie istnieje **poza bazą**,
   na kartce i w skrzynce wnioskodawcy.
2. **`konto_id` istnieje tylko wtedy, gdy konto istnieje w pełni** — przez
   30 dni karencji (`w_toku`) i po cofnięciu żądania (`cofniete`). W obu tych
   stanach konto ma adres, hasło i profil, więc wskaźnik nie ujawnia niczego,
   czego baza już nie wie, a jest **potrzebny**: to jest ten spór, który
   ADR §3.1 nazywa wprost („nigdy nie prosiłem o usunięcie konta"), a
   `User::cancelDeletion()` zeruje `delete_requested_at`, więc poza tym
   rejestrem nie zostaje po nim ślad.
3. **Z chwilą `wynik = 'wykonane'` powiązanie musi zniknąć** — i to nie jest
   obietnica w komentarzu, tylko CHECK
   `potwierdzenia_zadan_rodo_wykonane_bez_konta_check`. Reguła w PHP dałaby się
   obejść drugim miejscem zapisu; CHECK nie.

Domknięcie sprawy (wpisanie `wynik`, `zakonczono` i wyzerowanie `konto_id`)
**musi iść jedną instrukcją `UPDATE` w tej samej transakcji co wymazanie
danych** — inaczej istnieje okno, w którym wiersz mówi „wykonane", a wskaźnik
jeszcze stoi. CHECK to okno zamyka (odrzuci taki zapis), ale kod ma tego nie
próbować.

### 3.3 Słabości tego wariantu — nazwane, nie przemilczane

1. **Numer żyje poza naszą kontrolą.** Trafia do skrzynki wnioskodawcy i do
   logów dostawcy poczty. Usunięcie potwierdzenia po 36 miesiącach nie usuwa go
   stamtąd. To jest cena tego, że powiązanie jest u człowieka, a nie u nas —
   i jest to cena mniejsza niż trzymanie identyfikatora po naszej stronie.
2. **Kto zgubi numer, traci dostęp do własnego potwierdzenia** po wykonaniu
   usunięcia. Nie umiemy go odtworzyć i to jest celowe. Konsekwencja bywa
   dotkliwa i trzeba ją napisać w treści, którą człowiek dostaje: *ten numer
   jest jedynym, co po tej sprawie zostanie — zachowaj go*. W grupie 50+ to
   znaczy: numer ma być na ekranie i w liście, w postaci nadającej się do
   przepisania na kartkę, a nie w odnośniku.
3. **Nie odpowiemy na pytanie regulatora o KONKRETNĄ osobę bez jej numeru.**
   Umiemy pokazać, że żądania obsługujemy i w jakich terminach; nie umiemy
   powiedzieć „ta oto pani Kowalska złożyła żądanie 3 marca". Uważam to za
   właściwą stronę kompromisu — zdolność do odpowiedzenia na takie pytanie
   **jest** tym zbiorem danych, którego ocena każe nie trzymać — ale to jest
   decyzja właściciela, nie moja, i ma zostać podjęta świadomie.
4. **Sam numer nie jest upoważnieniem.** 59 bitów to dużo, ale rejestr nie ma
   i nie może dostać ekranu „wpisz numer, zobacz sprawę" — wtedy numer stałby
   się hasłem do cudzej sprawy i wymagałby drugiego składnika. Dziś numer podaje
   się w korespondencji obsługiwanej przez człowieka.
5. **Korelacja po dacie zostaje.** Nawet bez identyfikatora da się zestawić
   `zakonczono` z chwilą, w której czyjeś wpisy zmieniły autora na „konto
   usunięte". Dlatego daty są typu `date`, a nie `timestamptz` — doba zamiast
   sekundy. To **osłabia** korelację, nie usuwa jej. Przy małym serwisie, gdzie
   danego dnia konto usuwa jedna osoba, powiązanie nadal jest odtwarzalne przez
   kogoś, kto ma dostęp do bazy. Uczciwa nazwa tego stanu to
   **pseudonimizacja** (motyw 26 RODO), nie anonimizacja — i dlatego wiersz
   **nadal podlega retencji 36 miesięcy**, zamiast zostać „na zawsze jako dane
   anonimowe".
6. **`cofniete` z `konto_id` to nadal dane osobowe** żywego konta, tyle że
   uzasadnione. Po 36 miesiącach znikają razem z wierszem — retencja nie robi tu
   wyjątku.

## 4. Plan wycofania (AGENTS.md §6, punkt 4)

**Wycofanie samej migracji, dziś:** bezpieczne i bezwarunkowe. Tabela jest pusta,
nic do niej nie pisze, nic z niej nie czyta. `migrate:rollback` kasuje ją razem
z CHECK-ami i indeksami.

**Wycofanie po uruchomieniu zapisu:** `down()` **odmawia**, gdy w tabeli stoi
choć jeden wiersz z wypełnionym `zakonczono` — komunikat mówi, co zrobić.
Odmowa jest wąska (AGENTS.md §6, D-088): pusta tabela i tabela z samymi
sprawami w toku cofają się bez pytania, bo sprawa w toku żyje nadal
w `users.delete_requested_at`. Testy: `test_cofniecie_migracji_odmawia_gdy_stoi_zamknieta_sprawa`
plus dwie kontrole dodatnie.

**Kolejność przy awaryjnym wycofaniu wdrożenia: NAJPIERW KOD, POTEM MIGRACJA.**
Kod piszący do nieistniejącej tabeli wywróci obsługę żądania; migracja zdjęta
spod działającego kodu wywróci ją tak samo, tylko po cichu.

**Czego rollback NIE odwraca i trzeba to wiedzieć przed krokiem 6:** po
skasowaniu pełnych wpisów `audit_log` (krok 6) wycofanie tej migracji nie
przywróci ani wpisów, ani potwierdzeń. Od kroku 6 jedyną drogą powrotu jest
kopia zapasowa bazy — i to jest powód, dla którego kroki 5 i 6 są rozdzielone
i oba należą do właściciela.

## 5. Co musi wykonać właściciel — w tej kolejności

Każdy krok jest osobny, bo każdy ma inny moment, w którym może się okazać zły.

1. **Zatwierdzić albo odrzucić kompromis z §3.3 punkt 3** — czy godzimy się nie
   umieć odpowiedzieć na pytanie o konkretną osobę bez jej numeru. To jest
   jedyna decyzja w tym dokumencie, której nie wolno domknąć asercją w teście.
2. **Zatwierdzić zawartość i brzmienie pisma**, w którym człowiek dostaje numer
   sprawy — treść, moment wysyłki (przy PRZYJĘCIU żądania, nie przy wykonaniu)
   i sposób pokazania numeru na ekranie. Bez tego numer nie dociera do nikogo
   i cały wariant z §3.2 przestaje działać.
3. **Zlecić ścieżkę zapisu** (osobne zlecenie): wiersz `w_toku` przy zgłoszeniu
   z `/ustawienia/twoje-dane`, domknięcie w tej samej transakcji co
   `EraseAccountData` i co `CancelAccountDeletion`, oraz model z testami. Dopóki
   tego nie ma, tabela jest pusta i nic się nie dzieje.
4. **Zlecić automat retencji** — 36 miesięcy od `zakonczono`, z pominięciem
   `wstrzymanie_do`, na wzór `PrzedawnioneWpisyAudytu` (uwaga:
   `subMonthsNoOverflow`, nie `subMonths` — A6-04). Osobny klucz configu
   `kuking.potwierdzenia_rodo.retention_months`. **To jest jedyny klucz configu,
   jaki ta sprawa dostaje** — listy `NIGDY_NIE_KASUJ` w configu nie było i nie
   będzie.
5. **Uruchomić backfill** istniejących wpisów `account.*` do potwierdzeń
   (osobna, jednorazowa komenda z `--dry-run`, do zaprojektowania w tym samym
   zleceniu co krok 3). Backfill **niczego nie kasuje** — dokłada wiersze obok.
   Odwzorowanie: jedno potwierdzenie na jedno żądanie, nie na jeden wpis;
   `delete_requested` + `delete_cancelled` → jeden wiersz `cofniete`;
   `delete_requested` + `data_erased` → jeden wiersz `wykonane` z `zakres`
   z `metadata.zakres`; `wersja_procedury` = wartość obowiązująca w dniu
   zdarzenia; `konto_id` **tylko** dla `cofniete`. Numer sprawy jest losowany
   teraz, więc **wnioskodawcy historyczni go nie mają** — to trzeba świadomie
   przyjąć albo wysłać im numer osobno (decyzja właściciela).
   **Po backfillu porównać liczby**: liczba potwierdzeń musi zgadzać się
   z liczbą żądań wyliczoną z `audit_log`, i to sprawdzenie jest warunkiem
   wejścia w krok 6.
6. **Dopiero potem** skasować pełne wpisy `account.*` z `audit_log` i zdjąć trzy
   pozycje z `AuditLogEntry::NIGDY_NIE_KASUJ` (zmianą kodu, przez recenzję).
   To jest **jedyny destrukcyjny krok** w całej sprawie i jedyny nieodwracalny
   inaczej niż z kopii zapasowej — patrz §4.
7. **Zaktualizować politykę prywatności i `docs/legal/COMPLIANCE.md`** — ocena
   §E wymaga osobnego wiersza dla potwierdzeń z okresem 36 miesięcy i zakazuje
   chowania wyjątku bezterminowego pod sformułowaniem „według innych zasad".
   Dopiero po kroku 6, bo wcześniej polityka obiecywałaby stan, którego nie ma.
8. **Rozstrzygnąć osobno rejestr blokujący wskrzeszenie po odtworzeniu kopii**
   (§C, ostatni akapit). To NIE jest ta tabela i nie jest częścią tego projektu
   — ocena wprost mówi, że taki rejestr ma trwać tyle, co kopie, „nie
   bezterminowo z definicji". Zostaje otwarte.
