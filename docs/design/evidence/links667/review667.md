# Read-only review #667

Zakres: diff trzech Blade, LinkDestinationLabelsTest.php, CELE_LINKOW_667.md oraz istniejący kontrakt wyszukiwarki. Bez zmian aplikacji, nowych testów, przeglądarki i subagentów.

## Werdykt

Brak znalezionych blokerów. HTML breadcrumb przepisu i odpowiadający mu drugi element BreadcrumbList JSON-LD mają zgodne nazwę „Świeżo z Kuking” i cel discover. Nazwa odpowiada nagłówkowi istniejącego strumienia; nie obiecuje katalogu przepisów. Nie zmieniono routingu ani uprawnień.

Oba puste zeszyty kierują „Poszukaj przepisów” do search z sekcja=przepisy. Istniejący formularz zachowuje ten zakres w hidden, pokazuje pustą frazę oraz wskazówkę rozpoczęcia wyszukiwania. To uczciwe rozpoczęcie szukania, bez nowej funkcji katalogu. Wyszukiwarka jest dostępna także gościowi; sama zmiana nie rozszerza dostępu do prywatnych zeszytów.

## Siła regresji i granice

Test breadcrumb wymaga dokładnie jednego drugiego odnośnika okruszków, jego dokładnej nazwy i href, a następnie parsuje rzeczywisty JSON i sprawdza ten sam element BreadcrumbList. Obie wersje pustego zeszytu mają osobne żądania HTTP oraz dokładny href jednoznacznie podpisanego CTA w main. To nie jest globalne assertSee adresu z nawigacji. Test pustego wyszukiwania sprawdza zakres oraz puste pole q; tekst wskazówki jest sprawdzany globalnie, ale kontrakt celu jest już związany z formularzem.

Niewielka granica, nie blocker tej zmiany: test nie wykonuje submitu zapytania ani nie asertuje action formularza. Brak też własnego oglądu dłuższej nazwy breadcrumb na wąskim ekranie; root powinien zachować planowany odbiór UI. Nie wykazano tu nowej pułapki UX wymagającej zmiany celu lub tekstu.

Dokument uczciwie określa pakiet jako WIP, podaje początkowe porażki i późniejszy wynik oraz rozdziela pozostałe etapy dostarczenia. Wynik 4/17 jest dowodem zgłoszonym przez wykonawcę, nie moim nowym przebiegiem. Review nie zastępuje kontroli ujemnych, hooka, CI ani odbioru produkcji.
