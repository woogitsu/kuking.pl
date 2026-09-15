# Niedostępne zapisy w zeszycie — #567

15 września 2026, robocza Alfa 0.36. Poprawka lokalna; nie wdrożona.

## Odtworzenie i zakres

W main 72b96f9 zapis prywatnego przepisu pozostawał w collection_items,
lecz zeszyt pokazywał „W tym zeszycie nic jeszcze nie ma”. Odtworzono
HTTP+DB oraz na lokalnej zalogowanej przeglądarce, 320/1440 px.
Niezalogowany gość trafia na login — nie uznajemy tego za odbiór zeszytu.
Dodatkowa próba wykazała tę samą lukę dla treści usuniętych miękko: ich
wiersze zapisów pozostają i mogą odzyskać widoczność po przywróceniu.

Kontroler korzysta z istniejących paginatorów i ich total(), uwzględnia
oba typy zapisów. Dwa COUNT wszystkich zapisów zastępują dwa dotychczasowe
COUNT wpisów; brak N+1. withTrashed obejmuje wyłącznie liczenie, nigdy
pobieranie treści. Wszystkie dotychczasowe filtry i nazwy paginatorów
pozostają. Komunikat nie zgaduje przyczyny ani właściciela zeszytu.

## Kontrole

- 23 testy / 185 asercji: ZeszytNiedostepneZapisyTest, ZeszytPrzyjmujeWpisyTest,
  ZeszytWidocznoscTest, ZawartoscZeszytuTest, ZeszytZapisanychWpisowWydajnoscTest.
  W scenariuszu wydajności: 13 zapytań, 30 wpisów, 12 na stronie.
- Public/private/restore, blokady w obie strony, banned/pending_delete
  i przywrócenie, mieszane typy, obcy zalogowany widz, cztery kombinacje
  stron, prawdziwie pusty zeszyt, miękkie usunięcie i przywrócenie.
- Cztery fizyczne negatywy kontrolera: pominięcie przepisów; count() zamiast
  total() przepisów; count() zamiast total() wpisów; usunięcie withTrashed.
  Każdy FAIL→restore MD5/mtime→PASS. Osobne kopie/logi poza repo,
  indeks w evidence/zeszyt567/negatywy.json.

Przeglądarka na końcowych źródłach: 36 konfiguracji — 320, 360, 390,
414, 768 i 1440 px; oba motywy; tekst 100/140% przy zoomie 100% oraz
tekst 140% przy rzeczywistym zoomie 200%. Zoom potwierdzony tabs.getZoom
i innerWidth. Rzeczywiste logowanie i wejście do zeszytu; poprawny komunikat,
brak tytułu prywatnego przepisu i poziomego overflow.

Obejrzano desktop przed zmianą oraz trzy końcowe zrzuty: desktop jasny,
telefon jasny i telefon ciemny z zoomem 200%. Widoczny pełny komunikat,
bez fałszywej pustej karty. Zrzuty i JSON w katalogu evidence/zeszyt567.
Niezależne końcowe review, także COUNT z withTrashed: brak blokera.

Ograniczenia: odbiór wizualny używa pojedynczego niedostępnego przepisu;
odmiany 2/5, przywracanie i pozostałe stany sprawdzono PHP. Ten pakiet
nie mierzy ponownie wszystkich akcji klawiatury ani czytników ekranu.
Nowe asercje tekstowe same nie dowodzą braku danych w atrybutach HTML;
filtry modeli przed renderem zachowano i oceniono w review.

Hook, CI i produkcja wymagają potwierdzenia. Pełny port marki nadal
CZĘŚCIOWO. Nie dodano migracji ani nowych funkcji; rollback przez zwykły
revert pakietu po kontrolach.
