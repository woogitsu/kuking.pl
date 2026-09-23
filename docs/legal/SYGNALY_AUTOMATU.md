# Sygnały automatu — wykrywacz, który podnosi rękę

> Decyzje architektoniczne: **D-052** (sygnały lokalne) i **D-055** (ocena
> modelem OpenAI) — `docs/DECISIONS.md`.
> Kod: `app/Domain/Moderation/Sygnaly/`, `app/Moderacja/`,
> `app/Jobs/PrzeanalizujTresc.php`,
> `app/Http/Controllers/Admin/SygnalyController.php`.
> Progi: `config/kuking.php` → `moderation.sygnaly` i `moderation.model`.
> Wyłączniki: `KUKING_SYGNALY_AUTOMATU=false` (całość),
> pusty `OPENAI_MODERATION_KEY` (sama ocena modelem).

## 1. Po co to jest i czym NIE jest

Automat czyta świeżo opublikowane wpisy i komentarze, szuka kilku wzorców
i — gdy coś znajdzie — **stawia jedną pozycję w kolejce moderatora**.
Na tym kończy się wszystko, co robi.

Czego nie robi i nie będzie robić:

| Nie robi | Dlaczego |
|---|---|
| Nie ukrywa i nie usuwa treści | poz. 3.10: automat nigdy nie decyduje sam |
| Nie wycisza konta po N sygnałach | poz. 3.14 (**REJECT**) |
| Nie ogranicza po cichu zasięgu | poz. 3.16 (**REJECT**) — sprzeczne z art. 17 DSA |
| Nie powiadamia autora o oznaczeniu | Nie ma o czym: z treścią nic się nie stało |
| Nie liczy „punktów zaufania" konta | poz. 3.13 (**LATER/REJECT**) |
| Nie zagląda do treści prywatnych | Wpis `private` w ogóle nie wchodzi do analizy — także nie wychodzi do OpenAI |
| Nie wysyła nic identyfikującego autora poza serwis | Do modelu idzie sama treść: bez adresu e-mail, nazwy konta, identyfikatora i adresu IP |

Pozycje odwołania (3.x) pochodzą z `docs/INSPIRATION_DECISIONS.md` §3.
Zasada nadrzędna jest w `AGENTS.md` §9: „moderacja pomocnicza (flagowanie,
nigdy samodzielny ban)".

**Test pilnuje tego wprost.** `SygnalyAutomatuTest::test_oznaczona_tresc_jest_dalej_widoczna_dla_autora_i_dla_obcego`
oraz `test_autor_nie_dostaje_zadnego_powiadomienia_o_oznaczeniu` — bo obietnica
„autor niczego nie zauważy" jest tu całą treścią decyzji, a nie komentarzem
przy niej.

## 2. Kogo ten wykrywacz ma NIE złapać

To jest ważniejsze niż lista sygnałów i dlatego stoi przed nią.

Do Kuking przechodzi **grupa osób z Garnek.pl** — setki teraz, tysiące
w perspektywie. Przechodzą **razem**, i to jest dokładnie ten kształt, który
naiwny wykrywacz spamu uznaje za atak:

- rejestracje w tym samym tygodniu, część z tego samego łącza (koło gospodyń,
  biblioteka, dom seniora, jedna Wi-Fi w bloku);
- hurtowe przenoszenie archiwum: dziesiątki przepisów w godzinę;
- treść **wklejana**, nie pisana — czyli czas wypełnienia formularza bliski zeru;
- **te same przepisy u kilku osób**, bo krążyły w tej grupie latami;
- odnośniki do starego profilu w Garnku w pierwszym wpisie.

Każdy „oczywisty" sygnał spamowy trafia w te osoby celnie i w ich pierwszym
dniu. Oznaczenie nie ma dla nich konsekwencji — ale kolejka złożona w 90%
z fałszywych alarmów przestaje być czytana, a wtedy nie działa dla nikogo.

**Wniosek, który wygląda odwrotnie do intuicji: wzrost skali NIE odblokował
sygnałów „na dużą skalę".** Odblokował dane, ale ruch, który te dane wnosi,
jest ruchem, na którym te sygnały się mylą. Odblokował za to pracę nad
**kolejką** — grupowaniem, kolejnością, zamykaniem hurtem — i to jest ta
część, którą skala naprawdę zmieniła.

## 3. Sygnały wdrożone DZIŚ

Sygnałów jest **cztery**, w kolejności pilności (`Report::WAGA`):

| sygnał | waga | opisany w |
|---|---:|---|
| `automat_model` | **4** | §8 — ocena modelem OpenAI |
| `automat_wzorzec` | 3 | §3.1 |
| `automat_odnosnik` | 2 | §3.2 |
| `automat_powtorzenie` | 1 | §3.3 |

