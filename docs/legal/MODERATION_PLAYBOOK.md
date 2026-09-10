# Kuking.pl — podręcznik moderacji (operacyjny)

> To dokument roboczy dla zespołu moderacji (1–2 osoby). Nie jest to porada prawna — w sprawach niejasnych (groźby karalne, podejrzenie CSAM, żądania organów ścigania) zawsze konsultuj się z prawnikiem lub od razu zgłaszaj do organów, zgodnie z procedurą zero-tolerancji niżej. Powiązane dokumenty: `COMPLIANCE.md` (podstawy prawne, obowiązki DSA), `resources/legal/regulamin.md` (regulamin serwisu), `docs/MODERATION.md` (założenia produktowe).

> **Ten podręcznik opisuje stan faktyczny serwisu, nie plany.** Zdanie w nim nieprawdziwe to obietnica złożona człowiekowi, który stracił treść. Miejsca, w których panel moderacji czegoś NIE potrafi, są wypisane wprost — zamiast opisu narzędzia, którego nie ma. Zmieniasz kod moderacji: popraw i ten plik.

---

## 1. Zasady Kuking (wersja dla użytkowników)

Krótka, ludzka wersja — pisana tak, żeby 65-latek zrozumiał ją bez czytania dwa razy.

**Tekst OPUBLIKOWANY żyje w `resources/legal/zasady.md` i renderuje się na `/zasady` (`routes/web.php:83`). Poniższe brzmienie jest wersją roboczą tego samego tekstu — zmiana tutaj NIE zmienia strony w serwisie.** Dziś oba teksty się różnią: opublikowany punkt 11 mówi tylko „napisz do nas", a produkt ma już przycisk „Odwołanie od tej decyzji" w powiadomieniu i sześciomiesięczny termin. Ujednolicenie to osobna zmiana w `resources/legal/zasady.md`.

> ### Zasady Kuking
>
> Kuking to miejsce dla ludzi, którzy naprawdę gotują. Chcemy, żeby było tu miło i bezpiecznie. Dlatego prosimy:
>
> 1. **Bądź sobą.** Publikuj pod prawdziwym imieniem lub pseudonimem, ale nie podszywaj się pod inną osobę.
> 2. **Publikuj własne przepisy i teksty.** Jeśli korzystasz z cudzego przepisu, napisz to własnymi słowami i podaj, skąd go masz. Nie wklejaj przepisów żywcem z książek czy stron internetowych.
> 3. **Publikuj swoje zdjęcia.** Nie wrzucaj zdjęć znalezionych w internecie jako swoje.
> 4. **Szanuj innych.** Bez obrażania, wyzwisk, nękania i mowy nienawiści — nawet w komentarzach "w żartach".
> 5. **Bez treści dla dorosłych.** Kuking jest o jedzeniu, nie o nagości ani przemocy.
> 6. **Uważaj na porady zdrowotne.** Nie publikuj rad typu "to leczy raka" ani niebezpiecznych metod (np. przetwory bez zachowania zasad bezpieczeństwa). Podziel się swoim doświadczeniem, ale nie obiecuj cudów.
> 7. **Bez spamu i reklamy ukrytej jako przepis.** Linki afiliacyjne, "zarabianie z domu", suplementy cud — to nie miejsce na to.
> 8. **Szanuj dzieci.** Kuking jest dla osób od 16 lat. Nie publikuj danych ani zdjęć cudzych dzieci bez zgody rodzica.
> 9. **Nie publikuj cudzych danych osobowych** (adresu, telefonu, danych finansowych) bez zgody tej osoby.
> 10. **Zgłaszaj, co Cię niepokoi.** Widzisz coś złego? Kliknij "Zgłoś". Przeczytamy każde zgłoszenie.
> 11. **Możesz się odwołać.** Jeśli usunęliśmy Twoją treść lub zawiesiliśmy konto, a uważasz, że to pomyłka — w powiadomieniu o decyzji jest przycisk „Odwołanie od tej decyzji”. Masz na to 6 miesięcy, odpowiadamy w ciągu 7 dni roboczych. Jeśli konto zostało zablokowane, link „Złóż odwołanie” znajdziesz na ekranie logowania.
> 12. **Reagujemy na zgłoszenia, nie inwigilujemy.** Nie oceniamy Cię z góry — sprawdzamy tylko to, co ktoś zgłosił, albo co jest wyraźnie publiczne i budzi wątpliwości.

---

## 2. Katalog naruszeń

