## D-126 · Panel formularza nie pojawia się tam, gdzie w danym stanie ekranu nie ma czego wypełnić

**Data:** 11 września 2026 · Status: **obowiązuje**

Panel z mocną obwódką **obiecuje**, że jest co wypełnić. To ta sama zasada, co zakaz
martwego przycisku (D-053), przeniesiona na warstwę powierzchni.

Dlatego warstwę wybiera tam warunek, a nie stała: `errors/419`, `errors/429`, zmiana
adresu e-mail (gdy poczta nie działa) i odpowiedź w panelu moderacji (gdy nie ma
adresu do odpowiedzi) schodzą wtedy na sekcję.

**Przypadek otwarty, świadomie:** `pages/collections/index.blade.php` ma
`<details class="panel-formularza">` z podsumowaniem „Załóż nowy zeszyt". W stanie
zwiniętym mocna obwódka otacza sam przycisk — pola są w środku, ale niewidoczne.
To nie jest martwa obietnica, ale przez większość czasu panel nie ma czego
wypełniać. Zostawione bez zmian i nazwane wprost, żeby nie udawać, że problemu nie
ma.
