# Kuking — BRAND_EXTENDED

Rozszerzenie `docs/BRAND.md`. Nie zastępuje go — dokłada słownik operacyjny, którym da się pisać
interfejs, e-maile i komunikaty moderacyjne bez zwoływania narady o każde słowo.

Zasada nadrzędna: **piszemy o jedzeniu i o ludziach, nie o platformie.**
Test kontrolny dla każdego napisu: *czy Basia (61) przeczyta to raz i będzie wiedziała, co zrobić?*

---

## 1. Słownik marki — jak nazywamy rzeczy

### 1.1 Obiekty

| Obiekt | Nazwa obowiązująca | Nigdy | Uwaga |
|---|---|---|---|
| Zdjęcie + kilka słów | **danie** (w interfejsie), **wpis** (w dokumentacji) | „post", „publikacja", „content", „relacja" | „Danie" jest zrozumiałe bez tłumaczenia. „Wpis" zostaje językiem technicznym. |
| Pełny przepis | **przepis** | „receptura", „karta przepisu" | — |
| Wykonanie cudzego przepisu | **Ugotowałem** | „review", „ocena", „recenzja", „try" | Nazwa własna funkcji, pisana z wielkiej litery, bez cudzysłowu w interfejsie. |
| Zbiór zapisanych przepisów | **Zeszyt** | „kolekcja" (w interfejsie), „biblioteka", „wishlista", „ulubione" | „Zeszyt" to najmocniejsze słowo, jakie ma ten produkt: dokładnie tam ludzie 50+ trzymają przepisy dzisiaj. W dokumentacji technicznej pozostają `collections`. |
| Pojedynczy zbiór tematyczny | **półka** (w Zeszycie) | „tag", „folder", „board" | np. „Zeszyt → półka Zupy”. Do rozstrzygnięcia w testach; alternatywa: **rozdział**. [do weryfikacji] |
| Strona główna | **Start** | „feed", „dla Ciebie", „home", „tablica" | Zgodne z prototypem i `docs/UX_50_PLUS.md`. |
| Moje rzeczy | **Moje** / **Twoje dania** | „Twoje treści", „Moje publikacje", „profil twórcy" | — |
| Osoba | **osoba**, **ktoś**, imię użytkownika | „user", „profil", „creator", „twórca", „influencer" | — |
| Zdjęcie | **zdjęcie** | „fotka", „grafika", „media", „asset" | — |
| Powiadomienia | **Powiadomienia** | „aktywność", „notyfikacje", „alerty" | — |
| Wyszukiwanie | **Szukaj** | „Explore", „Odkrywaj", „Discover" | — |
| Konto | **Konto** | „ustawienia profilu twórcy" | — |
| Dział, w którym prosi się innych o radę | **Poradźcie** | „Forum", „Pytania" (jako napis na ekranie), „Q&A", „Hotline", „off-topic" | Nazwa własna, czasownik użyty jak w „Ugotowałem" — świadome odstępstwo od testu 3 z §3. Warunek z D-147: pod nagłówkiem **musi** stać zdanie „Ktoś to już robił i chętnie powie, jak." Dział jeszcze nie zbudowany (issue #372); adres zostaje `/pytania`. D-163. |

### 1.2 Akcje (etykiety przycisków)

| Akcja | Etykieta | Nigdy |
|---|---|---|
| Publikacja dania | **Opublikuj** | „Wyślij", „Podziel się", „Share" |
| Dodanie zdjęcia | **Dodaj zdjęcie** | „Upload", „Wybierz plik", „Załącz media" |
| Zapisanie do Zeszytu | **Zapisuję** | „Zapisz", „Dodaj do ulubionych", „Bookmark", „Pin" |
| Obserwowanie osoby | **Obserwuj** / **Obserwujesz** | „Follow", „Subskrybuj", „Dodaj do znajomych" |
| Zaprzestanie | **Przestań obserwować** | „Unfollow" |
| Zgłoszenie „ugotowałem to" | **Ugotowałem** | „Zrobiłem to!", „Wypróbowałem", „Done" |
| Komentarz | **Komentuj** / **Napisz komentarz** | „Odpowiedz na content", „Zabierz głos" |
| Odpowiedź w wątku | **Odpowiedz** | — |
| Zapis szkicu | dzieje się samo; komunikat **Szkic zapisany.** | „Autosave aktywny" |
| Kolejny krok kreatora | **Dalej** / **Wstecz** | „Next", „Kontynuuj" |
| Usunięcie | **Usuń** (+ potwierdzenie) | „Skasuj", „Wyrzuć", „Archiwizuj" jako eufemizm |
| Rezygnacja z akcji | **Zostaw** / **Anuluj** | „Nie, dzięki", „Może później" |
| Eksport danych | **Pobierz swoje dane** | „Export", „Backup", „Zrzut" |
| Zgłoszenie treści | **Zgłoś** | „Report", „Flaguj" |
| Zablokowanie osoby | **Zablokuj** | „Mute", „Wycisz" (to inna funkcja — jeśli powstanie, nazywa się **Ukryj**) |
| Zamknięcie okna | **Zamknij** | „×" bez etykiety przy ważnych akcjach |

