# Odbiór Alfy 0.26 — 14 września 2026

Pełny port marki: **CZĘŚCIOWO**. Ten odbiór zamyka potwierdzony błąd kontrastu #534, nie audyt wszystkich ekranów i stanów.

## Poprawka i regresja

PR #535, head `15dd0dbcd2777dc3a362929b5c79278eedafcc1d`.
Selektor `.notice a` nadpisywał kolory napisów przycisków z własnym tłem. Wyłączenie `.btn-primary` i `.btn-secondary` przywraca ich właściwe kolory, zachowując ochronę zwykłych linków z #89. Nie zmieniono treści, formularzy, danych, uprawnień ani palety.

[Raport lokalny](KONTRAST_PODPOWIEDZI_534.md): 24 konfiguracje / 72 pomiary, trzy rzeczywiste negatywy CSS z kopiami poza repo i odtworzeniem MD5 oraz mtime. Dodatkowo 12 wariantów prawdziwego zoomu 200%. Obejrzano reprezentatywne zrzuty. Fokus w tej regresji jest programowy; nie oznacza testu Tab. Niezależny review zamknął poprawione zabezpieczenie aktywnego połączenia fixture. Obowiązkowy hook przed wysyłką: PASS (257,86 s).

Pierwsza wysyłka została zatrzymana przez istniejący test źródła CSS. Przyczyną były CRLF po przeniesieniu pliku; przywrócono repozytoryjne LF, bez osłabiania testu. Ponowny test rodziny: 6 testów / 57 asercji. Wszystkie kontrole ujemne powtórzono na końcowych bajtach.

[Regresja gotowości strony](FOKUS_ZDJECIA_BEZ_JS_536.md) usuwa wyścig pomiaru bez JS z CSS. W CI poprawiony test potwierdził jeden opóźniony arkusz, 7 Tabów i obrys solid 3 px. Zachowano wszystkie wymagania; rzeczywiste negatywy JS i CSS nadal dają kod 1.

## Kontrole GitHuba i wdrożenie

PR #535 scalono jako `d7f92870bf9a0a3471e305c370ab79524c316d79`.
CI PR [34834435058](https://github.com/woogitsu/kuking.pl/actions/runs/34834435058)
i CI main [34836678738](https://github.com/woogitsu/kuking.pl/actions/runs/34836678738):
wszystkie dziesięć zadań success. Końcowe PHP w obu przebiegach:
**3794 testy / 76279 asercji**. Logi jobów 103944972721 i 103952020385.

Railway deployment **6435698933** zgłosił success 14 września 2026
**o 11:36:07 UTC** dla tego SHA. Workflow po wdrożeniu
[34839034097](https://github.com/woogitsu/kuking.pl/actions/runs/34839034097)
zakończył się success. Wcześniejszy Deploy 34836684762 był skipped;
nie użyto go jako dowodu wdrożenia.

## Ogląd produkcji i granice

Rzeczywisty HTTP i zalogowany Chrome pokazały **Alfa 0.26 / d7f9287**.
Arkusz `app-jTAzfs5M.css` odpowiada 200, SHA-256:
`b92b87c799c102e0de85907314f8db961e07fb37710d3fcb9cc66da3769ae260`.
Odczytany arkusz zawiera poprawiony selektor
`.notice a:not(.btn-primary):not(.btn-secondary)`.
JS `app-DXNAnudp.js` odpowiada 200, SHA-256:
`e94fdd3ddd8d45e79644a222e092c5a0b80d3a21f4941d8af8684425a4749d75`.
Oba lokalne fonty Inter (latin i latin-ext) odpowiadają 200.
Nie deklarujemy identyczności całego pliku CSS z lokalnym buildem:
mają różne zbiory wygenerowanych klas. Dowód poprawki stanowi odczyt
właściwej reguły z produkcji i ogląd rzeczywistego przycisku.

Na istniejącym własnym wpisie w jasnym motywie obejrzano podpowiedzi:
„Dodaj kolejne zdjęcie” ma czytelny biały napis na czerwonym tle,
a „Kolejność i wygląd zdjęć” ciemny napis na jasnym tle. Nie wykonywano
akcji zapisujących, nie zmieniono preferencji ani widoczności wpisu.
Prywatne URL, treść i zdjęcia nie zostały opublikowane w raporcie.
Kartę audytu przywrócono do wyszukiwarki. To ogląd desktopowy dwóch
przycisków, nie pomiar całej produkcyjnej macierzy motywów i skal.

Kontrast lokalny po zmianie (jasny / ciemny): primary 5,77 / 5,10; secondary 18,03 / 14,04; zwykły link 6,53 / 6,98. Hover i fokus również spełniają próg 4,5.

Wersja, zasoby i ogląd jednego istniejącego widoku nie dowodzą zgodności całego portalu. Przy zoomie 200% i tekście 140% stałe nawigacje pozostawiają mało wysokości; ta poprawka nie zmienia geometrii. Nie testowano fizycznej klawiatury ekranowej, wszystkich czytników ekranu, dostawców OAuth ani klientów poczty. Pozostałe pokrycie i ograniczenia: [macierz](MACIERZ_KOMPLETNOSCI_517.md).
