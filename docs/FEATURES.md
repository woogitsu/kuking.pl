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
- rodzinna książka; *(pierwszy krok: wspólny zeszyt z zaproszeniami — #1743, D-302, 26.09.2026)*
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
  przepisu, ilości przeliczane z tekstu składnika, zaokrąglenie kuchenne;
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

**Dopisane do planu i zbudowane** (D-333, wiersz „#2227”, decyzja właściciela
z 30 września 2026):

- kanały Atom dla publicznego profilu, tagu i publicznego zeszytu (#2227) —
  tylko to, co widzi gość na tej samej stronie, chronologicznie, najwyżej
  30 wpisów, z `ETag`/`Last-Modified`. To kanał **wychodzący**: import
  cudzych kanałów RSS zostaje na liście „Nie wcześnie” (D-300).

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

**V2, ale nie teraz** (decyzja właściciela z 26 września 2026). Propozycje
z audytu zapisane na później. Nie budować bez nowej decyzji, mimo D-282
(pięć pozycji odblokowała D-331, a #1903 — D-333, listy wyżej):

- strukturalne alergeny składników i filtr bezpiecznego wyboru (#1902);
- wybrane przepisy do czytania offline w PWA (#1904);
- głosowy tryb gotowania bez dotykania telefonu (#1906);
- prywatne podsumowanie AI uwag z wykonań przepisu (#1999);
- typowy rzeczywisty czas przygotowania z wykonań społeczności (#2067).

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
