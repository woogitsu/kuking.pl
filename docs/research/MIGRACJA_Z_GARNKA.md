# Migracja z Garnka — gdzie są ludzie i co sprawi, że zostaną

**Stan researchu:** 10 września 2026  
**Pytanie:** gdzie są dziś ludzie z Garnek.pl i co musi istnieć w Kuking, żeby zostali dłużej niż jeden wpis?  
**Zakres:** research zewnętrzny + stan `main` na commit `9074d724a46d99bd8d4a33e0c14a8afb7be85419`.

> **Granica:** Kuking nie jest następcą Garnek.pl i ten dokument nie proponuje takiego pozycjonowania. Nazwa Garnek pojawia się wyłącznie jako nazwa dawnego serwisu i wspólne wspomnienie, zgodnie z `docs/marketing/KAMPANIA_GARNEK.md`.
>
> **Nie dubluję** rekomendacji z `docs/product/PROSTOTA_JAK_GARNEK.md` (m.in. indeks tagów, poprzedni/następny wpis autora, obserwowanie z ekranu wpisu, uproszczenie landingu). Ten dokument dotyczy innego problemu: **przeniesienia relacji, rytuału i poczucia „to jest moje miejsce”**.

## Odpowiedź w skrócie

**FAKT.** Nie ma publicznego dowodu na jeden serwis, do którego przeniosła się społeczność Garnka. Ślady po zamknięciu są rozproszone: istnieje facebookowa grupa **„Garnkowicze”**, część osób utrzymywała relacje równolegle na Wizażu, a dla części użytkowników Garnek był przede wszystkim hostem zdjęć używanym wewnątrz innych społeczności, np. Forum Ogrodniczego. Źródła: Top Lista Adama / grupa „Garnkowicze” (informacja od 29.11.2024, odczyt 10.09.2026) — https://toplistaadama.siteor.pl/top10 i https://www.facebook.com/groups/1520324101919548 ; Wizaż, post z 27.08.2024 — https://rozmowy.wizaz.pl/kobieta/forum-plotkowe/wiza%C5%BCowe-spo%C5%82eczno%C5%9Bci/657357-wirtualna-przyja%C5%BA%C5%84-4/page114 ; Forum Ogrodnicze, post z 23.11.2024 — https://forumogrodnicze.info/viewtopic.php?start=15&t=125504 .

**WNIOSEK.** Nie należy projektować „wielkiej migracji z jednego miejsca do drugiego”. Trzeba dać jednej osobie możliwość wejścia, odnalezienia jednej znanej osoby, wysłania własnego profilu dalej i natychmiastowego powrotu do znajomego rytuału: zdjęcie → kilka słów → odpowiedź człowieka.

**FAKT.** Przy innych migracjach społeczności powtarza się ten sam wzorzec: archiwum uruchamia przeprowadzkę, ale o pozostaniu decydują relacje i rytuał. MeWe obniżało koszt wejścia importerem Google+ Takeout; użytkownicy Flickr narzekali po zmianach nie na brak „instagramowego wyglądu”, lecz na utratę funkcji grup i kontaktu ze znajomymi; po awarii Durszlaka użytkownicy wrócili szybko, ale dalej domagali się „akcji kulinarnych”; przy przejściu grupy z Wizażu do Netkobiet najważniejsze było „żebyśmy przeszły w komplecie”. Źródła i daty są rozpisane w części 2.

**WNIOSEK.** Największa tania luka Kuking nie leży dziś w publikowaniu zdjęć. Leży pomiędzy rejestracją a pierwszym feedem: onboarding proponuje osiem osób, ale nie pyta **„znasz już kogoś tutaj?”**, mimo że wyszukiwanie ludzi już działa (`OnboardingController`, `pages/onboarding/people.blade.php`, `SearchController`).

**HIPOTEZA.** Jeśli użytkownik 50+ w pierwszej sesji odnajdzie co najmniej jedną osobę, którą zna spoza Kuking, albo wyśle swój profil jednej znanej osobie i dostanie od niej reakcję/komentarz, prawdopodobieństwo powrotu będzie większe niż po samym opublikowaniu pierwszego zdjęcia. To trzeba sprawdzić na kohorcie alfy; nie traktuję tego zdania jako udowodnionego wyniku Kuking.

---

# 1. GDZIE SĄ TERAZ

## 1.1. Najpierw najważniejszy fakt: Garnek nie ma jednego „miejsca po nim”

**FAKT.** `https://garnek.pl` zwraca obecnie HTTP 410 Gone (sprawdzone 10.09.2026). ArchiveTeam datuje wyłączenie na 25.11.2024 i opisuje swoją kopię jako częściową. Źródło: Garnek, odczyt 10.09.2026 — https://garnek.pl ; ArchiveTeam, odczyt 10.09.2026 — https://wiki.archiveteam.org/index.php/Garnek.pl .

**FAKT.** 8.11.2024 na Elektroda.pl użytkownik szukał sposobu na hurtowe pobranie prawie 10 tys. zdjęć z konta Garnek przed zamknięciem; jeszcze w 2025 r. kolejne osoby pytały, czy da się je odzyskać po terminie. Źródło: Elektroda, wątek od 8.11.2024 — https://www.elektroda.pl/rtvforum/topic4085600.html .

**FAKT.** 23.11.2024 na Forum Ogrodniczym padło ostrzeżenie, że od poniedziałku 25 listopada zdjęcia z Garnka przestaną być wyświetlane i należy je skopiować. W marcu 2025 ta sama społeczność pomagała sobie przejść na Postimage i instruowała, jak przygotować obraz do publikacji. Źródło: Forum Ogrodnicze, post 23.11.2024 oraz dalsze wpisy 2.03.2025 — https://forumogrodnicze.info/viewtopic.php?start=15&t=125504 .

**WNIOSEK.** Dla części ludzi „utrata Garnka” oznaczała utratę **magazynu zdjęć**, a nie utratę całej społeczności. Ich relacje już wcześniej mieszkały gdzie indziej. Dlatego pozyskanie tych osób nie polega na znalezieniu jednego nowego portalu; trzeba docierać do istniejących małych sieci i dawać im łatwy sposób przeniesienia relacji.

## 1.2. Miejsca z bezpośrednim śladem Garnka

| Miejsce | Co wiemy | Wielkość | Zwyczaj / linki | Ocena dla Kuking |
|---|---|---:|---|---|
| **Facebook — „Garnkowicze”** — https://www.facebook.com/groups/1520324101919548 | **FAKT:** Top Lista Adama podaje, że od **29.11.2024** jest dostępna także w tej grupie; to konkretny ślad aktywności opartej na dawnej tożsamości Garnka po zamknięciu. Źródło: Top Lista Adama, informacja „od 29.11.2024”, odczyt 10.09.2026 — https://toplistaadama.siteor.pl/top10 | **[do weryfikacji]** — nie znalazłem wiarygodnego publicznego odczytu liczby członków z 10.09.2026. | Regulamin grupy i zasady dotyczące promocji/linków zewnętrznych: **[do weryfikacji]**. Sam fakt publikowania Top Listy nie jest zgodą na reklamę Kuking. | **Najbardziej wartościowe miejsce do rozmowy jakościowej**, ale wyłącznie po zgodzie administratora. Najpierw pytanie „czego brakuje po Garnku?”, dopiero potem ewentualny link. |
| **Wizaż — „Wirtualna przyjaźń 4”** — https://rozmowy.wizaz.pl/kobieta/forum-plotkowe/wiza%C5%BCowe-spo%C5%82eczno%C5%9Bci/657357-wirtualna-przyja%C5%BA%C5%84-4/page114 | **FAKT:** 27.08.2024 użytkowniczka wkleiła komunikat o zamknięciu Garnka, podała, że ma konto od czerwca 2011 i **9131 zdjęć**, nie chce ich stracić; w tym samym kontekście pojawia się wymiana numerów telefonu, żeby nie utracić kontaktu. Źródło: Wizaż, 27.08.2024 — link obok. | Nie dotyczy — to wątek, nie grupa akwizycji. | To miejsce rozmowy stałej grupy znajomych, nie tablica reklamowa. Tolerancja dla promocyjnych linków: **[do weryfikacji]**. | **Dowód mechaniki:** archiwum + kontakt. Nie używać jako kanału masowej promocji. |
| **Forum Ogrodnicze** — https://forumogrodnicze.info/viewtopic.php?start=15&t=125504 | **FAKT:** Garnek służył jako host zdjęć wewnątrz istniejącego forum; po zamknięciu społeczność przeszła na inne narzędzia do hostingu. Posty 23.11.2024 i 2.03.2025. | Nie dotyczy. | Zwyczaj: publikowanie zdjęć do rozmów ogrodniczych; moderator pomaga w technicznej migracji hostingu. Reklama obcego serwisu: **[do weryfikacji]**. | **Dowód, że „użytkownik Garnka” nie musi szukać nowej społeczności.** Może potrzebować tylko prostszego miejsca na zdjęcia. |
| **Elektroda** — https://www.elektroda.pl/rtvforum/topic4085600.html | **FAKT:** wątek 8.11.2024 dotyczy ratowania prawie 10 tys. zdjęć przed zamknięciem; później padają pytania o odzyskanie po czasie. | Nie dotyczy. | Forum techniczne; nie jest miejscem do promocji społeczności kulinarnej. | **Źródło problemu, nie kanał marketingowy.** |

