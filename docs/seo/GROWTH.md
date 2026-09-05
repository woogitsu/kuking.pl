# Growth — Kuking.pl (grupa docelowa 50+)

Zasada nadrzędna z `docs/PRODUCT.md`: *„Publiczne treści mogą zdobywać ruch z Google, ale celem jest powrót bez Google”*. Ten dokument jest więc świadomie skoncentrowany na kanałach **relacyjnych i lokalnych**, nie na paid acquisition ani content-farmingu — zgodnie z zasadami z `docs/FEATURES.md` („Nie wcześnie: masowy import cudzych treści”) i `docs/SEO_ANALYTICS_GROWTH.md` (cold start: 20–150 realnych domowych kucharzy, nie import bazy przepisów).

Fakty rynkowe zweryfikowane wrzesień 2026 (linki w `## Źródła`): Facebook w Polsce ma ok. 17–23 mln aktywnych użytkowników miesięcznie, a grupa **55–64 lata spędza na nim najwięcej czasu ze wszystkich grup wiekowych** (ok. 45 min/dzień) — to najsilniejszy pojedynczy sygnał, że FB jest właściwym kanałem pierwszego wyboru dla tej grupy, nie TikTok/Instagram. W Polsce działa **ponad 500 Uniwersytetów Trzeciego Wieku** (>150 000 słuchaczy) oraz — zależnie od źródła/rejestru — **ok. 18 000–26 500 Kół Gospodyń Wiejskich** (rozbieżność wynika z różnych rejestrów: KRS KGW vs. wszystkie koła historycznie zarejestrowane; do planowania przyjmij konserwatywnie ok. 18–20 tys. aktywnych) `[do weryfikacji: dokładna aktualna liczba aktywnych KGW]`.

---

## 1. Kanały — ranking efekt/koszt

Skala 1–5 (5 = najlepszy stosunek efekt/koszt dla tej konkretnej grupy docelowej, nie w ogóle).