**`automat_model` stoi najwyżej i dotyczy INNEJ klasy treści** niż pozostałe
trzy: nienawiści, przemocy, treści seksualnych i samookaleczenia (D-055).
Do 20 września ta sekcja wymieniała tylko trzy sygnały z wagami 3–2–1 —
moderator czytający listę nie wiedział, że istnieje cięższy. Pełny opis tej
drogi jest w §8; tutaj stoi, bo **tu się patrzy, żeby wiedzieć, co automat
potrafi podnieść**. Zgodności listy z kodem pilnuje
`DokumentyPrawneNieKlamiaTest::test_procedura_wymienia_kazdy_sygnal_automatu`.

Trzy sygnały opisane niżej w §3.1–3.3 opisują zachowanie **jednego konta
wobec jego własnej treści**. Żaden z nich nie porównuje kont między sobą.

### 3.1. Znany wzorzec ogłoszenia (`automat_wzorzec`, waga 3)

Numer telefonu podany do kontaktu, „zarabiaj z domu", kryptowaluty, pożyczki,
kasyno, odnośnik `t.me/` albo `wa.me/`.

- **Dlaczego tutaj ma sens:** to jest 90% realnego spamu w serwisie
  kulinarnym i jedyny sygnał, który nie potrzebuje żadnych danych o koncie —
  działa tak samo przy dwudziestu, jak przy dwudziestu tysiącach osób.
- **Fałszywy alarm:** koło gospodyń podaje numer do zamówień na ciasta.
  Ktoś pisze „zadzwoń do mnie, 600 100 200" pod przepisem koleżanki.
  Jedna pozycja w kolejce, moderator zamyka ją w trzy sekundy.