### Listopad 2024: czego udało się znaleźć, a czego nie

**FAKT.** Publicznie indeksowane listopadowe ślady, które udało się zweryfikować, są przede wszystkim **ratunkowe/techniczne**: 8.11 Elektroda (jak pobrać tysiące zdjęć), 23.11 Forum Ogrodnicze (skopiuj zdjęcia przed 25.11), a 29.11 pojawia się ślad aktywności „Garnkowiczów” na Facebooku. Źródła: odpowiednio https://www.elektroda.pl/rtvforum/topic4085600.html ; https://forumogrodnicze.info/viewtopic.php?start=15&t=125504 ; https://toplistaadama.siteor.pl/top10 .

**FAKT.** Najmocniejszy publicznie indeksowany wpis emocjonalny, który znalazłem, jest wcześniejszy — z **27.08.2024** na Wizażu: wieloletnie konto, 9131 zdjęć i obawa przed utratą zdjęć/kontaktu. Źródło: Wizaż, 27.08.2024 — https://rozmowy.wizaz.pl/kobieta/forum-plotkowe/wiza%C5%BCowe-spo%C5%82eczno%C5%9Bci/657357-wirtualna-przyja%C5%BA%C5%84-4/page114 .

**[do weryfikacji]** Nie znalazłem publicznie indeksowanego wpisu z listopada 2024, który jednocześnie byłby jednoznacznym „żałujemy Garnka i przenosimy się wszyscy do X”. Brak takiego wyniku nie dowodzi, że takich rozmów nie było — część mogła być w prywatnych grupach Facebooka, Messengerze albo w treści dziś nieindeksowanej.

## 1.3. Duże miejsca kulinarne: to są sąsiednie zwyczaje, nie dowód migracji Garnkowiczów

### Smaker

**FAKT.** Oficjalny regulamin grupy Facebook **„Smaker — sprawdzone przepisy”** opisuje ją jako prywatną grupę dla użytkowników Smaker i osób zainteresowanych gotowaniem; zabrania treści niezwiązanych z tematyką oraz promocji produktów/usług osób trzecich, w tym marek osobistych. Regulamin opublikowano 6.12.2021; to najnowsza publicznie indeksowana wersja, którą znalazłem. Źródło: Smaker, 6.12.2021, odczyt 10.09.2026 — https://smaker.pl/faq-regulamin-grupy-na-facebooku-smaker-sprawdzone-przepisy%2C1902998%2Ca%2C.html ; grupa: https://www.facebook.com/groups/smakerpl/ .

**[do weryfikacji]** Aktualna liczba członków i ewentualne zmiany regulaminu grupy po 2021 r. wymagają sprawdzenia bezpośrednio na Facebooku jako zalogowany użytkownik.

**WNIOSEK.** Tu nie wolno „wrzucić Kuking i zobaczyć, czy przejdzie”. Publicznie dostępne zasady są przeciw takiej promocji. Jedyna poprawna ścieżka to zgoda administratora — dokładnie zgodnie z `KAMPANIA_GARNEK.md`.

**FAKT.** Sam serwis Smaker jest dziś serwisem **przepisowym**: eksponuje kategorie, „Przepis dnia”, popularnych kucharzy i zachęca do dodawania przepisów. Odczyt 10.09.2026, przykład aktualnej strony: https://smaker.pl/przepisy-desery/przepis-fit-rafaello%2C2070018%2Csmaker.html .

**WNIOSEK.** Smaker konkuruje o potrzebę „znajdź/dodaj przepis”, ale jego struktura nie jest dowodem, że byli Garnkowicze chcą rankingu popularności. Kuking powinien zachować różnicę: człowiek i jego codzienne gotowanie przed bazą przepisów.

### Facebook — „1000 pomysłów na obiad i nie tylko”

**FAKT.** Zewnętrzny opis grupy z 1.06.2023 mówi o publikowaniu zdjęcia potrawy razem z przepisem. Źródło: JakiPrezent, 1.06.2023 — https://jakiprezent.pl/grupa-facebookowa-1000-pomyslow-na-obiad-i-nie-tylko/ ; grupa: https://www.facebook.com/groups/213015679210015 .

**FAKT Z OGRANICZENIEM.** Zewnętrzne zestawienie DanD odczytane 10.09.2026 podaje ok. **1,5 mln członków**; wcześniejsze zestawienie OneUp z 2025 r. podawało 1 380 689. To nie jest bezpośredni odczyt Facebooka, więc liczby należy traktować jako orientacyjne. Źródła: https://dand.ai/facebook-groups/biggestfoodgroups ; https://blog.oneupapp.io/largest-fb-groups/ .

**[do weryfikacji]** Aktualny regulamin linków zewnętrznych i autopromocji. Bez zgody administratora nie traktować tej grupy jako kanału kampanii.

**WNIOSEK.** To dobry przykład dzisiejszego zwyczaju „zdjęcie + konkretny kontekst kulinarny”, ale wymóg pełnego przepisu przy każdym zdjęciu byłby dla Kuking krokiem w złą stronę — podstawowym obiektem Kuking jest także zwykły wpis „zdjęcie + kilka słów”.

### Polish Foodies

**FAKT.** Serwis Polish Foodies deklaruje społeczność Facebook 100–105 tys.+; strona współpracy podaje, że większość odbiorców to Polish Americans. Odczyt 10.09.2026. Źródła: https://polishfoodies.com/ oraz https://polishfoodies.com/work-with-me/ ; grupa: https://www.facebook.com/groups/polishfoodies .

**[do weryfikacji]** Aktualne zasady zwykłego linkowania przez członków grupy.

**WNIOSEK.** To raczej źródło wiedzy o polskiej diasporze i treściach kulinarnych niż pierwszy kanał dla polskich użytkowników 50+ w Polsce/Belgii. Jeśli testować — przez administratora/współpracę, nie przez udawanie zwykłego polecenia.

## 1.4. Inne serwisy: jaką potrzebę przejmują dziś

| Serwis | FAKT | Co to znaczy dla Kuking |
|---|---|---|
| **Kwestia Smaku** — https://www.kwestiasmaku.com/ | 10.09.2026 strona nadal ma „Ostatnie komentarze” i sekcję „Wasze zdjęcia”; użytkownicy mogą dodawać zdjęcia wykonanych dań w kontekście przepisu. Źródło: strona główna, odczyt 10.09.2026. | **WNIOSEK:** zaspokaja potrzebę „ugotowałem cudzy przepis i pokażę efekt”, ale nie daje tego samego modelu osobistego, chronologicznego archiwum znajomych. Mechanikę odpowiedzi pod przepisem Kuking już ma przez `Ugotowałem` — nie trzeba kopiować portalu redakcyjnego. |
| **Cookpad PL** — https://cookpad.com/pl/create | Serwis aktywnie zachęca do tworzenia przepisów. W tygodniu 7–13.09.2026 trwa wyzwanie wymagające regularnego publikowania; regulamin wyzwania określa m.in. zdjęcie główne, co najmniej 3 kroki, zdjęcie/film kroku, składniki z ilościami, czas i porcje oraz system punktów/nagrodę. Źródło: Cookpad, wyzwanie 7–13.09.2026 — https://cookpad.com/pl/challenges/13364 . | **WNIOSEK:** warto pożyczyć **rytm** („co tydzień jest po co wrócić”), ale nie punktację i ciężki obowiązkowy formularz. Kuking ma chronić spontaniczne „zdjęcie + kilka słów”. |
| **Durszlak** | Durszlak jest dziś wspominany jako nieistniejący („śp. durszlak.pl”) przez twórcę Miksera Kulinarnego; wpis 21.01.2025. Źródło: https://zmiksowani.pl/forum/mikser-kulinarny/zmiany-w-serwisie-2025 . | **WNIOSEK:** Durszlak nie jest dziś miejscem migracji z Garnka. Jest natomiast bardzo dobrym historycznym przypadkiem migracji **rytuału** — patrz część 2. |
| **Fotosik** — https://www.fotosik.pl/spolecznosc | Publiczna strona społeczności odczytana 10.09.2026 pokazuje duże historyczne sumy hostowanych zdjęć/albumów/kont, ale przy liczniku nowych zdjęć „dzisiaj/wczoraj” widniało 0/0; najnowsze widoczne komentarze na stronie pochodziły m.in. z 2026 r. Źródło: strona społeczności, odczyt 10.09.2026. | **WNIOSEK:** to możliwy substytut **hostingu/archiwum**, nie dowód aktywnej migracji Garnkowiczów. Historyczne liczby kont nie są liczbą aktywnych ludzi. |
| **Flog** — https://forum.flog.pl/zdjecie-dnia-8 | Wątek z 29–30.01.2026 opisuje „zdjęcie dnia” z progiem plusów i jury. Źródło: forum Flog, 29–30.01.2026. | **WNIOSEK:** to przykład współczesnej społeczności foto, ale mechanika publicznego porównywania treści jest sprzeczna z zasadami Kuking. Nie kopiować. |
| **Blogi kulinarne** | Po awarii Durszlaka w 2011 blogerzy utrzymywali akcje kulinarne nawet poza platformą, przyjmując zgłoszenia linkami/e-mailem. Źródło: „Kuchenne Wędrówki”, 9.06.2011 — https://kuchenne-wedrowki.blogspot.com/2011/06/durszlak-is-dead-ale-truskawkowa-akcja.html . | **WNIOSEK:** społeczność potrafi utrzymać rytuał bez funkcji platformy, jeśli jest gospodarz i wspólny powód do działania. |

