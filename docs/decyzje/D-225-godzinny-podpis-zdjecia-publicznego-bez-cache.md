## D-225 — Godzinny podpis zdjęcia publicznego, bez cache sesji (#597/#610)

20 września 2026, jawna decyzja właściciela w zadaniu `gpt/cloudflare-cache`:
„Zaakceptuj godzinę dla wcześniej publicznego zdjęcia”. Podpis wydany, gdy
Policy dopuszcza anonima, może działać po zmianie widoczności do końca tej
godziny. Z 30-minutowym cache bajtów okno może sięgnąć 90 minut od wydania.
Treści dostępne wyłącznie prywatnie zachowują podpis do 5 minut i no-store.
Najszerszy rodzic i kontrola Policy z D-020 pozostają bez zmian.

Odpowiedzi z sesją, ciasteczkiem albo logowaniem nie trafiają do wspólnego
cache, także dla publicznych zdjęć. Publiczny odczyt zdjęcia bez stanu
klienta nie wystawia sesji. Ta decyzja nie dopuszcza cache HTML z sesją
ani nie ustala opóźnienia ukrycia HTML. Projekt reguł, bramka i ograniczenia:
`docs/infra/CLOUDFLARE_CACHE_597_610.md`. Konfiguracji Cloudflare nie zmieniono.
