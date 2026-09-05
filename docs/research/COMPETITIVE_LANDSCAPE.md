# Kuking.pl — krajobraz konkurencyjny

Research: wrzesień 2026. Wszystkie nietrywialne twierdzenia mają link w sekcji `## Źródła`.
Oznaczenia: `[niepotwierdzone]` = nie znalazłem wiarygodnego źródła; `[do weryfikacji]` = źródło jest, ale wtórne lub sprzeczne z innym.

---

## 1. Garnek.pl — sekcja zwłok

### Czym był

Garnek.pl nie był portalem kulinarnym. Był **serwisem do zdjęć, fotoblogów i „fotoforów"** — nazwa wzięła się od „garnka", ale produkt był fotograficzno-społecznościowy, z bardzo silną reprezentacją treści domowych: dania, ogród, zwierzęta, rodzina, wycieczki, majsterkowanie. Autoopis serwisu: „Strona na zdjęcia, fotoblogi, fotofora. Działa prosto, bez limitów, całkowicie darmowo. Zdjęcia można wrzucać MMS-em lub z komputera." ([sur.ly snapshot](https://sur.ly/i/garnek.pl/), [api.garnek.pl](https://api.garnek.pl/))

To istotne dla Kukinga: **Garnek nie wygrał treścią kulinarną, wygrał niskim progiem publikacji zdjęcia**.

### Dlaczego działał

Rekonstrukcja mechanik (na podstawie autoopisu serwisu, archiwów i blueprintu `docs/RESEARCH.md`):

| Mechanika | Dlaczego działała |
|---|---|
| Upload MMS-em i z komputera, bez limitów, darmowo | Próg publikacji był praktycznie zerowy w epoce, w której to nie było normą |
| Fotoblog = chronologiczny profil | Użytkownik miał **własne miejsce**, nie wpis w cudzej bazie |
| „Fotofora" — fora oparte na zdjęciach | Rozmowa toczyła się wokół konkretnego zdjęcia, nie abstrakcyjnego tematu |
| Komentarze pod zdjęciem | Natychmiastowa nagroda społeczna za zwykłe zdjęcie |
| Ulubieni / obserwowanie | Powroty napędzane relacjami, nie treścią |
| Ranking / „ostatnio dodane" | Widoczność dla nowych i małych kont |
| Brak estetycznego progu | Zdjęcie „byle jakie" było normą, nie wstydem |
| Desktop-first, ale MMS-friendly | Trafiał do ludzi, którzy nie mieli aparatu w telefonie klasy flagowca |

Efekt: naturalna, nieplanowana kohorta **50+**, która publikowała codzienność, bo nikt nie kazał jej być fotografem ani kucharzem.

### Dlaczego stracił znaczenie i umarł

