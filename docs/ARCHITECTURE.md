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
`DecyzjaModeracyjnaRequest` + `RozstrzygnijZgloszenie` (decyzja moderacyjna).

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
Moderation   → Notifications, Security
Security     → Moderation, Users     ← znany cykl, do rozcięcia
Contact      → Security
Media, Pwa   → Analytics
Kolejka, Polaczenia → Monitoring
```

Pilnuje tego `tests/Unit/GrafModulowDomenyBezCykliTest.php` (tokenizer PHP,
bez nowych bibliotek). Lista zastanych cykli w teście jest dokładna w obie
strony: nowy cykl oblewa test, a rozcięty znany też — żeby wpis nie został
furtką. Jedyny zastany cykl zaczął się jako `Moderation ↔ Security`
(`AlarmujOPilnymZgloszeniu` → `DziennyBudzetListow`,
`KomunikatZamknietegoKonta` → `UzasadnienieDecyzji`). Wejście przez
dostawcę (#1035) dołożyło krawędź `Security → Users`
(`WejdzPrzezDostawce` → `ZalozKonto`, `ZamekKonta`), więc ten sam cykl
objął `Compliance → Moderation → Security → Users → Compliance`. Do 25.09
urósł jeszcze o Media i Analytics: `Users → Media` (`EraseAccountData`),
`Media → Moderation` (`DostepDoZdjecia`), `Media → Analytics`
(`StoreUploadedImage`) i `Analytics → Compliance` (`PrzedawnioneSygnaly` →
`UsuwanieWPartiach`, #1657). Jedna silnie spójna składowa: Analytics,
Compliance, Media, Moderation, Security, Users. Do rozcięcia osobnym
zadaniem.

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
Graf, koszt i granice pomiarów: [protokół komentarzy](research/2026-09-21-komentarz-biezacy-stan.md).

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