| Kategoria | Przykład | Pierwsza reakcja | Eskalacja | Usunąć czy ukryć? | Informować autora? |
|---|---|---|---|---|---|
| Spam / reklama ukryta | Link afiliacyjny do sklepu z suplementami pod postem "przepisu" | Usunięcie treści, ostrzeżenie | 2. wystąpienie → blokada czasowa 7 dni; 3. → blokada trwała | Usunąć | Tak — szablon "treść usunięta" |
| Podszywanie się | Konto udające inną, realną osobę (np. znanego kucharza) | Zawieszenie konta do wyjaśnienia — **profil i wszystkie treści zostają publicznie widoczne** | Jeśli potwierdzone → blokada trwała; dopiero ona chowa profil | Panel nie ma „ukryj profil”. Ukryć da się pojedynczy wpis, przepis albo komentarz; podszywający się profil znika dopiero przy blokadzie | Tak, z prośbą o wyjaśnienie/dowód tożsamości |
| Nękanie / hejt | Seria obraźliwych komentarzy pod czyimś profilem | Usunięcie komentarzy, ostrzeżenie | Przy powtórzeniu → blokada czasowa, potem trwała | Usunąć komentarz | Tak |
| Mowa nienawiści | Treść atakująca grupę ze względu na pochodzenie, religię itp. | Natychmiastowe usunięcie, ostrzeżenie | Zwykle od razu blokada czasowa (to poważniejsza kategoria niż zwykły hejt) | Usunąć | Tak |
| Treści seksualne / nagość | Zdjęcie niezwiązane z jedzeniem, o charakterze seksualnym | Natychmiastowe usunięcie | Blokada czasowa; przy treści jednoznacznie pornograficznej — trwała | Usunąć | Tak |
| Naruszenie praw autorskich | Skopiowany 1:1 tekst przepisu z bloga/książki | Ukrycie treści, prośba o przeredagowanie | Przy uporze/powtórzeniu → usunięcie i ostrzeżenie | Najpierw ukryć (dać szansę poprawy), potem usunąć jeśli brak reakcji | Tak, z wyjaśnieniem co poprawić |
| Cudze zdjęcie podpisane jako własne | Zdjęcie z internetu/od innego użytkownika bez zgody | Usunięcie treści | Powtórka → blokada czasowa | Usunąć | Tak |
| Dane osobowe osób trzecich | Publikacja numeru telefonu/adresu innej osoby w komentarzu | Natychmiastowe usunięcie | Przy złośliwym charakterze (doxxing) → blokada trwała od razu | Usunąć | Tak |
| Niebezpieczna porada zdrowotna/żywieniowa | "Soda oczyszcza z raka", niebezpieczne przetwory bez zasad bezpieczeństwa | Ukrycie treści; wyjaśnienie wpisujesz w pole „Wiadomość do użytkownika” przy decyzji — rzeczowo, bez oskarżania | Przy uporczywym powtarzaniu → blokada czasowa | Ukryć. Serwis nie umie dopiąć „kontekstu” do treści: pod treścią, która zostaje widoczna, moderator może najwyżej napisać zwykły komentarz, jak każdy inny użytkownik | Tak, rzeczowo, bez oceniania |
| Nieletni na koncie | Wpis/profil sugerujący wiek poniżej 16 lat | Zawieszenie konta do wyjaśnienia — **profil zostaje widoczny** | Potwierdzone → trwałe zamknięcie konta (blokada), z informacją | Ukryć pojedyncze treści; profilu nie da się ukryć osobno | Tak, z wyjaśnieniem zasad wieku |
| Reklama alkoholu | Post promujący markę alkoholu (nie: przepis zawierający alkohol jako składnik) | Usunięcie posta reklamowego | Powtórka → ostrzeżenie, potem blokada | Usunąć | Tak |
| CSAM / seksualizacja dzieci | Jakakolwiek treść tego typu | **Zero tolerancji — patrz sekcja 6** | Natychmiastowe zgłoszenie do organów | Usunąć — usunięcie jest miękkie, wiersz i zdjęcie zostają w bazie jako dowód | **Powiadomienie wychodzi automatycznie przy KAŻDEJ decyzji.** Zostaw „Wiadomość do użytkownika” PUSTĄ — pójdzie wtedy samo neutralne zdanie domyślne. Poza tym nie kontaktuj się — patrz sekcja 6 |
| Groźby / zagrożenie życia | Wypowiedź wskazująca na realne zagrożenie życia (własnego lub cudzego) | **Zgłoszenie do organów — patrz sekcja 6** | — | Ukryć treść. Kopia robi się sama: ukrycie zmienia tylko status, wiersz zostaje w bazie i nic go nie kasuje | Ostrożnie, priorytet to bezpieczeństwo, nie moderacja. Powiadomienie do autora i tak wyjdzie automatycznie |

### Czego panel moderacji NIE potrafi — czytaj razem z tabelą wyżej

Kolumny „Pierwsza reakcja" i „Eskalacja" opisują politykę. Narzędzie ma dziś węższe możliwości i lepiej wiedzieć o tym przed kliknięciem, a nie po:

- **Jedno zgłoszenie = JEDNA decyzja.** Pilnuje tego indeks `moderation_actions_one_per_report` w bazie, nie tylko formularz. „Usunięcie treści **i** ostrzeżenie" to w panelu jedna decyzja: wybierasz `Usuń treść`, a ostrzeżenie mieści się w polu „Wiadomość do użytkownika". Drugiej decyzji do tego samego zgłoszenia nie zapiszesz.
- **Zawieszenie konta niczego nie chowa.** Konto zawieszone czyta serwis dalej, a jego profil, wpisy i przepisy są publicznie widoczne tak samo jak wcześniej — zawieszenie odbiera wyłącznie prawo do publikowania. Profil znika z serwisu dopiero przy blokadzie trwałej.
- **Długość zawieszenia wybierasz z listy: bez zawieszenia (pozycja domyślna), 1, 7 albo 30 dni, własny termin albo bezterminowo.** Własny termin to liczba dni od 1 do 365, którą wpisujesz w polu pod listą — przy każdym innym wyborze ta liczba jest ignorowana i nie musisz jej czyścić. Zawieszenie z terminem zdejmuje się samo; „bezterminowo" trwa do decyzji człowieka.
- **Brak wyboru NIE znaczy „bezterminowo".** Do września 2026 znaczył — czyli pomyłka przez zaniechanie dawała najsurowszą karę, jaką panel potrafi wydać. Dziś domyślnie zaznaczone jest „Bez zawieszenia", a decyzja „Zawieś konto" bez wybranego terminu nie przechodzi: formularz pyta, na jak długo, i nie traci przy tym tego, co już wpisałeś.
- **Powiadomienie o decyzji wychodzi zawsze i automatycznie** — przy ukryciu, usunięciu, ostrzeżeniu, zawieszeniu i blokadzie. Nie da się „ukarać po cichu". Puste pole „Wiadomość do użytkownika" znaczy tylko tyle, że pójdzie zdanie domyślne.
- **Panel nie pokazuje historii wcześniejszych kar autora.** Kolumna „Eskalacja" mówi „2. wystąpienie", „powtórka" — ale kolejka zgłoszeń tego nie liczy i nie wyświetla. Dziś to pamięć moderatora, nie funkcja produktu.
- **Ukryty PRZEPIS jest dla autora zamrożony.** Autor go zobaczy pod jego adresem, ale nie otworzy edycji (`RecipeStatusTransitions::BY_AUTHOR`: wiersz `hidden` jest pusty). Więc „ukryj i daj szansę poprawy" działa dla wpisu, a dla przepisu — nie. Przy prawach autorskich albo poproś o nową wersję przepisu, albo zdejmij ukrycie na czas poprawy.

---

## 3. Kolejka zgłoszeń: SLA, role, odwołania

