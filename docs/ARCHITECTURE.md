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

```text
app/Domain/
├── Users
├── Social
├── Posts
├── Recipes
├── Media
├── Collections
├── Search
├── Notifications
└── Moderation
```

Nie robimy z nich osobnych serwisów ani pakietów.

## Queue

MVP:
`QUEUE_CONNECTION=database`

Jobs:
- ProcessUploadedImage;
- GenerateUserExport;
- SendDigest;
- RefreshSearchDocument;
- GenerateSitemapChunk.

Redis dopiero po pomiarze.

## Search

MVP:
- PostgreSQL z `pg_trgm` i `unaccent`;
- porównania trigramowe oraz `LIKE` w `app/Domain/Search/SearchQuery.php`;
- GIN;
- SQL filters.

Typesense/Meilisearch tylko wtedy, gdy Postgres przestaje spełniać SLA.

## Feed

MVP:
```sql
WHERE author_id IN (...)
ORDER BY published_at DESC, id DESC
```

Cursor pagination. Bez fanout-on-write.

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