| # | Kanał | Efekt/koszt | Jak zacząć w tym tygodniu | Koszt | Jak mierzyć |
|---|---|---|---|---|---|
| 1 | **Znajomi i rodzina** | 5/5 | Właściciel/zespół osobiście zaprasza 20–50 realnych domowych kucharzy z własnej sieci (rodzina, sąsiedzi, znajomi rodziców) — dokładnie zgodnie z planem alpha z `SEO_ANALYTICS_GROWTH.md`. Rozmowa 1:1 lub telefon, nie masowy mailing. | 0 zł, tylko czas | Liczba kont założonych z bezpośredniego zaproszenia (`referrer_source='invite_link'` lub ręczna adnotacja), ich retencja D7/D30 vs. reszta ruchu |
| 2 | **Grupy na Facebooku** | 5/5 | Dołącz i bądź aktywny (nie tylko postuj link) w 5–10 istniejących grupach kulinarnych/lokalnych („Gotujemy jak nasze babcie”, grupy miejskie/osiedlowe, grupy „Kobiety po 50”). Odpowiadaj na pytania, dziel się realnym przepisem z linkiem do profilu, nie spamuj linkiem do apki. Rozważ własną grupę FB Kuking dopiero gdy jest 100+ aktywnych użytkowników do jej „zasilenia” — pusta grupa firmowa zniechęca bardziej niż brak grupy. | 0 zł (czas), ewentualnie kilkaset zł/mies. na boost pojedynczych postów po walidacji, że działa organicznie | UTM na linkach udostępnianych w grupach, `referrer_source=facebook` w `account_created`, stosunek kliknięć do rejestracji |
| 3 | **Koła Gospodyń Wiejskich (KGW)** | 4/5 | Kontakt z 3–5 lokalnymi KGW (adresy z rejestru KGW/urzędu marszałkowskiego) — propozycja: „pokażcie swoje przepisy szerzej, pomożemy założyć konta”. KGW mają ugruntowaną tożsamość kulinarną (konkursy na najlepszy przetwór, dożynki) i już często prowadzą profile FB — naturalny sojusznik, nie konkurent. | Czas dojazdu/rozmów, ewentualnie drobny gest (wydrukowane ulotki, poczęstunek na spotkaniu) | Liczba kont z tagiem „KGW [nazwa]” w onboardingu (pole `referrer_source` rozszerzone o `kgw`), liczba opublikowanych przepisów z `source_type='family'` po akcji |
| 4 | **Uniwersytety Trzeciego Wieku (UTW)** | 4/5 | Zaproponuj 30–45 min prelekcję/warsztat „Jak zapisać swoje przepisy, żeby się nie zgubiły” na jednym lokalnym UTW — to dokładnie problem z `docs/PRODUCT.md` („Problem”: treści rozrzucone między zeszytami/zdjęciami/aplikacjami). UTW mają >500 ośrodków i >150 tys. słuchaczy w całej Polsce — skalowalne po walidacji na 1–2 lokalnych. | Czas przygotowania prelekcji, dojazd; UTW zwykle nie płacą prelegentom, ale nie oczekują też opłaty od Ciebie | Liczba kont założonych podczas/po warsztacie (rejestracja na miejscu z pomocą wolontariusza), ankieta satysfakcji po warsztacie |
| 5 | **Biblioteki publiczne** | 4/5 | Kontakt z biblioteką osiedlową/gminną — propozycja spotkania „Cyfrowe zeszyty z przepisami” (biblioteki często już prowadzą kluby seniora i mają komputery/tablety do pokazania rejestracji na miejscu). | Zwykle 0 zł — biblioteki chętnie przyjmują bezpłatne wydarzenia edukacyjne | Jak wyżej: konta założone na miejscu, powrót D7 |
| 6 | **Parafie** | 3/5 | Ogłoszenie parafialne / gazetka parafialna z krótkim tekstem („Miejsce, gdzie zapiszesz przepisy rodzinne”) — działa najlepiej w mniejszych miejscowościach, gdzie parafia jest realnym węzłem społecznym. Nie prosić o „promocję z ambony” — wystarczy gazetka/tablica ogłoszeń. | 0 zł lub symboliczna darowizna na tacę | Ruch trudny do precyzyjnego trackingu (brak linku klikalnego z papieru) — użyj krótkiego, łatwego do przepisania adresu (`kuking.pl/start`) i pytania w onboardingu „Skąd się dowiedziałaś/eś?” |
| 7 | **Prasa lokalna** | 3/5 | Krótki, ludzki news do lokalnego portalu/gazety: nie „startup ogłasza rundę”, tylko historia jednej prawdziwej użytkowniczki i jej przepisu — dziennikarze lokalni chętniej publikują historię człowieka niż komunikat produktowy. | 0–kilkaset zł (czasem lokalne redakcje oczekują reklamy za publikację — negocjuj barter: wywiad w zamian za brak opłaty) | UTM w artykule, skok w `account_created` w dniu publikacji, pytanie w onboardingu |
| 8 | **Radio lokalne** | 2/5 | Krótki wywiad w lokalnej rozgłośni (audycje poranne/senioralne) — grupa 50+ nadal regularnie słucha radia, ale konwersja z usłyszanej nazwy domeny na realną rejestrację jest niższa niż z klikalnego linku. | 0 zł zwykle (audycje lokalne chętnie biorą gości), czas na nagranie | Trudne do precyzyjnego pomiaru — pytanie w onboardingu + obserwacja skoku ruchu bezpośredniego (`direct`) w oknie emisji |
| 9 | **YouTube** | 2/5 | Nie kanał firmowy z produkcją wideo (za drogie na start) — zamiast tego zachęcaj już aktywnych użytkowników Kuking, którzy nagrywają krótkie wideo „jak gotuję”, do umieszczenia linku do swojego profilu Kuking w opisie. Długoterminowo: 1 prosty film miesięcznie „za kulisami” budujący zaufanie do marki, nie viralowy content. | Czas montażu, 0 zł budżetu reklamowego na start | Kliknięcia z linków w opisach filmów (UTM), subskrypcje kanału jeśli powstanie |
| 10 | **Pinterest** | 2/5 | Niska priorytetowość *specyficznie dla polskiej grupy 50+* — Pinterest ma słabą penetrację w tej demografii w Polsce (silniejszy w USA/UK i wśród młodszych kobiet planujących posiłki). Wart rozważenia dopiero jako kanał **odkrywania przepisów przez młodsze pokolenie rodziny** (np. córka/wnuczka trafia na przepis babci i namawia ją do założenia konta) — czyli pośredni, nie bezpośredni kanał do 50+. | Niski (piny generowane automatycznie z opublikowanych przepisów, obraz + link) | Referral traffic z `pinterest.com` w analytics, drugorzędny wskaźnik |
| 11 | **SEO długiego ogona** | 2/5 (dla *tego* segmentu, mimo że wartościowe ogólnie) | Już opisane szczegółowo w `SEO_TECHNICAL.md` — structured data, sitemapy, wydajność. Działa, ale trafia głównie w ludzi **szukających przepisu w Google**, niekoniecznie w rdzeń grupy 50+, która częściej trafia przez polecenie osoby niż przez wyszukiwarkę. Traktuj jako uzupełnienie długoterminowe, nie główny silnik wzrostu w pierwszym roku. | Czas inżynieryjny (już zaplanowany), 0 zł mediowego | Ruch organiczny z Google Search Console, ale mierz go **osobno** od retencji — cel produktu to „powrót bez Google”, więc SEO ma dowozić nowych ludzi, nie być jedynym kanałem powrotu |

