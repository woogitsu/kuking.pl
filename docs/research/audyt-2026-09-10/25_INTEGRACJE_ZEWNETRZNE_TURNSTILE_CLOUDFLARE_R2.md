# Kuking.pl — audyt integracji zewnętrznych: Turnstile, Cloudflare, R2

**Repozytorium:** `woogitsu/kuking.pl`  
**Snapshot trzeciej warstwy:** `fd164ad3a91185d1969a109fd692a269ad9710e3`  
**Data:** 10.09.2026

## Werdykt

Integracje zewnętrzne są napisane defensywnie: timeouty, rozróżnienie błędu użytkownika od awarii dostawcy, retry i brak blokowania całego serwisu przez pojedynczy zewnętrzny endpoint. Nie znalazłem nowego P1 wynikającego z samego klienta Turnstile/Cloudflare.

Są natomiast dwa P2 przed publiczną betą:

- serwer akceptuje każde Turnstile `success=true` bez dodatkowego sprawdzenia `hostname/action`;
- kasowanie wielu media może wyprodukować burst osobnych zadań purge, które przy niższym planie Cloudflare wejdą w rate limit.

## EXT-01 — Turnstile nie sprawdza `hostname` ani `action` — P2 defense in depth

### Kod

`app/Turnstile/KlientTurnstile.php` poprawnie wysyła token do Cloudflare `siteverify`, ustawia connect/read timeout i analizuje odpowiedź. Gdy JSON ma `success === true`, wynik jest uznawany za poprawny.

Klient nie porównuje jednak pól odpowiedzi takich jak:

- `hostname`;
- `action`;
- opcjonalne `cdata`.

Cloudflare rekomenduje sprawdzenie hostname/action po stronie serwera, zwłaszcza gdy widget/action są używane do rozdzielenia powierzchni.

### Dlaczego nie P1

Standardowy widget Cloudflare może mieć whitelistę hostname w panelu. Audyt nie miał do tego panelu dostępu. Nie ma więc dowodu, że token z obcej domeny jest akceptowalny ani że „Any Hostname” jest włączone.

### Naprawa

- serwerowa allowlista produkcyjnych hostname (`kuking.pl`, `www...` jeśli rzeczywiście używany, staging osobno);
- nadać `data-action` dla krytycznych formularzy: registration, password recovery/login-link, legal notice/contact;
- do metody `sprawdz()` przekazać oczekiwany action i odrzucać `success=true`, gdy action/hostname nie pasują;
- test tokena poprawnego kryptograficznie, ale z inną action → odrzucenie.

## EXT-02 — fail-open Turnstile przy awarii dostawcy jest świadomą decyzją — nie zgłaszam jako błąd

`KlientTurnstile` klasyfikuje błąd sieci, 5xx, błędny sekret lub nieznany shape jako `Nierozstrzygniety` i przepuszcza formularz, pozostawiając rate limit.

To trade-off availability vs abuse. Dla społeczności, która nie może stracić rejestracji/odzyskiwania konta przez awarię Cloudflare, decyzja jest obroniona.

Warunek: liczba `Nierozstrzygniety` musi być obserwowalna. Skok tego stanu oznacza wyłączenie jednej warstwy ochrony i powinien wywołać alert/metrykę.

## EXT-03 — purge cache jest poprawnie retry'owany, ale może zrobić burst — P2

`app/Jobs/PurgePublicMediaCache.php`:

- ma retry/backoff;
- dzieli URL-e na paczki po 30;
- rzuca błąd po nieudanym HTTP, więc 429 trafia do retry.

`KasujZdjecie` dispatchuje purge w kontekście usuwania media. Przy wymazaniu konta lub hurtowym cleanupie może powstać wiele zadań równocześnie.

Aktualna dokumentacja Cloudflare pozwala na więcej URL-i w jednym żądaniu niż 30, ale ma limity liczby purge requests zależne od planu. Seria osobnych jobów może więc dojść do 429, a ich podobny backoff ponownie obudzić falę w tych samych momentach.

### Naprawa

- agregować/coalescować URL-e z wielu media;
- wykorzystywać aktualny limit requestu (np. 100 URL na Free/Pro/Business według dokumentacji sprawdzonej 10.09.2026);
- respektować `Retry-After`, dodać jitter;
- alert na finalny failed job purge;
- privacy runbook ma określać maksymalny czas, przez jaki cache może zwrócić skasowany publiczny zasób.

## EXT-04 — R2: konfiguracja kodu jest sensowna, stan panelu nadal pozostaje bramką P0

Kod rozdziela prywatny oryginał od wariantów i używa signed URL. To dobra architektura. Audyt repo nie jest jednak dowodem, że produkcyjne buckety, tokeny i domeny są ustawione dokładnie tak samo.

Przed startem należy na realnym środowisku wykazać:

1. oryginał nie ma publicznego adresu;
2. wariant chronionej treści wymaga autoryzacji w aplikacji i dostaje `private, no-store` także z R2;
3. zmiana visibility/block/moderation rzeczywiście odcina kolejny redirect;
4. usunięcie kasuje obiekt na każdym aktywnym/legacy dysku;
5. błędny klucz storage degraduje health/alert, a nie cicho serwuje local fallback.

## Źródła zewnętrzne

- Cloudflare Turnstile — Server-side validation: https://developers.cloudflare.com/turnstile/get-started/server-side-validation/
- Cloudflare Turnstile — Hostname management: https://developers.cloudflare.com/turnstile/additional-configuration/hostname-management/
- Cloudflare Cache — Purge cache: https://developers.cloudflare.com/cache/how-to/purge-cache/
