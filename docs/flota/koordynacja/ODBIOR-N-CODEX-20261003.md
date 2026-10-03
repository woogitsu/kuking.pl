# Odbiór lokalny paczki N — 3 października 2026

## Zakres i kolejność

Paczka N powstaje na własnej gałęzi `codex/paczka-n-20261003`. Paczka M (#2793) pozostaje zarezerwowana dla poprzedniej sesji do jej jawnego przekazania. N ma zostać wydana dopiero po M; przed ostatecznym PR-em wymagane jest scalenie świeżej bazy oraz pełne CI końcowego heada.

W lokalnym złożeniu są „Moje rozmowy” (#2432), zapamiętane gotowania (#2439), zakres zeszytów w „Co ugotuję” (#2591), dowód zabezpieczenia awatara i poprawna kolejność odtwarzania kopii (#2708), a także dwa projekty dokumentacyjne: dziennik decyzji CSAM i klucz dostępu (#2530). Projekty nie uruchamiają funkcji ani nowej infrastruktury. Kontrolę ujemną uprawnień dla #2784 właściciel zatwierdził osobno; została wykonana i odwrócona w izolowanej kopii.

## Wykonane kontrole

Na złożeniu `fb27b5c20ec1ec593fa512b4ba1841c2b9ed59a4`:

- świeży własny `vendor` z dokładnego `composer.lock`, PHP 8.4 i PostgreSQL 18.6, osobna baza UTF8;
- połączone testy zmienionych obszarów i strażników: 64 testy, 1859 asercji, bez porażek, błędów i pominięć;
- strażnicy CHANGELOG, nowości i tras pod Policy: 14 testów, 2348 asercji;
- pełny PHPStan: zero błędów;
- niezależny przegląd: prywatne trasy przed trasą z UUID, konta wyłącznie z sesji, brak przedłużenia retencji przez odczyt, poprawka CodeQL z C zachowana;
- rzeczywiste mutacje składników paczki oblały z własnych przyczyn, pliki przywrócono przed commitami.

To dowody lokalne dla wskazanego złożenia. Nie zastępują pełnej bramki końcowego heada, CI PR-a, CI push `main`, wdrożeń trzech usług ani produkcyjnego `/wydanie`. Próba zestawu Python na Windows wykazała jeden test zależny od separatora ścieżek; pełna bramka końcowa ma działać w izolowanej kopii Linux, bez zmiany testu ani obejścia haka.

## Otwarte czynności

- ostrzeżenia parsera (#2546, #2548) dodane z commita `6b7a81f7d`: 56 testów / 345 asercji oraz dwa rzeczywiste mutanty u agenta; sprostowanie rejestru (#2708) dodane z `7d3cea3bc`: 9 testów / 53 asercje i rzeczywisty mutant granicy. Polityka i archiwum bez zmian. Po złożeniu wykonywana jest wspólna kontrola końcowego heada;
- wykonać zwykły push z niezmienionym hakiem w przygotowanym środowisku Linux;
- po przekazaniu M i jej wydaniu złożyć świeżą bazę, sprawdzić konflikty i wymagane CI N;
- zachować osobno otwarte kryteria pilota 50+, przeglądu prawnego i kroków panelowych;
- oczekiwać potwierdzenia właściciela w #2025 w sprawie oczekiwania workera i harmonogramu na zielone CI.