**Reguła generalna:** kanały 1–5 (znajomi, FB, KGW, UTW, biblioteki) to prawdziwa praca cold-startu z `SEO_ANALYTICS_GROWTH.md` — wymagają obecności fizycznej/osobistej, nie skalują się bez pracy ludzkiej, ale dają **realnych, aktywnych kucharzy**, nie próżny ruch. Kanały 6–11 dokładają zasięg dopiero, gdy 1–5 już działają (jest co pokazać, są prawdziwe historie do opowiedzenia).

---

## 2. Mechaniki polecania — bez wstydu i bez spamu

Zasada z `docs/FEATURES.md` („Nie wcześnie”: *punkty za liczbę postów*) rozciąga się też na polecenia: **żadnej gamifikacji polecania** (rankingi „kto zaprosił najwięcej”, punkty, odznaki za liczbę zaproszeń). Odznaka **Founding Cook** dla pierwszych 500 kont (z `SEO_ANALYTICS_GROWTH.md`) jest jedynym dopuszczalnym elementem statusowym — i wyraźnie **bez punktowej rywalizacji**.

### 2.1 „Zaproś koleżankę” — zaproszenie 1:1, nie masowy import kontaktów

- Nigdy nie proś o dostęp do książki kontaktów/e-maili w telefonie do masowego zaproszenia (to wzorzec „growth hacking” z lat 2010, dziś słusznie kojarzony ze spamem i utratą zaufania — zwłaszcza dotkliwy dla grupy, która i tak jest ostrożna wobec „aplikacji, które chcą dostęp do wszystkiego”).
- Zamiast tego: przycisk „Wyślij zaproszenie” generuje **jeden** link do skopiowania/wysłania przez SMS/Messenger, z tekstem które użytkownik może edytować, np.: *„Cześć! Zapisuję swoje przepisy na Kuking — zobacz mój bigos: [link]. Może i Ty spróbujesz?”* — zaproszenie niesie **konkretną treść** (przepis, wpis), nie gołe „dołącz do apki”.
- Brak śledzenia „ilu zaprosiłaś” widocznego dla innych użytkowników — to prywatna informacja (widoczna dla właściciela produktu w analytics), nie element rywalizacji społecznej.

### 2.2 „Pokaż komuś swój przepis” — udostępnianie jako rdzeń, nie dodatek