**[do weryfikacji]** Nie znalazłem wiarygodnego publicznego źródła, które pozwalałoby powiedzieć „duża część Garnkowiczów przeszła do Smakera / Kwestii Smaku / Cookpada / Fotosika”. Te serwisy należy traktować jako **konkurencję o potrzebę**, a nie jako mapę byłych użytkowników Garnka.

## 1.5. Gdzie faktycznie szukać pierwszych ludzi

**WNIOSEK — kolejność kanałów, nie nowy playbook kampanii:**

1. **„Garnkowicze” na Facebooku** — jedyne znalezione miejsce, które po 25.11.2024 wprost zachowało nazwę/tożsamość dawnej społeczności. Najpierw rozmowa z administratorem; zasady promocji są [do weryfikacji].
2. **Małe istniejące kręgi relacji** (Wizaż/forum/grupy tematyczne) — nie jako tablice reklamowe, tylko jako miejsca, gdzie jedna osoba może zaprosić **konkretne znane osoby**.
3. **Duże grupy kulinarne** — dopiero po zgodzie administratora. W Smakerze publicznie dostępny regulamin wręcz zakazuje promocji usług osób trzecich (6.12.2021 — źródło wyżej).
4. **Portale przepisowe** — obserwować zwyczaje, nie próbować „przejąć bazy”. Ich użytkownik przychodzi głównie po przepis; Kuking musi wygrać relacją i archiwum człowieka.

---

# 2. CZEGO SIĘ NAUCZYĆ Z INNYCH MIGRACJI

## 2.1. Google+ → MeWe / Facebook / wiele miejsc: importer pomaga wejść, ale społeczność i tak się rozprasza

**FAKT.** Google ogłosił wyłączenie konsumenckiego Google+ na 2.04.2019 i kierował użytkowników do pobrania danych. Źródło: Google, 30.01.2019 — https://workspace.google.com/blog/product-announcements/what-you-need-to-know-about-the-sunset-of-consumer-google-plus-on-april-second .

**FAKT.** MeWe przygotowało import z archiwum Google Takeout obejmujący m.in. Circles, Communities i Stream, czyli nie tylko pojedyncze pliki. Opis narzędzia z 16.03.2019: https://www.cnx-software.com/2019/03/16/how-import-google-plus-mewe/ .

**FAKT.** W relacjach z czasu zamknięcia Google+ nie było jednego oczywistego następcy; społeczności wybierały różne miejsca i często utrzymywały kilka kanałów równolegle. Źródło: GoogleWatchBlog, 2.02.2019 — https://www.googlewatchblog.de/2019/02/in-sache-nach-aus/ .

**WNIOSEK — mechanika:** import danych zmniejsza koszt „zabieram swoje rzeczy”, ale nie rozwiązuje „gdzie są moi ludzie?”. Najlepiej działał tam, gdzie przenosił nie tylko pliki, lecz również **strukturę społeczną** (kręgi/społeczności).

**Dla Kuking:** nie budować importera Garnek — serwis jest 410, brak stabilnego API/eksportu, a `COLD_START.md` celowo odrzuca masowe zalewanie treścią. Warto natomiast maksymalnie obniżyć koszt odnalezienia ludzi i wysłania własnego profilu.

## 2.2. Flickr → Instagram: podobny interfejs nie zastępuje znajomych i grup

**FAKT.** W kwietniu 2014 Flickr wypuścił aplikację silnie upodabniającą się do Instagrama; jednocześnie zniknęło logowanie przez Facebook/Google i użytkownik musiał przejść przez konto Yahoo. Źródło: Engadget, 17.04.2014 — https://www.engadget.com/2014-04-17-flickr-instagram-like-app-update.html ; oficjalny wątek Flickr o przejściu na Yahoo login: https://www.flickr.com/help/forum/en-us/72157641974750055/ (2014; dokładny dzień w publicznym wyniku [do weryfikacji]).

**FAKT.** W maju 2014 Engadget opisywał problem nowej aplikacji jako brak znajomych/społeczności w porównaniu z miejscem, w którym kontakty użytkownika już były; część osób cross-postowała na Flickr z Instagrama. Źródło: Engadget, 8.05.2014 — https://www.engadget.com/2014-05-08-flickr-3-0-app.html/ .

**FAKT.** 25.04.2014 użytkownicy Flickr zgłaszali, że wersja Android 3.0 usunęła możliwość dodawania zdjęć do albumów/grup; inne wątki z 2014 narzekały na utratę dyskusji grupowych w aplikacji. Źródło: Flickr Help Forum, 25.04.2014 — https://www.flickr.com/help/forum/en-us/72157644299398354/ ; dyskusja grupowa: https://www.flickr.com/help/forum/en-us/72157644091018883/page3/ (2014; dokładny dzień [do weryfikacji]).

**WNIOSEK — mechanika:** kopiowanie powierzchni konkurenta nie tworzy retencji. Jeśli migracja urywa **„ludzi, z którymi tu byłem”** albo **„rzecz, którą robiliśmy razem”**, użytkownik odbiera nową platformę jako gorszą nawet wtedy, gdy wygląda nowocześniej.

**Dla Kuking:** nie „instagramizować” feedu i nie budować algorytmu. Priorytet: znane osoby, chronologia, komentarz/odpowiedź, rytuał gotowania.

## 2.3. Wizaż → Netkobiety: „czy wszyscy są?” jest ważniejsze niż marka platformy

> To nie jest migracja z Garnka. Używam jej jako **bardzo bliskiego behawioralnie przypadku**: polskojęzyczna, wieloletnia grupa kobiet, w dużej części korzystająca z telefonu, przenosząca swoją codzienną rozmowę między forami.

**FAKT.** 14.11.2023 w nowym wątku „Zagroda babuszki” na Netkobiety użytkowniczki pisały, że przyszły z Wizażu z powodu ryzyka zamknięcia; pada wprost potrzeba, aby grupa przeszła „w komplecie”, oraz pytania, kogo jeszcze brakuje. Źródło: Netkobiety, 14.11.2023 — https://www.netkobiety.pl/viewtopic.php?id=132353 .

**FAKT.** W równoległym wątku na Wizażu 18–19.11.2023 pojawia się prowadzenie innych osób przez rejestrację i alternatywy Netkobiety/Discord; jedna osoba musi uruchomić laptop, żeby dokończyć wejście, inne korzystają z telefonu. W grudniu pojawiają się też uwagi o brakujących powiadomieniach e-mail w nowym miejscu. Źródło: Wizaż, wpisy 18–19.11 i 3.12.2023 — https://wizaz.pl/forum/showthread.php?page=152&t=1289367 .

**FAKT.** Już w 2021 r. ta sama społeczność opisywała mobilną drogę do forum jako trudną, problemy z hasłem/pocztą oraz kłopotliwe funkcje zdjęć/znajomych na telefonie; jednocześnie doceniano osobę, która znalazła nowe miejsce i przeprowadziła resztę. Źródło: Wizaż, 25–26.12.2021 — https://wizaz.pl/forum/showthread.php?t=1284501 .

**WNIOSEK — mechanika:** migracja ma gospodarza, listę „kto już jest / kogo brakuje”, okres równoległego używania dwóch miejsc i dużo pomocy przy wejściu. Techniczna perfekcja platformy ma mniejsze znaczenie niż pewność: **„moja grupa tu jest i wiem, jak do niej trafić”**.

