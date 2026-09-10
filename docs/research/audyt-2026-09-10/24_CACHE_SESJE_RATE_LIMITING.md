# Kuking.pl — audyt cache, sesji i rate limiting

**Repozytorium:** `woogitsu/kuking.pl`  
**Snapshot trzeciej warstwy:** `fd164ad3a91185d1969a109fd692a269ad9710e3`  
**Data:** 10.09.2026

## Werdykt

Nie ma powodu przebudowywać MVP na Redis. Database-backed cache, session i queue są racjonalne przy małej skali, a limity endpointów są na ogół świadomie dobrane. Największym ryzykiem jest **współdzielenie tego samego PostgreSQL przez wszystkie te mechanizmy z bardzo kosztowną autoryzacją zdjęć**, opisaną w raporcie 23.

Nowe znalezisko bezpieczeństwa jest węższe: plaintext recovery codes 2FA trafiają chwilowo do flash session. Docelowy Railway IaC ustawia `SESSION_ENCRYPT=true`, ale stan panelu nie był dostępny, więc to **bramka weryfikacyjna**, a nie potwierdzona luka produkcji.

## SESSION-01 — świeże kody zapasowe 2FA przechodzą przez session payload — P2 operacyjne

### Kod

`app/Http/Controllers/Settings/TwoFactorSettingsController.php` po wygenerowaniu nowych kodów przekazuje ich jawne wartości przez flash session, aby wyświetlić je użytkownikowi jeden raz.

Permanentne wartości w `users` są chronione lepiej: `two_factor_secret` i backup codes są przechowywane z encrypted castami / hashami zgodnie z modelem.

`config/session.php` ma jednak domyślnie:

```text
SESSION_ENCRYPT=false
```

oraz bazodanowy driver sesji. Docelowy `.railway/railway.ts` ustawia `SESSION_ENCRYPT=true`, ale audyt nie miał dostępu do panelu Railway i nie może dowieść, że ta konfiguracja została zastosowana na produkcji.

### Skutek

Jeżeli produkcja rzeczywiście działałaby na DB session bez encryption, atakujący z samym odczytem bazy mógłby w krótkim oknie zobaczyć świeże jawne recovery codes. To słabsze ogniwo niż zaszyfrowane dane 2FA w tabeli użytkownika.

### Kryterium zamknięcia

- sprawdzić produkcyjne `SESSION_ENCRYPT=true`;
- test konfiguracji production/boot guard, który odmawia uruchomienia lub głośno degraduje status przy DB session + encryption off;
- test: znany kod zapasowy nie występuje w surowym zapisanym payload sesji przy konfiguracji produkcyjnej.

## CACHE-01 — DB cache jest poprawny na start, ale konkuruje o ten sam zasób — P2 skalowania

`CACHE_STORE=database`, database session i database queue oznaczają prostą operacyjnie topologię. Jej zaleta: mniej usług i mniej miejsc awarii. Wada: burst requestów zdjęć, session writes, limity, queue polling i właściwe query produktu walczą o ten sam PostgreSQL.

To stanie się problemem szybciej przez MEDIA-03 niż przez sam wybór drivera cache.

### Rekomendacja

- najpierw naprawić query amplification obrazków;
- mierzyć `pg_stat_statements`, connection count, p95 DB time i queue latency;
- Redis dodać dopiero, gdy pomiar pokaże contention albo gdy potrzebny stanie się rozproszony lock/onOneServer na wielu replikach.

## RATE-01 — wysoki limit zdjęć nie jest „za luźny” przypadkiem

Trasa media ma wysoki limit, ponieważ pojedynczy feed potrafi legalnie wygenerować ponad 100 requestów obrazków. Zmniejszenie limitu bez zmiany architektury odcinałoby prawidłowych użytkowników szybciej niż atakującego.

**Wniosek:** redukcja liczby requestów/query oraz CDN/signed-URL architecture ma większą wartość niż arbitralne obniżanie limitu.

## RATE-02 — limity IP zależą od poprawnego rozpoznania adresu klienta — odsyłacz P1

Pierwsza warstwa wskazała direct-origin/forwarded-header risk. Jeżeli origin Railway jest dostępny bez oczekiwanego proxy chain, limit per IP i `remoteip` Turnstile mogą dostać wartość o innym poziomie zaufania niż zakłada aplikacja.

Nie jest to nowy finding trzeciej warstwy; wymaga zamknięcia bramki origin/Host trust z raportu 02/11.

## LOCK-01 — database cache nie powinien być wykorzystywany jako wymówka dla nieatomowych inwariantów

Druga warstwa wykryła budżet poczty jako `check → increment`. Niezależnie od drivera należy użyć jednej atomowej operacji / blokady. Analogicznie nowe race `block↔follow` i `media cleanup↔attach` powinny mieć lock na danych domenowych, a nie „więcej cache”.

## Pozytywne

- sesje są server-side, a nie pełne dane w cookie;
- cookie/session config ma standardowe bezpieczne mechanizmy Laravel;
- limity są różnicowane według kosztu/ryzyka endpointu;
- brak niepotrzebnego Redisa upraszcza awarie i backup MVP.

## Decyzja

Brak P0/P1 wyłącznie w tej warstwie. P2 SESSION-01 należy zweryfikować przed publiczną betą, a skalowanie cache/sesji robić po pomiarze, nie przed nim.
