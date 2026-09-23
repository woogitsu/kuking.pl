# #652 — rzeczywisty zoom 200%

Wykonano 4 sceny: motyw jasny/ciemny × oba kierunki przejścia między listami.
Chrome extension chrome.tabs.setZoom(tabId, 2); chrome.tabs.getZoom po nawigacji = 2 w każdej scenie.
Viewport startowy 640×1800, po zoomie innerWidth/innerHeight 320×900, DPR 2, scrollWidth 320. Nie ustawiano deviceScaleFactor ani rozmiaru fontu.

Kliknięto rzeczywiste linki: wykonania2 → komentarze2 oraz komentarze2 → wykonania2. W każdym przypadku końcowy URL zachował oba parametry=2, a DOM zawierał komentarz13 i wykonanie13. Wszystkie 4 sceny PASS.

Obejrzano wszystkie cztery PNG wymienione w report.json. Oba przyciski paginacji mieszczą się i mają pełne czytelne etykiety w obu motywach. Treść zawija się bez poziomego przepełnienia. Istniejący przyklejony nagłówek zasłania górną część przewiniętej karty, a podpowiedź wyglądu dolną część widoku; przyciski paginacji w zapisanych kadrach pozostają odsłonięte i klikalne. Nie jest to ocena całej aplikacji przy zoomie.

Zrzuty wykonano Page.captureScreenshot bez nadpisania metryk viewportu, zgodnie z helperem scripts/panel-details.mjs. Pierwsze technicznie puste przechwycenia Playwright zostały zastąpione prawidłowymi i dopiero te obejrzano oraz przyjęto.

Serwer8052 i istniejąca baza browser652 bez zmian. Jedyny formularz: przełączenie motywu gościa (cookie); bez zmian danych bazy. Brak edycji źródeł aplikacji i push/commit.