- **Data zgonu: 25 listopada 2024.** Serwis zapowiedział wyłączenie na ten dzień, a od ok. 10:10 UTC 25.11.2024 zaczął odpowiadać kodem HTTP 410. ([Archiveteam](https://wiki.archiveteam.org/index.php/Garnek.pl))
- **Podana przyczyna: przychody reklamowe nie pokrywały kosztów utrzymania.** ([Archiveteam](https://wiki.archiveteam.org/index.php/Garnek.pl))
- **Archiwizacja jest niepełna.** Archiveteam oznaczył projekt jako „partially saved" i **świadomie pominął pobieranie obrazów ze wszystkich serwerów z powodu ograniczeń czasowych**; dostęp do archiwum na Internet Archive jest ograniczony. Czyli: dużej części zdjęć ludzi po prostu nie ma. ([Archiveteam](https://wiki.archiveteam.org/index.php/Garnek.pl))
- Przed wyłączeniem serwis udostępnił na profilu przycisk pobrania własnych zdjęć; użytkownicy masowo kombinowali z automatycznym ściąganiem, bo ręcznie było to niewykonalne przy tysiącach fotek. ([wątek na Elektroda.pl](https://www.elektroda.pl/rtvforum/topic4085600.html))
- Skala serwisu (liczba kont, liczba zdjęć) — **[niepotwierdzone]**, nie znalazłem wiarygodnego, cytowalnego źródła.

Przyczyna głębsza niż „mało reklam": Garnek został produktem **jednej epoki technologicznej**. MMS przestał mieć znaczenie, mobile stało się domyślne, a serwis nie zbudował ani mobilnej wersji na poziomie, ani modelu przychodowego niezależnego od display ads, ani żadnego zobowiązania użytkownika (subskrypcja, rodzinne archiwum, cokolwiek płatnego lub trudnego do porzucenia).

### Lekcje DO SKOPIOWANIA

1. **Zerowy próg publikacji.** Zdjęcie + kilka słów. Nie „dodaj przepis". Blueprint ma to poprawnie (`docs/PRODUCT.md`).
2. **Własne miejsce, chronologicznie.** Profil jako pamiętnik, nie jako lista wpisów w cudzej bazie.
3. **Rozmowa wokół konkretnego zdjęcia**, nie wokół tematu abstrakcyjnego. „Fotofora" → w Kuking: komentarze pod wpisem i późniejsze grupy (V1).
4. **Estetyczna amnestia.** Produkt musi komunikować, że rozlana zupa jest OK. To jest przewaga, nie defekt.
5. **Widoczność dla małych kont.** Bez tego nowy użytkownik publikuje w pustkę i nie wraca.
6. **Desktop nie jest opcjonalny.** 50+ w Polsce nadal siada do laptopa, żeby coś „porządnie" zrobić.

### Lekcje DO UNIKNIĘCIA

1. **Nie utrzymuj się z samych reklam display.** To jest dosłowna przyczyna śmierci Garnka. Blueprint już to zapisał (`docs/RESEARCH.md`) — trzymać się tego.
2. **Nie zostawiaj ludzi bez eksportu do ostatniego tygodnia.** Eksport danych musi być od MVP, działający, kompletny, jednym kliknięciem — a nie awaryjny przycisk przed wyłączeniem wtyczki.
3. **Nie przywiązuj produktu do jednej technologii wejścia** (MMS wtedy, dziś: wyłącznie natywna apka, wyłącznie Instagram Login, wyłącznie AI-import).
4. **Nie odkładaj mobile'a.** Garnek go de facto przegapił.
5. **Nie licz na to, że lojalność 50+ sama wystarczy.** Wystarczy do trwania, nie wystarczy do finansowania.

---

## 2. Polski rynek kulinarny dziś

### Dane o skali (Mediapanel / Gemius-PBI)

Rynek mierzy Mediapanel (dawniej Gemius/PBI). Ranking zmienia się miesiąc do miesiąca; poniżej **dane z konkretnych, datowanych publikacji**:

| Okres | Lider i kolejni | Użytkownicy / zasięg |
|---|---|---|
| X 2019 (Gemius/PBI) | Kwestiasmaku.com | 3,69 mln (13,15% internautów), 22,17 mln odsłon |
| | Smaker.pl | 3,58 mln, 26,78 mln odsłon |
| V 2020 (Gemius/PBI) | Kwestiasmaku.com | 3,99 mln (14,4%) |
| XI 2021 (Mediapanel) | Aniagotuje.pl | 5,23 mln (17,84%) |
| | Kwestiasmaku.pl | 5,18 mln (17,36%) |
| | Smaker | 3,84 mln (12,86%) |
| VIII 2022 (Mediapanel) `[do weryfikacji – miesiąc]` | Aniagotuje.pl | 5,98 mln, średni czas 6 min 35 s |
| | Smaker.pl | 4,29 mln (14,48%), 4 min 40 s |
| | Kwestiasmaku.com | 4,2 mln (14,17%), 3 min 32 s |

Nie udało mi się przypiąć **rankingu z 2025/2026 r. do stabilnego URL-a** — WirtualneMedia rotuje slugi i część linków zwraca 404. `[niepotwierdzone – aktualny ranking 2026]`. Trend jest jednak stabilny od lat: **trzech liderów to Aniagotuje, Kwestia Smaku i Smaker, w przedziale ok. 4–6 mln użytkowników miesięcznie każdy.**

**Najważniejsza liczba w całej tej tabeli to nie zasięg, a czas: 1 min 39 s – 6 min 35 s na użytkownika miesięcznie.** To nie jest społeczność. To ruch przelotowy z Google: wejście, przepis, wyjście. Tam nie ma czego obronić przed Kukingiem, bo tam nikt nie mieszka.

### Kto jest kim

| Produkt | Właściciel | Model treści | Co robi dobrze | Czego brakuje | Co Kuking bierze |
|---|---|---|---|---|---|
| **Kwestiasmaku.com** | niezależny (jeden autor/redakcja) | autorskie przepisy, redakcyjna jakość; są konta i „ulubione przepisy"; **autor wprost zaprasza czytelników do wrzucania zdjęć swoich dań w komentarzach** | zaufanie do jakości przepisu, spójność, SEO | brak profili ludzi, brak feedu, „ugotowałem" wciska się w komentarze bo nie ma dla tego miejsca | dowód, że popyt na „ja to ugotowałem" istnieje i dziś przecieka do komentarzy — Kuking daje temu strukturę |
| **AniaGotuje.pl** | niezależny (marka osobista) | jeden autorytet, wideo + tekst, mocne SEO | najwyższy czas na stronie w rankingu (6 min 35 s), realna marka osobista | zero UGC, zero relacji między użytkownikami | model „twarzy": Kuking potrzebuje widocznych, konkretnych ludzi, nie anonimowej bazy |
| **Smaker.pl** | Grupa Interia / Polsat-Interia | **UGC**: 106 749 przepisów, 108 048 zarejestrowanych kucharzy, profile „popularnych kucharzy", „Dodaj przepisy i zarabiaj" | jedyny duży polski gracz z realnym UGC i profilami; skala archiwum | brak sygnału realnego wykonania, słaby feed relacyjny, monetyzacja twórcy zamiast więzi, czas 4 min 40 s | ostrzeżenie: sama skala UGC nie tworzy społeczności; płacenie za wolumen przepisów produkuje śmieci |
| **Przepisy.pl** | Unilever (Knorr, Rama, Kasia, Lipton, Carte d'Or, Hellmann's) | brand content pod portfolio produktowe | budżet, produkcja, SEO | to nie jest medium społecznościowe i nigdy nie będzie — konflikt interesu z autentycznością | nic poza świadomością, że będzie się nas przelicytowywać w SEO |
| **Doradcasmaku.pl** | Prymat sp. z o.o. | brand content + program TV („Doradca Smaku", 24. sezon od 23.03.2026) | integracja TV↔web, ogromna baza | najniższy czas w rankingu (1 min 39 s) — czysty drive-by | dowód, że baza przepisów bez ludzi ma zerową retencję |
| **Beszamel (SE.pl)** | ZPR Media | sekcja kulinarna tabloidu | dystrybucja z portalu-matki | brak tożsamości produktu | nic |
| **MojeGotowanie.pl** | Burda Media Polska | redakcja + magazyn, 370 tys. polubień strony FB | dystrybucja print↔web | brak UGC | nic |
| **Gotujmy.pl, Przyslijprzepis.pl** | Burda Media Polska | agregacja + UGC „przyślij przepis" | wolumen | „przyślij" to donacja treści, nie tożsamość | nazwa mechaniki mówi wszystko: **„przyślij przepis" ≠ „pokaż, co ugotowałeś"** |
| **MniamMniam** | `[niepotwierdzone]` — działa od 2001 r., właściciela nie potwierdziłem | baza przepisów | długowieczność | jak wyżej | nic |
| **Haps.pl** | Agora | portal kulinarny | dystrybucja | jak wyżej | nic |
| **Smakosze.pl** | Iberion | portal kulinarny | dystrybucja | jak wyżej | nic |
| **Pysznosci.pl** | Mediapop | content na skalę | wzrosty zasięgu | jak wyżej | nic |
| **Durszlak.pl** | Bauer | agregator przepisów z blogów + **„zeszyty"** (prywatne zbiory zapisanych przepisów) | zeszyty były realną wartością dla użytkownika | serwis został zaniedbany — pod koniec zasysał tylko nagłówki z blogów, wygląd nieaktualizowany, były okresy bez nowych treści | zamknięty 31 sierpnia (rok: 2024 `[do weryfikacji]`) — **„zeszyty z przepisami przepadły"**. To druga po Garnku polska lekcja: ludzie tracą archiwum, gdy platforma umiera |
| **Cookpad PL** (`cookpad.com/pl`) | Cookpad Inc. (TSE:2193) | pełny UGC + **Cooksnap** + dziennik gotowania + kolekcje + Premium | ma dokładnie tę mechanikę, którą Kuking uważa za rdzeń | brak polskiej masy krytycznej i polskiej obsługi społeczności `[niepotwierdzone – skala PL]` | **to jest realna konkurencja produktowa. Patrz sekcja 4.** |
| **Grupy kulinarne na Facebooku** | Meta | wyłącznie strumień; brak struktury | tam **są** ludzie 50+, dziś, dziś wieczorem | patrz niżej | to jest realna konkurencja o uwagę |

### Grupy kulinarne na Facebooku — właściwy konkurent

To tam dziś dzieje się to, po co powstaje Kuking. Dziennikarz Spider's Web opisał tę fascynację precyzyjnie: **„Nie chodzi o bycie doskonałym, to pochwała codzienności i zwyczajności"** — szczodrze nalana zupa, rozlewająca się mizeria, kanapki zrobione przez męża, niedbałe krojenie warzyw. ([Spider's Web, V 2025](https://spidersweb.pl/2025/05/kulinarne-grupy-na-facebooku.html))

Co grupy FB robią dobrze:
- ludzie 50+ już tam są i już umieją obsłużyć interfejs;
- publikacja to jeden przycisk;
- natychmiastowa nagroda (dziesiątki komentarzy w ciągu godziny);
- brak wymogu jakości.

Co grupy FB robią źle — i to jest cała nasza luka:

| Problem grupy FB | Konsekwencja dla użytkownika |
|---|---|
| Brak archiwum i brak sensownego szukania w grupie | Przepis babci wrzucony w 2019 r. jest nieodnajdywalny. Praktycznie przestał istnieć. |
| Treść to komentarz, nie obiekt | Nie da się zapisać, ocenić, wersjonować, wyeksportować ani wydrukować przepisu |
| Zależność od jednego admina | Admin traci ochotę / konto / życie → grupa umiera z całym dorobkiem |
| Chaos przy braku regulaminu | Bez spójnych zasad grupa osuwa się w spam, konflikty, a skrajnie — w zamknięcie przez Facebooka ([PostPost.pl](https://postpost.pl/marketing-prawniczy/jak-stworzyc-idealny-regulamin-grupy-na-facebooku-praktyczny-poradnik-dla-administratorow/)) |
| Reklama i „przepisy" wklejane masowo | Rozmycie treści; przepisy w sieci bywają zmyślone i plagiatowane ([Smaker, regulamin grupy FB](https://smaker.pl/faq-regulamin-grupy-na-facebooku-smaker-sprawdzone-przepisy,1902998,a,.html)) |
| Boty AI infiltrujące grupy | Dziennikarz Spider's Web osobiście wyłapał boty AI wchodzące do „autentycznej" grupy (V 2025) — autentyczność grup FB jest już aktywnie atakowana |
| Algorytm i zasięgi | Twój post widzi ułamek grupy; nie kontrolujesz, kto zobaczy |
| Zero eksportu | Dorobek 5 lat gotowania jest nieprzenośny |

Skala pojedynczych grup — nie mam cytowalnych liczb członkostwa (Facebook nie udostępnia ich w indeksowalnej formie). `[niepotwierdzone]`. Dla porządku: fanpage'e kulinarne w Polsce są rzędu setek tysięcy polubień (MojeGotowanie.pl 370 258, Kulinarne przygody 385 877, Kamis „Życie ze smakiem" 145 800 — [NapoleonCat](https://napoleoncat.com/pl/blog/ranking-najpopularniejsze-profile-z-kulinarnymi-inspiracjami-na-facebooku-i-instagramie/)).

### Kto jest realną konkurencją, a kto nie

**Realna konkurencja (o uwagę i o zachowanie):**
1. **Grupy kulinarne na Facebooku** — nasz jedyny prawdziwy przeciwnik. Konkurujemy o ten sam wieczorny gest: „wrzucę zdjęcie obiadu".
2. **Cookpad w wersji polskiej** — konkurencja produktowa 1:1, z gotowym Cooksnapem, dziennikiem i Premium.
3. **Messenger / WhatsApp (rodzinne czaty)** — realnie tam trafia większość zdjęć obiadów u osób 60+. To „konkurent domyślny".
4. **Smaker.pl** — jedyny polski gracz z profilami i UGC; ma archiwum, którego nie mamy.

**Nierealna konkurencja (inny biznes, inny użytkownik):**
- Kwestia Smaku, AniaGotuje, Olga Smile, Moje Wypieki — to **media autorskie**. Konkurują o pozycję w Google na frazę „szarlotka", nie o to, czy Basia opublikuje zdjęcie swojego obiadu. Docelowo są to raczej **potencjalni sojusznicy/importerzy ruchu** niż wrogowie.
- Przepisy.pl, Doradca Smaku, Kuchnia Lidla, Beszamel, Haps, Pysznosci — brand content i portalowy content na skalę. Wygrają z nami SEO i przegrają z nami retencję. Nie ma sensu z nimi walczyć na liczbę stron (blueprint już to stwierdza w `docs/RESEARCH.md`).
- TikTok/Instagram/YouTube kulinarne — konkurencja o czas, ale w innym trybie (konsumpcja wideo od twórców, nie publikowanie własnej codzienności). Osoba 65+ nie zacznie nagrywać rolki z obiadu.

---

## 3. Zagraniczne analogi — co brać, czego nie

### Cookpad (Japonia / Indonezja) — najważniejszy benchmark

- Powstał jako Coin Ltd. (X 1997), usługa „Kitchen@coin" (III 1998), nazwa Cookpad od VI 1999, IPO na TSE w VII 2009. ([Wikipedia](https://en.wikipedia.org/wiki/Cookpad))
- Skala: **60 mln unikalnych użytkowników miesięcznie w Japonii, 40 mln globalnie**, ~800 mln odsłon/mies. (2021), **ponad 5 mln zarejestrowanych przepisów** (XII 2018). Globalna siedziba w Bristolu, biuro w Dżakarcie; Indonezja to najszybciej rosnący rynek. ([Wikipedia](https://en.wikipedia.org/wiki/Cookpad), [Cookpad blog](https://blog.cookpad.com/us/cooksnaps-cooksnaps-everywhere/))
- **Cooksnap** = zdjęcie dania ugotowanego z cudzego przepisu. Cookpad sam definiuje trzy powody publikacji: (1) podziękować autorowi za inspirację, (2) dać innym znać, że przepis **działa, bo go przetestowałaś**, (3) budować powiązania i wymianę między domowymi kucharzami. ([Cookpad blog](https://blog.cookpad.com/us/cooksnaps-cooksnaps-everywhere/))
- Monetyzacja: reklama (od III 2002) + **subskrypcja premium (od IX 2004)**. ([Wikipedia](https://en.wikipedia.org/wiki/Cookpad))

**Co brać:** cały mechanizm „Ugotowałem". Kuking robi to poprawnie i idzie dalej niż Cookpad, traktując `Ugotowałem` jako **główny sygnał jakości przepisu, silniejszy od like'a** — z faktycznym czasem, odczuwaną trudnością i „zrobię ponownie" (`docs/PRODUCT.md`). To jest dobra, konkretna przewaga produktowa.

**Czego nie brać / co jest przestrogą:** Cookpad **kurczy się finansowo**. Przychody FY2024: 5,88 mld JPY (−22,76% r/r); FY2025: 5,34 mld JPY (−9,2%); zysk netto FY2025 741 mln JPY (−44%), marża 14% vs 23% rok wcześniej. Spółka wskazuje jako główny powód spadku przychodów w Q1 FY2025 **zmniejszenie liczby subskrypcji premium**. ([StockAnalysis](https://stockanalysis.com/quote/tyo/2193/revenue/), [SimplyWall.st](https://simplywall.st/stocks/jp/media/tse-2193/cookpad-shares/past), [raport Cookpad Q1 FY2025](https://cf.cpcdn.com/info/assets/wp-content/uploads/20250509140325/FY2025-Q1_Consolidated-Earnings-Results.pdf))

Wniosek: **sam Cooksnap nie jest modelem biznesowym**. Subskrypcja za „szybsze wyszukiwanie i więcej ulubionych" jest słabym powodem do płacenia. Kuking musi mieć powód do płacenia oparty na **czymś, czego nie da się wypisać** — rodzinne archiwum, książka drukowana, prywatność, przekazanie zbioru dzieciom.

### Ravelry (rękodzieło) — jak nisza trzyma ludzi latami

- Skala: ~11 mln użytkowników (I 2023, źródło wtórne — `[do weryfikacji]`), 9 mln (III 2020), 6 mln (2016). ([Ravelry „4 Million Ravelers"](https://www.ravelry.com/about/fourmillion), [Expanded Ramblings](https://expandedramblings.com/index.php/ravelry-statistics-facts/))
- Dlaczego trzyma: **siła serwisu niszowego nie polega na łączeniu ludzi, ale na dostarczeniu narzędzi, które pozwalają robić coś lepiej niż dotąd**; serwisy społeczne działają lepiej, gdy są mniejsze i szyte na miarę. ([Bokardo](http://bokardo.com/archives/the-power-of-niche-social-network-sites/), [Slate](https://slate.com/technology/2011/07/ravelry-and-knitting-why-facebook-can-t-match-the-social-network-for-knitters.html))
- Struktura: „project pages" (własne archiwum wykonanych robótek), baza wzorów, grupy, lokalne spotkania, wymiany włóczki, „knit-alongs", fora do rozwiązywania problemów. Cała branża jest w środku: projektanci, sklepy, producenci włóczki. ([IDEA](https://www.idea.org/blog/2011/07/12/niche-social-networks-ravelry-exhibitfiles-and-others/), [Frances Bell](https://francesbell.com/bellblog/ravelry-a-knitting-community-as-a-site-of-joy-and-learning/), [Craft Industry Alliance](https://craftindustryalliance.org/raverly-at-10-how-the-knitting-social-network-has-inspired-and-impacted-yarntrepreneurs/))
- Badania nad lojalnością w społecznościach online: **lojalne społeczności mają gęstsze sieci interakcji użytkownik–użytkownik** (nawet po kontroli poziomu aktywności całej społeczności), a lojalność rozkwita w społecznościach bardziej inkluzywnych i spójnych. ([arXiv 1703.03386](https://arxiv.org/pdf/1703.03386))

**Co brać dla Kukinga:**
1. **„Project page" = odpowiednik naszego wpisu/Ugotowałem.** Ravelry trzyma ludzi bo ich robótki są *ich*, opisane, policzone i odnajdywalne po latach. Kuking musi mieć równie dobre **archiwum osobiste** („moje 340 obiadów od 2026 r.").
2. **Gęstość relacji > liczba użytkowników.** Metryka do pilnowania: średnia liczba wzajemnych interakcji na aktywnego użytkownika, nie DAU.
3. **Grupy i lokalność** (V1 „grupy / fotofora" w blueprintcie) — z Ravelry wynika, że to nie jest dodatek, to jest silnik.
4. **Wpuszczenie „branży"** — koła gospodyń, UTW, lokalne piekarnie, producenci przetworów. Nie jako reklamodawców, jako uczestników.

### Strava — aktywność jako treść

- Model: **aktywność staje się treścią, kudos staje się zaangażowaniem, kluby stają się społecznością**. Każdy przejazd/bieg to jednocześnie rekord osobisty i publiczny sygnał. ([Startup Signals](https://startupsignals.substack.com/p/strava-if-its-not-on-strava-it-didnt), [NoGood](https://nogood.io/blog/strava-marketing-strategy/))
- Skala nagrody społecznej: **ponad 14 mld kudosów w 2025 r., +20% r/r**. ([Substack – Health Matters](https://healthmattersandme.substack.com/p/why-over-100-million-athletes-are))
- Dowód przyczynowy: badanie na 4500 użytkownikach wykazało, że **interakcje społeczne (kudos, komentarze) skłaniały użytkowników do publikowania większej liczby aktywności**; aktywności grupowe dostają o 95–121% więcej kudosów niż samotne. ([ScienceDirect](https://www.sciencedirect.com/science/article/pii/S0378873322000909), [Trophy](https://trophy.so/blog/strava-gamification-case-study))
- Mechanizm psychologiczny: świadomość, że aktywność zobaczą ludzie, których opinia ma znaczenie, zwiększa konsekwencję zachowania. ([Startup Signals](https://startupsignals.substack.com/p/strava-if-its-not-on-strava-it-didnt))

**Co brać:** Kuking ma dokładnie ten sam surowiec — **czynność, którą użytkownik i tak wykonuje codziennie**. Gotowanie obiadu to „aktywność" w rozumieniu Stravy. Wnioski operacyjne:
1. Kuking nie musi produkować treści — musi **rejestrować to, co i tak się dzieje**. To najtańszy content engine, jaki istnieje.
2. **Reakcja musi być szybka i pewna.** Post bez reakcji w pierwszej dobie = utracony użytkownik 50+. Konieczne: gwarancja pierwszego komentarza (rotacja „powitalna", grupy startowe, kuratorzy).
3. **Gotowanie wspólne > gotowanie samotne.** Odpowiednik „group activity": wyzwania (V1), rodzinna książka, gotowanie tego samego przepisu w tygodniu.

**Czego nie brać:** rankingów wydolnościowych i segmentów. Rywalizacja liczbowa w gotowaniu 50+ zadziała odwrotnie — wypcha ludzi, którzy „gotują zwyczajnie".

### Untappd — logowanie jako rytuał

- Model: check-in piwa ze **zdjęciem, czasem i lokalizacją**; najbardziej charakterystyczny element to **odznaki** — cyfrowe osiągnięcia za konkretne wzorce zachowań, porównywalne ze znajomymi. ([Wikipedia](https://en.wikipedia.org/wiki/Untappd), [arXiv – analiza wzdłużna grywalizacji Untappd](https://arxiv.org/pdf/2601.04841))

**Co brać:** rytuał logowania czynności („check-in obiadu") + **odznaki jakościowe, nie ilościowe**: „ktoś ugotował Twój przepis", „5 osób powiedziało, że wyszło", „przepis w rodzinie od 3 pokoleń". **Czego nie brać:** odznak za wolumen („100 wpisów"). Blueprint już zakazuje punktów za liczbę postów (`docs/FEATURES.md` → „Nie wcześnie") — słusznie. Analiza Untappd zwraca uwagę na etyczne problemy grywalizacji zachowań, które sama zachęca do intensyfikacji; w gotowaniu to samo ryzyko dotyczy jedzenia.

### Goodreads — katalog jako powód powrotu

- Model: **katalogowanie społeczne** — śledzenie postępu, oceny, recenzje, społeczność. Wzrost na początku szedł przez znajomych i znajomych znajomych. ([Wikipedia/analizy](https://marketingcasestudy.io/alternatives/goodreads/))
- Goodreads jest jednocześnie podręcznikowym przykładem, że **użyteczność katalogu utrzymuje ludzi nawet przy złym UX** — krytyka interfejsu jest powszechna i mimo to użytkownicy zostają, bo ich lista przeczytanych książek jest tam. ([Design critique, Pratt](https://ixd.prattsi.org/2024/09/design-critique-goodreads-good-books-meet-bad-ux-ios-app/))

**Co brać:** własne archiwum jest kotwicą silniejszą niż feed. **Czego nie brać:** wniosku „UX nie ma znaczenia". Dla 50+ ma znaczenie decydujące — Goodreads trzyma 30-latków, których nie da się przestraszyć interfejsem.

### Nextdoor — ostrzeżenie o moderacji

- Hiperlokalna sieć sąsiedzka. Problemy: trudności z moderacją treści, **„zanieczyszczenie informacyjne"** — ludzie wrzucają błahostki (szczekający pies, wystawiona kanapa), przez które muszą się przekopywać służby; ryzyka prywatności i ujawniania lokalizacji; przy słabych więziach lokalnych powstaje niska solidarność sąsiedzka. Zaangażowanie algorytmicznie napędzają komentarze. ([Tripepi Smith](https://www.tripepismith.com/nextdoor-challenges-hyper-local-social-media/), [Debating Communities and Networks XII](https://networkconference.netstudies.org/2021/2021/04/26/as-a-hyperlocal-form-of-social-media-nextdoor-helps-neighbours-connect-but-not-always-for-the-better/))

**Co brać:** komentarz jest paliwem — projektować pod komentarz, nie pod like. **Czego nie brać:** otwartego pola „napisz co chcesz do sąsiadów". Kuking jest chroniony tym, że **jednostką treści jest danie, nie opinia**. Trzymać się tego twardo: gdy główną akcją stanie się „napisz posta", produkt osunie się w politykę i sąsiedzkie kłótnie, a koszt moderacji eksploduje (`docs/MODERATION.md`).

### Pinterest — kto tam już jest

- 11,8% globalnej audiencji reklamowej Pinteresta to osoby **55+**, z czego ponad trzy czwarte to kobiety; kobiety to ok. 70% całej bazy. ([DataReportal](https://datareportal.com/essential-pinterest-stats), [Statista](https://www.statista.com/statistics/1300092/pinterest-global-audience-by-age-group-and-gender/))
- Dane dla Polski w rozbiciu na wiek: **[niepotwierdzone]**.

**Co brać:** Pinterest dowodzi, że **kobiety 55+ zbierają przepisy wizualnie i robią to chętnie**. Kolekcje w Kukingu (MVP) to nie „nice to have", to główny nawyk tej grupy. Warto sprawdzić Pinterest jako kanał akwizycji dla publicznych przepisów (obrazek + link).

### Flickr / Fotka.pl / Nasza-Klasa — polskie i zagraniczne lekcje o śmierci

**Flickr.** Yahoo kupiło serwis głównie dla **przeszukiwalnych, otagowanych zdjęć**, nie dla społeczności; Flickr nigdy nie znalazł się w centrum strategii Yahoo. Migracja logowania na Yahoo RegID wściekła kluczową społeczność wczesnych użytkowników. Założyciele odeszli w 2008. Brak przyzwoitej wersji mobilnej — pełna responsywność dopiero w IV 2017. Oferta 1 TB darmowego miejsca przyciągnęła ludzi szukających dysku, nie fotografów. Społeczność wyparowała. ([Gizmodo](https://gizmodo.com/how-yahoo-killed-flickr-and-lost-the-internet-5910223), [PetaPixel](https://petapixel.com/2012/05/15/yahoo-and-the-decline-and-fall-of-flickr/), [TIME](https://time.com/6855/flickr-turns-10-the-rise-fall-and-revival-of-a-photo-sharing-community/))

> Lekcje dla Kukinga: (1) nigdy nie ruszać logowania bez ścieżki bezbolesnej — dla 50+ zmiana loginu to koniec relacji z produktem; (2) nie kupować użytkowników darmowym miejscem; (3) mobile nie jest projektem na później.

**Nasza-Klasa.** Start XI 2006, błyskawiczna dominacja. 22 VI 2010 rebranding na NK.pl — od tego momentu „cichy upadek". Użytkownicy musieli zaakceptować kontrowersyjny regulamin z zgodą na przekazywanie danych partnerom; wprowadzano opłaty za dotąd darmowe funkcje; „Śledzik" wprowadzono i usunięto po kilku miesiącach; API było przez lata zamknięte, więc nie powstał ekosystem. Rozgoryczeni użytkownicy przeszli na raczkującego wtedy Facebooka, który w kilka miesięcy zdeklasował NK. Serwis zniknął 27 VII 2021 o 23:59. ([SentiOne](https://sentione.com/blog/pl/nasza-klasa-historia-wzlotu-i-upadku/), [Komputronik NANO](https://nano.komputronik.pl/n/nasza-klasa-historia/), [WirtualneMedia](https://www.wirtualnemedia.pl/artykul/koniec-nasza-klasa-znika-swojskie-medium-spolecznosciowe), [RMF FM](https://www.rmf.fm/magazyn/news,40302,koniec-nk-pl-nasza-klasa-zakonczyla-dzialalnosc-co-przyczynilo-sie-do-jej-upadku.html))

> **To jest najważniejsza polska lekcja o retencji.** NK nie przegrała funkcjami — przegrała **zaufaniem**. Rebranding zabrał nazwę, regulamin zabrał poczucie bezpieczeństwa danych, opłaty za dotychczas darmowe rzeczy zabrały poczucie uczciwości. Grupa 50+ jest na to *najbardziej* wrażliwa. Konkretnie dla Kukinga: nie zmieniać nazwy, nie zmieniać reguł prywatności retroaktywnie, nie zamykać za paywallem tego, co było darmowe, nie kasować funkcji, którą ludzie polubili.

**Fotka.pl.** Uruchomiona 14 II 2001 przez Rafała Agnieszczaka i Andrzeja Ciesielskiego; w szczycie wg danych firmowych 19–19,5 mln utworzonych profili; rebranding logo 2016, redesign i przeniesienie na fotka.com w 2019. **Dziś nadal działa** — poza pierwszą dziesiątką polskich serwisów społecznościowych pod względem odwiedzalności, ale z oddanym gronem użytkowników, firma z Elbląga bez giełdy i bez sprzedaży funduszowi. ([Wikipedia](https://pl.wikipedia.org/wiki/Fotka), [Polskie Radio 24](https://polskieradio24.pl/artykul/3647591,dziubki-biale-kozaczki-i-miliony-zlotych-fotkapl-wyprzedzila-swoje-czasy), [My Company Polska](https://mycompanypolska.pl/artykul/fotka-sie-nie-poddaje/7371), [Spider's Web](https://spidersweb.pl/2024/07/fotka-pl-randki-2024.html))

> Lekcja pozytywna: **polski serwis społecznościowy może żyć 25 lat bez skali Facebooka**, jeśli ma jasną funkcję i nie próbuje być wszystkim. To realistyczny sufit i realistyczny cel dla Kukinga.

### Tabela zbiorcza

| Produkt | Model treści | Co robi dobrze | Czego brakuje | Co Kuking bierze |
|---|---|---|---|---|
| Garnek.pl (†2024) | fotoblog + fotofora, zdjęcia codzienności | zerowy próg publikacji, własne miejsce, relacje, brak progu estetycznego | model przychodowy, mobile, eksport, ciągłość | rdzeń produktu; zakaz utrzymywania się z samych reklam |
| Durszlak.pl (†) | agregacja blogów + prywatne „zeszyty" | zeszyty jako realna wartość osobista | utrzymanie produktu; archiwum przepadło | eksport i trwałość archiwum jako obietnica marki |
| Nasza-Klasa (†2021) | profile + zdjęcia + klasy | „swojskość", ogromna baza 30–60+ | zaufanie: rebranding, regulamin, opłaty, brak API | zakaz retroaktywnych zmian reguł |
| Fotka.pl | profile + zdjęcia + randki | 25 lat trwania w niszy, lojalna baza | skala, znaczenie | dowód, że nisza w PL jest utrzymywalna |
| Flickr | zdjęcia + tagi + grupy | społeczność ekspercka, otwartość | mobile, logowanie, priorytet właściciela | zakaz łamania logowania i kupowania ludzi darmowym miejscem |
| Cookpad | UGC przepisy + **Cooksnap** + dziennik | „ja to ugotowałem" jako obiekt pierwszej klasy; skala | monetyzacja (premium spada, przychody −22,8% w FY2024) | mechanika `Ugotowałem`; ostrzeżenie o modelu przychodów |
| Ravelry | projekty + wzory + grupy + fora | 10+ lat retencji w niszy rzemieślniczej; narzędzia, nie tylko feed | brak masowości (i dobrze) | archiwum osobiste + grupy + gęstość relacji jako metryka |
| Strava | aktywność = treść, kudos = reakcja | zamiana codziennej czynności w treść; 14 mld kudosów/rok | rywalizacja wypycha słabszych | „co dziś ugotowałeś" jako log aktywności; gwarancja reakcji |
| Untappd | check-in + zdjęcie + odznaki | rytuał, mikro-osiągnięcia | grywalizacja zachowania konsumpcyjnego | odznaki jakościowe (`Ugotowałem`), zero odznak za wolumen |
| Goodreads | katalog + oceny + recenzje | katalog jako kotwica retencji | UX | archiwum > feed |
| Nextdoor | hiperlokalny strumień | komentarze napędzają zaangażowanie | moderacja, śmieci informacyjne, prywatność | jednostką treści jest danie, nie opinia |
| Pinterest | zbieranie wizualne | kobiety 55+ realnie tam zbierają przepisy | brak społeczności i dowodu wykonania | kolekcje jako funkcja pierwszej klasy; kanał akwizycji |
| Smaker.pl | UGC: 106,7 tys. przepisów, 108 tys. kucharzy | jedyny duży polski UGC z profilami | brak dowodu wykonania, płatność za wolumen, czas 4:40 | skala UGC ≠ społeczność |
| Kwestia Smaku / AniaGotuje | media autorskie | zaufanie, jakość, SEO, marka osobista | brak ludzi jako obiektów | rola „twarzy"; popyt na cooksnap widoczny w komentarzach |
| Przepisy.pl / Doradca Smaku | brand content | budżety, SEO | konflikt interesu, czas 1:39 | nic; nie walczyć na wolumen stron |
| Grupy kulinarne FB | strumień postów | ludzie 50+ już tam są, jeden przycisk, natychmiastowa reakcja | brak archiwum, brak szukania, admin single point of failure, spam, boty AI, zero eksportu | całą naszą propozycję wartości: „to samo, ale nie zginie" |

---

## 4. Luka rynkowa

**Polski rynek kulinarny w internecie jest rynkiem wydawniczym udającym rynek społecznościowy.** Trzech liderów (AniaGotuje, Kwestia Smaku, Smaker) zbiera po 4–6 mln użytkowników miesięcznie, ale średni czas na użytkownika waha się od 1 min 39 s do 6 min 35 s. To znaczy, że nikt tam nie mieszka. To infrastruktura odpowiedzi na zapytanie „szarlotka przepis" — bardzo dobra infrastruktura, świetnie zoptymalizowana pod Google i pod display ads, i całkowicie pusta społecznie. Nawet Smaker, jedyny duży polski gracz z realnym UGC (106 749 przepisów, 108 048 kucharzy) i profilami, płaci za dodawanie przepisów („Dodaj przepisy i zarabiaj") — czyli kupuje wolumen, a nie buduje więzi. Nie ma tam ani jednego produktu, którego północną metryką byłoby „ile osób wróciło, bo ktoś skomentował ich obiad".

**Jednocześnie zachowanie, pod które Kuking jest projektowany, już masowo istnieje — tylko dzieje się w miejscu, które je marnuje.** Ludzie, w większości kobiety 50+, codziennie wrzucają zdjęcia obiadów do grup na Facebooku i do rodzinnych czatów na Messengerze. Dziennikarze piszą o tym z rozczuleniem, bo to jedyna niesztuczna rzecz, jaka im została w feedzie („nie chodzi o bycie doskonałym, to pochwała codzienności"). Ale grupa na Facebooku nie ma archiwum, nie ma sensownego wyszukiwania, nie ma eksportu, treść jest komentarzem a nie obiektem, cała pamięć zbiorowa wisi na jednym adminie, spam i boty AI wchodzą w to coraz agresywniej, a przepis babci wrzucony w 2019 roku jest praktycznie nieodnajdywalny. **Polska ma już dwa świeże dowody, co się dzieje z takimi zbiorami: Garnek.pl wyłączony 25 XI 2024 z powodu niewystarczających przychodów reklamowych, z archiwum ratowanym tylko częściowo i pominiętymi obrazami; Durszlak.pl zamknięty 31 sierpnia, wraz z „zeszytami z przepisami", w których ludzie zbierali przepisy latami.**

**Luka jest więc bardzo konkretna i bardzo wąska: nie ma w Polsce miejsca, w którym publikacja zdjęcia obiadu jest tak łatwa jak w grupie na Facebooku, a jednocześnie trwała, wyszukiwalna, twoja i wyeksportowalna.** Kuking nie musi wygrać z Kwestią Smaku na frazę „pierogi", nie musi mieć miliona przepisów i nie musi pokonać TikToka na wideo. Musi być odpowiedzią na jedno zdanie, które ta grupa mówi sama: „gdzieś to miałam". Cookpad ma najbliższą mechanikę (Cooksnap) i ma polską wersję, ale nie ma polskiej masy krytycznej ani polskiej obsługi społeczności — a jego własne finanse (przychody −22,8% w FY2024, −9,2% w FY2025, spadające premium) pokazują, że nawet globalny gracz nie rozwiązał pytania „za co ludzie płacą". To zostawia Kukingowi wąskie, ale realne okno: **być polskim, ludzkim, czytelnym archiwum codziennego gotowania z wbudowanym dowodem, że komuś to naprawdę wyszło** — z modelem przychodowym opartym na trwałości (rodzinna książka, eksport, druk, prywatność), a nie na odsłonach.

---

## 5. Ryzyka konkurencyjne

### Jeśli Facebook/Meta pchnie mocniej w grupy kulinarne

**Prawdopodobieństwo: wysokie (dzieje się stale, bez ogłoszeń).** Meta systematycznie dosypuje grupom narzędzi. Wystarczy, że doda przyzwoite wyszukiwanie w grupie, zapisywanie postów w foldery i eksport — i 70% naszej propozycji wartości znika.

**Co nas chroni strukturalnie:**
- Meta nigdy nie zrobi produktu, w którym **przepis jest obiektem** (składniki, kroki, porcje, skalowanie, wersje, `Ugotowałem` z czasem i trudnością). To wymaga schematu danych, a Meta buduje pod uniwersalny post.
- Meta nie zrobi **eksportu jako obietnicy marki** — ich model to lock-in.
- Meta nie zrobi **UX 50+ jako standardu** (18 px, 48 px przyciski, brak ukrytych gestów, brak swipe'ów) — ich UI optymalizuje pod zaangażowanie, nie pod czytelność.
- Grupa jest własnością admina, a nie użytkownika. My dajemy **własny profil i własne archiwum**.

**Co zrobić teraz, nie później:**
1. Uczynić `Ugotowałem` + kolekcje + eksport + rodzinną książkę osią komunikacji, a nie „feed jak na FB".
2. **Nie walczyć o ten sam post, walczyć o jego trwałość.** Ułatwić przeniesienie: „wrzuć tu też" i późniejszy import/OCR (V2).
3. Zbudować relację z **adminami grup FB** jako pierwszymi ambasadorami — to oni najlepiej wiedzą, że ich dorobek jest kruchy.

### Jeśli Cookpad wejdzie mocniej w Polskę

**Prawdopodobieństwo: średnio-niskie.** Cookpad ma polską wersję (`cookpad.com/pl`, polskie trendy wyszukiwań, Cooksnap, dziennik gotowania, kolekcje, Premium, wsparcie `info-pl@cookpad.com`), więc infrastruktura jest gotowa. Ale spółka jest w wieloletnim spadku przychodów i marż — **firma kurcząca się finansowo nie odpala kampanii akwizycyjnej na rynku 38-milionowym**. Cookpad raczej redukuje niż inwestuje.

**Ryzyko realne, gdyby jednak weszli:** mieliby lepszy produkt bazowy z dnia na dzień (dojrzały Cooksnap, apki natywne, wyszukiwarka po składnikach).

**Nasza obrona:**
1. **Polskość jako produkt, nie jako tłumaczenie.** Kategorie, sezonowość (przetwory, Wigilia, święconka, grzyby, kiszonki), jednostki („szklanka", „łyżka"), język. Cookpad PL jest przetłumaczony, nie osadzony.
2. **Obsługa społeczności po polsku i przez ludzi.** Moderacja, powitania, kuratorzy, wyzwania. Tego nie zrobi zdalne biuro.
3. **UX 50+ jako twardy standard** — Cookpad jest projektowany globalnie pod 25–45.
4. **Rodzinne archiwum i pamięć** (książka rodzinna, OCR zeszytów w V2) — emocjonalny lock-in, którego globalna aplikacja nie odtworzy.

### Pozostałe ryzyka

| Ryzyko | Prawdopodobieństwo | Skutek | Reakcja |
|---|---|---|---|
| **Smaker/Interia dodaje „Ugotowałem"** | średnie — mają UGC, profile i dystrybucję | tracimy jedyną unikalną mechanikę | mechanika nie jest fosą; fosą jest jakość społeczności i UX 50+. Nie budować strategii na jednej funkcji |
| **Wydawca (Burda, ZPR, Agora, RASP) kupuje lub kopiuje** | średnie | przelicytują nas w SEO i mediach | nie konkurować SEO; konkurować powrotami bez Google (`docs/PRODUCT.md` zasada 6) |
| **Kwestia Smaku / AniaGotuje uruchamiają społeczność wokół marki osobistej** | średnio-niskie | zabierają najbardziej zaangażowanych | traktować ich jako partnerów: kanał „mój przepis, wasze wykonania" |
| **TikTok/Reels przejmuje uwagę 50+** | rosnące | mniej czasu na publikowanie, więcej na konsumpcję | Kuking nie konkuruje w wideo; konkuruje w „zapisz i odnajdź" |
| **Zalew AI-slopu (zmyślone przepisy, boty)** | wysokie — już się dzieje w grupach FB | erozja zaufania do UGC | `Ugotowałem` ze zdjęciem to **najlepszy dostępny weryfikator autentyczności w tej kategorii**. To trzeba komunikować wprost jako przewagę |
| **Brak masy krytycznej — publikowanie w pustkę** | **najwyższe ryzyko produktu** | użytkownik 50+ po jednym poście bez reakcji nie wraca nigdy | startować geograficznie/tematycznie wąsko; gwarantować reakcję; kuratorzy i koła gospodyń/UTW jako zalążek |
| **Koszt moderacji** | średnie | wypalenie, ryzyko prawne (DSA) | jednostką treści jest danie; brak DM w MVP; jasne zasady od dnia zero (`docs/MODERATION.md`) |

---

## Źródła

**Garnek.pl i polskie serwisy zamknięte**
- https://wiki.archiveteam.org/index.php/Garnek.pl
- https://sur.ly/i/garnek.pl/
- https://api.garnek.pl/
- https://www.elektroda.pl/rtvforum/topic4085600.html
- https://polecamprzepis.pl/koniec-popularnego-serwisu-kulinarnego-durszlak-zeszyty-z-przepisami-przepadna/
- https://kulinarnapolska.org/koniec-popularnego-serwisu-kulinarnego-durszlak-zeszyty-z-przepisami-przepadna/ (403 przy pobraniu — treść znana z indeksu wyszukiwarki)
- https://durszlak.pl/

**Nasza-Klasa, Fotka.pl**
- https://sentione.com/blog/pl/nasza-klasa-historia-wzlotu-i-upadku/
- https://nano.komputronik.pl/n/nasza-klasa-historia/
- https://www.wirtualnemedia.pl/artykul/koniec-nasza-klasa-znika-swojskie-medium-spolecznosciowe
- https://www.rmf.fm/magazyn/news,40302,koniec-nk-pl-nasza-klasa-zakonczyla-dzialalnosc-co-przyczynilo-sie-do-jej-upadku.html
- https://adstalk.pl/nasza-klasa-dlaczego-najwiekszy-serwis-social-media-w-polsce-upadl/
- https://pl.wikipedia.org/wiki/Fotka
- https://polskieradio24.pl/artykul/3647591,dziubki-biale-kozaczki-i-miliony-zlotych-fotkapl-wyprzedzila-swoje-czasy
- https://mycompanypolska.pl/artykul/fotka-sie-nie-poddaje/7371
- https://spidersweb.pl/2024/07/fotka-pl-randki-2024.html

**Polski rynek kulinarny — dane Mediapanel / Gemius-PBI**
- https://www.wirtualnemedia.pl/artykul/przepisy-online-najlepsze-serwisy-kulinarne (XI 2021)
- https://www.wirtualnemedia.pl/przepisy-gotowanie-serwisy-najlepsze,7169763422550145a
- https://www.wirtualnemedia.pl/artykul/kwestia-smaku-na-czele-serwisow-kulinarnych-ania-gotuje-moje-wypieki-i-przepisy-pl-z-duzymi-wzrostami-top10 (V 2020)
- https://www.press.pl/tresc/37385,najpopularniejsze-serwisy-kulinarne-ma-interia (X 2019)
- https://www.wirtualnemedia.pl/artykul/najlepsze-serwisy-kulinarne-na-czele-kwestia-smaku-i-smaker-w-dol-doradca-smaku-top-10
- https://www.wirtualnemedia.pl/tags/serwisy%20kulinarne
- https://interaktywnie.com/biznes/artykuly/trendy/najsmaczniejsze-w-polskiej-sieci-serwisy-kulinarne-pod-lupa-18232

**Produkty polskie — model treści i właściciele**
- https://smaker.pl/ (106 749 przepisów, 108 048 kucharzy — stopka serwisu)
- https://www.kwestiasmaku.com/
- https://www.doradcasmaku.pl/ , https://www.doradcasmaku.pl/program-doradca-smaku
- https://www.mojegotowanie.pl/info/kontakt , https://www.tvnmedia.pl/nasze-media/online/mojegotowaniepl
- https://www.mniammniam.com/
- https://www.przepisy.pl/ (403 przy pobraniu; właściciel i marki wg publikacji WirtualneMedia)

**Grupy i profile kulinarne na Facebooku**
- https://spidersweb.pl/2025/05/kulinarne-grupy-na-facebooku.html
- https://smaker.pl/faq-regulamin-grupy-na-facebooku-smaker-sprawdzone-przepisy,1902998,a,.html
- https://postpost.pl/marketing-prawniczy/jak-stworzyc-idealny-regulamin-grupy-na-facebooku-praktyczny-poradnik-dla-administratorow/
- https://wildmoose.pl/jakie-sa-rodzaje-grup-na-facebooku-i-co-moze-administrator/
- https://pl-pl.facebook.com/help/938172370267447/
- https://napoleoncat.com/pl/blog/ranking-najpopularniejsze-profile-z-kulinarnymi-inspiracjami-na-facebooku-i-instagramie/

**Cookpad**
- https://en.wikipedia.org/wiki/Cookpad
- https://blog.cookpad.com/us/cooksnaps-cooksnaps-everywhere/
- https://cookpad.com/pl
- https://stockanalysis.com/quote/tyo/2193/revenue/
- https://simplywall.st/stocks/jp/media/tse-2193/cookpad-shares/past
- https://cf.cpcdn.com/info/assets/wp-content/uploads/20250509140325/FY2025-Q1_Consolidated-Earnings-Results.pdf
- https://japan-dev.com/companies/cookpad

**Ravelry i społeczności niszowe**
- https://www.ravelry.com/about/fourmillion
- http://bokardo.com/archives/the-power-of-niche-social-network-sites/
- https://slate.com/technology/2011/07/ravelry-and-knitting-why-facebook-can-t-match-the-social-network-for-knitters.html
- https://www.idea.org/blog/2011/07/12/niche-social-networks-ravelry-exhibitfiles-and-others/
- https://francesbell.com/bellblog/ravelry-a-knitting-community-as-a-site-of-joy-and-learning/
- https://craftindustryalliance.org/raverly-at-10-how-the-knitting-social-network-has-inspired-and-impacted-yarntrepreneurs/
- https://arxiv.org/pdf/1703.03386 (Loyalty in Online Communities)
- https://expandedramblings.com/index.php/ravelry-statistics-facts/

**Strava, Untappd, Goodreads, Nextdoor, Pinterest, Flickr**
- https://www.sciencedirect.com/science/article/pii/S0378873322000909
- https://startupsignals.substack.com/p/strava-if-its-not-on-strava-it-didnt
- https://nogood.io/blog/strava-marketing-strategy/
- https://trophy.so/blog/strava-gamification-case-study
- https://healthmattersandme.substack.com/p/why-over-100-million-athletes-are
- https://en.wikipedia.org/wiki/Untappd
- https://arxiv.org/pdf/2601.04841
- https://ixd.prattsi.org/2024/09/design-critique-goodreads-good-books-meet-bad-ux-ios-app/
- https://marketingcasestudy.io/alternatives/goodreads/
- https://www.tripepismith.com/nextdoor-challenges-hyper-local-social-media/
- https://networkconference.netstudies.org/2021/2021/04/26/as-a-hyperlocal-form-of-social-media-nextdoor-helps-neighbours-connect-but-not-always-for-the-better/
- https://datareportal.com/essential-pinterest-stats
- https://www.statista.com/statistics/1300092/pinterest-global-audience-by-age-group-and-gender/
- https://gizmodo.com/how-yahoo-killed-flickr-and-lost-the-internet-5910223
- https://petapixel.com/2012/05/15/yahoo-and-the-decline-and-fall-of-flickr/
- https://time.com/6855/flickr-turns-10-the-rise-fall-and-revival-of-a-photo-sharing-community/