**Dla Kuking:** D-037 (prawdziwy gospodarz) jest ważniejszy, niż wygląda. Nie potrzebujemy mechanizmu „importuj kontakty”; potrzebujemy czytelnego szukania znanej osoby i profilu, który da się wysłać.

## 2.4. Nasza-Klasa → Facebook: kopiowanie zwycięzcy może zabić własny powód istnienia

**FAKT.** Dane Megapanel PBI/Gemius opublikowane 24.01.2011 pokazywały jednocześnie wzrost Facebooka w Polsce i spadek Naszej-Klasy w listopadzie 2010. Źródło: Rzeczpospolita, 24.01.2011 — https://www.rp.pl/media/art14762571-facebook-ma-coraz-wiecej-uzytkownikow-nasza-klasa-mniej .

**FAKT.** W retrospektywie po zamknięciu NK w 2021 socjolog wskazywał m.in. na utratę swojskiej tożsamości przez naśladowanie Facebooka oraz irytację reklamami i opłatami za rzeczy wcześniej darmowe. Źródło: Wirtualne Media, 28.07.2021 — https://www.wirtualnemedia.pl/koniec-nasza-klasa-znika-swojskie-medium-spolecznosciowe%2C7170147801536129a .

**WNIOSEK — mechanika:** migranci nie potrzebują kopii platformy, którą właśnie opuszczają. Potrzebują miejsca, które zachowuje ich relacje, ale ma **własny jasny powód istnienia**.

**Dla Kuking:** zakaz „Kuking = nowy Garnek” jest produkcyjnie zdrowy. Nostalgia może otworzyć drzwi; retencję musi dowieźć własny rytuał Kuking: gotuję → pokazuję → ktoś odpowiada / gotuje po mnie.

## 2.5. Durszlak: odbudowany serwis bez wspólnego rytuału nadal wydaje się „nie tym miejscem”

**FAKT.** Po nagłym zniknięciu Durszlak.pl w czerwcu 2011 blogerzy stracili agregowane przepisy, kontakty i „akcje kulinarne”. Podstawowa wersja została odbudowana w około dwa tygodnie, ale jeszcze 25.07.2011 użytkownicy narzekali na brak akcji; 6.08 autorka aktualizacji odnotowała ich powrót. Źródło: Czary Kuchenne, wpis 9.06.2011 z aktualizacjami 25.07 i 6.08.2011 — https://czarykuchenne.blogspot.com/2011/06/uwaga-serwis-durszlakpl-zosta-zamkniety.html .

**FAKT Z OGRANICZENIEM.** Artykuł z 24.06.2011 podawał, że w pierwszej dobie po powrocie dodano ponad 200 blogów; liczba pochodzi z komunikacji wokół serwisu, nie z niezależnego audytu. Źródło: e-biznes.pl, 24.06.2011 — https://e-biznes.pl/durszlak-wraca-do-sieci/?k=r&r=2011 .

**FAKT.** Część blogerów utrzymała konkretną akcję („Truskawki 2011”) poza Durszlakiem, przyjmując zgłoszenia przez link/e-mail. Źródło: Kuchenne Wędrówki, 9.06.2011 — https://kuchenne-wedrowki.blogspot.com/2011/06/durszlak-is-dead-ale-truskawkowa-akcja.html .

**WNIOSEK — mechanika:** **rytuał jest bardziej przenośny niż platforma**. Ludzie potrafią odtworzyć go ręcznie, jeśli ktoś jest gospodarzem i wiadomo, co wspólnie robimy.

**Dla Kuking:** nie potrzeba kolejnego systemu punktów. Wystarczy konsekwentny, ludzki rytm gospodarza i istniejące `Ugotowałem`, odpowiedzi, wspomnienia oraz tygodniowe podsumowanie.

## 2.6. Wykop → Hejto: fala rejestracji nie jest retencją

**FAKT.** 17.01.2023 post na Hejto o migracji z Wykop 2.0 zebrał 4309 głosów w ankiecie, 5138 reakcji i 229 komentarzy. To **samoselekcyjna ankieta**, nie liczba wszystkich migrantów. Źródło: Hejto, 17.01.2023 — https://www.hejto.pl/wpis/migracja-z-wykop-2-0-do-hejto-sprawdzmy-ilu-nas-tu-teraz-jest-zasady-sa-proste-1 .

**FAKT.** W komentarzach użytkownicy od razu testowali podstawowe rzeczy: aplikację, własne wpisy, RSS, nick/profil; pojawiały się też uwagi o wolnym działaniu, wylogowaniu i tarciu przy nazwie konta. Źródło: ten sam wątek, 17.01.2023.

**WNIOSEK — mechanika:** podczas migracyjnego „okna emocji” produkt dostaje jeden kredyt zaufania. Pierwsza sesja musi dowieźć **rdzeń**, nie roadmapę. Awaria publikacji, problem z logowaniem albo niemożność odnalezienia ludzi kosztuje wtedy więcej niż w spokojnym wzroście organicznym.

## 2.7. Wspólny wzorzec — co naprawdę przenosi społeczność

| Mechanika | Co daje | Dowód z przypadków | Wniosek dla Kuking |
|---|---|---|---|
| **Odzyskanie ludzi** | Natychmiastowy sens feedu i komentarzy | Wizaż→Netkobiety; Flickr | Priorytet nr 1: znana osoba musi być łatwa do znalezienia/zaproszenia. |
| **Przeniesienie rytuału** | Powód, by wrócić jutro/za tydzień | Durszlak, Cookpad (regularne wyzwania) | Wykorzystać gospodarza, `Ugotowałem`, wspomnienia, digest. Nie dodawać punktów. |
| **Archiwum / własność** | Motywacja do założenia konta i poczucie bezpieczeństwa | Garnek/Elektroda/Wizaż, Google+ Takeout | Komunikować własne archiwum i eksport; nie budować martwego importera Garnka. |
| **Jedna osoba-gospodarz** | Pomoc, normy, poczucie „ktoś tu jest” | Wizaż→Netkobiety, akcje Durszlaka | D-037 to mechanika retencji, nie dekoracja. |
| **Niskie tarcie wejścia** | Nie urywa migracji na haśle/telefonie | Flickr/Yahoo, Wizaż mobilnie, Hejto | D-056 i D-053 są właściwym kierunkiem; testować realne telefony 50+. |
| **Możliwość pisania po swojemu** | Zachowuje tożsamość grupy | Hejto/Wykop, Wizaż | Nie wymuszać pełnego przepisu ani „ładnego zdjęcia”; zwykły wpis ma zostać zwykłym wpisem. |
| **Własna tożsamość nowego miejsca** | Powód, by nie wrócić do dominującej platformy | NK→Facebook | Kuking ma być Kuking, nie „nowy Garnek” i nie „mały Facebook”. |

---

# 3. CO Z TEGO WYNIKA DLA KUKING

## 3.1. Priorytet 1 — odzyskanie znanej osoby w onboardingu

### FAKT w obecnym produkcie

`app/Http/Controllers/OnboardingController.php::people()` proponuje osiem osób z `DailyBoard`. `resources/views/pages/onboarding/people.blade.php` pozwala je zaznaczyć albo pominąć. Osobno istnieje już wyszukiwanie ludzi: `app/Http/Controllers/SearchController.php` + `resources/views/pages/search.blade.php`, zakres `sekcja=ludzie`. Nie ma jednak na ekranie onboardingu prostego przejścia **„Znajdź osobę, którą już znasz”**.

### WNIOSEK

Migranta bardziej interesuje Basia, którą zna od lat, niż ośmiu poprawnie dobranych nieznajomych. Najtańsza poprawka to nie nowy silnik rekomendacji, tylko wejście do funkcji, która już istnieje.

### Co zrobić

- `resources/views/pages/onboarding/people.blade.php` — nad listą lub bezpośrednio pod nią dodać zwykły odnośnik: **„Znasz już kogoś tutaj? Znajdź tę osobę”** → `route('search', ['sekcja' => 'ludzie'])`.
- `resources/views/auth/register.blade.php` — zmienić pomoc przy `display_name` z bardzo ogólnego „Imię, przezwisko albo cokolwiek chcesz” na kierunkową, ale neutralną wobec Garnka: **„Imię lub pseudonim, po którym znajomi Cię rozpoznają.”** Nie dodawać osobnego pola „nick z Garnka”.
- Nie dodawać importu książki adresowej, kontaktów Google ani Facebook Graph API.

### Jak poznamy, że pomogło

