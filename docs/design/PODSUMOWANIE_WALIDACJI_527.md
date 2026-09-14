# Podsumowanie walidacji bez zgadywania przyczyny — #527

## Potwierdzony problem i zmiana

Na lokalnej rzeczywistej stronie moderatora zapis notatki liczącej 2001
znaków wracał z poprawnym błędem „Notatka jest za długa” oraz mylącym
nagłówkiem „Jednej rzeczy jeszcze brakuje”. Tekst nie był brakujący.
Próba przeszła normalne logowanie i włączenie 2FA, na izolowanych danych.

Ten sam nagłówek występował we wspólnym `error-summary`, w kreatorze
przepisu i w kolejce zgłoszeń. Wszystkie trzy mówią teraz **„Sprawdź
formularz”**. Przyczyny i odnośniki do pól pozostają. Nie zmieniono alertu,
celowego fokusu podsumowania, sufiksów wierszy ani zachowania `old()`.

Dolne instrukcje rejestracji hasłem, przez Google i Facebooka wskazują
„co trzeba poprawić”. Nie nazywają błędu formatu lub zajętej nazwy brakiem
wartości. Nie zmieniono uwierzytelniania ani połączeń z dostawcami OAuth.

`COPY_STYLE.md` powtarzał historyczny błędny wzorzec. Poprawiono tabelę
zgodnie z nadrzędną zasadą prawdziwego komunikatu: opis ma odpowiadać
rzeczywistej przyczynie. Nie zmieniono kierunku wizualnego konstytucji.

## Regresja

`PodsumowanieWalidacjiNieZgadujePrzyczynyTest` obejmuje:

- rzeczywisty POST za długiej notatki: jeden błąd oraz dwa błędy przy
  dodatkowo nieprawidłowym statusie; tekst wraca, stan bazy nie zmienia się;
- rzeczywistą aktualizację Livewire nieprawidłowej krótkiej nazwy;
- rendery trzech rzeczywistych widoków wejścia z błędem formatu nazwy.
  Nie są one testem zewnętrznego OAuth.

Dotychczasowe testy `DrzwiWejsciowePrawdaTest` i `TerminZawieszeniaTest`
sprawdzają nowe słowa, zachowując asercje obecności podsumowania,
odpowiednich danych i pojedynczego alertu.

Zintegrowany zakres #524/#527: **93 testy / 671 asercji, zielony** po
kontrolach ujemnych. Obejmuje także ochronę sekretów, idempotencję,
przekroczenie limitu, pełny przepis i panel wiadomości. Pint poprawny.
To wykonanie lokalne, nie wynik GitHub Actions nowego pakietu.

Sześć rzeczywistych negatywów Blade przywracało kolejno stare zdanie w
każdym z sześciu zmienionych widoków. Wszystkie zakończyły test błędem.
Kopie poza repo: `/tmp/kuking527-negative-1789370369854152799`.
Po każdej próbie przywrócono bajty, MD5 i mtime oraz wyczyszczono kompilację
Blade przed ponownym pomiarem. Ślad: `output/negative527-results.json` i
`output/negative527-*.log`. To dodatkowe sześć negatywów względem siedmiu
opisanych w raporcie #524.

## Odbiór i ograniczenia

Baza lokalna `kuking_audit429`, PostgreSQL 55439; rzeczywisty Chromium.
Ponowiony nieprawidłowy POST notatki, 320/1440 px, tekst 140%, oba motywy:
cztery konfiguracje. Sprawdzono treść podsumowania, zachowanie wszystkich
2001 znaków, link do pola oraz szerokość dokumentu. Zrzuty:
`output/playwright/summary527-*.png`. To nie test wysyłania odpowiedzi e-mail.

Nie przypisujemy tego odbioru każdemu formularzowi ani wszystkim stanom
błędów. Zestaw nie obejmuje klawiatury ekranowej. Wcześniejsza ramka #518
nie została odtworzona; celowy fokus błędu pozostaje i nie jest jej naprawą.

Podczas przygotowania sondy kreatora 181-znakowy tytuł ujawnił odrębny błąd
autosave przed walidacją — [#528](https://github.com/woogitsu/kuking.pl/issues/528).
Nie uznano wyjątku SQL za poprawną regresję komunikatu; test #527 używa
nieprawidłowej dwuliterowej nazwy, która dochodzi do właściwej walidacji.
Błąd autosave ma osobny reproduktor i zakres naprawy.

Zmiana jest częścią Alfy 0.24 na gałęzi
`fix/524-527-odzyskiwanie-formularzy`. Wdrożenie wymaga osobnego odczytu
po kontroli CI i scaleniu; pełny port marki nadal **CZĘŚCIOWO** odebrany.