- **Czego pilnujemy przy progu:** dziewięć cyfr **samo w sobie nie wystarcza**
  — „Piecz w 180, potem 200, na koniec 220" ma dokładnie kształt numeru
  telefonu. Sygnał zapala się, gdy numer ma kierunkowy (`+48`) albo gdy obok
  cyfr stoi słowo kontaktowe („tel", „zadzwoń", „WhatsApp"). Bez tego warunku
  automat oznaczałby przepisy na chleb.
- **Czego świadomie NIE ma na liście wzorców:** „okazja", „promocja",
  „sprzedam". W serwisie kulinarnym ogłoszenie o nadmiarze śliwek z działki
  i spam wyglądają wtedy identycznie.

### 3.2. Odnośnik zewnętrzny u świeżego konta (`automat_odnosnik`, waga 2)

Adres `https://…` albo `www.…` do obcej domeny, w treści konta **młodszego
niż 7 dni** i **w pierwszych 3 treściach** tego konta.

- **Dlaczego tutaj ma sens:** konto założone po to, żeby wkleić adres, to
  najczęstszy kształt spamu na serwisach społecznościowych. Dwa warunki naraz
  są konieczne: samo „nowe konto" oznaczyłoby całą falę migracyjną, sam
  „odnośnik" — każdego, kto uczciwie podaje źródło przepisu.
- **Fałszywy alarm:** osoba prowadząca blog kulinarny zaprasza do siebie
  w pierwszym wpisie. To jest fałszywy alarm, którego **nie da się odróżnić
  maszynowo** od spamu — i właśnie dlatego pozycja idzie do człowieka,
  a nie do filtra.
- **Wyjątek dla fali migracyjnej:** `moderation.sygnaly.domeny_bez_sygnalu`
  (domyślnie `garnek.pl`, `kuking.pl`). Odnośnik do starego profilu w Garnku
  w pierwszym wpisie jest rzeczą **oczekiwaną**, nie podejrzaną. Lista jest
  krótka i celowo nie zawiera Facebooka ani YouTube'a — tamte adresy trafiają
  się w spamie równie często jak w dobrej wierze.
- **Gołe „cos.pl" nie liczy się jako odnośnik**: w zdaniu „u nas mówi się na
  to «pierogi z blachy.pl»" wygląda identycznie, a nie prowadzi nigdzie.

### 3.3. Powtórzona treść tego samego konta (`automat_powtorzenie`, waga 1)

Ta sama albo prawie ta sama treść (≥ 92% podobieństwa) opublikowana przez
**to samo konto** drugi raz w ciągu 60 minut, przy tekście dłuższym niż
40 znaków.

- **Dlaczego tutaj ma sens:** spamer wysyła jedno ogłoszenie w kilkanaście
  miejsc. To jest kształt, którego uczciwy użytkownik nie ma powodu tworzyć.
- **Fałszywy alarm:** ktoś opublikował wpis, zauważył literówkę, skasował
  i wysłał jeszcze raz. Albo ta sama porada wklejona pod dwoma podobnymi
  pytaniami. Jedna pozycja, jedno kliknięcie.
- **Próg 40 znaków to najważniejsza liczba w tym sygnale.** Bez niego automat
  oznaczałby najżyczliwsze osoby w serwisie: „Wygląda pysznie" pod pięcioma
  wpisami z rzędu to nie spam, to uprzejmość i dokładnie ten ruch, który ma
  ten serwis trzymać przy życiu.
- **Porównujemy WYŁĄCZNIE w obrębie jednego konta.** Ten sam przepis na
  sernik krążył w kole gospodyń trzydzieści lat i teraz wchodzi tu pięcioma
  drogami naraz — patrz §2.

## 4. Sygnały, których dziś NIE wdrażamy

| Sygnał | Znacznik | Dlaczego nie |
|---|---|---|
| **Wiele kont z jednego IP** | **ODŁOŻONE** | To jest opis fali migracyjnej, nie ataku: mąż i żona, biblioteka, dom seniora, CGNAT na wsi. Poza tym w bazie mamy **wyłącznie skrót adresu IP** (`audit_log.ip_hash`, klucz poza bazą) — zbudowanie na nim łączenia kont zamieniłoby dziennik bezpieczeństwa w narzędzie inwigilacji. Wrócić, gdy fala opadnie, i **wyłącznie jako sygnał przy REJESTRACJI**, nigdy przy treści. |
| **Nagła seria wpisów (burst)** | **ODŁOŻONE** | Hurtowe przenoszenie archiwum to seria wpisów. Do wdrożenia dopiero wtedy, gdy „normalne tempo" nowego konta da się policzyć z danych, czyli po ustabilizowaniu się fali. |
| **Ta sama treść u RÓŻNYCH kont** | **ODŁOŻONE** | Przepisy krążące w grupie od lat. Nie umiemy dziś odróżnić pięciu kont założonych po to, żeby wkleić to samo ogłoszenie, od pięciu pań, które mają ten sam przepis na sernik. Wrócić, gdy będzie z czym porównać. |
| **Czas wypełnienia formularza** | **ODŁOŻONE** | Nasz użytkownik wkleja przepis z notatnika albo z maila od siostry — czas pisania jest wtedy zerowy i to jest zachowanie DOCELOWE, nie podejrzane (`docs/research/repos/discourse-discourse.md` §4.1). Po **D-053** wolno mierzyć ten czas skryptem w przeglądarce i nie trzeba dorabiać drogi bez JS; gdyby to kiedyś wchodziło, obowiązuje jedna twarda zasada: **brak danych z przeglądarki (skrypt się nie dociągnął) NIE JEST zachowaniem podejrzanym** — inaczej oznaczymy każdego, komu zabrakło zasięgu. Koszt wdrożenia: ukryte, podpisane pole w czterech formularzach (wpis, komentarz, przepis, „Ugotowałem") plus obsługa formularza przywróconego z cache przeglądarki. Nieproporcjonalny do wartości sygnału, który i tak trzeba by wyłączyć dla wklejających. |
| **Automatyczne wyciszenie po N zgłoszeniach** | **REJECT** | poz. 3.14. Nie wraca. |
| **Ciche ograniczanie zasięgu** | **REJECT** | poz. 3.16, art. 17 DSA. Nie wraca. |
| **Poziomy zaufania (TL0–TL4)** | **REJECT na dziś** | poz. 3.13 — blisko publicznego rankingu użytkowników zakazanego w `AGENTS.md` §12. |
| **Captcha** | **REJECT** | Bariera wejścia dla 50+. Zamiast niej — te sygnały. |
| **Analiza przepisów (`recipes`)** | **ODŁOŻONE** | Tekst przepisu leży w trzech tabelach. Spam ląduje tam, gdzie jest najszybciej: w polu „napisz kilka słów" i pod cudzym zdjęciem. |
| **Ponowna analiza po EDYCJI treści** | **ODŁOŻONE, ze świadomą luką** | Opublikować niewinny wpis i dopisać spam edycją to najprostsze obejście tego wykrywacza. Nie zamykamy go dziś, bo jedno oznaczenie na treść jest fundamentem obietnicy „odrzucone nie wraca", a ponowna analiza po każdej poprawce literówki kosztuje zadanie w kolejce za każdym razem. Ta luka jest zamykana zgłoszeniem od człowieka i **musi wrócić na stół**, gdy pojawi się pierwszy przypadek. |

## 5. Kolejka: jak to ma przeżyć tysiąc kont przy jednym moderatorze

Sama lista pozycji przestaje wystarczać dużo wcześniej, niż się wydaje.

1. **Osobny ekran** — `/admin/sygnaly`. Oznaczenia automatu **nie wchodzą**
   do `/admin/zgloszenia`. Tam czekają ludzie i biegną terminy z DSA art. 16
   ust. 5; maszynowe podejrzenia zasypałyby tamtą listę w tydzień.
   `?zrodlo=automat` na ekranie zgłoszeń pokazuje je z pełnym formularzem
   decyzji — bo formularz z obowiązkami z art. 17 jest w serwisie JEDEN.
2. **Grupowanie po autorze** — dziesięć wpisów jednego konta to JEDNA pozycja
   do rozstrzygnięcia. Umożliwia to kolumna `reports.autor_tresci_id`
   (patrz `docs/DATABASE.md`).
3. **Kolejność = decyzja o tym, czego moderator NIE zdąży przejrzeć.**
   Najpierw najcięższy sygnał w grupie (`Report::WAGA`), potem grupy
   największe, potem najnowsze. Przy tysiącu kont nikt nie dochodzi do końca
   listy i trzeba to założyć wprost.
4. **Zamknięcie grupy jednym kliknięciem** — „To nic takiego" zamyka wszystkie
   otwarte oznaczenia jednego konta. Każde dostaje własny wiersz
   w `moderation_actions`, bo log ma odpowiadać na pytanie „co się stało z tą
   treścią".
5. **Odrzucone nie wraca** — indeks częściowy `reports_jeden_automat_na_tresc`
   dopuszcza **jedno** oznaczenie na treść, na zawsze. Automat nie kłóci się
   z moderatorem w kółko (ten sam wybór zrobił Discourse,
   `docs/research/repos/discourse-discourse.md` §4.5).

### Wydajność

- Analiza idzie w kolejce `low`, nigdy w kontrolerze. Publikacja wpisu nie
  czeka na nią ani milisekundy.
- Porównanie powtórzeń pyta **wyłącznie o treści tego autora z ostatniej
  godziny**, z twardym limitem 20 wierszy, po istniejących indeksach
  `posts_author_published_idx` i `comments_author_idx`. Koszt nie rośnie
  z tabelą, tylko z aktywnością jednej osoby w jednej godzinie.
- `similar_text()` jest kwadratowa, więc porównujemy najwyżej 1500 pierwszych
  znaków i odrzucamy pary różniące się długością bardziej niż próg, zanim ją
  wywołamy.
- Ekran kolejki: jedno zapytanie agregujące po indeksie częściowym
  `reports_automat_autor_idx` (obejmuje tylko pozycje OTWARTE, więc kolejka,
  którą moderator opróżnia, naprawdę tanieje) plus dwa zapytania na adresy
  treści całej strony.

## 6. Jak mierzymy, czy to działa

```bash
php artisan kuking:raport-sygnalow --dni=30
```

Komenda odpowiada na dwa pytania i tylko na te dwa: **ile tego jest** (pozycji
dziennie, w rozbiciu na sygnały) i **ile okazało się niczym** (odsetek decyzji
`no_action`). Odsetek liczy się z pozycji ROZPATRZONYCH — pozycja, której
nikt nie obejrzał, nie jest ani trafieniem, ani pomyłką.

Progi, po których trzeba zareagować:

| Co widać | Co to znaczy | Co zrobić |
|---|---|---|
| Fałszywe alarmy > **70%** dla jednego sygnału | Ten sygnał kosztuje więcej uwagi, niż jest wart | Zaostrzyć próg w `config/kuking.php` albo wyłączyć sygnał |
| Więcej niż **30 pozycji dziennie** łącznie | Więcej, niż jedna osoba przejrzy między innymi obowiązkami | Zawęzić najgłośniejszy sygnał; rozważyć drugiego moderatora |
| Rosnąca liczba pozycji **starszych niż tydzień** | Kolejka rośnie szybciej, niż jest opróżniana | Odsetki wyżej są zaniżone i przestają cokolwiek mówić — najpierw opróżnić kolejkę |
| **Zero** oznaczeń przez tydzień przy rosnącym ruchu | Albo nie ma spamu, albo wykrywacz jest wyłączony | Sprawdzić `KUKING_SYGNALY_AUTOMATU` **zanim** uzna się to za dobrą wiadomość |

## 7. Przy jakiej skali to podejście się kończy

| Do ilu kont wystarczy | Co się psuje potem | Na co zamienić |
|---|---|---|
| **~2 000 aktywnych kont** — porównanie powtórzeń w PHP | Przy bardzo aktywnym koncie limit 20 porównań zaczyna gubić trafienia, a `similar_text()` na długich wpisach zjada workera | Skrót treści liczony przy zapisie (kolumna z hashem znormalizowanego tekstu + indeks) — porównanie robi wtedy baza, nie PHP |
| **~5 000 otwartych oznaczeń** — grupowanie `GROUP BY` na żywo | Agregat po indeksie częściowym przestaje mieścić się w czasie odpowiedzi ekranu | Materializowany licznik grupy albo widok odświeżany zadaniem |
| **Jeden moderator** | Powyżej ~30 pozycji dziennie kolejka rośnie szybciej, niż jest opróżniana, niezależnie od jakości sygnałów | Drugi człowiek albo ostrzejsze progi. Narzędzia się tu nie da dołożyć — patrz §8, model AI to **druga para oczu, nie zastępstwo dla pierwszej** |
| **Wpisy i komentarze** | Spam przenosi się na przepisy i na zdjęcia, których żaden z tych sygnałów nie widzi | §8 — ocena zdjęć modelem |

## 8. ETAP DRUGI: ocena modelem OpenAI (`omni-moderation-latest`)

**Status: WDROŻONE** (D-055, decyzja właściciela z 9 września 2026).
Kod: `app/Moderacja/`, `app/Notifications/PilnyAlarmModeracyjny.php`,
`app/Console/Commands/PodsumowanieAutomatu.php`.
Wyłącznik: pusty `OPENAI_MODERATION_KEY`.

### 8.1. To łapie INNĄ klasę treści niż nasz dzisiejszy problem

Moderation API ocenia **nienawiść, przemoc, treści seksualne
i samookaleczenie**. Nie ocenia spamu — a naszym realnym zagrożeniem przy
fali z Garnek.pl jest właśnie spam: „zarobki z domu", odnośniki, numery
telefonów.

**To jest uzupełnienie sygnałów z §3, nie ich zamiennik.** Trzeba to napisać
wprost, bo inaczej ktoś uzna, że skoro jest AI, to spam mamy załatwiony.

### 8.2. Zdjęcia są tu największą wartością

Kuking stoi na fotografiach obiadów wrzucanych przez nieznajomych. Wersja
`omni` ocenia obrazy — i jest **jedynym narzędziem, jakie mamy na treść,
której nikt nie przeczyta, dopóki ktoś jej nie zgłosi**. Zdjęcia mają
pierwszeństwo przed tekstem przy wdrożeniu.

Od issue #237 dotyczyło to także **zdjęcia profilowego**, które jest oglądane
częściej niż jakikolwiek wpis — patrz §9. **Od D-240 już nie:** awatar bez
potwierdzonej zgody nie wychodzi do OpenAI, a mechanizmu takiej zgody nie ma
(§10).

### 8.3. Dane wychodzą poza EOG

Wysłanie wpisu albo zdjęcia do OpenAI to powierzenie przetwarzania podmiotowi
w USA. Wymaga tego, zanim pójdzie pierwsze żądanie:

- wpis w tabeli podmiotów przetwarzających w
  `resources/legal/polityka-prywatnosci.md`: **co wysyłamy** (treść wpisu,
  zdjęcie), **czego NIE wysyłamy** (adres e-mail, nazwa konta, cokolwiek
  identyfikującego — i to jest wymóg dla kodu, nie deklaracja),
  **gdzie trafiają dane** (USA) i **na jakiej podstawie**;
- zdanie w akapicie o przekazywaniu poza EOG, w tej samej formie co dla
  pozostałych dostawców spoza UE;
- **informacja o automatycznej ocenie treści dla użytkownika** — w zasadach
  społeczności (`resources/legal/zasady.md` punkt 12), nie w polityce
  prywatności: to jest informacja o moderacji, nie o danych. DSA art. 14
  ust. 1 wymaga opisania używanych narzędzi automatycznych, a art. 17 ust. 3
  lit. c — powiedzenia w uzasadnieniu decyzji, że przy wykryciu treści użyto
  środków automatycznych.

Wszystkie trzy są ZROBIONE:
`resources/legal/polityka-prywatnosci.md` ma OpenAI w tabeli podmiotów
przetwarzających, osobny akapit „Co wysyłamy do OpenAI i czego NIE wysyłamy"
oraz drugi wyjątek w akapicie o przekazywaniu poza EOG;
`resources/legal/zasady.md` punkt 12 wymienia oba narzędzia i mówi wprost,
że żadne z nich niczego nie ukrywa ani nie blokuje.

Zabezpieczenie mechaniczne: `PolitykaPrywatnosciWymieniaKazdaUslugeTest`
mapuje usługi zewnętrzne na słowa, które muszą paść w polityce — obecność
klasy `App\Moderacja\KlientOpenAI` oblewa test, dopóki w polityce nie ma
słowa „OpenAI". Sprawdzone kontrolą ujemną: po usunięciu wszystkich wystąpień
tego słowa test oblewa.

Trzecim miejscem jest samo uzasadnienie decyzji:
`UzasadnienieDecyzji::skadSprawa()` mówi autorowi, że treść wskazało
narzędzie oceniające maszynowo, a nie czyjeś zgłoszenie (art. 17 ust. 3
lit. b i c).

### 8.4. Granica obowiązuje tak samo, i tu jeszcze mocniej

Model podnosi rękę, nigdy nie zamyka drzwi. **Żadnego automatycznego
ukrywania, wyciszania ani blokowania na podstawie wyniku modelu** —
poz. 3.10 powstała dokładnie na taką sytuację. Model wytrenowany głównie na
angielskim będzie się mylił na polszczyźnie, a już zwłaszcza na języku, jakim
mówi o jedzeniu siedemdziesięcioletnia kobieta z Podkarpacia. Fałszywy alarm
ma kosztować jedną pozycję w kolejce i nic więcej.

Wynik zapisujemy **razem z powodem po polsku**, nie jako surową liczbę z API:
moderator, który dostaje `sexual: 0.62`, nie wie, czego szukać.

### 8.5. Powiadomienie na e-mail — zbiorcze, nie po jednym liście

Jeden list na każdą oznaczoną treść przy fali migracyjnej zamieni skrzynkę
moderatora w śmietnik i skończy się tym, że przestanie ją czytać — czyli
alarm przestanie działać dokładnie wtedy, gdy będzie potrzebny.

| Kanał | Kiedy | Uzasadnienie progu |
|---|---|---|
| **Podsumowanie zbiorcze** | raz dziennie o 07:00, jeden list: „5 nowych pozycji w kolejce", z rozbiciem na sygnały. `kuking:podsumowanie-automatu` | Kolejka nie jest awarią. Codzienny rytm wystarcza, żeby nic nie zaległo, i nie uczy nikogo ignorowania listów. **List nie wychodzi, gdy nie ma o czym pisać** — „0 nowych pozycji" przez trzy tygodnie to najlepszy sposób, żeby czwarty list przeszedł niezauważony |
| **List natychmiastowy** | wyłącznie `KategorieModeracji::PILNE`: treści seksualne i wszystko, co dotyczy dzieci | Te dwie kategorie mają w `resources/legal/zasady.md` własną sekcję „Czego nie tolerujemy w ogóle" i są jedynymi, przy których zwłoka jednego dnia jest realną szkodą, a nie niedogodnością. Mają też **niższy próg** (`prog_pilny`, 0,2 zamiast 0,5): tu wolimy fałszywy alarm od przeoczenia |

#### Co się dzieje, gdy list natychmiastowy NIE dojdzie (issue #1051)

Do 22 września 2026: nic. Sprawa zostawała w `reports`, alarm nie wychodził,
a ponowna analiza tej samej treści zatrzymywała się na „automat już to
oglądał" i milczała — zgubione zostawało zgubione. W bazie nie było pola,
po którym dałoby się taką sprawę odróżnić od dnia bez ani jednego pilnego
zgłoszenia.

Dziś sprawa pilna **albo dociera, albo zostawia ślad, że nie dotarła**:

| Stan `reports.alarm_pilny_stan` | Znaczy |
|---|---|
| `NULL` | Sprawa nie jest pilna — tak wygląda ogromna większość wierszy |
| `zalegly` | Alarm należny, jeszcze nie zlecony. Zapisywany **tą samą transakcją**, która zapisuje sprawę, więc worker ubity zaraz po `COMMIT`-cie nie kasuje obowiązku |
| `zlecony` | Alarm przekazany kanałowi pocztowemu; `alarm_pilny_zlecony_at` mówi kiedy. To nie znaczy „EmailLabs przyjął" — za ten odcinek odpowiadają `failed_jobs` i `mail_failures` |
| `bez_adresu` | `KUKING_MODEL_ALARM_EMAIL` jest pusty, kanał alarmowy nie istnieje. Naprawia to wpisanie adresu, nie ponowienie |
| `nieudany` | Zlecenie listu rzuciło wyjątkiem; wyjątek poszedł do `report()` |

Sonda `alarmy_moderacji` w `/health` pyta o wiersze ze stanem ustawionym
i pustym `alarm_pilny_zlecony_at` — **bez okna czasowego**, więc sprawa
z nocy nie robi się niewidzialna o świcie. Gaśnie dopiero wtedy, gdy alarm
zostanie zlecony naprawdę. Ponowna analiza tej samej treści **dosyła**
zaległy alarm, bo `OznaczDoPrzegladu` oddaje teraz istniejący wiersz zamiast
`null`.

**Limit poczty:** EmailLabs, plan darmowy, **300 listów dziennie**, dzielone
z listami do użytkowników (potwierdzenia rejestracji, zmiany adresu,
powiadomienia moderacyjne). Alarmy moderacyjne nie mogą zjeść limitu
potrzebnego na rejestracje — to jest drugi, niezależny powód, dla którego
podsumowanie jest zbiorcze, a listy natychmiastowe ograniczone do dwóch
kategorii.

### 8.6. Wymagania techniczne

**Dziennik awarii (#828, #925):** cztery granice — transport OpenAI,
przygotowanie zdjęcia, analiza treści i analiza awatara — korzystają ze
wspólnego `App\Moderacja\ExceptionContext`. Z wyjątku zostaje tylko nazwa
klasy; etap jest stałą podaną przez nasz kod. Nie zapisujemy wiadomości,
niezatwierdzonego kodu wyjątku, stosu ani poprzedniego wyjątku. Mogą zawierać
tekst, zdjęcie, adres z parametrami albo sekret. Osobna gałąź błędnej
odpowiedzi HTTP zachowuje dotychczasowy status liczbowy, bez jej ciała.
Awaria nadal oznacza brak wyniku analizy, nie sankcję dla autora.
Testy i ograniczenia pomiaru: `docs/security/DZIENNIK_WYJATKOW_828_925.md`.

- klucz przez `env()` (`OPENAI_MODERATION_KEY`); **brak klucza = funkcja
  wyłączona** — `KlientOpenAI::oceniamy()` oddaje `false`, żadne żądanie nie
  wychodzi, nic nie pada. Tak jest lokalnie, w CI i w testach;
- wywołanie w kolejce, w TYM SAMYM zadaniu co sygnały lokalne
  (`PrzeanalizujTresc`). Dwa zadania próbowałyby postawić dwa oznaczenia tej
  samej treści, a `reports_jeden_automat_na_tresc` przepuściłby tylko to,
  które wygrało wyścig — ocena modelu potrafiłaby wtedy przepaść dlatego, że
  wpis zawierał numer telefonu;
- limit czasu 8 s; **awaria OpenAI nie wstrzymuje publikacji wpisu** —
  publikacja dzieje się w innym żądaniu, a każdy błąd kończy się brakiem
  jednej pozycji w kolejce (sprawdza to
  `ModeracjaModelemTest::test_awaria_openai_nie_ma_zadnego_skutku`);
- do API nie idzie NIC identyfikującego autora: ani adres e-mail, ani nazwa
  konta, ani identyfikator wpisu, ani adres IP. Pilnuje tego test
  `test_do_openai_nie_wychodzi_nic_identyfikujacego_autora`;
- treści PRYWATNE nie wychodzą w ogóle — wpis `private` nie wchodzi do
  analizy;
- zdjęcie idzie jako `data:` z **wariantu thumb przekodowanego do JPEG**:
  wariant nie ma EXIF-u (czyli GPS-u kuchni), a `data:` zamiast adresu, bo
  publiczny adres dla OpenAI byłby publiczny także dla wszystkich innych;
- **nie używamy pola `flagged` z API.** Progi trzymamy u siebie
  (`moderation.model.prog`), bo cudza decyzja przy polszczyźnie i kuchni
  bywa hojna („zabiłam kurę na rosół", „krwisty stek"), a każde trafienie
  kosztuje uwagę jedynego moderatora.

---

## 9. ZDJĘCIE PROFILOWE — trzecia droga do modelu (issue #237)

Do 10 września 2026 model oceniał zdjęcia **wpisów** i nic więcej. Zdjęcie
profilowe szło zupełnie inną drogą (`AvatarSettingsController` →
`StoreUploadedImage` → `ProcessUploadedImage`) i nikt na niej nie zlecał
analizy — awatar nie pojawiał się w kolejce automatu nigdy, dopóki ktoś nie
zgłosił go ręcznie.

### 9.1. Dlaczego to była większa luka, niż wyglądała

**Awatar jest widoczny CZĘŚCIEJ niż jakikolwiek wpis.** Wpis widzą
obserwujący i ci, którzy trafią na niego w feedzie. Awatar chodzi za
człowiekiem po całym serwisie: przy każdym komentarzu pod cudzym przepisem,
na tablicy dnia, na listach obserwujących i obserwowanych, w wynikach
szukania osób. Jedno zdjęcie trafia więc przed oczy większej liczby osób niż
wpis, w którym stało.

Do tego jest **najtańszym miejscem dla kogoś, kto chce zaszkodzić**: nie
wymaga napisania ani jednego słowa, więc nie rusza `WykrywaczSygnalow`
(pracuje na tekście), a przy fali migracyjnej nikt nie będzie oglądał
kilkuset nowych awatarów po kolei.

### 9.2. Jak to działa

| Krok | Co się dzieje |
|---|---|
| wgranie zdjęcia | `AvatarSettingsController` zapisuje `profiles.avatar_media_id` i **dopiero potem** zleca `PrzeanalizujAwatar` |
| warianty | zadanie sprawdza stan zdjęcia; przy `pending`/`processing` **wraca do kolejki** (`release`, 30 s, do 3 prób) |
| ocena | `OcenaModelem::dlaZdjecia()` — ta sama droga co zdjęcia wpisów: wariant `thumb`, przekodowany do JPEG, wysłany jako `data:` |
| oznaczenie | jeden wiersz w `reports`, `source = automat`, **`target_type = media`**, powód zaczyna się od „Zdjęcie profilowe: " |
| alarm | kategorie pilne → jeden list (`AlarmujModeratora`, wspólny z analizą wpisów) |

**Zadanie CZEKA na warianty, zamiast cicho nie zrobić nic.** Wariant `thumb`
powstaje w `ProcessUploadedImage`, na kolejce `media`, czyli w innym zadaniu.
Gdyby `PrzeanalizujAwatar` kończyło się powodzeniem przy zdjęciu w stanie
`processing`, cała funkcja działałaby wyłącznie wtedy, gdy worker mediów
wyprzedzi worker kolejki `low` — czyli losowo, i nikt by tego nie zauważył.

### 9.3. Celem oznaczenia jest PLIK, nie konto

Indeks `reports_jeden_automat_na_tresc` przepuszcza jedno oznaczenie automatu
na (typ, identyfikator) — **na zawsze**, także po odrzuceniu. Gdyby celem
było konto (`target_type = user`), oceniony zostałby pierwszy awatar tego
konta i **żaden następny**, a podmiana zdjęcia to sekunda pracy. Cel to więc
konkretne zdjęcie.

Nazwa typu w bazie to `media`, a nie `avatar`, bo `ModeratedContent::TYPY`
mapuje **klasę** modelu, a klasa jest ta sama dla awatara i dla zdjęcia we
wpisie. Że w danym wierszu chodzi o zdjęcie profilowe, mówi treść powodu
i podgląd w kolejce.

### 9.4. Co moderator może z tym zrobić — i czego NIE MOŻE

`ModerationAction::DOZWOLONE['media']` to `none`, `warn`, `suspend`, `ban`.
Świadomie **bez `hide` i bez `remove`**:

- `hide` — `Media` nie ma statusu w rozumieniu moderacji. Przycisk robiłby
  dokładnie to, co robił przy „Ugotowałem": nic, przy powiadomieniu
  „ukryliśmy Twoją treść";
- `remove` — `$target->delete()` na zdjęciu jest nieodwracalne (`Media` nie
  ma miękkiego kasowania), a odwołanie od decyzji `remove` ma treść
  **przywrócić** (DSA art. 17). Decyzja, od której nie da się skutecznie
  odwołać, nie może stać na tym ekranie.

Zostaje więc ostrzeżenie (od D-058 razem z odpowiedzią pocztą wprost
z panelu), zawieszenie i ban. **Usuwanie zdjęcia profilowego przez
moderatora wymaga najpierw miękkiego kasowania zdjęć** — osobna praca.

### 9.5. Granica bez zmian

Awatar **zostaje widoczny**, autor niczego się nie dowiaduje, decyzję
podejmuje człowiek (poz. 3.6, 3.10, D-052). Przy zdjęciu profilowym pokusa
jest większa niż zwykle — „przecież wystarczy podmienić na literę" — ale
ciche podmienienie komuś awatara przez maszynę to jest dokładnie shadow
filtering z poz. 3.16, odrzucony jako sprzeczny z art. 17 DSA.

### 9.6. Czego ta zmiana NIE objęła

- **zdjęcia przepisów i „Ugotowałem"** — sprawdzone: idą przez
  `PublishPost`/`PublishComment`, więc model je widzi tą samą drogą co
  zdjęcia wpisów;
- **zdjęcie w tle profilu** — nie istnieje i na razie nie wejdzie
  (issue #245);
- **koszt w kolejce.** Endpoint jest bezpłatny, więc pieniędzy to nie kosztuje,
  ale pozycji w kolejce moderatora — tak. Progu nie ruszamy z góry: mierzymy
  na pierwszej setce kont (§6).

## 10. Co wolno wysłać do OpenAI (D-240)

Do dostawcy wychodzi **wyłącznie treść publiczna**: taka, którą gość bez konta
zobaczyłby w serwisie w chwili wysyłki. Rozstrzyga `app/Moderacja/GranicaWysylki.php`,
pytając te same Policy co strona dla gościa — więc komentarz pod wpisem albo
przepisem przełączonym na prywatny, „dla obserwujących", ukrytym, usuniętym
albo należącym do zbanowanego konta nie wychodzi (#827). Granica jest pytana
przed **każdym** żądaniem, także przed każdym zdjęciem wpisu, i jeszcze raz
przed postawieniem oznaczenia. Treść „dla obserwujących" nie jest już oceniana
modelem — to świadome zawężenie wobec D-055.

Zdjęcie wpisu wychodzi tylko z wariantu `thumb`, bez zastępstwa innym
wariantem, i tylko gdy **bajty** — przed dekodowaniem i po przekodowaniu do
JPEG — mają dłuższy bok najwyżej 320 px. Inaczej zdjęcie jest pomijane
z wpisem w dzienniku (`stage=image_boundary`).

**Zdjęcie profilowe nie wychodzi wcale.** `PrzeanalizujAwatar` zostaje pustym
zadaniem tylko po to, żeby zlecenia sprzed wdrożenia nie kończyły się błędem.

Brak klucza na produkcji zostawia w dzienniku `stage=openai_disabled` przy
każdej nieocenionej treści. Odpowiedź bez ani jednej znanej kategorii,
z pustymi albo uszkodzonymi wynikami, nie jest już oceną „czyste" — zostawia
ostrzeżenie. Lokalne sygnały z §3 działają niezależnie od stanu modelu.

**Granica lokalna jest szersza niż granica wysyłki (D-241).** Treść, która nie
może wyjść do OpenAI, nadal sprawdzają lokalne wzorce spamu z §3. Dotyczy to
wpisów „dla obserwujących”, treści zbanowanych kont i komentarzy pod zapowiedzią
przepisu „dla obserwujących” (`GranicaWysylki::pozaAutorem()`). Te wzorce nie
wysyłają niczego poza serwer. Poza obiema granicami zostają: treść prywatna,
ukryta, usunięta oraz konto w karencji usunięcia albo wymazane.
