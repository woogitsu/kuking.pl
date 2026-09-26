## D-020 · Adresem zdjęcia jest trasa aplikacji, a bucket wariantów traci domenę

**Data:** 6 września 2026 · **Decyzja właściciela** · Status: **obowiązuje** ·
audyt W7-02 (P0, prywatność), issue #120

Adresem każdego zdjęcia w serwisie był adres pliku w buckecie z własną domeną
CDN. Taki adres nikogo o nic nie pyta i nie przestaje działać: kto raz go
skopiował — z podglądu źródła strony, z historii przeglądarki, z podglądu
linku w komunikatorze — otwierał zdjęcie także po zablokowaniu, po cofnięciu
obserwowania, po przełączeniu przepisu na prywatny i po decyzji moderacyjnej.

Cała macierz widoczności obowiązywała stronę HTML i nie obowiązywała ani
jednego piksela. Najgorszy przypadek nazwał audyt wprost:
`recipes.source_scan_media_id` — skan odręcznej kartki z rodzinnym przepisem,
a na niej nazwiska, adresy i czyjeś pismo.

### Co odrzucono

**Adres nie do zgadnięcia („security through obscurity").** Klucze i tak są
UUID-ami, więc to jest stan obecny opisany ładniejszym słowem. Nie rozwiązuje
niczego, o co chodzi: adres raz ujawniony zostaje ważny na zawsze.

**Podpisywanie adresów CDN-u bez trasy aplikacji.** Krótszy termin ważności
zamiast kontroli dostępu. Nadal odpowiada „tak" komuś, kogo autor właśnie
zablokował, tyle że przez pięć minut zamiast przez lata — i nie da się tego
związać z Policy, bo podpis powstaje bez wiedzy o tym, kto pyta.

**Strumieniowanie bajtów przez PHP.** Najprostsze do napisania i najdroższe
w działaniu: jedna strona feedu to kilkadziesiąt zdjęć po kilkaset kilobajtów,
a proces zajęty przepisywaniem obrazka nie obsługuje nikogo innego.
`X-Accel-Redirect` odpadł osobno i twardo: **przed PHP stoi Caddy, nie nginx**,
a Caddy takiego mechanizmu nie zna.

**Kolumna `visibility` na `media`.** Wygląda najtaniej i jest najdroższa:
byłaby SIÓDMĄ kopią reguły widoczności w tym repozytorium, w dodatku
denormalizowaną, więc rozjeżdżającą się przy każdej zmianie widoczności
rodzica. Powtarzającą się przyczyną błędów jest tu dokładnie to — „reguła
istnieje poprawnie w jednej warstwie, a druga implementuje ją inaczej".

### Co wybrano

**Wszystkie warianty w buckecie BEZ domeny publicznej. Adresem zdjęcia jest
trasa aplikacji, która pyta Policy treści nadrzędnej i przekierowuje (302) na
krótko podpisany adres. Bajty nie idą przez PHP.**

Reguła widoczności zdjęcia nie powstaje na nowo. `DostepDoZdjecia` odwraca
listę rodziców (tę samą, co `KasujZdjecie::ODWOLANIA`) i woła ICH Policy przez
`Gate`. Rodzicom, którzy Policy nie mieli, dopisano ją delegującą do przepisu
albo do konta, zamiast wpisywać warunek u siebie.

**Najszerszy rodzic wygrywa.** Zdjęcie da się przypiąć do kilku treści naraz,
a przez rodzica publicznego bajty i tak są jawne. Rodzic najwęższy dawałby
pustą ramkę w publicznym przepisie bez żadnego zysku dla prywatności.

**Odmowa to 404 nieodróżnialne od zdjęcia nieistniejącego**, z treścią
odpowiedzi włącznie.

**Cena, wprost:** każde żądanie zdjęcia to teraz żądanie do Laravela i kilka
zapytań o rodziców. Krok 1 świadomie tego nie optymalizuje — dopiero pomiar
z produkcji ma rozstrzygnąć, czy potrzebny jest cache decyzji. Druga cena:
podgląd linku w serwisach społecznościowych idzie teraz przez przekierowanie
(`og:image` wskazuje trasę), co część scraperów obsługuje wolniej.

**Czego to NIE załatwia i nie da się załatwić z kodu:** zdjęcie klucza `url`
z konfiguracji nie zdejmuje domeny `cdn.kuking.pl` z bucketu po stronie
Cloudflare. Dopóki ta domena tam wskazuje, stare adresy działają dalej.
To jest **issue #120** i należy do właściciela.

**Zmiana wymaga:** zmierzonego kosztu tej trasy na produkcji (wtedy zmienia się
sposób, nie zasada) albo image CDN-u z własną autoryzacją na brzegu, który
umiałby zapytać Kuking o decyzję, zanim odda plik.

📄 `app/Domain/Media/DostepDoZdjecia.php` ·
`app/Http/Controllers/MediaController.php` · `app/Models/Media.php` ·
`config/filesystems.php` · `docs/MEDIA_PIPELINE.md` ·
`tests/Feature/ZdjeciaChronioneNieWyciekajaTest.php` · issue #120