**HIPOTEZA / warunek akceptacji:** w teście zadaniowym 5 osób 50+ dostaje informację „Twoja znajoma ma w Kuking nazwę `X`; znajdź ją i zacznij obserwować”. **Co najmniej 4/5** wykonuje zadanie bez podpowiedzi w **≤ 2 minuty**, na telefonie. Test powtórzyć z JavaScriptem wyłączonym — wynik ma być taki sam funkcjonalnie.

**Warunek produktu po kampanii:** na pierwszej kohorcie kampanii sprawdzić razem z istniejącymi progami `RETENTION_LOOPS.md`, czy D7/second-post nie spada poniżej ustalonego tam poziomu ostrzegawczego. Nie przypisywać wzrostu temu jednemu linkowi bez eksperymentu — to ma być bramka bezpieczeństwa, nie fałszywa atrybucja.

### Dane / RODO

Samo dodanie odnośnika i zmiana copy **nie tworzą nowej kategorii danych**. Wyszukiwarka już działa i obecny `SEARCH_PERFORMED` zapisuje tylko długość frazy oraz `has_results`, nigdy frazę (`SearchController`, `ZapiszSygnal`). `product_signals` mają **90 dni retencji** (`docs/research/ANALITYKA_STAN_WDROZENIA.md`, `kuking:sprzataj-sygnaly`). Jeśli zakres sygnału kiedyś rozszerzyć o `section=ludzie`, podstawą powinien być **uzasadniony interes — art. 6 ust. 1 lit. f RODO**, po zapisaniu LIA dla celu „pomiar tarcia wyszukiwarki”; retencja pozostaje 90 dni, bez nicku, frazy i adresu IP. Źródło prawne: RODO, art. 5 i 6, tekst oficjalny UE — https://eur-lex.europa.eu/eli/reg/2016/679/2016-05-04 (rozporządzenie z 27.04.2016, odczyt 10.09.2026).

## 3.2. Priorytet 2 — własny profil ma być „kartką z adresem”, którą łatwo wysłać jednej osobie

### FAKT w obecnym produkcie

Publiczny profil ma stabilny adres `/@{username}` (`routes/web.php`, `resources/views/pages/profile/show.blade.php`). Kuking ma już zaprojektowane bezpieczne udostępnianie treści w `app/Domain/Sharing/Udostepnianie.php`: zwykłe linki WhatsApp/e-mail/Facebook działające bez JS, a `navigator.share` jest wyłącznie ulepszeniem progresywnym zgodnie z D-044. Ten mechanizm obsługuje wpisy/przepisy, nie profil.

### WNIOSEK

Jednostką migracji nie powinien być „kontakt zaimportowany do Kuking”, tylko **publiczny adres profilu**, który użytkownik sam wyśle osobie, którą zna. To zachowuje kontrolę po stronie człowieka i nie wymaga przetwarzania cudzej książki adresowej.

### Co zrobić

- `resources/views/pages/profile/show.blade.php` — na **własnym** profilu pokazać akcję „Wyślij swój profil znajomym”.
- Reużyć wzorzec D-044: wersja podstawowa bez JS ma dać co najmniej gotowy adres oraz drogi, które działają zwykłym URL-em; arkusz systemowy może być dodatkiem.
- Jeśli obecny komponent udostępniania nie da się bezpiecznie rozszerzyć na profil bez warunków specyficznych dla `Post`/`Recipe`, nie wciskać `Profile` do `Udostepnianie` na siłę. Lepsza jest mała, jawna klasa dla publicznego profilu niż `instanceof` rozrastający się do ogólnego „share wszystkiego”.
- Nie pytać, **komu** użytkownik wysyła. Nie pobierać kontaktów.

### Jak poznamy, że pomogło

**HIPOTEZA / warunek akceptacji:** 5 testerów 50+ ma zadanie „wyślij komuś swój profil przez aplikację, której zwykle używasz”. **4/5** potrafi doprowadzić do gotowej wiadomości/linku bez instrukcji w **≤ 90 sekund**. Na komputerze bez obsługi `navigator.share` nadal istnieje kompletna droga bez JS.

### Dane / RODO

Kuking nie potrzebuje danych odbiorcy. Systemowy arkusz udostępniania i `mailto:`/WhatsApp przekazują wybór odbiorcy do urządzenia/usługi zewnętrznej — **retencja po stronie Kuking: 0 nowych danych odbiorcy**. Sam publiczny URL profilu wykorzystuje dane już publikowane w ramach usługi; proponowana zmiana nie rozszerza zakresu publikacji. Jeśli kiedyś mierzyć klik „udostępnij profil”, wystarczy zdarzenie enum bez odbiorcy/URL/nicku; podstawa art. 6 ust. 1 lit. f RODO po LIA, retencja maksymalnie taka jak obecne `product_signals` — 90 dni. Oficjalny tekst RODO: https://eur-lex.europa.eu/eli/reg/2016/679/2016-05-04 .

## 3.3. Priorytet 3 — archiwum ma być obietnicą, nie importerem

### FAKT

Elektroda (8.11.2024) i Wizaż (27.08.2024) pokazują realny ból utraty wieloletnich archiwów Garnek: https://www.elektroda.pl/rtvforum/topic4085600.html oraz https://rozmowy.wizaz.pl/kobieta/forum-plotkowe/wiza%C5%BCowe-spo%C5%82eczno%C5%9Bci/657357-wirtualna-przyja%C5%BA%C5%84-4/page114 . Jednocześnie sam Garnek jest dziś 410, a publiczna kopia ArchiveTeam jest opisana jako częściowa: https://wiki.archiveteam.org/index.php/Garnek.pl (odczyt 10.09.2026).

### WNIOSEK

Budowanie importera „z Garnka” byłoby kosztowne, kruche i spóźnione. Ale potrzeba stojąca za importerem jest nadal żywa: **„mam stare zdjęcia na dysku i chcę mieć swoje miejsce, gdzie nie znikną”**.

### Co zrobić

- `resources/views/pages/posts/create.blade.php` — obok zdania „Wybierz zdjęcie z telefonu…” dodać krótką, neutralną informację: **„Może być z dzisiaj albo starsze zdjęcie z Twojego archiwum.”**
- Nie zmieniać modelu danych, nie dodawać „trybu migracji”, nie dodawać źródła `garnek` do wpisu.
- Utrzymać istniejące „od teraz masz swoje archiwum” po pierwszej publikacji oraz eksport danych.

### Jak poznamy, że pomogło

**HIPOTEZA / warunek akceptacji:** po obejrzeniu samego ekranu dodawania zdjęcia co najmniej **4/5 testerów 50+** poprawnie odpowiada na pytanie „czy możesz tu dodać zdjęcie obiadu sprzed kilku lat?” bez dodatkowego wyjaśnienia. To test zrozumienia copy, nie licznik „starych zdjęć” — nie potrzebujemy analizować EXIF ani dat plików.

### Dane / RODO

Zmiana copy nie tworzy nowego przetwarzania. Nie zapisujemy daty wykonania zdjęcia ani „pochodzenia z Garnka”; pipeline nadal usuwa EXIF/GPS. **Brak nowej podstawy i brak dodatkowej retencji.** Dla obecnego przetwarzania zdjęć obowiązuje aktualna podstawa opisana w dokumentach prawnych produktu — jej treści ten research nie zmienia.

## 3.4. Priorytet 4 — migracyjny „go/no-go” mierzyć drugim ruchem, nie rejestracją

### FAKT w repo

`docs/product/RETENTION_LOOPS.md` ma już progi jakości: dla wczesnego etapu każdy nowy wpis ma dostać odpowiedź w 24 h, a spadek `second_post_7d` poniżej 35% jest sygnałem ostrzegawczym; docelowo przy większej próbie dokument zakłada wyższy poziom. `docs/product/COLD_START.md` mówi wprost, żeby nie rosnąć szybciej, niż gospodarz jest w stanie odpowiadać. `docs/research/ANALITYKA_STAN_WDROZENIA.md` opisuje istniejące `kuking:raport`, WAC oraz D7/D30.

### WNIOSEK

Kampania migracyjna może wyglądać świetnie w rejestracjach i jednocześnie przegrywać produktowo. Prawdziwą bramką jest **drugi ruch**: powrót, drugi wpis, komentarz, obserwowanie, `Ugotowałem`.

### Co zrobić

Nie budować nowego dashboardu. Przed każdą kolejną falą kampanii właściciel ma sprawdzić istniejące wskaźniki i trzy pytania:

1. Czy pierwsze wpisy nowych osób dostały ludzką odpowiedź zgodnie z progiem `RETENTION_LOOPS.md`?
2. Czy `second_post_7d` nie jest poniżej progu ostrzegawczego 35%?
3. Czy `kuking:raport` pokazuje rzeczywiste D7, a nie wyłącznie przyrost kont?