### SLA (realistyczne dla 1–2 osób, nie 24/7)

| Priorytet | Co się kwalifikuje | Cel czasowy reakcji |
|---|---|---|
| P0 — krytyczny | CSAM, groźby zagrażające życiu, aktywny doxxing | Natychmiast po zauważeniu, maks. kilka godzin, poza kolejnością wszystkiego innego |
| P1 — pilny | Nękanie, mowa nienawiści, dane osobowe osób trzecich, nagość | W ciągu 24 godzin w dni robocze |
| P2 — standardowy | Spam, prawa autorskie, niebezpieczne porady, podszywanie | W ciągu 72 godzin |
| P3 — niski | Drobne naruszenia stylu/tonu, wątpliwe kategorie | W ciągu 7 dni, mogą czekać na tygodniowy przegląd |

**Priorytetu nie ma w narzędziu.** `reports` nie ma kolumny priorytetu, a kolejka `/admin/zgloszenia` jest posortowana od NAJNOWSZYCH. Podział P0–P3 wyżej to porządek w głowie moderatora i nic go nie wymusza: sprawa P0 sprzed dwóch dni leży niżej niż spam sprzed godziny. Praktyczny wniosek — przeglądaj całą zakładkę „Otwarte", a nie tylko jej pierwszy ekran.

**Zasada realistyczna:** przy 1–2 osobach nie da się gwarantować SLA 24/7. Ustaw oczekiwania w komunikacji z użytkownikami ("odpowiadamy zwykle w ciągu 2–3 dni roboczych") i **nie obiecuj więcej, niż jesteś w stanie dotrzymać** — niedotrzymane obietnice szkodzą zaufaniu bardziej niż szczery, dłuższy czas reakcji.

### Role

| Rola | Może |
|---|---|
| **Użytkownik** | Zgłaszać treści/konta, blokować innych użytkowników, odwoływać się od decyzji dotyczącej jego konta/treści |
| **Moderator** | Przeglądać kolejkę zgłoszeń, ukrywać/usuwać i przywracać treść, wysyłać ostrzeżenia, zawieszać konta (1, 7, 30 dni, własny termin 1-365 dni albo bezterminowo), **blokować konta trwale**, rozpatrywać odwołania, odrzucać zgłoszenia z uzasadnieniem |
| **Admin** | Dziś **dokładnie to samo co moderator** — z jednym wyjątkiem w drodze: rozstrzyganie odwołań przechodzi na samego administratora (D-039). Do czasu scalenia tamtej zmiany ta kolumna opisuje stan bez wyjątków |

**UWAGA: to nie jest podział uprawnień, tylko podział obowiązków do uzgodnienia między ludźmi.** W kodzie role `moderator` i `admin` mają identyczne możliwości — cały panel stoi za jednym pytaniem `isModerator()`, a `User::isAdmin()` nie jest dziś użyte nigdzie. Konkretnie:

- blokada trwała jest w zasięgu zwykłego moderatora,
- odwołania rozpatruje każdy, kto ma dostęp do `/admin/odwolania`,
- **ekranu audit logu nie ma w ogóle** — `audit_log` zapisuje się i da się go przeczytać wyłącznie w bazie,
- **zarządzania kontami moderatorów nie ma w panelu** — rola nadaje się w bazie.

Jeśli podział ról ma być realny, musi go najpierw zacząć egzekwować kod. Do tego czasu nie opisuj go użytkownikowi jako gwarancji.

Rekomendacja przy 1–2 osobach: **jedna osoba nie powinna być jednocześnie moderatorem i jedynym organem odwoławczym** dla własnych decyzji — jeśli to niemożliwe personalnie (mały zespół), przynajmniej **odczekaj i spójrz na sprawę drugi raz po czasie** zamiast automatycznie podtrzymywać pierwszą decyzję.

### Ścieżka odwołania