- Każdy publiczny przepis i wpis ma jeden, duży, podpisany przycisk „Udostępnij” (nie ikonkę strzałki bez opisu — zgodnie z `docs/UX_50_PLUS.md`: „ważna akcja ma tekst”), otwierający natywny system share sheet (Web Share API) z fallbackiem do kopiowania linku + osobnych przycisków Messenger/WhatsApp/e-mail dla przeglądarek bez wsparcia.
- Tekst udostępnienia domyślnie wypełniony treścią przepisu (tytuł + pierwsze zdanie opisu + link), edytowalny — nie link goły.

### 2.3 Wydruk przepisu jako pretekst do dzielenia się

Realna, niedoceniana ścieżka dla tej grupy docelowej: **wydrukowana kartka z przepisem** przekazywana fizycznie sąsiadce/córce jest naturalnym, niewstydliwym sposobem dzielenia się — dokładnie tak, jak od dekad krążą przepisy zapisane odręcznie.

- Widok „Wersja do druku” na każdym przepisie: duża czcionka, bez zdjęć w tle utrudniających czytelność wydruku, składniki i kroki w czytelnym układzie, **QR kod + krótki URL** na dole kartki prowadzący z powrotem do przepisu na Kuking.
- To zamienia analogowy akt dzielenia się (kartka przekazana z ręki do ręki) w kanał powrotu do cyfrowego produktu — bez proszenia nikogo o „polecanie aplikacji”, po prostu naturalne zachowanie z dodanym QR kodem.
- Mierzalność: unikalny parametr w URL/QR (`?src=print`) pozwala policzyć skuteczność tego kanału mimo offline'owego charakteru.

---

## 3. Sezonowe okna wzrostu — polski kalendarz kulinarny

Konkretne działania przypięte do realnych momentów w roku, kiedy Polacy **i tak już gotują i szukają przepisów** — dużo tańsze niż tworzenie sztucznego zainteresowania.

| Okres | Wydarzenie kulinarne | Konkretne działanie |
|---|---|---|
| **Styczeń** | Poświąteczne resztki, postanowienia noworoczne | Krótka kampania w istniejących grupach FB: „Co zrobić z resztkami z Wigilii” — zachęta do publikacji przepisu na resztki, niska bariera wejścia (nawiązuje do „Wpis” jako najniższego progu publikacji z `PRODUCT.md`) |
| **Luty** | Tłusty Czwartek (pączki, faworki) | Najsilniejszy pojedynczy dzień w roku pod względem intencji wyszukiwania „przepis na pączki” — przygotować i przypomnieć istniejącym użytkownikom (powiadomienie/e-mail) o dodaniu swojego przepisu na pączki z wyprzedzeniem 1 tygodnia, żeby było co pokazać w dniu święta |
| **Marzec–Kwiecień** | Wielkanoc (żurek, mazurek, pisanki, święconka) | Drugie najważniejsze okno kulinarne w roku po Wigilii. Zachęta do „Ugotowałem” na tradycyjne dania wielkanocne — naturalna okazja do przypomnienia funkcji „Ugotowałem” tym, którzy jej jeszcze nie użyli |
| **Maj–Czerwiec** | Start sezonu grillowego, truskawki | Współpraca z KGW/UTW przy lokalnych piknikach sąsiedzkich (fizyczna obecność, rejestracja na miejscu) |
| **Lipiec–Sierpień** | Sezon owoców i warzyw, początek przetworów (dżemy, kompoty) | To naturalny szczyt aktywności KGW (konkursy na przetwory, dożynki pod koniec sierpnia/we wrześniu) — najlepszy moment na kontakt z KGW z sekcji 1, bo mają wtedy realny, świeży powód do pokazania swoich przepisów |
| **Wrzesień–Październik** | Sezon grzybowy, kiszenie kapusty i ogórków, dynia | Wysoka intencja wyszukiwania „jak kisić”/„przepis na grzyby” — jednocześnie wysokie ryzyko treści niebezpiecznych (botulizm, zatrucia grzybami — patrz `docs/MODERATION.md` „Food safety”), więc każda kampania sezonowa musi iść w parze z aktywną moderacją tych kategorii w danym oknie, nie tylko z promocją |
| **Listopad** | Wszystkich Świętych, początek przygotowań do Wigilii | Cichszy miesiąc promocyjnie, dobry moment na przygotowanie contentu/funkcji (np. kolekcje „Moja Wigilia”) przed grudniowym szczytem |
| **Grudzień** | **Wigilia** — największe pojedyncze wydarzenie kulinarne w polskim roku (12 potraw, karp, pierniki) | Największe okno w roku. Konkretne działania: (1) przypomnienie 2–3 tygodnie wcześniej do wszystkich użytkowników o zapisaniu przepisów na Wigilię „zanim się zgubią” (dokładnie problem z `PRODUCT.md`), (2) zachęta do kolekcji „Moja Wigilia” (funkcja kolekcji już w MVP), (3) w dniu/dniach świąt — zero nachalnej komunikacji, ludzie gotują, nie czytają maili |