Miejsca: `docs/product/RETENTION_LOOPS.md`, `docs/product/COLD_START.md`, `app/Domain/Analytics/*`, `docs/research/ANALITYKA_STAN_WDROZENIA.md`. To **operacyjna bramka**, nie nowy system analityczny.

### Jak poznamy, że pomogło

**Warunek zamknięcia:** pierwsza fala kampanii jest oceniona po D7 z wpisanymi realnymi liczbami; jeśli `second_post_7d < 35%` albo nowe wpisy nie dostają odpowiedzi zgodnie z istniejącą bramką, następnej fali **nie zwiększamy**, tylko naprawiamy przyczynę. To wykorzystuje już zatwierdzone progi, nie wymyśla nowych KPI pod ten dokument.

### Dane / RODO

Nie proponuję nowego trackera. Istniejące `product_signals` mają 90 dni retencji; `ostatnio_widziany_at` jest pojedynczą nadpisywaną wartością konta, nie historią odwiedzin (`ANALITYKA_STAN_WDROZENIA.md`). Jeśli robi się ręczne zestawienie kampanii, przechowywać **agregaty**, nie eksport e-maili/nicków. Podstawa dla wewnętrznej analityki produktu: art. 6 ust. 1 lit. f RODO po LIA; minimalizacja i ograniczenie przechowywania wynikają z art. 5. Oficjalny tekst: https://eur-lex.europa.eu/eli/reg/2016/679/2016-05-04 .

## 3.5. Priorytet 5 — HEIC potraktować jako ryzyko pierwszej sesji, nie jako debatę technologiczną

### FAKT zewnętrzny

Apple opisuje HEIF/HEVC jako obsługiwane formaty zdjęć/wideo na współczesnych urządzeniach i wskazuje ustawienie „Most Compatible” jako drogę do JPEG/H.264. Źródło: Apple Support, aktualizacja 5.12.2025, odczyt 10.09.2026 — https://support.apple.com/en-la/116944 .

### FAKT w repo

D-064 świadomie odrzuca HEIC/HEIF z komunikatem co zrobić; `photo_upload_failed` ma osobny `reason=heic_unsupported`, a `product_signals` mają 90 dni retencji. To jest już mierzalne bez dodawania biblioteki, mikroserwisu czy zewnętrznego analityka.

### HIPOTEZA

Dla części realnych użytkowników 50+ z iPhone’em pierwszy wybrany plik może być HEIC. **Skala w grupie docelowej Kuking jest [do weryfikacji]** — nie wolno jej zgadywać na podstawie globalnego udziału iPhone’ów.

### Co zrobić

- Przed kampanią wykonać test na **5 realnych iPhone’ach należących do osób z grupy docelowej**, z ich normalnymi ustawieniami aparatu i zdjęciami z ich galerii.
- Sprawdzić nie tylko „czy plik wchodzi”, ale czy po komunikacie użytkownik umie bez pomocy opublikować **jakiekolwiek własne zdjęcie w tej samej sesji**.
- Po starcie obserwować istniejący `photo_upload_failed / heic_unsupported`; nie zapisywać nazw plików.
- Nie zmieniać D-064 na podstawie samego researchu. Jeśli test wykaże realne, nieodwracalne przerwanie pierwszej publikacji — wrócić do właściciela z danymi i osobną decyzją.

Miejsca: `docs/DECISIONS.md` D-064, `app/Support/RozpoznanieZdjecia.php`, `app/Domain/Analytics/ZapiszSygnal.php`, `docs/research/ANALITYKA_STAN_WDROZENIA.md`, formularze uploadu.

### Jak poznamy, że pomogło

**Warunek testu:** 5/5 testerów potrafi zakończyć pierwszą publikację zdjęcia w tej samej sesji. Jeśli choć jedna osoba utknie **wyłącznie** przez HEIC mimo prawidłowego komunikatu i nie potrafi samodzielnie obrać drogi dalej, to nie jest automatycznie zgoda na `libheif`, ale jest spełniony warunek do ponownej decyzji właściciela.

### Dane / RODO

Użyć istniejącego sygnału `photo_upload_failed` bez nazwy pliku/treści. Podstawa analityki technicznej: art. 6 ust. 1 lit. f RODO po LIA; retencja: istniejące **90 dni** i automatyczne `kuking:sprzataj-sygnaly`. Nie zwiększać retencji dla kampanii. Źródło prawne: https://eur-lex.europa.eu/eli/reg/2016/679/2016-05-04 .

## 3.6. Co już jest dobre i NIE wymaga nowego feature’u

**WNIOSEK.** Research wzmacnia kilka już podjętych decyzji, więc nie tworzę dla nich nowych rekomendacji:

- **D-037 — prawdziwy gospodarz:** migracje małych społeczności potrzebują osoby, która przeprowadza ludzi i odpowiada.
- **D-056 — magic link + hasło równolegle:** przypadki Flickr/Wizaż pokazują, że konto/hasło potrafi być barierą, ale nie ma powodu odbierać ludziom znanej metody logowania.
- **D-044 — zwykłe linki + systemowe udostępnianie jako dodatek:** dobre dla Messenger/WhatsApp bez importowania kontaktów.
- **Chronologiczny feed obserwowanych:** nie ma dowodu, że migrantowi potrzebny jest algorytm; przypadki Flickr/NK raczej pokazują wartość przewidywalności i własnej tożsamości.
- **D-057 — tygodniowy digest:** pomaga wrócić bez budowania uzależniającej mechaniki; ma jawne wypisanie i własny próg jakości.
- **„Zdjęcie + kilka słów” bez wymogu perfekcji:** to lepiej pasuje do migracji z fotoblogowej kultury niż wymuszanie kompletnego przepisu jak w wyzwaniach Cookpad.

## 3.7. Czego NIE robić

| Nie robić | Dlaczego |
|---|---|
| **Nie nazywać Kuking „nowym Garnkiem”, „następcą Garnka” ani „Garnkiem 2.0”.** | Łamie granicę projektu, ryzykuje pasożytniczą tożsamość i powtarza mechanikę NK: naśladowanie większego/innego serwisu rozmywa własny powód istnienia. Źródło dla retrospektywy NK: Wirtualne Media, 28.07.2021 — https://www.wirtualnemedia.pl/koniec-nasza-klasa-znika-swojskie-medium-spolecznosciowe%2C7170147801536129a . |
| **Nie budować importera Garnek ani scrapera ArchiveTeam.** | Garnek jest 410, archiwum publiczne jest częściowe, brak stabilnego kontraktu danych; duży koszt utrzymania i ryzyko praw/autorstwa. Potrzebę archiwum rozwiązujemy prostszą drogą: własne pliki użytkownika + eksport Kuking. |
| **Nie importować książki adresowej / kontaktów Facebook/Google.** | Najcenniejszą mechanikę „znajdź swoich” da się osiągnąć istniejącą wyszukiwarką i publicznym linkiem profilu bez zbierania danych osób, które nigdy nie weszły do Kuking. Mniej danych = mniej ryzyka RODO i mniej integracji do utrzymania. |
| **Nie robić obowiązkowej aplikacji mobilnej.** | W badanym przypadku Wizaż/Netkobiety tarcie mobilne już utrudniało migrację; zmuszanie do sklepu z aplikacjami doda kolejny próg. Kuking ma PWA/mobile web i jednego właściciela. Aplikacja natywna wraca do dyskusji dopiero, jeśli testy wykażą konkretne zadanie niemożliwe do wykonania w web/PWA. Źródło problemu mobilnego: Wizaż, 18–19.11.2023 — https://wizaz.pl/forum/showthread.php?page=152&t=1289367 . |
| **Nie wymuszać pełnego przepisu przy zwykłym zdjęciu.** | Cookpad może wymagać struktury w konkretnym wyzwaniu, ale Kuking ma inną jednostkę społecznościową. Przymus zamieniłby prostą publikację w formularz i odciął kulturę „pokaż co dziś gotujesz”. Źródło wymagań Cookpad: 7–13.09.2026 — https://cookpad.com/pl/challenges/13364 . |
| **Nie dodawać punktów, top użytkowników, rankingu zdjęć ani „najpopularniejszych Garnkowiczów”.** | Łamie AGENTS §12; Flog pokazuje, że takie mechaniki istnieją w społecznościach foto, ale to dokładnie inny model motywacji. Źródło Flog: 29–30.01.2026 — https://forum.flog.pl/zdjecie-dnia-8 . |
| **Nie algorytmizować feedu obserwowanych pod kampanię.** | Migrant chce odzyskać znane osoby i ich chronologię. Brak dowodu, że ranking feedu rozwiązuje problem migracji; za to narusza twardą zasadę produktu. |
| **Nie spamować grup kulinarnych linkiem do Kuking.** | Smaker publicznie zabrania promocji usług osób trzecich; w pozostałych dużych grupach aktualna tolerancja linków jest [do weryfikacji]. Zgoda administratora jest warunkiem wejścia. Źródło Smaker: 6.12.2021 — https://smaker.pl/faq-regulamin-grupy-na-facebooku-smaker-sprawdzone-przepisy%2C1902998%2Ca%2C.html . |
| **Nie dodawać pola „nick z Garnka”.** | To przywiązuje tożsamość Kuking do obcej platformy i tworzy dodatkową daną bez konieczności. Wystarczy obecny `display_name`/`username`, jeśli copy zachęci do rozpoznawalnego pseudonimu. |
| **Nie mierzyć migracji przez surowe frazy wyszukiwania, odbiorców udostępnienia ani książkę kontaktów.** | Nie są potrzebne do odpowiedzi „czy ludzie znajdują ludzi i wracają”. Obecna analityka Kuking celowo nie zapisuje frazy wyszukiwania i ma 90-dniową retencję sygnałów. |

