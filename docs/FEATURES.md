# MVP / V1 / V2

## MVP

### Konto i profil
- email + hasło;
- reset hasła;
- weryfikacja;
- username;
- display name;
- avatar;
- bio;
- większy tekst;
- archiwum;
- eksport;
- usunięcie konta.

### Social
- follow;
- unfollow;
- block;
- chronologiczny feed;
- komentarze;
- odpowiedzi;
- in-app notifications;
- „Podziel się” — wysłanie publicznego przepisu albo wpisu poza serwis:
  arkusz systemowy na telefonie (`navigator.share`), a pod spodem jawna
  lista WhatsApp / e-mail / Facebook plus adres do skopiowania. Przycisk
  stoi wyłącznie przy treści widocznej dla kogoś bez konta. Messengera jako
  osobnego linku nie ma — wymaga własnej aplikacji na Facebooku
  (`docs/DECISIONS.md`, D-044).

Wspomnienia: na stronie głównej jeden własny wpis z tego samego dnia sprzed
roku lub więcej, z cichym podpisem („Rok temu, 6 września"). Blok pojawia się
tylko wtedy, gdy jest co pokazać. Całość wyłącza jeden przełącznik
w `/ustawienia/prywatnosc`, a pojedyncze wspomnienie chowa przycisk przy nim.

Urodziny (#1755): opcjonalny dzień i miesiąc bez roku w `/ustawienia/urodziny`.
W dniu urodzin (strefa Europe/Warsaw, 29.02 → 28.02 w latach nieprzestępnych)
na `/home` jedno zdanie z życzeniami od gospodarza, z wyłącznikiem przy dacie.
Mail z życzeniami tylko za osobną zgodą, w sufitach poczty. Przypomnienie
obserwującym („Dziś urodziny: …”) tylko po włączeniu przez solenizanta, jako
powiadomienie w serwisie w dobowym limicie i poza ciszą nocną — nie w feedzie.

Rocznica dołączenia (#1754): w rocznicę założenia konta (dzień w strefie
Europe/Warsaw, 29 lutego → 28 lutego w latach nieprzestępnych) na stronie
głównej jedno zdanie od gospodarza. Zero nowych danych (`users.created_at`),
bez maila i powiadomień, ten sam wyłącznik co Wspomnienia.

### Wpis
- zdjęcie lub kilka zdjęć;
- tekst;
- widoczność;
- edycja;
- usunięcie.

### Przepis
Kreator 3 kroków:
1. o przepisie;
2. składniki;
3. przygotowanie.

Autosave szkicu.

Pole źródła (`recipes.source_url`) — „skąd jest ten przepis". Opcjonalne.

Tryb gotowania: `/przepisy/{przepis}/gotuj`, wielkie kroki na cały ekran,
odhaczanie kroków, minutnik kroku. Ekran nie gaśnie (Wake Lock, z degradacją
tam, gdzie przeglądarka go nie ma).

Przy odhaczonych krokach można wybrać „Zacznij od początku”. Dopiero
potwierdzenie usuwa odhaczenia bieżącego przepisu; wyjście i anulowanie
zachowują postęp. Inne przepisy oraz historia wykonań pozostają bez zmian (#903).

### Ugotowałem
- zdjęcie;
- uwaga;
- would make again;
- actual time;
- perceived difficulty.

### Kolekcje
- zapisz;
- własne foldery.

### Search
- ludzie;
- przepisy;
- fraza/składnik;
- proste filtry.

### Trust
- report;
- block;
- moderator panel;
- audit;
- rate limits.

### Web
- publiczne profile;
- publiczne przepisy;
- Recipe schema;
- sitemap;
- PWA.

---

## V1

- grupy / fotofora;
- Moja wersja — fork przepisu (**wdrożone przed bramką** — decyzja właściciela z 26.09.2026, D-301);
- planner;
- lista zakupów;
- rodzinna książka; *(pierwszy krok: wspólny zeszyt z zaproszeniami — #1743, D-302, 26.09.2026; drugi: „Wydrukuj zeszyt” — okładka, spis i przepisy do druku z przeglądarki, #2351, F7, D-333, 30.09.2026)*
- Q&A;
- Web Push;
- wyzwania społecznościowe. *(pierwszy krok: „Ugotujmy razem” — jeden przepis tygodnia wybrany przez gospodarza i wykonania z tego tygodnia, bez nagród i bez członkostwa; decyzja właściciela z 30.09.2026, karta F3 z researchu nowych funkcji z 30.09.2026)*

## V2

> **D-282 (26 września 2026):** decyzja właściciela zniosła zakaz budowania
> tej sekcji podczas prac nad MVP — funkcje niżej wolno budować od tej daty
> (kolejność P0 → P1 → P2 nadal obowiązuje, `AGENTS.md` §10). Lista
> „Nie wcześnie” poniżej pozostaje zakazana bez zmian.

- OCR starych zeszytów;
- import URL/PDF/zdjęcie — **URL i PDF wdrożone jako prywatny szkic** (D-300); **URL i PDF chodzą w kolejce** (#28, plik PDF czeka na prywatnym dysku i znika po odczycie — #2051); zdjęcie kartki/OCR wdrożone (D-298);
- pantry — **zbudowane** (D-285): prywatna lista „Co mam w domu”;
- „co ugotuję z tego, co mam” — **zbudowane** (D-285): dopasowanie bez AI, jawna reguła doboru;
- zamienniki — **od autora wdrożone (D-284)**: tekst przy składniku,
  „Zamiast tego: …” na stronie przepisu; podpowiedzi AI — jeszcze nie;
- skalowanie porcji — **wdrożone (D-284)**: „Na ile porcji?” na stronie
  przepisu, ilości przeliczane z tekstu składnika, zaokrąglenie kuchenne.
  Liczby grupowane co trzy cyfry zwykłą spacją, NBSP lub wąską NBSP są
  odczytywane w całości (#2455); błędne albo niejednoznaczne grupowanie
  pozostaje tekstem autora, bez częściowego przeliczenia;
- wartości odżywcze — szacunek na porcję z tabel CIQUAL/USDA, wdrożone (D-299); bez filtrów dietetycznych i bez profilu diety;
- koszt;
- native apps, jeśli PWA potwierdzi retencję.

**Odblokowane z listy „V2, ale nie teraz”** (D-331, decyzja właściciela
z 29 września 2026) — wolno budować:

- kilka jawnych zakresów czasu w wyszukiwarce przepisów (#1997);
- udostępnianie publicznego zeszytu (#2000);
- widoczne kalorie na porcję w danych SEO przepisu / JSON-LD (#1996);
- historia i porównanie publicznych wersji przepisu (#2024);
- opcjonalna synchronizacja postępu gotowania między urządzeniami (#2016).

**Zbudowane** (D-333, wiersz „#2379”, decyzja właściciela z 1 października 2026):

- kolejka do 4 przepisów z niezależnymi minutnikami, `/gotuj-kilka` (#2379) —
  **zbudowane**. Kolejka żyje tylko w przeglądarce (`localStorage`, bez konta
  i bez migracji), wygasa po 24 godzinach od ostatniej zmiany, ma przycisk
  „Wyczyść kolejkę”; samo otwarcie lub odświeżenie nie odnawia tego terminu;
  zamknięcie karty kończy działające minutniki (kolejka
  zostaje); alarm ma ten sam dźwięk co minutnik pojedynczego przepisu plus
  komunikat tekstowy; bez AI. Każdy przepis przechodzi `RecipePolicy::view`,
  a ten, którego osoba już nie widzi, wypada z kolejki z komunikatem. Bez
  JavaScriptu ekran pokazuje zwykłe linki do trybu pojedynczego, a przycisk
  „Dodaj do kolejki gotowania” pojawia się dopiero ze skryptem.
  Minutnik trzyma w `sessionStorage` UUID kroku, niewrażliwy odcisk jego
  instrukcji i czasu oraz pierwotny czas/numer, bez tekstu przepisu (#2589).
  Przestawienie nie zmienia terminu. Zmiana lub usunięcie czynności daje
  widoczny komunikat o wcześniejszym kroku i możliwość anulowania, bez
  odnośnika do innej czynności. Starszy zapis bez tożsamości także nie jest
  przypisywany do obecnego kroku; nadal odlicza i alarmuje. `localStorage`
  kolejki oraz adres zachowują wyłącznie slug i numer kroku zgodnie z D-333.

**Dopisane do planu i zbudowane** (D-333, wiersz „#2227”, decyzja właściciela
z 30 września 2026):

- kanały Atom dla publicznego profilu, tagu i publicznego zeszytu (#2227) —
  tylko to, co widzi gość na tej samej stronie, chronologicznie, najwyżej
  30 wpisów, z `ETag`/`Last-Modified`. To kanał **wychodzący**: import
  cudzych kanałów RSS zostaje na liście „Nie wcześnie” (D-300).

**Dopisane do planu i zbudowane** (D-333, wiersz „#2352”, decyzja właściciela
z 1 października 2026; karta F5 z researchu nowych funkcji):

- „Wskazówki od gotujących” (#2352) — autor przepisu proponuje, żeby uwagę
  z czyjegoś wykonania („Ugotowałem” z notatką) stała przy jego przepisie;
  kucharz klika „Zgadzam się” albo „Nie” (**zgoda na wniosek, brak odpowiedzi
  to brak publikacji**) i może zgodę wycofać w każdej chwili. Kolejność po
  dacie zgody, bez rankingu; moderacja jak wykonanie; eksport obu stron;
  wymazanie konta kucharza usuwa wskazówkę. Bez AI (to ludzka wersja tego, co
  chciało #1999, które zostaje na liście niżej).

**Zdjęte z listy „V2, ale nie teraz” i zbudowane** (D-333, wiersze „#1903”,
decyzja właściciela z 30 września 2026):

- spiżarnia z terminami ważności i priorytetem zużycia (#1903), jako
  rozszerzenie „Co mam w domu” — **zbudowana**: termin z opakowania
  („Należy zużyć do” / „Najlepiej spożyć przed”), ilość jako wolny tekst,
  oznaczenie „mrożone”, sekcja „Zużyj w pierwszej kolejności” z jawną regułą
  (`PriorytetZuzycia`), tryb przepisów „Najpierw to, co się psuje”
  (`/co-ugotuje?najpierw=termin`), jedno zdanie na Starcie i sobotnie
  przypomnienie e-mailem za osobną, domyślnie wyłączoną zgodą. Bez AI, OCR
  paragonu i kodu kreskowego, bez push.

**Zbudowane za flagą** (D-333, wiersz „#1902”, decyzja właściciela z 30 września 2026):

- alergeny przepisu według autora i filtr w wyszukiwarce przepisów (#1902) —
  autor zaznacza na poziomie przepisu 14 alergenów z Załącznika II
  rozporządzenia 1169/2011 i potwierdza, że lista jest pełna; bez
  oznaczenia strona przepisu mówi „Alergeny: nie sprawdzono”; filtr
  „Bez wskazanych alergenów (według autorów)” pomija przepisy niesprawdzone;
  podpowiedzi ze słownika (bez AI) są zawsze niezaznaczone. **Włączenie
  (`KUKING_ALERGENY_WLACZONE=true`) dopiero po teście z osobami 50+.** Bez
  profilu alergii widza, bez AI, bez danych w JSON-LD, tylko wyszukiwarka
  przepisów.

**Odblokowane i zbudowane** (D-333, wiersz „#2067”, decyzja właściciela
z 1 października 2026; włączone od razu, bez flagi):

- typowy rzeczywisty czas przygotowania z wykonań społeczności (#2067) —
  na stronie przepisu, obok czasu autora, dwa osobne zdania: „Autor podaje
  około N min.” i „Gotujący zwykle potrzebują około N min (na podstawie K
  osób).” Mediana czasów z „Ugotowałem” (na osobę), zaokrąglona do 5 minut,
  dopiero od 5 różnych osób; tylko czasy > 0 i ≤ 24 h; tylko wykonania
  widoczne dla widza (blokady, status konta); bez zakresu, bez ikon.

**Zbudowane** (D-333, wiersz „#2343”, założenia wykonawcy do potwierdzenia, 1 października 2026):

- wyjaśnienia terminów kulinarnych w trybie gotowania (#2343) — wyłącznie
  statyczny słownik w repozytorium i rozwijane „Wyjaśnij to” pod krokiem,
  bez AI, bez zapisu i bez śledzenia; wariant z modelem AI zostaje zablokowany
  przez „AI — nic nowego” (D-333).

**V2, ale nie teraz** (decyzja właściciela z 26 września 2026). Propozycje
z audytu zapisane na później. Nie budować bez nowej decyzji, mimo D-282
(pięć pozycji odblokowała D-331, a #1902 i #1903 — D-333, listy wyżej):

- wybrane przepisy do czytania offline w PWA (#1904);
- głosowy tryb gotowania bez dotykania telefonu (#1906);
- prywatne podsumowanie AI uwag z wykonań przepisu (#1999).

## Nie wcześnie

- DM;
- live chat;
- live video;
- marketplace;
- payouts;
- punkty za liczbę postów;
- masowy import cudzych treści — w tym import wielu adresów naraz, całych
  blogów, map witryn i kanałów RSS, import zdjęć z cudzych stron oraz
  „przepisywanie własnymi słowami” przez AI przed publikacją (D-300).

Wysoki koszt moderacji i spam nie są potrzebne do udowodnienia wartości Kuking.