### 1.3 Stany i komunikaty

| Stan | Formuła |
|---|---|
| Sukces | **Zapisano.** / **Opublikowano.** / **Szkic zapisany.** — kropka, bez wykrzyknika |
| Trwa | **Dodaję zdjęcie…** — z widocznym postępem, jeśli trwa dłużej niż 2 s |
| Pusto | zdanie o tym, **co się tu pojawi**, plus jeden przycisk z tekstem |
| Błąd | **co się nie udało + dlaczego + co zrobić**, w tej kolejności |
| Brak uprawnień | **Ta strona jest widoczna tylko dla zalogowanych.** |
| Prywatność | **Kto to widzi: wszyscy / tylko obserwujący / tylko ja** |

---

## 2. Słowa zakazane

### 2.1 Zakaz bezwarunkowy — nie występują w interfejsie, e-mailach ani w marketingu

`content` · `explore` · `discover` · `engage` / `engagement` · `creator` / `twórca treści` ·
`influencer` · `feed` · `tapnij` / `tap` · `swipe` · `like` (jako czasownik: „lajkuj") ·
`share` · `upload` · `stories` · `reels` · `challenge` · `onboarding` (w tekstach dla użytkownika) ·
`dashboard` · `hub` · `Creator Hub` · `community` (po angielsku) · `user` · `UX` ·
`smart` · `AI-powered` · `zoptymalizowany` · `dedykowany` · `platforma` (o sobie) ·
`ekosystem` · `rozwiązanie` · `synergia` · `wartość dodana` · `must-have` · `game changer` ·
`must try` · `foodie` · `foodporn` · `pyszota` · `mniam` · `smakuje jak u mamy™` ·
`serie` / `passa` / `streak` · `punkty` · `poziom` · `odznaka` · `ranking użytkowników` ·
`ekspert` (jako etykieta użytkownika) · `senior` · `seniorzy` · `dla starszych` ·
`łatwy nawet dla…` · `intuicyjny` · `wystarczy jedno kliknięcie` (bo nigdy nie wystarcza)

Powody, jednym zdaniem każdy: żargon angielski wyklucza; żargon korporacyjny brzmi jak
regulamin; mechaniki grywalizacyjne (`passa`, `punkty`, `poziom`) produkują wstyd u osoby,
która ma inne priorytety niż aplikacja; słowo `senior` łamie regułę z `docs/UX_50_PLUS.md`, że
produkt nie jest oznaczany jako „dla seniorów"; `foodie`/`mniam` infantylizuje.

### 2.2 Co z „feed"?

**Zakazane w interfejsie i w komunikacji.** Dopuszczone w kodzie, dokumentacji technicznej,
nazwach klas i endpointów (`FeedController`, `feed_items`) — tam jest terminem, nie napisem.
Dla użytkownika: **Start**, a w opisach: **„co gotują osoby, które obserwujesz"**.

### 2.3 Zakazy warunkowe

| Słowo | Kiedy wolno |
|---|---|
| `kolekcja` | tylko w dokumentacji i kodzie; w interfejsie **Zeszyt** |
| `moderacja` | w regulaminie i w centrum pomocy; w komunikacie do użytkownika: **„zgłoszenie", „decyzja", „zasady"** |
| `społeczność` | wolno, po polsku, oszczędnie — nie w każdym nagłówku |
| `przepis dnia`, `polecane` | tylko jeśli naprawdę jest za tym redakcja lub kryterium; nigdy jako maska dla algorytmu |
| `Ty` z wielkiej litery | w e-mailach i komunikatach osobistych: tak; w etykietach przycisków: nie |
| emoji | nigdzie w produkcie; wyjątkowo w materiałach drukowanych i w social media (nie w powiadomieniach push) |

### 2.4 Forma gramatyczna

- **Preferujemy formy bezosobowe i rzeczownikowe w etykietach:** „Dodaj zdjęcie", „Zapisano".
- **W zdaniach zwracamy się per „Ty", z wielkiej litery**, bez zdrobnień i bez rozkazującego tonu.
- **Nie zakładamy płci** tam, gdzie to możliwe: „Zapisano", nie „Zapisałeś". Wyjątkiem jest claim główny „Pokaż, co dziś ugotowałeś" — jest utrwalony w `docs/BRAND.md` i zostaje. Wariant równoległy do materiałów, gdzie odbiorcą jest wprost kobieta: **„Pokaż, co dziś ugotowałaś"**.
- **Liczby zapisujemy cyframi** („8 komentarzy"), jednostki pełnym słowem („15 MB", „40 minut", nie „40'").

---

## 3. Zasady nazywania nowych funkcji

Nowa funkcja przechodzi pięć testów. Nie przechodzi choćby jednego → nazwa wraca do pracowni.

1. **Test babci przy telefonie.** Czy osoba, która nigdy nie widziała tej funkcji, zgadnie z samej nazwy, co się stanie po naciśnięciu? Jeśli trzeba tooltipa, nazwa jest zła.
2. **Test polskiego słownika.** Czy słowo istnieje w polszczyźnie i znaczy dokładnie to? Kalki (`kolekcjonuj`, `submituj`, `postuj`) odpadają.
3. **Test czasownika.** Akcje nazywamy czasownikami w trybie rozkazującym („Zapisz", „Obserwuj"), obiekty — rzeczownikami („Zeszyt", „Przepis", „Danie"). Bez mieszania.
4. **Test długości.** Etykieta przycisku maks. 2 słowa, maks. 16 znaków; musi zmieścić się w przycisku 48 px wysokości bez zawijania na ekranie 320 px.
5. **Test kuchni.** Czy słowo pochodzi z kuchni albo z domu, a nie z informatyki i marketingu? Jeśli ma dwóch kandydatów, wygrywa ten z kuchni. („Zeszyt" bije „Kolekcję". „Ugotowałem" bije „Wypróbowałem".)

Dodatkowo:
- **Nazwa funkcji jest jedna i nie ma synonimów.** Jeśli w jednym miejscu jest „Zapisz", to nigdzie nie ma „Dodaj do Zeszytu". Rejestr nazw prowadzi się w tym pliku, nie w głowach.
- **Nazwy własne funkcji piszemy z wielkiej litery i nie odmieniamy dziwnie:** „dodaj Ugotowałem", „trzy razy Ugotowałem". Nie: „ugotowałemy", „ugotowałemów".
- **Nie tworzymy marek wewnętrznych.** Żadnego „Kuking Premium", „Kuking Pro", „Kuking Studio" bez twardej potrzeby handlowej.

---

## 4. Nagłówki — dobre i złe

| Kontekst | Dobrze | Źle | Dlaczego źle |
|---|---|---|---|
| Ekran główny | **Co dziś ugotowałeś?** | „Odkryj tysiące inspiracji kulinarnych" | Obietnica bazy danych, nie rozmowy. Kuking nie jest bazą. |
| Rejestracja | **Załóż konto.** | „Dołącz do społeczności pasjonatów gotowania!" | Wykrzyknik, „pasjonaci" wyklucza tych, którzy po prostu gotują obiad. Dopisek „Zajmie minutę" wyjęty 11.09.2026: obietnica z miarą, której nie mierzymy. |
| Pierwszy wpis | **Wystarczy zdjęcie i kilka słów.** | „Stwórz swój pierwszy content!" | Żargon + presja tworzenia. |
| Zeszyt | **Twój zeszyt z przepisami.** | „Zarządzaj swoją biblioteką treści" | Zarządzanie to praca, nie kuchnia. |
| Ugotowałem | **Ktoś to naprawdę ugotował.** | „Zobacz oceny i recenzje użytkowników" | „Recenzja" i „użytkownik" to język serwisu zakupowego. |
| Przepis | **Pomidorowa z własnych pomidorów** | „TOP 10 NAJLEPSZYCH ZUP 2026!!!" | Clickbait; tracimy zaufanie w jednym nagłówku. |
| Eksport danych | **Pobierz swoje dane.** | „Eksport danych w formacie JSON zgodnie z RODO" | Prawdziwe, ale nieczytelne. Szczegół techniczny idzie pod spód, małym drukiem. |
| Strona o nas | **Prowadzimy to na własną rękę.** | „Kuking to innowacyjna platforma social foodtech" | — |
| Onboarding, koniec | **Gotowe.** | „Świetnie! Jesteś gotowy na kulinarną przygodę!" | Dwa wykrzykniki i metafora, której nikt nie prosił. |
| Pusty Zeszyt | **Zeszyt jest pusty.** | „Ojej, tu jeszcze nic nie ma :(" | Emotikon, bezradność. |
| 404 | **Tej strony nie ma.** | „Ups! Zgubiliśmy się w kuchni!" | Żart zamiast informacji. Osoba szuka drogi, nie dowcipu. |

**Reguły nagłówka:**
- jedno zdanie, maks. 8 słów, kropka albo znak pytania;
- czasownik na początku, jeśli to wezwanie do działania;
- zero wykrzykników (twardy zakaz w produkcie; w druku maks. jeden na materiał);
- zero wielkich liter w środku zdania i zero CAPS LOCKA poza wordmarkiem `KUKING`;
- nagłówek nigdy nie jest jedynym nośnikiem informacji — pod nim jest zdanie wyjaśniające i akcja z tekstem.

---

## 5. Ton e-maili

### 5.1 Zasady

| Reguła | Konkret |
|---|---|
| Jeden e-mail = jeden powód | Jeśli są dwa powody, są dwa e-maile albo żaden. |
| Temat mówi, co jest w środku | „Z Twoich przepisów gotowano w tym tygodniu 4 razy" — nie „Zobacz, co nowego w Kuking!" |
| Nadawca ma imię i nazwisko człowieka | np. „Kuking — <imię właściciela>", adres `kontakt@kuking.pl`, nie `noreply@`. Odpisanie musi być możliwe. |
| Jedna akcja, jeden przycisk | Przycisk z tekstem, min. 44 px wysokości, min. 16 px czcionki. |
| Działa bez obrazków | Cała treść czytelna przy zablokowanej grafice. Logo jako PNG z tekstem alternatywnym „Kuking". |
| Rezygnacja jest widoczna | Link „Wypisz się z tych e-maili" u dołu, tym samym rozmiarem co reszta stopki, nie jaśniejszym szarym 9 px. |
| Częstotliwość jest umową | Domyślnie: powiadomienia o rzeczach, które dotyczą użytkownika + jeden tygodniowy podsumowujący. Nic więcej bez zgody. |
| Zero presji | Nigdy nie liczymy dni nieobecności, nie chwalimy się cudzą aktywnością „a Basia dodała już 12 dań", nie budujemy pilności („zostały 2 dni"). |

### 5.2 Wzorce

**Powiadomienie (transakcyjne)**
> Temat: Marek ugotował Twoją pomidorową
>
> Marek ugotował Twoją pomidorową i dodał zdjęcie. Napisał, że zrobi jeszcze raz.
>
> `[ Zobacz, jak wyszła ]`
>
> Dostajesz ten e-mail, bo ktoś ugotował Twój przepis. Możesz to wyłączyć w Koncie.

**Tygodniowe podsumowanie**
> Temat: Z Twoich przepisów gotowano w tym tygodniu 4 razy
>
> W tym tygodniu cztery osoby ugotowały coś z Twoich przepisów — najczęściej pomidorową.
> Do Zeszytu zapisano Twoje dania 11 razy.
>
> `[ Zobacz, jak im wyszło ]`

**Po długiej przerwie (maks. raz na kwartał)**
> Temat: Twój zeszyt czeka
>
> Dawno Cię tu nie było i to zupełnie w porządku. Wszystko jest na miejscu.
>
> `[ Zajrzyj do swoich dań ]`

**Zakazane tematy e-maili:** „Tęsknimy!", „Nie zapomnij o nas", „Twoje konto wkrótce
straci…", „Ostatnia szansa", „Zobacz, co przegapiłeś", „🔥 Gorące przepisy tygodnia".

---

## 6. Ton komunikatów moderacyjnych

Zasada: **oddziel czyn od człowieka.** Piszemy o treści i o zasadzie, nigdy o charakterze osoby.
Każdy komunikat ma cztery elementy w tej kolejności: **co się stało → której zasady dotyczy →
co to zmienia → co można zrobić.** I zawsze jest droga odwoławcza.

| Sytuacja | Komunikat |
|---|---|
| Zgłoszenie przyjęte | Dziękujemy za zgłoszenie. Sprawdzimy je w ciągu 48 godzin. O decyzji napiszemy. |
| Treść ukryta na czas sprawdzenia | Ten komentarz jest chwilowo ukryty, bo ktoś go zgłosił. Sprawdzamy. Nic nie zostało usunięte. |
| Treść usunięta | Usunęliśmy Twój komentarz z 3 marca, bo zawierał obraźliwe określenie innej osoby. Zasada: „Rozmawiamy o jedzeniu, nie o ludziach". Twoje konto działa normalnie. Jeśli uważasz, że to pomyłka, odpisz na tego e-maila. |
| Ostrzeżenie | To drugie usunięcie w ciągu miesiąca. Przy kolejnym musimy na tydzień wyłączyć możliwość komentowania. Piszemy o tym wprost, żeby nie było zaskoczenia. |
| Ograniczenie funkcji | Na tydzień wyłączyliśmy Twoje komentarze. Możesz dalej dodawać dania i przepisy, a Twój zeszyt jest nietknięty. Komentarze wrócą 12 marca. |
| Blokada konta | Zablokowaliśmy Twoje konto, ponieważ [konkretny powód]. Twoje dane możesz pobrać przez 30 dni: `[ Pobierz swoje dane ]`. Odwołanie: odpisz na tego e-maila. |
| Zgłoszenie odrzucone | Sprawdziliśmy zgłoszony komentarz i nie łamie on naszych zasad. Rozumiemy, że mógł być nieprzyjemny. Możesz zablokować tę osobę: wtedy nie zobaczysz jej treści. |
| Spór między osobami | Nie rozstrzygamy, kto ma rację w kuchni. Rozstrzygamy tylko to, czy ktoś złamał zasady. Tutaj nikt nie złamał. |
| Fałszywe „Ugotowałem" | Usunęliśmy to „Ugotowałem", bo zdjęcie pochodzi z innej strony. „Ugotowałem" ma znaczyć, że ktoś naprawdę to ugotował — na tym opiera się cały serwis. |

**Nigdy w moderacji:**
- „naruszyłeś regulamin" bez wskazania **którego punktu** i **czym**;
- „Twoje zachowanie", „jesteś", „użytkownicy tacy jak Ty" — ocena osoby zamiast czynu;
- sarkazm, żart, emotikon, maskotka;
- strona bierna kryjąca sprawcę: „treść została usunięta" — piszemy **„usunęliśmy"**;
- „decyzja jest ostateczna" bez podania drogi odwoławczej;
- kody i identyfikatory zgłoszeń jako jedyna treść.

---

## 7. Trzy propozycje hasła dodatkowego

Obok istniejących: **„Pokaż, co dziś ugotowałeś"** (claim główny) i **„Gotujemy po swojemu"** (drugi).

### H1. **Przepis jest dobry, kiedy ktoś go ugotował.**
- **Do czego:** strona o „Ugotowałem", materiały wyjaśniające, SEO na stronach przepisów, prasa.
- **Dlaczego działa:** wypowiada wprost regułę nr 5 z `docs/PRODUCT.md` — „Ugotowałem jest silniejszym sygnałem jakości niż like" — i robi z niej pozycjonowanie przeciw wszystkim bazom przepisów naraz. To jedyne zdanie w zestawie, które mówi, **czym Kuking różni się od Google'a**.
- **Ryzyko:** dłuższe niż claim główny, nie nadaje się na baner ani na koszulkę.

### H2. **Twój zeszyt i ludzie, którzy gotują.**
- **Do czego:** onboarding, opis w sklepie PWA, meta description, ulotka.
- **Dlaczego działa:** w siedmiu słowach oddaje oba filary z `docs/PRODUCT.md` — własne archiwum **i** społeczność — używając najmocniejszego słowa produktu („zeszyt") oraz frazy z README („ludzie, którzy naprawdę gotują"). Nie obiecuje inspiracji, obiecuje dwie konkretne rzeczy.
- **Ryzyko:** brzmi opisowo, nie sprzedażowo. To zaleta w tej grupie, ale nie zadziała jako hasło reklamowe.

### H3. **Tu się naprawdę gotuje.**
- **Do czego:** hasło krótkie, do sygnetu, do naklejki, do banera, do podpisu w social media.
- **Dlaczego działa:** cztery słowa, dwuznaczność sympatyczna i po polsku — „naprawdę gotuje" znaczy jednocześnie „prawdziwe, nie na pokaz" i „dzieje się tu ruch". Trafia w rdzeń pozycjonowania („nie kolejna baza anonimowych przepisów") bez ani jednego słowa o technologii.
- **Ryzyko:** bez kontekstu wizualnego może być czytane jako opis restauracji.

### H4 (warunkowe, wyłącznie do gadżetów) — **Każdy jest królem swojej kuchni.**
- **Do czego:** fartuch, kubek, naklejka, jeden akapit na stronie „O nas" wyjaśniający koronę w znaku.
- **Dlaczego istnieje:** to jedyne miejsce, w którym wolno powiedzieć wprost o „KING" w „KUKING".
- **Dlaczego jest warunkowe:** **w produkcie zakazane.** Jest komplementem dla użytkownika, a to rejestr, przed którym ostrzega `MASCOT_CONCEPT.md` (sekcja 6.3). Żart o koronie ma być odkrywany, nie ogłaszany. Wolno na fartuchu, nigdy w interfejsie ani w powiadomieniu.

**Nie używamy jako haseł:** „Gotuj z pasją", „Smak domu", „Kulinarne inspiracje", „Zainspiruj się",
„Twoja kulinarna korona", „Zostań królem kuchni", „Gotowanie to sztuka", „Jak u mamy".

---

## 8. Szybka lista kontrolna przed publikacją tekstu

1. Czy jest w tym choć jedno słowo z listy zakazanej (2.1)?
2. Czy jest wykrzyknik? Czy jest emoji?
3. Czy nazwa funkcji zgadza się ze słownikiem (1.1, 1.2) i nie ma synonimu w innym miejscu?
4. Czy przy błędzie napisaliśmy **dlaczego** i **co zrobić**?
5. Czy przycisk ma tekst, a nie samą ikonę?
6. Czy zdanie zmieści się na ekranie 320 px przy tekście powiększonym do 200%?
7. Czy komunikat mówi o jedzeniu i ludziach, a nie o platformie i treściach?
8. Czy cokolwiek tutaj sugeruje wiek odbiorcy?
9. Czy maskotka nie mówi w pierwszej osobie?
10. Czy da się to przeczytać na głos bez zażenowania osobie, która gotuje od czterdziestu lat?

---

## Źródła

Dokumenty wewnętrzne (podstawa tego rozszerzenia):
- `docs/BRAND.md` — claim główny, drugi claim, osobowość marki, wstępna lista „unikać"
- `docs/PRODUCT.md` — obiekty (wpis, przepis, Ugotowałem), zasady, persony, North Star
- `docs/UX_50_PLUS.md` — typografia, kontrolki, błędy, autosave, zakaz oznaczania „dla seniorów"
- `README.md` — pozycjonowanie „ludzie, którzy naprawdę gotują", zakres MVP
- `prototype/index.html`, `prototype/assets/styles.css` — istniejąca estetyka i realne etykiety
- `MASCOT_CONCEPT.md` (ten katalog) — ton głosu, zakazy wokół korony, 16 mikro-tekstów

Zewnętrzne, dla tonu i antywzorców:
- Duolingo — pasywno-agresywna retencja jako antywzorzec: https://www.universityxp.com/news/2025/7/25/we-havent-seen-you-in-a-while-duolingos-passive-aggressive-strategy-for-keeping-users-hooked
- Duolingo — dark patterns i cyfrowe poczucie winy: https://opinionsandconditions.substack.com/p/duolingo-owl-dark-patterns-digital-guilt
- „Toy-like" interfejsy odbierane jako infantylizujące — projektowanie (nie)zaufania w technologiach dla starzenia: https://arxiv.org/html/2608.02784
- Styl komunikacji a akceptacja technologii u osób starszych: https://www.ncbi.nlm.nih.gov/pmc/articles/PMC9473738/
- Mailchimp — puste stany jako komponent systemu: https://www.designsystems.one/design-systems/mailchimp-design
- Cookpad — pozycjonowanie „by home cooks, for home cooks": https://medium.com/cookpadteam/organic-a-new-corporate-brand-design-for-cookpad-2e502eb03273

Oznaczenia `[do weryfikacji]` w tym pliku dotyczą decyzji, które wymagają testów z użytkownikami
(13 osób wg `docs/UX_50_PLUS.md`), a nie faktów zewnętrznych.
