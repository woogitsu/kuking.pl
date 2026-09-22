# Przekazanie panelu #581 — 15 września 2026

## Najpilniejsze
Użytkownik zażądał zakończenia z powodu limitu. Nie trwa push ani test. Nie uruchamiać całego audytu od nowa.

Repo kanoniczne: C:\Users\matma\Documents\Codex\kuking.pl.
Worktree pakietu: C:\Users\matma\Documents\Codex\kuking-panel581, gałąź fix/581-marka-panelu.
Kod panelu zapisany w 9a2a40dd8470f614a85341f307e8d9a9df56d353, wynik nieudanego hooka w 3b7d4bd. Późniejszy commit WIP zawiera niniejsze przekazanie i rozpoczęte korekty 4 testów.
Gałąź NIE została wysłana. Nie ma PR panelu. Nie obchodzić hooka.

## Dlaczego push nie przeszedł
Pełny hook: Pint, składnia PHP, skrypty, PHPStan i odwracalność migracji PASS; pełne PHP FAIL. Proces41749 zakończony exit1. Log /home/mateusz/push581-final.log.
Odtworzenie: 25 testów, 267 asercji, cztery porażki. Diagnoza:
1. LicznikiKolejekPaneluTest — regex nie dopuszcza nowego zamknięcia span etykiety przed plakietką. Semantyka czytnika jest zachowana w HTML.
2. TrybPaneluWMenuTest — regex powrotu nie dopuszcza span marka-panel-nav-etykieta.
3. PanelSzerokiTelefonTest — regex znacznika app-body nie dopuszcza dodanego atrybutu data-marka-panel. Nie zawiodła asercja geometrii CSS.
4. OdstepMiedzyDrogamiWejsciaTest — wykrywa nową regułę rytmu powierzchni ograniczoną do [data-marka-panel] .marka-panel-tresc, która nie dotyczy logowania.

## Rozpoczęte korekty testów — WIP
W powyższych czterech plikach dodano dopuszczenie konkretnej nowej struktury bez usuwania właściwych asercji. Po pierwszej korekcie 25 testów /272asercje: pozostała jedna porażka skanera marginesów. Przyczyna: selector CSS jest wieloliniowy. W kanonicznym worktree dopisano normalizację whitespace przed sprawdzaniem wąskiego wyjątku. TEJ OSTATNIEJ KOREKTY NIE PRZETESTOWANO: próba Copy-Item do ścieżki WSL była błędna (pojedynczy backslash). Runtime nadal ma poprzednią wersję tego testu. Najpierw zsynchronizować ten plik przez WSL cp albo Python shutil i odczytać zmianę. Nie uruchamiać helpera fix581-contracts.py ponownie: oczekuje starych ciągów.
Korekty wymagają Pint, sprawdzenia sensu wyjątków i fizycznych kontroli ujemnych prawdziwych źródeł zgodnie z AGENTS, następnie pełnego hooka. Nie są gotowym obejściem bramki.

## Co już sprawdzono w panelu
Raport docs/design/PANEL_MODERACJI_MARKA_581.md ma aktualny checkpoint i historię. Świeże600/600, zoom główne26/26, stany12/12, walidacja11formularzy×2motywy=22/22. To nie dowodzi wszystkich poprawnych operacji. Zrzuty obejrzane reprezentatywnie; pełny port marki nadal CZĘŚCIOWO.
Niezależny review: C:\Users\matma\Documents\Codex\kuking.pl\output\REVIEW_FINAL_PANEL581.md. Nie znalazł blokera kodu, później potwierdził dodatkowe body2/2. Nie widział jeszcze najnowszych korekt4testów. Lokalne CSRF w negative.txt zredagowano (7wartości).

## Środowisko i wysyłka
Runtime /home/mateusz/kuking-panel581-runtime. Ma teraz WŁASNE .git z bundle HEAD9a2a40d. Nie kopiować metadanych do Windows. Po nowych commitach pobrać świeży bundle i zsynchronizować źródła, zachowując lokalne .env/vendor/storage. Nie resetować cudzych zmian.
PHP /opt/kuking-php-8.4-avif/bin/php; Node /home/mateusz/.nvm/versions/node/v24.19.0/bin/node.
PostgreSQL tylko127.0.0.1:55439, DB kuking_581_tests; browser kuking_581_browser; acceptance kuking_581_acceptance. Nigdy5432.
Server lokalny8035 istniał przy ostatnim odczycie. Nie jest procesem push/test; zweryfikować przed użyciem. Nie uruchamiać pełnego PHP równolegle z browserem piszącym media.
Helper output/push581-final.py w repo kanonicznym wykonał jednorazowy git init/fetch bundle; teraz NIE nadaje się do ponowienia (assert no.git). Odtworzyć zwykły push z jego bezpiecznymi zmiennymi DB/PGPORT55439, MAIL_MAILER=array i wymaganym pre-push scripts/check.sh --szybko.
output/create-pr581-final.py: deduplikacja, draft, ale twardy SHA9a2a40d — po nowym commicie zaktualizować. Po błędzie API odczytać stan przed ponowieniem.

## Inny pakiet i produkcja
PR586 bezpieczeństwo już na GitHub, head17026fc1e1421000e0488a76f66d718ffb7fdb76. Draft; ready blokowane limitem GraphQL. Required CI35013023908 przeszło; nonblocking race connectionrefused127.0.0.1:32769, nie ustalono przyczyny TCP. Nie twierdzić, że wdrożono. Patrz output/DIAGNOZA_WYSCIGI_586.md i ready586.py.
Ostatnia potwierdzona produkcja w tej pracy: Alfa0.39, SHA108bc93f809ff904baf694b04e96c956d17bdbd3. Zweryfikować aktualny main/deploy przed merge.
Nie ruszać PR456. Stary nieśledzony RAPORT_583.md w worktree digest583 jest zastąpionym materiałem, nie publikować jako aktualny raport.

## Kolejność
1. Aktualne statusy repo/procesów; odczyt AGENTS i powyższych raportów.
2. Dokończyć cztery korekty regresji, ich negatywy, review i Pint.
3. Commit, synchronizacja runtime, zwykły push z hookiem.
4. ZdalnySHA, draftPR, wymaganeCI. Nie scalać przed odbiorem.
5. Dopiero po wymaganych kontrolach merge i Railway/HTTP potwierdzenie.
6. Potem #584/#585 i pozostała macierz marki; bez nowego audytu od zera.
