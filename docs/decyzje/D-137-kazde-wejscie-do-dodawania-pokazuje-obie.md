## D-137 · Każde wejście do dodawania pokazuje OBIE drogi, a nie tę, przez którą się weszło

**Data:** 11 września 2026 · Issue #366 · Zgłosił właściciel · Status: **obowiązuje**

### Zgłoszenie

„użytkownicy nie widzą że w »Dodaj« można wybrać »Zdjęcie i kilka słów« i »Cały
przepis«… trzeba to ujednolicić".

### Co było nie tak

Drzwi do dodawania policzone: **osiem** prowadziło prosto do zdjęcia, **trzy**
prosto do przepisu, **trzy** do ekranu wyboru. Czyli w jedenastu przypadkach na
czternaście człowiek nie dowiadywał się, że druga droga w ogóle istnieje.

To tłumaczy zjawisko, o które właściciel pytał osobno — „wszyscy dodają zdjęcie
i kilka słów". Nie dlatego, że wybrali; dlatego, że nie mieli czego wybierać.

### Decyzja

Nad **każdym** formularzem dodawania stoi ten sam komponent
`x-zakladki-dodawania` z dwiema zakładkami: „Zdjęcie i kilka słów" oraz
„Cały przepis". Bieżąca jest oznaczona, druga jest odnośnikiem.

Kontrast policzony **przed** wklejeniem, nie po: napis bieżącej 5,72:1 w jasnym
i 4,72:1 w ciemnym (próg 4,5), obwódka niebieżącej 4,16:1 i 3,83:1 (próg 3 dla
obwódki kontrolki, WCAG 1.4.11). Token `--color-border` dałby 1,40:1 i nie nadawał
się tu w ogóle.
