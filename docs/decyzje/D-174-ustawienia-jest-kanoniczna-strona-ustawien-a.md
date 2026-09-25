## D-174 · `/ustawienia` jest kanoniczną stroną ustawień, a menu konta stoi na `<details>`

**Data:** 12 września 2026 · PR #439 · issue #344 · Status: **obowiązuje**

### Kontekst

Napis „Ustawienia" prowadził na ekran o nagłówku **„Czytelność"**. **D-168** przyjęło
to świadomie jako koszt — bo rozdroża `/ustawienia` w serwisie nie było, a dorobienie
go to była nowa trasa, nowy ekran i decyzja o tym, co jest kanoniczną stroną ustawień.

### Decyzja

Rozdroże **istnieje** (`SettingsIndexController`, trasa w grupie `auth`) i jest spisem
wszystkich dziewięciu ekranów ustawień. Napis „Ustawienia" wszędzie — w nawigacji
bocznej, na profilu i w menu konta — celuje w `settings.index`. **Napis i nagłówek
ekranu, na który prowadzi, mówią wreszcie to samo słowo.**

### Menu konta przy awatarze stoi na `<details>`, nie na skrypcie

Działa **bez JavaScriptu** (`AGENTS.md` §5). `resources/js/app.js` dokłada tylko
zamykanie kliknięciem obok i klawiszem `Esc` — czyli wygodę, nie działanie.

### „Powiadomienia" w pasku górnym na telefonie

`.side-nav` poniżej 64rem nie ma wcale, a dolny pasek niesie pięć pozycji i szóstej
mieć nie może. Własny profil przestał być jedynym ekranem, z którego człowiek
z telefonem dochodzi do obsługi konta.

📄 `app/Http/Controllers/Settings/SettingsIndexController.php` ·
`resources/views/pages/settings/index.blade.php` · `MenuKontaPrzyAwatarzeTest` ·
`RozdrozeUstawienTest` · D-168 · D-053