---

## 4. Czego nie robić

- **Kupowanie ruchu** (reklamy performance nastawione na CPA rejestracji) na etapie przed potwierdzoną retencją — koszt pozyskania bez retencji to zmarnowany budżet, a produkt jeszcze nie ma dowodu, że nowi ludzie zostaną. Płatny ruch ma sens dopiero po walidacji organicznej pętli (kanały 1–5 działają i są mierzalne).
- **Farmy treści** — masowe generowanie przepisów przez AI pod SEO. Sprzeczne wprost z zasadą projektu i z ryzykiem kary Google „scaled content abuse” opisanym w `SEO_TECHNICAL.md` §7.
- **Import cudzych przepisów** (np. scrapowanie popularnych blogów kulinarnych, żeby „było co pokazać” na start) — zakazane wprost w `docs/FEATURES.md` („Nie wcześnie: masowy import cudzych treści”) i ryzykowne prawnie (patrz `docs/MODERATION.md` „Copyright”). Cold start ma się opierać na prawdziwych 20–150 kucharzach, nie na wypełnieniu bazy cudzą treścią.
- **Influencerzy zamiast społeczności** — płacenie znanej twarzy kulinarnej za jednorazowy post daje chwilowy skok ruchu bez realnej społeczności wokół niej (obcy ludzie przychodzą zobaczyć influencera, nie zostają dla społeczności). To odwrotność pozycjonowania z `docs/PRODUCT.md` („miejsce, gdzie poznajesz ludzi, którzy naprawdę gotują”, nie kolejna platforma z gwiazdami). Współpraca z pojedynczą, realną osobą ma sens dopiero jako **część** istniejącej społeczności (np. aktywna liderka KGW, która i tak już jest w produkcie), nie jako zewnętrzna twarz kampanii.

---

## Źródła

- [Facebook w Polsce 2026 – czy młodzi użytkownicy odchodzą?](https://socialberry.pl/facebook-w-polsce-2026-mlodzi-uzytkownicy/)
- [Statystyki mediów społecznościowych 2026](https://widoczni.com/blog/statystyki-uzytkowania-social-mediow/)
- [Koła gospodyń wiejskich 2026 — wykaz, lista 18 073 KGW](https://www.coig.com.pl/wykaz_lista_kola-gospodyn-wiejskich_w_polsce.php)
- [Dofinansowanie dla KGW w 2026 roku](https://i-rolnik.pl/dofinansowanie-dla-kol-gospodyn-wiejskich-kgw-w-2026-roku-budzet-zwiekszony-do-165-mln-zl/)
- [UTW statystyka — Politechnika Łódzka](https://utwpl.p.lodz.pl/o-nas/utw-statystyka)
- [Uniwersytety Trzeciego Wieku w Polsce — Infor.pl](https://www.infor.pl/prawo/prawa-seniora/edukacja-seniora/260628,Uniwersytety-Trzeciego-Wieku-w-Polsce.html)
- Pliki wewnętrzne projektu: `docs/PRODUCT.md`, `docs/FEATURES.md`, `docs/SEO_ANALYTICS_GROWTH.md`, `docs/UX_50_PLUS.md`, `docs/MODERATION.md`
