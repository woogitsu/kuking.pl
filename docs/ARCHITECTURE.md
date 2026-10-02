# Architektura Kuking

Plan techniczny przed wzrostem: [kanoniczne #614 i uzgodnienie stanu na
20.09.2026](infra/PLAN_TECHNICZNY_614.md). Mapa kontroli i wdrożeń:
[odpowiedzialności CI, historia zabezpieczeń i granice uproszczenia #611](infra/MAPA_CI_611.md).

## Decyzja

**Modularny monolit Laravel.**

```text
Browser / PWA
      ↓
Laravel 13
├── Users
├── Social
├── Posts
├── Recipes
├── Media
├── Collections
├── Search
├── Notifications
└── Moderation
      ↓
PostgreSQL 18
```

## Dlaczego monolit

Największym ryzykiem pierwszych miesięcy nie jest skala serwera, tylko:

- pusta społeczność;
- brak retencji;
- zbyt trudne publikowanie;
- słaba jakość treści;
- spam;
- niedopracowana moderacja.

Monolit zmniejsza liczbę ruchomych części i jest bardzo dobry do pracy przez agentów AI.

## Warstwy

### UI / HTTP
- Blade;
- Livewire;
- kontrolery;
- Form Requests.

Granica przyjęta w #970: **Form Request odpowiada za wejście HTTP** (rola,
reguły, komunikaty, kolejność sprawdzeń), **akcja w `app/Domain` za regułę
i transakcję**, a **kontroler za orkiestrację odpowiedzi**. Wzorce:
`ZapisPrzepisuRequest` + `ZapiszPrzepisZFormularza` (przepis) oraz
`DecyzjaModeracyjnaRequest` + `RozstrzygnijZgloszenie` (decyzja moderacyjna)
oraz `ListaKontRequest` + `App\Domain\Moderation\ListaKont` (lista kont
w panelu — wejście z adresu bez reguł odsyłających z błędem, bo parametr
spoza listy spada do wartości domyślnej; zapytania poza kontrolerem)
oraz „Twoje dane”: `ZamowEksportDanych` (paczka RODO — kontroler wybiera tylko
zdanie z `WynikZamowieniaEksportu`) i `ProsbaOUsuniecieKontaRequest` +
`RequestAccountDeletion` (zgłoszenie usunięcia konta).
Nowy wpis i pytanie: `ZapisWpisuRequest` (faza 1: zdjęcia w `rules()`, przed
kontrolerem; faza 2: `walidatorTresci()` wołany po wgraniu zdjęć i obsłudze
przycisków tagów, żeby błąd treści wrócił z `old()` niosącym UUID-y zdjęć
i tagi) — `PostController::store()` zostaje przy orkiestracji odpowiedzi.
Edycja wpisu i pytania: `EdycjaWpisuRequest` (`rules()` puste — treść waliduje
`trescWpisu()` dopiero po Policy, decyzji moderacji i przyciskach tagów;
marker `_tag_form_post_id` idzie też do żądania z kontenera, bo z niego
powstaje `old()`; reguły wspólne ze store'em w traicie `WalidujeTrescWpisu`).
Komentarz: `KomentarzRequest` (Policy w `authorize()` przed walidacją pól).
Zeszyty (krok 5): `ZapisZeszytuRequest` (założenie i zmiana nazwy, opisu
i widoczności — Policy `update` w `authorize()` przed polami, ten sam 403
co w kontrolerze) oraz `ZapisDoZeszytuRequest` i `WyjecieZZeszytuRequest`
nad wspólną `WyborZeszytuRequest` (jedna reguła wyboru własnego lub wspólnego
zeszytu, różne zdania błędów; `rules()` puste, wybór waliduje metoda wołana
przez kontroler po 404 i Policy). `app/Domain/Collections` nie zna
`Illuminate\Http`: `ZeszytyDoWyboru::dla(?User)` i `CollectionSaveContext`
(surowe `save_type`/`save_id` + osoba) dostają zwykłe wartości, a jedno
pobranie zeszytów na żądanie trzyma adapter `App\Http\Support\ZeszytyZZadania`
(używa go komponent `wybor-zeszytu` i karty). Cała domena jest pod
`DomenaNieZalezyOdHttpTest`: nowy import `Illuminate\Http` w `app/Domain`
oblewa test; jawne wyjątki (z powodem w teście) to klient wychodzący
`Http\Client` i `UploadedFile` per plik; `Request` nie ma wyjątków — lista
dokładna w obie strony. Sesję, IP i pola formularza domena dostaje przez
`App\Support\ZadanieDomenowe` (adapter `App\Http\Support\ZadanieHttp`:
`WejdzPrzezDostawce`, `FacebookConnectionConfirmation`, `ExternalRegistrationDraft`),
a pamięć jednego żądania przez `App\Support\PamiecZadania` (adapter
`PamiecZadaniaHttp`, wiązanie w `AppServiceProvider`: `Ukrycia`,
`SkrotyObserwowania`, `ListyWidza` — obserwowane osoby i blokady widza liczone raz
na żądanie dla tablicy dnia i skrótów w menu; feed obserwowanych czyta świeżo, #983). Kontroler woła domenę jako `ZadanieHttp::z($request)`.
Zostaje w kontrolerze: przypadki użycia listy, wyjęcia niedostępnych i
zapisu przepisu (kolejne kroki).
Profil publiczny: `ProfilRequest` (zakładka, rok i fraza z adresu, `rules()` puste — jak `ListaKontRequest`).
Onboarding: `ZapisZainteresowanRequest`, `ZapisObserwowanychRequest` + `ObserwujWybraneOsoby` (zapis; limit 20 osób, para nazwa–identyfikator #793) oraz `EkranLudziRequest` + `PrzygotujEkranLudzi` (ekran „kogo obserwować”).
Zgłoszenie treści: `ZgloszenieTresciRequest` (limit trasy → odsyłka konta pod nazwą → pola; cel i Policy zostają w `ReportContent`). `NotificationController` nie ma walidacji wejścia.
Przypadki użycia z własną transakcją i blokadą: `ZamknijGrupeSygnalow` (`SygnalyController::odrzucGrupe()`, wynik w `WynikZamknieciaGrupy`) i `ZuzyjLinkDoLogowania` (`LoginLinkController::store()`, awaria dziennika jako `WejscieLinkiemWycofane`) — kolejność blokad bez zmian; pozostałe `DB::transaction` w kontrolerach to jednolinijkowe savepointy wokół pojedynczego zapisu. Ustawienia 2FA: `WlaczenieDwuetapowejRequest`/`NoweKodyZapasoweRequest`/`WylaczenieDwuetapowejRequest` (worki błędów `regenerate` i `disable`) + `WlaczDwuetapowa` i `WygenerujNoweKodyZapasowe` (dwa domknięcia `ZamekKonta`, kolejność sprawdzeń bez zmian); `RegistrationInviteController` nie ma blokad — tylko `PrzyjecieZaproszeniaRequest` (odczyt tokenu bez reguł, żeby błąd nie był wyrocznią).
Tablica tam, gdzie oczekiwany jest tekst (#2239 i rodzina, audyt BP-04): parametr
ADRESU podany jako lista (`?tydzien[]=`) usuwa `App\Http\Middleware\ParametryAdresuBezTablic`
w grupie `web` — kontroler widzi brak parametru, a nie tablicę. Wyjątki (formularze GET
wysyłające listę, dziś tylko `onboarding.people`) stoją w `LISTY_DOZWOLONE`; ślad usunięcia
czyta `bylWAdresie()` (klasyfikacja sekretnych adresów, komunikat o nierozpoznanym czasie).
Pole TREŚCI formularza czytane przed walidacją albo bez niej idzie przez `App\Support\Wejscie`:
`normalizujTekst()` (tablica zostaje dla reguły `string`, błąd przy polu) albo `tekst()`
(ukryte pole: tablica to pusty napis i zwykła droga „nieaktualne”). Nie `(string) $request->input(...)`
ani `$request->string()` — oba rzutują tablicę i dają HTTP 500. Pilnuje tego
`TabliceWParametrachNieDajaBledu500Test` (przypadki ze zgłoszeń + obchód tras GET i publicznych formularzy).
Kolejne kandydaty (od największego): `CollectionController` (dalsze kroki), `NotificationController`,
kontrolery logowania Google/Facebook (#1035).

### Application
Use cases, np.:
- PublishPost;
- PublishRecipe;
- FollowUser;
- RecordCookedEvent;
- ReportContent;
- ExportUserData.

### Domain
Reguły:
- visibility;
- block;
- ownership;
- stany przepisu;
- stany moderacji.

### Infrastructure
- Eloquent;
- storage;
- queue;
- mail;
- analytics;
- monitoring.

## Granice

`app/Domain` ma dziś 26 modułów (stan z 24.09.2026):

```text
Analytics · Collections · Comments · Compliance · Contact · Digest · Feed ·
Kolejka · Kopie · Media · Moderation · Monitoring · Notifications ·
Polaczenia · Posts · Pwa · Questions · Recipes · Search · Security ·
Sharing · Social · Tags · Users · Wspomnienia · Zgody
```

Nie robimy z nich osobnych serwisów ani pakietów.

## Widoczność treści w zapytaniach zbiorczych (#1687)

Policy (`PostPolicy`, `RecipePolicy`, `CookedEventPolicy`, `CommentPolicy`)
odpowiada na pytanie o **jeden** rekord. Lista, licznik albo eksport, które
filtrują wiele wierszy naraz, nie wołają Policy po jednym rekordzie (N+1),
tylko używają **nazwanej specyfikacji zapytania** i **testu równoważności**
z Policy. Model Eloquenta nie trzyma własnej kopii reguł innego modułu.

```text
Notification::scopeVisibleTo()           ← tylko wejście (lista, licznik,
        │                                   „Oznacz wszystkie", otwarcie, eksport)
        ▼
Domain/Notifications/WidocznoscPowiadomien   reguły samego powiadomienia:
        │                                    sprawca, blokada, typ służbowy,
        │                                    czy komentarz wciąż istnieje
        ▼
Domain/Widocznosc/WidocznoscTresciSql        widoczność wpisu, przepisu
                                             i „Ugotowałem" jako fragment EXISTS
```

Zgodności pilnuje `tests/Feature/Visibility/PowiadomieniaZgodneZPolicyTest`:
macierz cel × widz × stan × rodzaj komentarza, stan nakładany po utworzeniu
powiadomienia. Nowa reguła w Policy treści bez obsługi w specyfikacji daje
czerwony test z nazwą komórki. Tolerowane są wyłącznie dwa znane rozjazdy,
każdy w jednym kierunku i z numerem zgłoszenia (#1378, #1385).

Zasada dla kolejnych modułów: jeśli zapytanie zbiorcze musi odtworzyć regułę
z Policy, reguła trafia do specyfikacji w `app/Domain`, a obok powstaje test
równoważności z Policy — nie kolejna prywatna kopia w modelu albo kontrolerze.

Etap 2 (#1687): dokąd prowadzi powiadomienie i co pokazuje jego wycinek też
nie należy do modelu. `Domain/Notifications/CelPowiadomienia` liczy adres
„Zobacz" dla jednego powiadomienia (`adres()`, wejście przez
`Notification::adresDocelowy()`) i dla całej strony jednym odczytem
komentarzy (`adresy()`, #833); `Domain/Notifications/WycinkiKomentarzy`
czyta żywe wycinki komentarzy (D-229) dla listy i eksportu. Obie klasy
dostają powiadomienia, które już przeszły przez `WidocznoscPowiadomien`.

Etap 3 (#1687): termin ochrony odwoławczej powiadomienia (retencja, ADR
§5.2) liczy `Domain/Compliance/TerminOchronyOdwolawczej`, obok jedynego
odbiorcy — `PrzedawnionePowiadomienia`. Przy modelu zostaje tylko lista
typów z własnym terminem (`Notification::WYDLUZONA_RETENCJA_DO_TERMINU_ODWOLANIA`).

Etap 4 (#1687): odczyt powiadomień ma jedno wejście —
`Domain/Notifications/OdczytPowiadomien`: strona listy (z doładowanym
zbiorczo stanem wykonań i przepisów), licznik nieprzeczytanych, licznik
plakietki z sufitem, „Oznacz wszystkie" i pojedyncze otwarcie. Kontroler
i `User::unreadNotificationsCount()` / `unreadNotificationsBadgeCount()`
nie składają już filtra po swojemu; każde wejście przechodzi przez
`Notification::scopeVisibleTo()`. `tests/Feature/OdczytPowiadomienTest`
pilnuje, że blokada ukrywa wiersz we wszystkich wejściach naraz i że liczba
zapytań strony nie rośnie z liczbą wierszy. Eksport danych i Web Push nadal
wołają `visibleTo()` wprost (ten sam kontrakt, inny kształt wyniku).

Etap 5 (#1687): wybór imienia z partii zapisów („Anna oraz 2 inne osoby…")
przeszedł z modelu do `WidocznoscPowiadomien::pierwszyWidocznyZapisujacy()`,
obok warunku widoczności całej partii (`widocznaPartiaZapisow()`). Model nie
składa już SQL o blokadach i statusach kont; `blokadaZOdbiorca()` jest
prywatna. `tests/Feature/ZapisujacyDoPokazaniaZgodniZListaTest` pilnuje, że
wiersz jest na liście wtedy i tylko wtedy, gdy jest osoba do pokazania.

Etap 6 (#1687): rozwiązywanie celów powiadomienia — `pierwszyWpis()`,
`wersjaDoPokazania()` i `slugZapisanegoPrzepisu()` — przeszło z modelu do
`CelPowiadomienia`, obok `adres()` i `wpisSmakowicie()`. Model zostawia
predykaty dla widoku i kontrolera (`pierwszyWpisNiedostepny()`,
`przepisUsuniety()`, `wersjaDostepna()`) oraz podręczny wynik zbiorczego
sprawdzenia listy (`zapamietajSlugPrzepisu()` itd.), więc zapytań jest tyle
samo co przed zmianą. `tests/Feature/CelPowiadomieniaRozwiazujeCeleTest`
pilnuje, że przycisk „Zobacz" i treść karty opierają się na tym samym
rozstrzygnięciu resolvera i że model nie odzyskał tych metod.

Etap 7 (#1687): zakresy list treści (`Post/Recipe/CookedEvent::scopeWidoczneDla()`)
porównano z Policy na macierzy typ × status konta autora × stan × widoczność ×
widz (`tests/Feature/Visibility/ListyTresciZgodneZPolicyTest`). Wynik: żadnego
błędu, cztery zamierzone różnice, każda utrwalona testem (test sprawdza też, że
różnica nadal zachodzi, więc opis nie przeżyje zmiany zachowania):

1. Status konta autora (`banned`, `pending_delete`) — zakres `Post`/`Recipe`
   odpowiada na relację widz ↔ autor, a granicę statusu dokłada wywołujący
   (`whereHas('author', … dostepnyJakoAutor())`). Zakres z wbudowanym statusem
   odciąłby autora od własnych treści (`Post::scopeTylkoOdAktywnychAutorow()`).
   Kontrakt: zakres + `dostepnyJakoAutor()` = Policy. `suspended` i `erased`
   nie zamykają treści ani w zakresie, ani w Policy.
2. Właściciel konta `banned`/`pending_delete` — Policy wpuszcza go do własnej
   treści, filtr statusu listy też jego tnie (konto bez otwartej sesji).
3. Zapowiedź własnego przepisu ukrytego przez moderację — bramka
   `zWidocznymPrzepisem()` wymaga przepisu opublikowanego; lista jest
   ostrzejsza od Policy.
4. „Ugotowałem" — zakres liczy blokadę z kucharzem i status jego konta;
   stan przepisu rozstrzyga strona przepisu jednym `RecipePolicy::view()`
   na całą galerię (bez N+1).

Etap 8 (#1687, domknięcie): `tests/Feature/PowiadomieniaJedenKontraktWidocznosciTest`
dopisuje dwa dowody, których brakowało. Pełna strona (`OdczytPowiadomien::NA_STRONE`
= 30) z komentarzami, odpowiedziami i obserwowaniami ma przez HTTP tę samą liczbę
zapytań co strona z trzema wierszami. Eksport danych konta niesie dokładnie te
powiadomienia, które człowiek widzi na liście (blokada, konto zbanowane, wpis ukryty).

Nr 2–4 to lista ostrzejsza od Policy. Nr 1 działa odwrotnie: zakres SAM (bez
granicy statusu) pokazuje więcej — dlatego każde nowe zapytanie o cudze
treści musi tę granicę dołożyć.

### Kierunek zależności (issue #971)

Zależność między modułami to użycie nazwy `App\Domain\<Inny>\…` w kodzie
modułu (`use`, `new`, `::class`, typ). Zasada jest jedna: **graf zależności
modułów nie ma cykli.** Jeśli A używa B, to B nie używa A — ani wprost, ani
przez trzeci moduł. Wtedy granica modułu mówi, co może zepsuć zmiana w nim.

Gdy moduł niżej musi wywołać coś z modułu wyżej, odwracamy krawędź:
kontrakt mieszka u wołającego, implementacja w module, który ją dostarcza,
a łączy je `AppServiceProvider`. Wzorzec: rejestracja (`Users\Actions\ZalozKonto`)
woła `Users\ObserwowanieGospodarza`, a implementację daje
`Social\Actions\ObserwujGospodarza` — bo `Social` już zależy od `Users`
(`ZamekPary` → `ZamekKonta`, D-079/D-080).

Krawędzie w chwili wprowadzenia zasady (skrót, nie lista dozwolonych —
nowa krawędź jest w porządku, dopóki nie zamyka cyklu):

```text
Users        → Compliance, Media, Zgody
Social       → Notifications, Users
Comments     → Notifications, Users
Posts        → Media, Moderation, Notifications, Tags
Recipes      → Media, Notifications
Feed         → Collections
Wspomnienia  → Collections
Collections  → Notifications
Moderation   → Notifications, Users
Security     → Moderation, Users
Contact      → Security
Media, Pwa   → Analytics       (Media nie zależy już od Moderation, #2149)
Kolejka, Polaczenia → Monitoring
```

Pilnuje tego `tests/Unit/GrafModulowDomenyBezCykliTest.php` (tokenizer PHP,
bez nowych bibliotek). Lista zastanych cykli w teście (`ZNANE_CYKLE`) jest
dokładna w obie strony: nowy cykl oblewa test, a rozcięty znany też — żeby
wpis nie został furtką. **Od #2149 (etap 3) lista jest pusta: graf modułów
`app/Domain` nie ma żadnego cyklu.**

Jedyny zastany cykl zaczął się jako `Moderation ↔ Security`
(`AlarmujOPilnymZgloszeniu` → `DziennyBudzetListow`,
`KomunikatZamknietegoKonta` → `UzasadnienieDecyzji`). Wejście przez
dostawcę (#1035) dołożyło krawędź `Security → Users`
(`WejdzPrzezDostawce` → `ZalozKonto`, `ZamekKonta`), więc ten sam cykl
objął `Compliance → Moderation → Security → Users → Compliance`. Do 25.09
urósł jeszcze o Media i Analytics: `Users → Media` (`EraseAccountData`),
`Media → Moderation` (`DostepDoZdjecia`), `Media → Analytics`
(`StoreUploadedImage`) i `Analytics → Compliance` (`PrzedawnioneSygnaly` →
`UsuwanieWPartiach`, #1657). Rozcięcie, krawędź po krawędzi:

- **Analytics → Compliance** (#2149, etap 1): `UsuwanieWPartiach` to ogólny
  mechanizm bazy, więc przeniesiono go do `App\Support` — Analytics wypadło
  ze składowej.
- **Media → Moderation** (etap 2): `DostepDoZdjecia` brał z
  `ModeratedContent::TYPY` wyłącznie nazwy typów `media` i `post` w
  `reports.target_type`. Nazwy są teraz stałymi `Report::TARGET_MEDIA` i
  `Report::TARGET_POST` (fakt o tabeli, mieszka przy modelu), a
  `ModeratedContent::TYPY` odwołuje się do tych samych stałych — bez zmiany
  zachowania i bez „worka Core". Media wypadło ze składowej.
- **Compliance → Moderation** (etap 3): retencja spraw
  (`PrzedawnioneSprawyModeracyjne`) odświeża liczniki kolejek panelu raz na
  koniec przebiegu, bo kasowanie zbiorcze nie woła zdarzeń modeli. Wołała
  `KolejkiPanelu` wprost. Teraz woła kontrakt
  `App\Support\OdswiezanieLicznikowKolejek`, który implementuje
  `KolejkiPanelu`, a `AppServiceProvider` wiąże go z **tym samym singletonem**
  (flaga „już zaplanowane na commit" jest wspólna ze zdarzeniami modeli).
  Compliance wypadło ze składowej — było w niej wyłącznie przez tę krawędź.
- **Moderation → Security** (etap 3): `AlarmujModeratora` i
  `AlarmujOPilnymZgloszeniu` używały `Security\DziennyBudzetListow`. To
  wspólny licznik dobowego sufitu poczty, używany też przez `Contact`,
  `Digest`, `Notifications` i kontrolery logowania, a jego sąsiadem jest
  `App\Poczta\ListZarezerwowany`. Klasa przeniesiona do `App\Poczta` bez
  zmiany zachowania (te same klucze cache, ta sama logika; zmienił się tylko
  namespace). Cykl `Moderation ↔ Security` przestał istnieć, a razem z nim
  składowa.

Zostają wyłącznie krawędzie jednokierunkowe, np. `Security → Moderation`
(`KomunikatZamknietegoKonta` → `UzasadnienieDecyzji`), `Security → Users`
(`ZalozKonto`, `ZamekKonta`), `Moderation → Users` (`ZamekUprzywilejowanegoAktora`,
`ZamekKonta`, `OdmowaOstatniegoAdministratora`), `Users → Compliance`
(`RejestrPotwierdzenRodo`, `DziennikWymazan`), `Users → Media` i
`Compliance → Media` (`KasujZdjecie`), `Media → Analytics`. Żadna z nich nie
wraca. Test ma osobnych strażników dla każdej rozciętej krawędzi
(`test_analytics_nie_zalezy_od_compliance`, `test_media_nie_zalezy_od_moderation`,
`test_compliance_nie_zalezy_od_moderation`, `test_moderation_nie_zalezy_od_security`)
z kontrolą, że skaner widzi krawędź w drugą stronę. Raport krawędzi wewnątrz
składowej (`KRAWEDZIE_W_SKLADOWEJ`, etap 2) usunięto, bo nie ma już składowej
do raportowania; gdyby cykl wrócił, oblewa `ZNANE_CYKLE`.

## Zmiana roli podczas uprzywilejowanej operacji

Akcja domenowa, która zapisuje skutek moderatora lub administratora, nie może
ufać obiektowi `User` wczytanemu na początku żądania. `ChangeUserRole` może
w międzyczasie zatwierdzić degradację. `ZamekUprzywilejowanegoAktora` bierze
w jednej transakcji wspólną blokadę ostatniego administratora, następnie
blokadę wiersza aktora i przekazuje akcji świeży model. Policy i zapis skutku
muszą nastąpić wewnątrz tej samej transakcji. Tę kolejność stosują
`ResolveAppeal`, `ZdejmijZUrzedu`, `RozstrzygnijZgloszenie`, `RestoreContent`
(także `ModerationController::restore()`) i przyjęcie odpowiedzi w
`WyslijOdpowiedz`; wysłanie przyjętego listu może zakończyć się później.
Kolejność blokad: zamek ról → aktor → zgłoszenie/treść/sprawa → konto celu.
Akcja wołana z innej akcji, która już trzyma zamek (`RestoreContent` z
`ResolveAppeal`), ma osobne wejście „pod zamkiem” (`podZamkiem()`) przyjmujące
świeżego aktora — chronione wejście publiczne nie bierze protokołu drugi raz.

Nowe akcje przyjmujące aktora z rolą powinny korzystać z tego samego wzorca.
Blokada aktora przed wspólną blokadą mogłaby zakleszczyć się ze zmianą roli
lub karą konta. Przeploty z obu kolejności sprawdzają testy na dwóch
połączeniach PostgreSQL (issue #2086).

## Queue

MVP:
`QUEUE_CONNECTION=database`

Jobs:
- ProcessUploadedImage;
- GenerateUserExport;
- NotifyUserExportReady (list „paczka gotowa”, ponawiany osobno od budowy paczki).

Poza kolejką (zamiast jobów, świadomie, na razie):
- tygodniowy digest — komenda harmonogramu
  `App\Console\Commands\WyslijPodsumowaniaTygodnia` (`Mail::queue()` per
  odbiorca), nie osobny job `SendDigest`;
- wyszukiwarka czyta PostgreSQL na żywo (`App\Domain\Search\SearchQuery`),
  nie ma materializowanego dokumentu ani joba `RefreshSearchDocument`
  do jego odświeżania;
- sitemapa generuje się na żądanie z cache'em HTTP (`SitemapController`);
  podział na chunki i job `GenerateSitemapChunk` to plan przy dziesiątkach
  tysięcy adresów (`docs/seo/SEO_TECHNICAL.md`), nie dzisiejszy stan.

Redis dopiero po pomiarze.

## Search

MVP:
- PostgreSQL z `pg_trgm` i `unaccent`;
- porównania trigramowe oraz `LIKE` w `app/Domain/Search/SearchQuery.php`;
- GIN;
- SQL filters.

Typesense/Meilisearch tylko wtedy, gdy Postgres przestaje spełniać SLA.

Fraza wyszukiwania ma najwyżej 120 znaków po przycięciu skrajnych spacji.
`SearchQuery::phraseValidator()` jest wspólną regułą formularzy i domeny:
`/szukaj` oraz `/witaj/ludzie` zachowują dłuższy tekst i pokazują błąd przy
polu oraz w podsumowaniu, bez zapytania wyszukującego i bez przekierowania.
Bezpośrednie `recipes()` i `people()` odrzucają go przez `ValidationException`
z kluczem `q`; przyszła integracja #815 musi obsłużyć ten sam kontrakt.
Nie obcinamy frazy. Granica dotyczy tekstu wejściowego, przed transliteracją.

W wyszukiwaniu ludzi pojedyncze początkowe `@` jest prefiksem prezentacyjnym:
`@basia` daje ten sam wynik co `basia`, również przy dopasowaniu fragmentów,
imienia i specjalności. Nie zmienia to filtrów kont i blokad, wyszukiwania
przepisów ani znaków `@` wewnątrz frazy. Sam prefiks nie liczy się do minimum
dwóch znaków nazwy. Pomiary i decyzje właściciela: [#885/#886](research/GRANICE_WYSZUKIWANIA_885_886.md).

## Feed

MVP:
```sql
WHERE author_id IN (...)
ORDER BY published_at DESC, id DESC
```

Cursor pagination. Bez fanout-on-write.

Kursor strony głównej jest zawsze związany z serwerowo wybranym źródłem:
`obserwowani`, `tagi` albo `odkrywanie`. Parametr adresu tylko potwierdza
źródło, nie pozwala go wybrać. Jeżeli między żądaniami zmieni się podstawa
źródła (np. obserwowana osoba przestanie być obserwowana), kontynuacja wraca
przekierowaniem do czystej pierwszej strony zamiast stosować stary kursor do
innego zapytania.

## Zdjęcia: adresem jest trasa aplikacji

```text
przeglądarka → /zdjecia/{uuid}/{wariant}
             → MediaController
             → DostepDoZdjecia → Policy treści NADRZĘDNEJ
             → 302 na krótko podpisany adres w buckecie
```

Bucket wariantów **nie ma własnej domeny**. Bajty nie idą przez PHP — idzie
przez nie wyłącznie decyzja, kto może je zobaczyć.

Dlaczego to jest sprawa architektury, a nie szczegół implementacji: `media`
nie ma i nie dostanie kolumny `visibility`. Widoczność zdjęcia to widoczność
treści, do której jest przypięte, a rodziców jest sześciu i zdjęcie może mieć
więcej niż jednego. Reguła żyje więc w `app/Domain/Media/DostepDoZdjecia`,
która **woła istniejące Policy** zamiast powtarzać ich warunki — inaczej
byłaby to siódma kopia reguły widoczności w tym repozytorium.

Szczegóły, kompromisy i to, czego ta zmiana nie załatwia:
`docs/MEDIA_PIPELINE.md` → „Adresem zdjęcia jest trasa aplikacji"
oraz `docs/DECISIONS.md` → D-020.

## Publikacja komentarza na bieżącym stanie

`PublishComment` korzysta z `LockCommentContext`: w jednej transakcji blokuje
uporządkowany zbiór kont, istniejące obserwowania, zależności celu oraz rodzica
i korzeń. Dopiero świeża kontrola dostępu pozwala zapisać komentarz razem
z powiadomieniami. `DeleteComment` sprawdza odpowiedzi dopiero pod tym samym
zamkiem komentarza; zachowuje dotychczasową decyzję placeholder albo usunięcie.
`EditComment` przed blokadą komentarza blokuje świeży wiersz konta autora:
sankcja zatwierdzona wcześniej odcina poprawkę, a poprawka rozpoczęta
wcześniej kończy się przed sankcją. Kolejność konto → komentarz jest zgodna
z publikacją odpowiedzi; sam model konta z początku żądania nie rozstrzyga
uprawnienia do zapisu (#2090).
Graf, koszt i granice pomiarów: [protokół komentarzy](research/2026-09-21-komentarz-biezacy-stan.md).

## Obserwowanie po zmianie stanu konta

`FollowUser` pod `ZamekPary` ponownie sprawdza oba świeże konta przed
utworzeniem relacji i powiadomienia. `UpdateTagFollows` zachowuje kolejność
`TagMutationLock` → konto → tagi; po blokadzie konta używa świeżego modelu
i odmawia dodania tagu przez przycisk lub zbiorczy formularz, jeśli konto
straciło aktywność. Cofnięcie istniejącego obserwowania pozostaje możliwe,
także przez formularz zawierający wyłącznie usunięcia. Test dwóch połączeń
rozstrzyga oba przeploty z sankcją konta (#2091).

Każda zmiana wiersza `follows` na parze osób — `FollowUser`, `UnfollowUser`
i `BlockUser` — idzie przez `ZamekPary` (wiersze `users` rosnąco po id), więc
równoległe obserwuj, przestań obserwować i zablokuj ustawiają się w jednej
kolejce: stan końcowy wynika z kolejności żądań, bez 40P01 i bez 23505 dla
człowieka (#2404). Pilnuje tego `tests/Dwa/ObserwowanieIPrzestanObserwowacTenSamZamekParyTest.php`, a w CI `scripts/kontrola-negatywna-2404.py` sprawdza,
że gołe `detach()` w `UnfollowUser` oblewa ten test.

## Wybór redakcyjny: jeden pełny zestaw i audyt w tej samej transakcji

Tablica dnia i kolaż strony powitalnej zastępują cały wybór przez `DELETE`
i serię `INSERT`-ów. Zapis mieszka w `app/Domain/Feed/Actions/ZapiszTabliceDnia`
i `ZapiszKolaz`; kontrolery panelu tylko autoryzują, walidują i odpowiadają.
Jedna transakcja obejmuje trzy rzeczy, w tej kolejności:

1. blokadę doradczą zasobu (`ZamekWyboruRedakcji`: tablica osobno dla
   każdej daty, kolaż jako jeden zasób) — istnieje także przy pustym
   zestawie, więc dwa równoległe zapisy dają zestaw A albo B, nigdy A ∪ B
   (#1027);
2. `DELETE` i wstawienie nowego zestawu;
3. wpis `audit_log` (`daily_board.updated|cleared`, `hero_kolaz.updated|cleared`)
   — awaria dziennika cofa zmianę wyboru (#1329, D-249 klasa 1).

Pomiar przeplotu na dwóch połączeniach:
`tests/Dwa/WyborRedakcjiNieZlaczaDwochZestawowTest.php`.

## PWA

Od początku:
- manifest;
- service worker dla shell/assets;
- instalowalność;
- bez ryzykownego cache prywatnych odpowiedzi.

## Scale path

### Alpha
```text
Railway web
Railway Postgres
database queue
object storage
```

### Beta
```text
web
worker
postgres
R2
```

### Wzrost
Na podstawie telemetryki:
- Redis;
- osobny search;
- read replica;
- więcej workerów;
- image CDN/transforms.

Nie zgadujemy problemów, których jeszcze nie ma.

### Sekretny adres i następny dokument

Middleware nagłówków używa wspólnej klasyfikacji analityki do `no-referrer`
na żądaniach z poświadczeniem w adresie. Sam brak beacona na pierwszej stronie
nie chroni następnej. Zakres, formularze, lokalny test dwóch dokumentów
i ograniczenia dowodu: [REFERRER_SEKRET_1052](infra/REFERRER_SEKRET_1052.md).

## Harmonogram: jedno wykonanie na termin (#595)

Każde zadanie w `routes/console.php` ma `->onOneServer()` obok
`->withoutOverlapping()`. To nie jest przygotowanie pod skalowanie — replika
serwisu jest dziś jedna i nikt jej nie zwielokrotnia.

Powód jest zmierzony i dotyczy WDROŻENIA. Przy nakładaniu się starego i nowego
kontenera oba mają własny harmonogram, więc `kuking:sprzataj-osierocone-zdjecia`
i `kuking:policz-kolejki` **wykonały się dwa razy w jednej minucie**. Przy
zadaniach kasujących dane to nie jest drobiazg.

`withoutOverlapping()` tego nie zatrzymuje i nie wolno go tak czytać: jego
blokada chroni przed dwoma przebiegami JEDNOCZEŚNIE i jest zwalniana, gdy
przebieg się kończy. Drugi kontener, który wystartuje chwilę po pierwszym,
zastaje ją wolną. `onOneServer()` bierze blokadę na TERMIN (zadanie + minuta)
i trzyma ją do końca tej minuty, więc powtórzenie nie rusza.

Blokady leżą we wspólnym cache PostgreSQL (`CACHE_STORE=database`, tabela
`cache_locks`) — sterownik `database` implementuje `LockProvider`, więc działa
to bez Redisa, którego AGENTS.md zabrania.

To NIE zastępuje idempotencji samych operacji domenowych ani zadań kolejki.
Strażnikiem jest `tests/Feature/HarmonogramJednegoSerweraTest.php`: sprawdza
i sam plik (każde zadanie ma flagę), i zachowanie (drugi scheduler w tej samej
minucie nie powtarza zakończonego zadania, a następny planowy termin nie ginie).