> **Ta sekcja opisuje mechanizm, który DZIAŁA W PRODUKCIE** (issue #10).
> Liczby (6 miesięcy, 7 dni roboczych, 24 godziny) siedzą w
> `config/kuking.php` → `kuking.moderation` i są egzekwowane przez kod.
> Zmieniasz je tu — zmień je i tam, inaczej znowu obiecujemy coś, czego
> system nie robi.

1. Użytkownik dostaje **powiadomienie w serwisie** o decyzji, z uzasadnieniem
   napisanym przez moderatora (Art. 17 DSA — patrz `COMPLIANCE.md` 1.1)
   i przyciskiem „Odwołanie od tej decyzji".
2. Odwołanie składa się **formularzem w produkcie**:
   - osoba aktywna albo zawieszona — z powiadomienia (`/odwolanie/{decyzja}`);
     zawieszenie nie blokuje wysłania odwołania, choć blokuje wszystko inne;
   - osoba **zablokowana** — `/odwolanie`, formularz przed logowaniem,
     zamknięty loginem i hasłem (nie loguje i nie zdejmuje blokady). Link jest
     na ekranie logowania, bo to jedyny ekran, który taka osoba zobaczy;
   - kto nie pamięta hasła — zostaje adres e-mail, wypisany na obu
     formularzach. Odwołanie z e-maila moderator wprowadza ręcznie.

   **Druga strona sprawy — ZGŁASZAJĄCY (issue #23, art. 20 ust. 1 wymienia
   wprost decyzje „o niepodjęciu działania").** Ma własną, osobną drogę:
   podpisany, wygasający link `/zgloszenie/{zgłoszenie}/odwolanie`, przysłany
   mailem razem z decyzją. **Działa to jednak WYŁĄCZNIE dla zgłoszeń
   nielegalnej treści z formularza DSA (art. 16), i tylko gdy zgłaszający
   podał adres e-mail.** Kto zgłosił treść zwykłym przyciskiem „Zgłoś" pod
   wpisem, nie dostaje dziś ani powiadomienia o decyzji, ani linku do
   odwołania — jedyne, co mu zostaje, to adres kontaktowy. To jest znana
   dziura, nie zamysł; do czasu jej zamknięcia **nie obiecuj zgłaszającemu
   ze zwykłej ścieżki, że dostanie odpowiedź automatycznie**.
3. **Termin na złożenie: 6 miesięcy od decyzji.** Art. 20 ust. 1 DSA wymaga co najmniej tyle; do 7 IX 2026 stało tu 14 dni, wzięte z rozsądku operacyjnego, nie z przepisu (patrz `docs/decyzje/DSA_POMIAR.md`). Po nim formularz mówi wprost, że
   termin minął, i kieruje na adres e-mail dla nowych okoliczności.
4. **Jedno odwołanie od jednej decyzji NA KAŻDĄ ZE STRON.** Pilnuje tego
   `UNIQUE (moderation_action_id, appellant)` w bazie (migracja
   `2026_09_07_800000_appeals_open_to_reporters`). Od jednej decyzji mogą więc
   istnieć DWA odwołania naraz — autora treści i zgłaszającego — np. przy
   ostrzeżeniu: autor uważa je za niesłuszne, zgłaszający za zbyt łagodne.
   Druga próba tej samej strony odbija się komunikatem „Odwołanie od tej
   decyzji już do nas trafiło". Przy 1–2 osobach brak limitu znaczyłby, że
   jedna sprawa potrafi zająć całą moderację na tydzień, a kolejne pismo w tej
   samej sprawie nie wnosi nowych faktów. DSA art. 20 wymaga dostępu do
   wewnętrznego rozpatrzenia skargi, nie nieskończonej liczby instancji.
5. Sprawę POWINIEN oceniać ktoś inny niż pierwotny decydent — ale **nic
   w systemie tego nie sprawdza** i przy zespole 1–2 osób nie ma jak.
   Egzekwowane jest wyłącznie to, co da się wyegzekwować: **ten sam moderator
   nie podtrzyma własnej decyzji przez 24 godziny** od jej podjęcia. Cofnąć własną
   decyzję może natychmiast — przyznanie się do pomyłki nie ma powodu czekać,
   a doba z niesłusznie ukrytą treścią szkodzi wyłącznie poszkodowanemu.
6. **Odpowiedź w ciągu 7 dni roboczych** — decyzja podtrzymana albo cofnięta,
   **zawsze z uzasadnieniem** (pole obowiązkowe, pilnuje tego także CHECK
   w bazie). Kolejka `/admin/odwolania` pokazuje termin przy każdej sprawie
   i wyróżnia te po terminie. Dni roboczych liczymy bez weekendów; świąt
   system nie zna, więc to cel operacyjny, nie zobowiązanie co do godziny.

   **KOLEJKA SAMA SIĘ ZGŁASZA (D-060, od 10 września 2026).** Nowe odwołanie
   tworzy **powiadomienie w serwisie dla kont z rolą `admin`** — czyli dla
   tych, które mogą sprawę zamknąć (D-039). W powiadomieniu stoi termin
   odpowiedzi. Moderator bez tej roli powiadomienia nie dostaje (nie może
   zamknąć sprawy), ale widzi przy pozycji „Odwołania" w menu panelu
   **licznik tego, co czeka** — tak samo jak przy „Zgłoszeniach", „Sygnałach
   automatu", „Wiadomościach do nas" i „Bez odpowiedzi".

   **POCZTA — TYLKO NA TERMIN, NIE NA KAŻDE ODWOŁANIE.** Listu w chwili
   złożenia odwołania nie ma (uzasadnienie: D-060 — wiadro 300 listów na dobę
   dzielone z rejestracjami, D-047). Raz na dobę o 07:10 chodzi natomiast
   `kuking:pilnuj-terminow-odwolan`: **jeden** list, i tylko wtedy, gdy któreś
   otwarte odwołanie ma termin odpowiedzi w progu (2 dni robocze,
   `moderation.appeal_reminder_working_days`) albo już PO terminie. List mówi,
   ile spraw wisi i do kiedy — bez treści odwołania i bez nazw ludzi.
7. Cofnięcie decyzji **realnie ją cofa**: treść wraca do stanu sprzed ukrycia
   (szkic zostaje szkicem), konto wraca do aktywnego. Odwołanie, po którym nic
   się nie zmienia, nie jest odwołaniem.
8. Odpowiedź dla AUTORA dociera powiadomieniem w serwisie; osoba zablokowana
   czyta ją **na ekranie logowania**, bo do serwisu nie wejdzie. Odpowiedź dla
   ZGŁASZAJĄCEGO idzie mailem na adres z jego zgłoszenia — konta może nie mieć
   wcale.
9. Wynik odwołania jest **ostateczny w ramach Kuking** — nie ma formalnego obowiązku ODS (Kuking jest zwolniony jako mały podmiot, patrz `COMPLIANCE.md` 1.2), ale warto **poinformować użytkownika**, że może zgłosić sprawę do UODO (dane osobowe) lub do Koordynatora ds. Usług Cyfrowych (UKE), jeśli uważa, że naruszono jego prawa — to buduje zaufanie i jest zgodne z duchem przejrzystości DSA.

---

## 4. Szablony wiadomości do użytkownika

Ton: uprzejmy, konkretny, bez pouczania, bez emocji, po polsku, zrozumiały dla każdego wieku.

**Gdzie te teksty wklejasz i czym one są.** Szablony 4.1–4.5 to treść pola „Wiadomość do użytkownika" w formularzu decyzji. Idą do autora jako **powiadomienie w serwisie**, nie mailem — kanału zwrotnego nie ma, więc żaden z nich nie może kończyć się słowem „odpisz". Osoba zablokowana powiadomienia nie przeczyta (do serwisu nie wejdzie): jej ten sam tekst wyświetla się przy próbie logowania. Szablon 4.6 dotyczy zgłaszającego i jako jedyny jedzie e-mailem — ale tylko przy zgłoszeniu nielegalnej treści z adresem (patrz §3). Szablon 4.7 to pole „uzasadnienie" przy zamykaniu odwołania.

### 4.1 Treść usunięta

> Cześć [imię/nick],
>
> Usunęliśmy Twoją treść „[tytuł/fragment]” opublikowaną [data], ponieważ narusza nasze Zasady Kuking — konkretnie: [krótki, konkretny powód, np. "zawierała link reklamowy niezwiązany z przepisem"].
>
> Jeśli uważasz, że to pomyłka, kliknij „Odwołanie od tej decyzji” w powiadomieniu w serwisie — masz na to 6 miesięcy. Przyjrzymy się sprawie jeszcze raz i odpowiemy w ciągu 7 dni roboczych.
>
> Pozdrawiamy,
> Zespół Kuking

### 4.2 Treść ukryta (niewidoczna dla innych)

**Wariant A — WPIS, który autor może poprawić:**

> Cześć [imię/nick],
>
> Ukryliśmy Twój wpis „[tytuł]” — nie jest teraz widoczny dla innych osób. Powód: [np. "wygląda na skopiowany z innej strony — napiszesz to własnymi słowami?"].
>
> Wpis nadal jest Twój: otworzysz go pod tym samym adresem co wcześniej ([adres]) i znajdziesz go w paczce „Twoje dane" w ustawieniach. Możesz go poprawić i napisać nam o tym przyciskiem „Odwołanie od tej decyzji” w powiadomieniu. Gdy uznamy sprawę za wyjaśnioną, przywrócimy wpis do stanu sprzed ukrycia — jeśli był szkicem, zostanie szkicem.
>
> Pozdrawiamy,
> Zespół Kuking

**Wariant B — PRZEPIS. Ukryty przepis jest dla autora zamrożony: zobaczy go, ale nie otworzy edycji. Nie obiecuj mu poprawiania tego samego przepisu.**

> Cześć [imię/nick],
>
> Ukryliśmy Twój przepis „[tytuł]” — nie jest teraz widoczny dla innych osób. Powód: [konkretny powód].
>
> Przepis nadal jest Twój: otworzysz go pod tym samym adresem co wcześniej ([adres]) i znajdziesz go w paczce „Twoje dane" w ustawieniach. Ukrytego przepisu nie da się jednak edytować — jeśli chcesz go poprawić, napisz nam o tym przyciskiem „Odwołanie od tej decyzji” w powiadomieniu. Zdejmiemy ukrycie, żebyś mógł nanieść zmiany.
>
> Pozdrawiamy,
> Zespół Kuking

> **CZEGO TU NIE MA I DLACZEGO.** Do 8 września 2026 stało w tym szablonie zdanie:
> „Jeśli nic się nie zmieni w ciągu 14 dni, treść zostanie usunięta". **Nic
> takiego się nie dzieje i nigdy się nie działo.** W serwisie nie ma zadania,
> które kasowałoby ukrytą treść po jakimkolwiek terminie — harmonogram
> (`routes/console.php`) sprząta osierocone zdjęcia, eksporty danych, wygasłe
> kary, konta po karencji, sygnały produktowe, `audit_log`, powiadomienia
> i przedawnione sprawy moderacyjne, i na tym koniec. Ukryta treść zostaje
> ukryta, aż zrobi z nią coś człowiek. Ukrycie ma też własny licznik czasu
> tylko w Twojej głowie: nic go nie przypomni. **Jeśli ukrywasz „na próbę",
> zapisz sobie termin sam.**
>
> Nie dopisuj tego zdania z powrotem, dopóki takiego automatu nie ma
> — a decyzja, czy w ogóle ma powstać, należy do właściciela serwisu, nie do
> moderatora. Obietnica usunięcia jest w jedną stronę nieodwracalna.

### 4.3 Ostrzeżenie

> Cześć [imię/nick],
>
> To ostrzeżenie — Twoja treść/komentarz „[fragment]” naruszył(a) nasze Zasady Kuking ([konkretny powód]). Tym razem nie blokujemy konta, ale przy kolejnym podobnym zgłoszeniu może dojść do czasowej blokady.
>
> Zasady Kuking znajdziesz tutaj: https://kuking.pl/zasady. Jeśli masz pytania, napisz do nas na kontakt@kuking.pl.
>
> Pozdrawiamy,
> Zespół Kuking

### 4.4 Zawieszenie konta (blokada czasowa)

Mów „zawiesiliśmy", nie „zablokowaliśmy" — powiadomienie, które ta osoba dostanie obok, ma tytuł „Twoje konto jest zawieszone do [data]", a „blokada" znaczy w tym serwisie coś innego i ostatecznego (szablon 4.5). Długość wybierasz z listy: 1, 7 albo 30 dni, własny termin (1-365 dni) albo bezterminowo — a „Bez zawieszenia" jest pozycją domyślną i znaczy dokładnie to, co mówi. **Zawieszenie bezterminowe nie zdejmie się samo** — wtedy nie pisz „po tym czasie konto odblokuje się samo", bo nie ma żadnego „po tym czasie".

> Cześć [imię/nick],
>
> Zawiesiliśmy Twoje konto na [1 / 7 / 30] dni (do [data]), ponieważ [konkretny powód, np. "kilka Twoich komentarzy naruszyło zasadę szacunku wobec innych — mimo wcześniejszego ostrzeżenia"].
>
> Przez ten czas możesz czytać Kuking dalej, ale nie opublikujesz wpisu ani komentarza. Po tym terminie konto odblokuje się samo. Jeśli uważasz, że to pomyłka, kliknij „Odwołanie od tej decyzji” w powiadomieniu — zawieszenie nie blokuje wysłania odwołania. Odpowiemy w ciągu 7 dni roboczych.
>
> Pozdrawiamy,
> Zespół Kuking

### 4.5 Blokada trwała

> Cześć [imię/nick],
>
> Zamknęliśmy Twoje konto na stałe. Powód: [konkretny powód, np. "wielokrotne naruszenia zasad dotyczących [...] mimo wcześniejszych ostrzeżeń" / "treść naruszająca prawo"].
>
> Jeśli uważasz, że to błąd, możesz się odwołać w ciągu 6 miesięcy: na ekranie logowania jest link „Złóż odwołanie”. Poprosimy tam o Twój login i hasło — tylko po to, żeby mieć pewność, że piszesz Ty; to nie odblokuje konta. Odpowiedź zobaczysz na tym samym ekranie logowania. Po tym terminie decyzja jest ostateczna, chyba że pojawią się nowe okoliczności.
>
> Zespół Kuking

### 4.6 Zgłoszenie odrzucone (brak naruszenia)

> Cześć [imię/nick],
>
> Sprawdziliśmy Twoje zgłoszenie dotyczące „[co zostało zgłoszone]”. Po analizie uznaliśmy, że treść nie narusza Zasad Kuking, dlatego zostaje bez zmian. [Opcjonalnie: krótkie wyjaśnienie dlaczego].
>
> Jeśli masz dodatkowe informacje, których nie uwzględniliśmy, odpisz na tego maila. Możesz też odwołać się od tej decyzji — link jest niżej w tej wiadomości, masz na to 6 miesięcy.
>
> Dziękujemy, że dbasz o Kuking.
> Zespół Kuking

**Ten szablon ma dziś zastosowanie tylko do zgłoszeń nielegalnej treści (formularz DSA, art. 16) z podanym adresem e-mail — bo tylko one wychodzą mailem, razem z linkiem do odwołania.** Osoba, która zgłosiła treść zwykłym przyciskiem „Zgłoś" pod wpisem, nie dostaje od serwisu nic: ani tej wiadomości, ani informacji, że sprawa jest zamknięta. Jeśli chcesz jej odpowiedzieć, musisz napisać maila ręcznie i wtedy „odpisz na tego maila" jest prawdą; inaczej nie obiecuj odpowiedzi.

### 4.7 Odwołanie rozpatrzone

> Cześć [imię/nick],
>
> Ponownie przeanalizowaliśmy Twoją sprawę dotyczącą [treść/konto] z [data].
>
> **Decyzja:** [podtrzymujemy poprzednią decyzję / cofamy poprzednią decyzję i przywracamy treść/konto].
>
> [Uwaga: odwołanie od jednej decyzji rozpatrujemy raz. Jeśli pojawią się nowe okoliczności, napisz do nas.]
>
> [Krótkie uzasadnienie].
>
> Dziękujemy za cierpliwość.
> Zespół Kuking

---

## 5. Spam i cold-start abuse

### Typowe wzorce (do rozpoznania szybko, "na oko")

- **Linki afiliacyjne** — post wygląda jak przepis, ale głównym elementem jest link do sklepu z kodem partnerskim.
- **Suplementy / "cudowne" produkty** — "ten proszek zmienił moje gotowanie" + link.
- **MLM** — posty o "dołącz do mojego zespołu", "sprzedawaj ze mną", zwykle w bio lub komentarzach.
- **"Zarobki z domu"** — komentarze niezwiązane z treścią posta, kierujące do zewnętrznych grup/komunikatorów.
- **Konta-boty zakładane hurtowo** — wiele kont założonych w krótkim odstępie czasu, z tym samym wzorcem bio/pierwszego posta.
- **Automatyczne komentarze-podziękowania** — identyczny tekst pod wieloma losowymi postami ("super przepis, sprawdź mój profil!").

### Progi automatyczne (propozycja do wdrożenia w kolejce moderacji)

- Konto założone <24h **i** publikujące link zewnętrzny w pierwszym poście → automatyczne oznaczenie do przeglądu (nie automatyczne usunięcie — unikać false positives dla nowych, prawdziwych użytkowników).
- >3 identyczne lub niemal identyczne komentarze w ciągu 10 minut → automatyczne ograniczenie (throttle) konta + oznaczenie do przeglądu.
- Nowe konto z linkiem w bio do domeny niezwiązanej z gotowaniem (sklep, kurs, "zarabianie") → wyższy priorytet. Uwaga: **kolejki triage dziś nie ma.** Statusy `triage` i `reviewing` istnieją w bazie, ale żaden kod ich nie nadaje — zgłoszenie idzie z `open` prosto do `resolved` albo `rejected`, a zakładka „W trakcie" w panelu jest z tego powodu zawsze pusta.
- Perceptual hash wykorzystany do wykrywania masowego wgrywania tego samego zdjęcia przez różne konta w krótkim czasie → sygnał farmy kont. Kolumna `media.perceptual_hash` jest w schemacie od pierwszej migracji, ale **nic jej dziś nie wypełnia** — pipeline zdjęć jej nie liczy. To jest więc pełne zadanie do zrobienia, nie „włączenie" czegoś gotowego.

### Rate limity — co jest ustawione, a co dopiero postulujemy

**Stan faktyczny jest w `config/kuking.php` → `kuking.limits` (zapis „liczba prób, minuty") i to on obowiązuje, nie ta lista.** Dziś: rejestracja `5,10`, komentarz `10,1`, zgłoszenie treści `10,10`, wpis `20,10`, odwołanie `5,60`. Wartości niżej to postulaty, których jeszcze nikt nie wdrożył — nie powołuj się na nie w rozmowie z użytkownikiem:

- Rejestracja: max 3 konta / IP / 24h (dziś: 5 prób na 10 minut, bez dobowego okna).
- Komentarz: max 1 na 10 sekund, max 20/godzinę dla nowego konta (<7 dni) — dziś jest jeden limit 10/minutę dla wszystkich, bez rozróżnienia na konta nowe.
- Zgłoszenie treści: max 20/dzień na użytkownika, żeby jedna osoba nie zalała kolejki (dziś: 10 na 10 minut, bez limitu dobowego).
- Publikacja posta/przepisu: bez sztywnego limitu na starcie (to spowalnia prawdziwych, aktywnych użytkowników) — ale throttle przy nagłym, nietypowym wzroście częstotliwości dla jednego konta.

Pełna lista techniczna: `SECURITY_BASELINE.md`.

---

## 6. Treści wrażliwe w kontekście kulinarnym — stanowisko produktowe

| Temat | Stanowisko Kuking |
|---|---|
| **Diety cudowne** (np. "dieta zerowa", "detoks sokowy leczy") | Nie zakazujemy dzielenia się osobistym doświadczeniem dietą, ale **moderujemy twierdzenia medyczne** ("leczy", "eliminuje chorobę"). Dodajemy neutralny komentarz/kontekst tam gdzie to możliwe, zamiast kasować całą treść — chyba że twierdzenie jest rażące i potencjalnie niebezpieczne (patrz niżej). |
| **"Soda oczyszcza z raka" i podobne pseudo-medyczne twierdzenia** | **Usuwamy** twierdzenia sugerujące, że jedzenie/dieta leczy poważną chorobę (rak, cukrzyca itp.) — to nie jest "opinia kulinarna", to potencjalnie niebezpieczna dezinformacja zdrowotna. Traktujemy jak kategorię "niebezpieczna porada zdrowotna" z katalogu naruszeń. |
| **Weki, kiszenie, przetwory domowe** | To rdzeń kultury kulinarnej naszej grupy docelowej — **nie zakazujemy**, ale przy przepisach na przetwory niskokwasowe (np. warzywa, mięso w słoikach) **dodajemy widoczne przypomnienie o ryzyku botulizmu** i linkujemy do rzetelnego źródła (np. wytyczne sanepidu/GIS). Nie cenzurujemy, edukujemy. |
| **Bimber / domowy alkohol wysokoprocentowy** | Produkcja alkoholu w Polsce poza zarejestrowaną gorzelnią jest **nielegalna** (bimber to nie to samo co wino/piwo domowe, które są legalne na użytek własny). **Nie publikujemy instrukcji destylacji** — to usuwamy niezależnie od intencji autora, bo promuje czyn zabroniony. Wino domowe, piwo domowe, nalewki na bazie kupionego alkoholu — dozwolone. |
| **Grzyby (zbieractwo, przetwory)** | Wysokie ryzyko realnej szkody (zatrucia grzybami są częste i poważne w Polsce). Nie zakazujemy przepisów na grzyby **kupione/pewne gatunki**, ale przy treściach sugerujących samodzielne rozpoznawanie gatunków w lesie **dodajemy przypomnienie** o konsultacji z klasyfikatorem/sanepidem. Nie jesteśmy od diagnozowania gatunków — to nie jest odpowiedzialność moderatora. |
| **Surowe mięso / ryby, żywienie niemowląt** | Tematy wysokiego ryzyka (salmonella, alergie u niemowląt) — **nie cenzurujemy** dzielenia się doświadczeniem, ale nie promujemy takich treści w rekomendacjach/na start stronie bez wyraźnego kontekstu. Reagujemy na zgłoszenia jak na "niebezpieczną poradę", jeśli treść brzmi jak rekomendacja ogólna, a nie osobista anegdota. |
| **Reklama alkoholu** | Rozróżniamy: **przepis zawierający alkohol jako składnik** (dozwolone, normalne w kuchni) vs. **reklama konkretnej marki/promocja spożycia** (niedozwolone — to inna kategoria niż przepis, i dodatkowo w Polsce reklama alkoholu podlega osobnym ograniczeniom ustawowym poza zakresem tego dokumentu — [do weryfikacji z prawnikiem, jeśli kiedykolwiek pojawi się współpraca z markami alkoholowymi]). |

**Czym dziś jest „dodajemy przypomnienie" i „dodajemy kontekst".** Serwis nie ma żadnego mechanizmu doklejania ostrzeżeń do treści: nie ma banera przy przepisach na przetwory, nie ma flagi „temat wrażliwy", nie ma automatycznego linku do wytycznych GIS. Wszystko, co wyżej brzmi jak funkcja produktu, jest dziś robotą ręczną i ma dokładnie dwie drogi: **zwykły komentarz moderatora pod treścią** (piszesz go jak każdy użytkownik) albo **prośba do autora o uzupełnienie własnego tekstu**, wysłana w polu „Wiadomość do użytkownika". Redakcyjnie te ostrzeżenia wchodzą też wprost w treść przepisów startowych — patrz `docs/decyzje/PRZEGLAD_BEZPIECZENSTWA_ZYWNOSCI.md`.

**Zasada ogólna:** moderacja treści kulinarnych wrażliwych działa w trybie **"edukacja, nie cenzura"** wszędzie, gdzie ryzyko jest realne, ale niekrytyczne (kiszenie, grzyby, diety) — i w trybie **"usuwamy"** tam, gdzie ryzyko jest poważne i bezpośrednie (botulizm z konkretnej niebezpiecznej metody, twierdzenia medyczne, nielegalna działalność jak destylacja).

---

## 7. Zdjęcia — procedury szczególne

| Sytuacja | Procedura |
|---|---|
| **Nagość / treści seksualne** | Natychmiastowe usunięcie po zgłoszeniu lub wykryciu. Ostrzeżenie za pierwszym razem (o ile jednoznacznie nie CSAM — patrz niżej), blokada przy powtórce. |
| **Przemoc na zdjęciach** | Ocena kontekstu — zdjęcie polowania/uboju w kontekście kulinarnym nie jest automatycznie zakazane, ale drastyczne, celowo szokujące treści usuwamy. Brak automatyzmu — to wymaga oceny człowieka. |
| **Cudze zdjęcie podpisane jako własne** | Usunięcie + wiadomość do autora (szablon 4.1). Właściciela oryginału serwis powiadomi sam tylko wtedy, gdy zgłosił rzecz formularzem „Zgłoś treść niezgodną z prawem" i podał adres e-mail; po zwykłym „Zgłoś" pod zdjęciem nie dostanie nic i trzeba napisać do niego ręcznie. |
| **CSAM (treści przedstawiające seksualne wykorzystywanie dzieci)** | **Procedura zero-tolerancji — patrz niżej, osobno.** |

### 7.1 Procedura zero-tolerancji — CSAM i zagrożenie życia

To jedyna sytuacja, w której **nie stosujemy** standardowej ścieżki "ostrzeżenie → blokada". Działamy natychmiast.

1. **Nie rozpowszechniaj, nie kopiuj, nie przesyłaj dalej** podejrzanej treści (nawet wewnętrznie, np. e-mailem między moderatorami) — to samo w sobie może być czynem karalnym. Ogranicz dostęp do minimum: w panelu wybierz `Usuń treść` i **nie ściągaj pliku na dysk lokalny**. Statusu `quarantine` w tym serwisie NIE MA — CHECK w bazie dopuszcza dla wpisu i przepisu wyłącznie `draft`, `published`, `hidden`, `removed`, a dla komentarza `published`, `hidden`, `removed`. Nie próbuj też ustawiać statusu ręcznie w bazie: `Usuń treść` robi miękkie usunięcie (`deleted_at`), zdejmuje treść z serwisu i zostawia komplet danych na potrzeby zgłoszenia.
2. **Natychmiast ukryj/usuń treść z widoku publicznego** (techniczne wyłączenie widoczności), ale **zachowaj metadane** (ID treści, ID konta, timestamp) potrzebne do zgłoszenia — nie kasuj rekordu z bazy przed zgłoszeniem organom.
3. **Zgłoś natychmiast do organów:**
   - Polska: **Dyżurnet.pl** (zespół NASK ds. nielegalnych treści w internecie, w tym CSAM) — zgłoszenie online, działa całodobowo jako punkt przyjęcia zgłoszeń.
   - Oraz/lub bezpośrednio **Policja** (997 lub najbliższa jednostka), jeśli sytuacja wskazuje na trwające zagrożenie/wykorzystywanie dziecka.
   - [do weryfikacji z prawnikiem: czy jako dostawca hostingu Kuking ma dodatkowy obowiązek zgłoszenia do konkretnego organu na mocy Art. 18 DSA lub przepisów krajowych implementujących walkę z CSAM — potwierdzić dokładną ścieżkę **przed startem**, nie w trakcie realnego incydentu].
4. **Zablokuj konto autora natychmiast i trwale**, bez wysyłania standardowego szablonu z uzasadnieniem szczegółowym — wystarczy neutralna informacja "Twoje konto zostało trwale zablokowane z powodu naruszenia prawa" (nie opisuj szczegółowo powodu w komunikacji z użytkownikiem — to może zaszkodzić postępowaniu, jeśli sprawa trafi do organów).
5. **Nie kontaktuj się z autorem w żaden inny sposób i nie próbuj samodzielnie "wyjaśniać sprawy"** — to zadanie organów ścigania, nie moderacji.
6. **Udokumentuj wewnętrznie** fakt zgłoszenia (data, do kogo, numer referencyjny jeśli dostępny) — potrzebne na wypadek pytań regulatora.
7. **Zagrożenie życia (np. groźby samobójcze, groźby wobec innej osoby)** — analogicznie: zachowaj treść, zgłoś do odpowiednich służb (Policja 112/997, w przypadku zagrożenia suicydalnego można też wskazać użytkownikowi telefon zaufania — 116 123 Centrum Wsparcia dla osób w kryzysie psychicznym — w odpowiedzi, jeśli sytuacja na to pozwala i nie utrudnia to działań służb).

**To jedyna kategoria w tym dokumencie, gdzie "szybciej i ostrożniej" zawsze wygrywa z "poczekajmy i sprawdźmy dokładniej".**

---

## 8. Wypalenie moderatora — limity i rotacja

Przy 1–2 osobach moderacja treści wrażliwych (zwłaszcza zdjęć i opisów) jest realnym obciążeniem psychicznym, nawet w portalu kulinarnym (zgłoszenia hejtu, nękania, sporadycznie zdjęcia nieodpowiednie).

- **Limit czasu ciągłej pracy nad kolejką zgłoszeń:** rekomendacja max 60–90 minut jednorazowo, potem przerwa — szczególnie przy kategoriach P0/P1.
- **Rotacja:** jeśli zespół ma 2 osoby, **na zmianę** przejmować przegląd kolejki tydzień po tygodniu, zamiast dzielić po kategoriach — zmniejsza to skumulowaną ekspozycję jednej osoby na najgorsze treści.
- **Co odłożyć do automatu, żeby oszczędzić czas człowieka:**
  - wykrywanie duplikatów zdjęć (perceptual hash) — automatyczne oznaczanie, człowiek tylko potwierdza,
  - filtrowanie oczywistego spamu (linki afiliacyjne wg listy domen) — automatyczne ukrycie do przeglądu, nie wymaga pełnej analizy człowieka za każdym razem,
  - proste rate-limity (sekcja 5) — działają bez udziału moderatora,
  - szablony odpowiedzi (sekcja 4) — nie pisać za każdym razem od nowa.
  **Z tej listy działa dziś jedno: rate-limity** (`config/kuking.php` → `kuking.limits`) i szablony, które właśnie czytasz. Wykrywania duplikatów zdjęć nie ma (`media.perceptual_hash` nikt nie wypełnia), listy domen spamerskich ani automatycznego ukrywania do przeglądu nie ma wcale — całą kolejkę przegląda dziś człowiek, sztuka po sztuce.
- **Co NIE powinno nigdy trafiać do pełnej automatyzacji bez człowieka:** decyzje o blokadzie trwałej konta, każda sprawa P0 (CSAM/zagrożenie życia — wymaga świadomej decyzji człowieka o zgłoszeniu do organów), odwołania.
- **Wsparcie:** jeśli moderator natrafi na szczególnie ciężką treść (CSAM, przemoc), **nie zostawiaj tego bez rozmowy** — nawet krótka wymiana z drugą osobą w zespole po fakcie pomaga. To nie jest slabość, to standard branżowy w trust & safety.

---

## Źródła

- [Regulation (EU) 2022/2065 — Digital Services Act, Art. 16–18 (notice and action, statement of reasons, notification of suspicions of criminal offences), EUR-Lex](https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX%3A32022R2065)
- [Dyżurnet.pl — zespół NASK ds. zgłaszania nielegalnych treści w internecie](https://www.dyzurnet.pl/)
- [Czy przepisy kulinarne chroni prawo autorskie — Prawo.pl](https://www.prawo.pl/biznes/czy-przepisy-kulinarne-chroni-prawo-autorskie,512468.html)
- Powiązane: `docs/legal/COMPLIANCE.md` (podstawy prawne DSA/RODO cytowane w tym dokumencie)
- Wewnętrzne źródło produktowe: `docs/MODERATION.md` (założenia produktowe, na których oparto ten podręcznik)
- Kod, który egzekwuje terminy i decyzje opisane wyżej: `config/kuking.php` (`kuking.moderation`), `app/Domain/Moderation/`, `app/Http/Controllers/Admin/ModerationController.php`, `app/Http/Controllers/Admin/AppealController.php`, `routes/console.php` (harmonogram)
