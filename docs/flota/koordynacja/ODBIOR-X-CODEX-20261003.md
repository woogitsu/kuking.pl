# Odbiór lokalny paczki X — 3.10.2026, 11:06 UTC

## Zakres zamrożony

Gałąź koordynatora `codex/paczka-x-20261003`, kod
`a6fc170368f201e12e99d64e554e8fa6446524ee`. Przyjęte sześć zakresów:

| Issue | Przyjęty head autora | Poprawka |
|---|---|---|
| #2882 | `306a716ed22de44edc399dffe86126dfbf67123b` | Odmowa całego niewspieranego ułamka Unicode, także z licznikami i mianownikami Unicode. Pierwszy wariant odrzucono i poprawiono; D-284 nie poszerza parsera. |
| #2883 | `d12ef23706ccd22bbe3518ddaf839d93e2de31d1` | Brak przypięcia daje odmowę; zero zostaje wyłącznie dla rzeczywistego ponowienia. Poprawny wybór i klucz pozostają, niedostępne UUID odpadają. |
| #2884 | `8febcb6665f61c19f5e64518a3bad21351677bff` | Wycofanie wskazówki nie pozwala zmienić tekstu wcześniej otwartej sprawy; czas i opis zmian pozostają do korekty. |
| #598 | `ac217417602f92fd347894581ba387497b7007ec` | Niedostępny pomiar zapisuje istniejący rekord z null; kod błędu i alarm zachowane. Zbiorczego issue nie zamykać bez pomiarów produkcji. |
| #2817 | `b9c41ab1ffde4fdc21955490b78345e6f3d6fbc7` | Wersja i czas prób oceniane osobno, bez utożsamiania NULL i zgodności albo zera; granica strony objęta HTTP. |
| #2836 | `2075b9ac3bf2c45e10d1a65f03b22dfd57e6e3f3` | Kopia dnia zachowuje porcje i dopisek, odcisk wykrywa ich zmianę od podglądu. |

Root przeczytał kod i przyjął niezależne odczytowe ACCEPT każdego zakresu.
Konflikty trzech plików zbiorczych rozwiązano sumą: dotychczasowe kontrole
i ich wzorce oraz wpisy CHANGELOG pozostają. X zawiera zwykłe merge W19,
N–U z poprawką odnośnika `c31238b01` i main wydania S `09f8af1c7`.
Nie zastępuje wcześniejszych paczek nowym równoległym PR-em; przed własnym
PR wymaga ich przyjęcia i świeżej integracji C. Nowe prace #2877 i
uzupełnienie dowodu #2861 przeznaczono do późniejszej Y.

## Własne kontrole koordynatora

Runtime `/home/codex-admin/kuking-koordynacja-20261003-codex/repo-x`,
baza `kuking_test_x20261003`, właściciel `kuking_pg18_owner`,
`127.0.0.1:55488`, PostgreSQL18.6. Fizyczne zależności z locków,
własny klucz i jawne APP_BASE_PATH; inne bazy i worktree nietknięte.

| Kod pomiaru | Rzeczywiste wykonanie |
|---|---|
| `03380a6b8` | 305 testów / 2366 asercji funkcjonalnych oraz 48 / 1731 dodatkowych jednostek/HTTP/strażników PASS, bez błędów i pominięć. Cztery kontrole #2882/#2884: właściwe FAIL i PASS po exact restore. Pełny PHPStan0; build z 307 testami Node PASS. |
| `f52fbfcce` | Zdjęcia i monitoring: 80 / 2164 PASS, zero failure/error/skip. Cztery kontrole #2883/#598 POTWIERDZONA, właściwe przyczyny, exact bytes+mtime czterech źródeł. Pełny PHPStan0. |
| `a6fc17036` | Próby/Planer/strażnicy/dokumenty: 178 / 2965 PASS, zero failure/error/skip. Cztery kontrole #2817/#2836 POTWIERDZONA; oba źródła mają identyczne bajty i nanosekundowy mtime po przywróceniu. Pełny PHPStan0. |

Dowody poza repo w katalogu `transfer` tego samego stanowiska:
`X-feature.xml`, `X-extra-guards.xml`, `X-central-controls.log`,
`X-phpstan.log`, `X-build.log`, `X-quatre-feature.xml`,
`X-quatre-controls.log`, `X-quatre-phpstan.log`,
`X-final-code-feature.xml`, `X-final-code-controls.log`,
`X-final-code-phpstan.log`; trzy skrypty stage mają zapisane exit0.
37 testów mechanizmu kontroli PASS na kolejnych składaniach.
Bajty źródeł #2882/#2884/#2883/#598/#2817/#2836 odpowiadają przyjętym commitom.

To lokalny odbiór, nie pełne CI ani ogląd produkcji. Zwykły pełny pre-push
końcowego commita z tą dokumentacją, pełne CI niedraftowego PR i odbiór
produkcji pozostają wymagane. Nie odhaczono nieuruchomionych kontroli.

## Granice i wycofanie

Bez migracji, nowych funkcji, kosztów, retencji, danych produkcji i zmian
Policy. Cofnięcie wąskich korekt kodu przywróci znane błędy; nie cofa się
bazy i zapisanych danych. Przywracanie należy opisać w PR wydania.

Dodatkowy wyścig opóźnionego INSERT ReportContent jest odrębnym, jeszcze
nieodtworzonym podejrzeniem. Automatyczny przegląd odmówił jego izolowanej
próby; nie wykonywano jej za agenta i nie utworzono testowej bazy. Osobna
decyzja właściciela pozostaje potrzebna do tej konkretnej odrzuconej czynności.
Nie zmienia to odebranego scenariusza #2884 z zapisaną wcześniej sprawą.

Ręczne kryteria pilota50+, prawa, paneli, R2/CDN i kopii pozostają otwarte.
#2861 wymaga jeszcze dodatkowej sceny panelu moderatora i okna wewnątrz
operacji potwierdzenia2FA; agent przygotowuje ją osobno, bez cofania ochrony.

## Odświeżenie zależności bez zmiany kodu

3.10.2026 zwykły merge dołączył odebraną W `4f16fc756da0fbb2a8d14e2a68fe5004ef98c22d`,
zawierającą świeżą V4d3 i C30 po N–U. Po merge aplikacja, zasoby i testy Dwa
są bitowo identyczne z X `23b6ab6532a845d0fd0cf160be4d23ba984c6fa2`,
której pełny zwykły push zakończył exit 0. Różnica to dokumentacja zależności.
Dokładny nowy head wymaga jeszcze zwykłego pełnego pushu oraz pełnego CI
PR-a po przyjęciu W do C; nie otwierać kumulującego duplikatu wcześniej.
