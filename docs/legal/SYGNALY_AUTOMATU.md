# Sygnały automatu — wykrywacz, który podnosi rękę

> Decyzja architektoniczna: **D-052** (`docs/DECISIONS.md`).
> Kod: `app/Domain/Moderation/Sygnaly/`, `app/Jobs/PrzeanalizujTresc.php`,
> `app/Http/Controllers/Admin/SygnalyController.php`.
> Progi: `config/kuking.php` → `moderation.sygnaly`.
> Wyłącznik: `KUKING_SYGNALY_AUTOMATU=false`.

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
| Nie zagląda do treści prywatnych | Wpis `private` w ogóle nie wchodzi do analizy |

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

Wszystkie trzy opisują zachowanie **jednego konta wobec jego własnej treści**.
Żaden nie porównuje kont między sobą.

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

**Status: zaprojektowane, wdrażane osobnym PR-em.** Decyzja właściciela
z 9 września 2026.

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

Zabezpieczenie mechaniczne: test mapujący usługi zewnętrzne na słowa, które
muszą paść w polityce prywatności — pojawienie się klasy
`App\Moderacja\KlientOpenAI` w kodzie oblewa test, dopóki w polityce nie ma
słowa „OpenAI".

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
| **Podsumowanie zbiorcze** | raz dziennie, jeden list: „5 nowych pozycji w kolejce, w tym 1 poważna" | Kolejka nie jest awarią. Codzienny rytm wystarcza, żeby nic nie zaległo, i nie uczy nikogo ignorowania listów |
| **List natychmiastowy** | wyłącznie kategorie, które nie mogą czekać: treści seksualne na zdjęciach oraz cokolwiek dotyczącego dzieci | Te dwie kategorie mają w `resources/legal/zasady.md` własną sekcję „Czego nie tolerujemy w ogóle" i są jedynymi, przy których zwłoka jednego dnia jest realną szkodą, a nie niedogodnością |

**Limit poczty:** EmailLabs, plan darmowy, **300 listów dziennie**, dzielone
z listami do użytkowników (potwierdzenia rejestracji, zmiany adresu,
powiadomienia moderacyjne). Alarmy moderacyjne nie mogą zjeść limitu
potrzebnego na rejestracje — to jest drugi, niezależny powód, dla którego
podsumowanie jest zbiorcze, a listy natychmiastowe ograniczone do dwóch
kategorii.

### 8.6. Wymagania techniczne

- klucz przez `env()`; **brak klucza = funkcja wyłączona**, bez wywracania CI
  i pracy lokalnej;
- wywołanie w kolejce, nigdy w kontrolerze;
- krótki limit czasu; **awaria OpenAI nie może wstrzymać publikacji wpisu**;
- do API nie idzie nic identyfikującego autora.