## 3.8. Gdzie zasady projektu kosztują nas użytkowników

### 1. HEIC bez obsługi może kosztować część pierwszych publikacji

**FAKT:** Apple aktywnie wspiera HEIF/HEIC; źródło Apple z 5.12.2025 — https://support.apple.com/en-la/116944 . **HIPOTEZA:** część grupy 50+ Kuking trafi z takim plikiem; skala [do weryfikacji]. D-064 jest więc realnym kosztem wygody. Nie obchodzę decyzji — rekomenduję pomiar na prawdziwych telefonach i powrót do właściciela tylko przy realnym zerwaniu pierwszej sesji.

### 2. Brak aplikacji natywnej może odrzucić część osób przyzwyczajonych do ikony/aplikacji

**FAKT:** w podobnej migracji Wizaż→Netkobiety pojawiało się tarcie „muszę odpalić laptop / na telefonie jest trudno”; źródło 18–19.11.2023 — https://wizaz.pl/forum/showthread.php?page=152&t=1289367 . **WNIOSEK:** to koszt, ale budowanie i utrzymanie osobnych aplikacji przy jednym właścicielu byłoby dziś nieproporcjonalne. PWA i bardzo dobry mobile web są właściwą kompensacją; aplikację rozważać dopiero po zaobserwowanym problemie, nie z założenia.

### 3. Brak algorytmicznego feedu/rankingów rezygnuje z części „growth hacków”

**FAKT:** współczesne serwisy kulinarne/foto używają popularności, punktów i wyróżnień — przykłady: Smaker (popularni kucharze, odczyt 10.09.2026) https://smaker.pl/ ; Cookpad (punkty w wyzwaniu 7–13.09.2026) https://cookpad.com/pl/challenges/13364 ; Flog („zdjęcie dnia”, 29–30.01.2026) https://forum.flog.pl/zdjecie-dnia-8 . **WNIOSEK:** Kuking świadomie rezygnuje z części mechanik zwiększających ekspozycję/rywalizację. To może kosztować pewien poziom krótkoterminowego engagementu — **skala w Kuking [do weryfikacji]** — ale ich dodanie zniszczyłoby obietnicę spokojnej, chronologicznej społeczności i złamało AGENTS §12. Nie rekomenduję zmiany zasady.

### 4. „Ważne rzeczy bez JS” zwiększa koszt implementacji, ale obniża koszt migracji człowieka

**WNIOSEK:** trzeba projektować dwie warstwy dla części interakcji (np. udostępnianie), co kosztuje czas właściciela. Z drugiej strony właśnie w grupie 50+ i podczas migracji awaria skryptu/słabsze urządzenie nie może zamieniać podstawowej czynności w ślepą ścianę. Przypadki Wizaż/Hejte pokazują, że tarcie pierwszej sesji jest realne; źródła: Wizaż 18–19.11.2023 — https://wizaz.pl/forum/showthread.php?page=152&t=1289367 ; Hejto 17.01.2023 — https://www.hejto.pl/wpis/migracja-z-wykop-2-0-do-hejto-sprawdzmy-ilu-nas-tu-teraz-jest-zasady-sa-proste-1 . Zasada kosztuje development, ale nie widzę dowodu, że kosztuje więcej użytkowników, niż chroni.

---

# 4. RANKING — GOTOWE DO ZAMIANY NA ISSUES

> Kolejność celowo omija rzeczy już opisane w `docs/product/PROSTOTA_JAK_GARNEK.md`.

## 1. ZROBIĆ W TYM TYGODNIU · mały koszt

### **Onboarding: „Znajdź osobę, którą już znasz” + rozpoznawalna nazwa przy rejestracji**

**Uzasadnienie:** inne migracje pokazują, że odzyskanie znanych ludzi jest ważniejsze niż rekomendacja nieznajomych; Kuking ma już wyszukiwarkę ludzi, więc brakuje głównie wejścia do niej.

**Zakres:** `resources/views/pages/onboarding/people.blade.php`, `resources/views/auth/register.blade.php`; bez nowej trasy i bez nowej tabeli.

**Warunek zamknięcia:** 4/5 testerów 50+ na telefonie, bez podpowiedzi, znajduje wskazane istniejące konto i zaczyna je obserwować w ≤2 min; ta sama ścieżka działa z JS wyłączonym.

## 2. ZROBIĆ W TYM TYGODNIU · mały/średni koszt

### **Własny profil: „Wyślij swój profil znajomym” bez importu kontaktów**

**Uzasadnienie:** publiczny adres profilu jest najtańszą „jednostką migracji” małej grupy — człowiek sam wybiera, komu go wysyła, a Kuking nie zbiera cudzych kontaktów.

**Zakres:** `resources/views/pages/profile/show.blade.php`; reużyć zasady D-044 i wzorzec `app/Domain/Sharing/Udostepnianie.php`, bez robienia `navigator.share` jedyną drogą.

**Warunek zamknięcia:** 4/5 testerów 50+ doprowadza do gotowego wysłania własnego profilu przez używaną przez siebie aplikację w ≤90 s; na desktopie/no-JS nadal istnieje zwykły, działający sposób; w bazie Kuking nie pojawia się żaden adres/telefon odbiorcy.

## 3. ZROBIĆ W TYM TYGODNIU · bardzo mały koszt

### **Dodawanie zdjęcia: powiedz, że starsze zdjęcie z własnego archiwum też pasuje**

**Uzasadnienie:** utrata archiwum była jednym z najmocniejszych problemów przy zamknięciu Garnka, ale nie potrzebujemy importera, żeby powiedzieć użytkownikowi, że jego stare własne zdjęcia są mile widziane.

**Zakres:** wyłącznie copy w `resources/views/pages/posts/create.blade.php`.

**Warunek zamknięcia:** po obejrzeniu formularza co najmniej 4/5 testerów 50+ bez podpowiedzi odpowiada, że może dodać zdjęcie sprzed kilku lat; zero zmiany modelu danych i zero zapisu daty/źródła zdjęcia.

## 4. PRZED PIERWSZĄ WIĘKSZĄ FALĄ KAMPANII · mały koszt operacyjny

### **Migracyjny go/no-go: oceniaj D7 i drugi wpis, nie liczbę rejestracji**

**Uzasadnienie:** przypadek Hejto pokazuje, że fala wejść może być ogromna i jednocześnie natychmiast ujawniać tarcie; Kuking ma już własne progi retencji i analitykę powrotów.

**Zakres:** bez nowego dashboardu; użyć `docs/product/RETENTION_LOOPS.md`, `docs/product/COLD_START.md`, `docs/research/ANALITYKA_STAN_WDROZENIA.md` i istniejącego `kuking:raport`.

**Warunek zamknięcia:** po pierwszej fali istnieje zapis realnych wyników D7/second-post oraz odpowiedzi na pierwsze wpisy; jeśli `second_post_7d < 35%` lub bramka odpowiedzi z `RETENTION_LOOPS.md` nie jest spełniona, kolejna fala nie jest zwiększana.

## 5. PRZED KAMPANIĄ · mały koszt, duże ryzyko do zdjęcia

### **HEIC: test pierwszego uploadu na 5 iPhone’ach grupy docelowej**

**Uzasadnienie:** D-064 świadomie odrzuca HEIC, a migracyjna pierwsza sesja ma mały margines na awarię; nie znamy jednak skali problemu w realnej grupie Kuking.

**Zakres:** test urządzeń + odczyt istniejącego `photo_upload_failed.reason=heic_unsupported`; `docs/DECISIONS.md` D-064, `app/Support/RozpoznanieZdjecia.php`, `app/Domain/Analytics/ZapiszSygnal.php`, `docs/research/ANALITYKA_STAN_WDROZENIA.md`.

