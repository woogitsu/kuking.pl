# Architektura Kuking

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
- PostgreSQL FTS;
- `pg_trgm`;
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