**Warunek zamknięcia:** 5/5 testerów potrafi opublikować własne zdjęcie w tej samej sesji; raport testu podaje osobno przypadki HEIC i sposób wyjścia z błędu, bez nazw plików i bez wydłużenia 90-dniowej retencji sygnałów.

## 6. PO PIERWSZYCH 20–50 REALNYCH OSOBACH · średni koszt badawczy

### **Test „czy przeszła moja mała grupa”: znana osoba → profil → komentarz → powrót**

**Uzasadnienie:** najsilniejsza mechanika ze wszystkich migracji to nie „mam konto”, tylko „są tu moi ludzie i rozmawiamy tak jak wcześniej”.

**Zakres:** sesja zadaniowa oparta na istniejących ekranach: rejestracja → `onboarding.people` → `search?sekcja=ludzie` → profil → obserwowanie → wpis/komentarz; nie budować nowego feature’u do samego badania.

**Warunek zamknięcia:** 5/5 uczestników potrafi wskazać, gdzie szuka znanej osoby; 4/5 potrafi ją znaleźć i obserwować bez pomocy; po 7 dniach raportujemy istniejące D7/second-post dla kohorty, bez surowego eksportu e-maili/nicków.

## 7. DUŻA ZMIANA · wyłącznie po danych, wymaga decyzji właściciela

### **HEIC: ponowna decyzja o obsłudze tylko jeśli pilotaż pokaże zerwanie pierwszej publikacji**

**Uzasadnienie:** obsługa HEIC może usunąć realną barierę, ale dokładanie biblioteki/dekodowania bez danych łamie zasadę „nie budujemy na zapas” i zwiększa koszt utrzymania media pipeline.

**Zakres:** **nie implementować w ramach issue decyzyjnego**. Zebrać wynik issue #5 wyżej, istniejące sygnały `heic_unsupported`, koszt pamięci/CPU oraz możliwe warianty mieszczące się w monolicie. Nie rezerwować numeru w `DECISIONS.md`; numerację prowadzi właściciel.

**Warunek zamknięcia:** właściciel wybiera jawnie jedną z dwóch dróg: (A) D-064 zostaje, bo test pokazuje akceptowalną drogę wyjścia, albo (B) obsługujemy HEIC w monolicie po osobnym projekcie technicznym z limitem pamięci, testami bezpieczeństwa i planem wycofania. Decyzja opiera się na danych z realnych użytkowników, nie na globalnym udziale iPhone’ów.

---

# Źródła — szybki indeks

Wszystkie linki były sprawdzane podczas researchu 10.09.2026, chyba że przy pozycji podano inną datę publikacji.

1. Garnek — stan 410: https://garnek.pl  
2. ArchiveTeam, Garnek.pl: https://wiki.archiveteam.org/index.php/Garnek.pl  
3. Elektroda, ratowanie zdjęć, 8.11.2024: https://www.elektroda.pl/rtvforum/topic4085600.html  
4. Forum Ogrodnicze, ostrzeżenie 23.11.2024 i późniejszy hosting: https://forumogrodnicze.info/viewtopic.php?start=15&t=125504  
5. Wizaż, „Wirtualna przyjaźń 4”, 27.08.2024: https://rozmowy.wizaz.pl/kobieta/forum-plotkowe/wiza%C5%BCowe-spo%C5%82eczno%C5%9Bci/657357-wirtualna-przyja%C5%BA%C5%84-4/page114  
6. Top Lista Adama / „Garnkowicze”, informacja od 29.11.2024: https://toplistaadama.siteor.pl/top10  
7. Facebook, „Garnkowicze”: https://www.facebook.com/groups/1520324101919548  
8. Smaker, regulamin grupy, 6.12.2021: https://smaker.pl/faq-regulamin-grupy-na-facebooku-smaker-sprawdzone-przepisy%2C1902998%2Ca%2C.html  
9. Facebook, Smaker: https://www.facebook.com/groups/smakerpl/  
10. „1000 pomysłów na obiad i nie tylko”, opis 1.06.2023: https://jakiprezent.pl/grupa-facebookowa-1000-pomyslow-na-obiad-i-nie-tylko/  
11. DanD, zestawienie grup FB, odczyt 10.09.2026: https://dand.ai/facebook-groups/biggestfoodgroups  
12. OneUp, zestawienie grup FB, 2025: https://blog.oneupapp.io/largest-fb-groups/  
13. Polish Foodies: https://polishfoodies.com/  
14. Polish Foodies — współpraca: https://polishfoodies.com/work-with-me/  
15. Kwestia Smaku: https://www.kwestiasmaku.com/  
16. Cookpad — tworzenie: https://cookpad.com/pl/create  
17. Cookpad — wyzwanie 7–13.09.2026: https://cookpad.com/pl/challenges/13364  
18. Mikser o „śp. Durszlak.pl”, 21.01.2025: https://zmiksowani.pl/forum/mikser-kulinarny/zmiany-w-serwisie-2025  
19. Fotosik społeczność: https://www.fotosik.pl/spolecznosc  
20. Flog, „zdjęcie dnia”, 29–30.01.2026: https://forum.flog.pl/zdjecie-dnia-8  
21. Google, sunset Google+, 30.01.2019: https://workspace.google.com/blog/product-announcements/what-you-need-to-know-about-the-sunset-of-consumer-google-plus-on-april-second  
22. Import Google+ Takeout → MeWe, 16.03.2019: https://www.cnx-software.com/2019/03/16/how-import-google-plus-mewe/  
23. Rozproszenie społeczności Google+, 2.02.2019: https://www.googlewatchblog.de/2019/02/in-sache-nach-aus/  
24. Flickr — aplikacja podobna do Instagram + Yahoo account, 17.04.2014: https://www.engadget.com/2014-04-17-flickr-instagram-like-app-update.html  
25. Flickr — znajomi/społeczność, 8.05.2014: https://www.engadget.com/2014-05-08-flickr-3-0-app.html/  
26. Flickr — brak dodawania do albumów/grup na Androidzie, 25.04.2014: https://www.flickr.com/help/forum/en-us/72157644299398354/  
27. Netkobiety, „Zagroda babuszki”, 14.11.2023: https://www.netkobiety.pl/viewtopic.php?id=132353  
28. Wizaż, migracja/Discord/telefon, 18–19.11.2023 i 3.12.2023: https://wizaz.pl/forum/showthread.php?page=152&t=1289367  
29. Wizaż, problemy mobilne/hasła, 25–26.12.2021: https://wizaz.pl/forum/showthread.php?t=1284501  
30. Rzeczpospolita, Facebook vs NK, 24.01.2011: https://www.rp.pl/media/art14762571-facebook-ma-coraz-wiecej-uzytkownikow-nasza-klasa-mniej  
31. Wirtualne Media, koniec NK, 28.07.2021: https://www.wirtualnemedia.pl/koniec-nasza-klasa-znika-swojskie-medium-spolecznosciowe%2C7170147801536129a  
32. Czary Kuchenne, zniknięcie/powrót Durszlaka, 9.06.2011 + aktualizacje: https://czarykuchenne.blogspot.com/2011/06/uwaga-serwis-durszlakpl-zosta-zamkniety.html  
33. e-biznes.pl, Durszlak wraca, 24.06.2011: https://e-biznes.pl/durszlak-wraca-do-sieci/?k=r&r=2011  
34. Kuchenne Wędrówki, akcja po Durszlaku, 9.06.2011: https://kuchenne-wedrowki.blogspot.com/2011/06/durszlak-is-dead-ale-truskawkowa-akcja.html  
35. Hejto, migracja z Wykop, 17.01.2023: https://www.hejto.pl/wpis/migracja-z-wykop-2-0-do-hejto-sprawdzmy-ilu-nas-tu-teraz-jest-zasady-sa-proste-1  
36. Apple Support, HEIF/HEVC, aktualizacja 5.12.2025: https://support.apple.com/en-la/116944  
37. RODO — oficjalny tekst: https://eur-lex.europa.eu/eli/reg/2016/679/2016-05-04  
38. DSA — oficjalny tekst: https://eur-lex.europa.eu/eli/reg/2022/2065  

## Ostatni wniosek

**WNIOSEK.** Największym błędem byłoby zoptymalizowanie kampanii pod **pierwszy wpis**. Pierwszy wpis można dostać nostalgią. Drugi powstaje dopiero wtedy, gdy człowiek wie, **kto tu jest, kto go zobaczy i po co ma wrócić**. Dlatego najtańszy właściwy kierunek nie brzmi „więcej funkcji Garnka”, tylko: **łatwiej odnaleźć swoich → łatwiej wysłać swój profil → gospodarz odpowiada → mierzymy D7/second-post, zanim zaprosimy następnych.**
