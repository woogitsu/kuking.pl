# Odzyskanie edycji po 419 — rzeczywisty zoom 200%

Źródła: 6fb497caf260f5a45cb79edca0ba46c5f712dc17. Izolowany runtime /home/mateusz/kuking-492-put-zoom, baza kuking_492_put_zoom na127.0.0.1:55439, UTC, poczta array. Serwer8074 został zatrzymany po odbiorze.

Oba motywy ustawiono rzeczywistymi kontrolkami aplikacji, tekst140. Rozszerzenie Chromium potwierdziło getZoom=2, CSSviewport320×900. Kontrolowana niewłaściwa wartość CSRF i usunięcie Sec-Fetch-Site z transportu wywołały prawdziwe middleware419. Nie jest to naturalne wygaśnięcie sesji.

W obu motywach: wybrany snapshot przepisu, kroków i mediów nie zmienia się przy419 (nie jest to porównanie całej bazy), odzyskany payload obejmuje niepuste pola, pięć Tab od punktu startowego dociera do Wyślij jeszcze raz. Przycisk ma focus-visible, widoczny obrys/cień, po dodatkowym scrollIntoViewIfNeeded wykonanym przez harness mieści się w viewport i przechodzi hit-test środka; nie dowodzi to samoczynnego odsłonięcia przez sam Tab. Enter powoduje prawdziwy POST z _method=PUT, odpowiedź302 i zapis opisu oraz trzech kroków. Zachowane istniejące medium, hero, skan, przypisania zdjęcia kroku, minutniki kroków i prywatność. Brak poziomego overflow ekranu419.

Obejrzano oba PNG. Akcja ponowienia nie jest zasłonięta. Przy tej skali górny pasek i dolna nawigacja zajmują znaczną część ekranu; górny pasek przykrywa fragment tekstu w bieżącym przewinięciu. Nie jest to odbiór całej nawigacji ani dowód, że każdy fragment strony pozostaje niezasłonięty. Nie badano fizycznego telefonu/czytnika ani nowych uploadów.

Błędy przyrządu przed końcowym sukcesem: bootstrap testowy wymaga jawnego DB_DATABASE (guard zatrzymał fixture przed zapisem); domyślny headless-shell nie uruchomił rozszerzenia zoomu; fill nie służy do ukrytego tokenu; oczekiwanie URL już obecnego na419 nie czeka na zakończenie ponowienia. Końcowy harness używa pełnego Chromium, zmiany kontrolowanego tokenu w DOM i czeka na rzeczywistą odpowiedźPOST302 przed odczytem DB. Oba końcowe scenariuszePASS, results.json. Nie zmieniano kodu aplikacji ani produkcji.

To uzupełnia wąską granicę poprzedniego ODBIOR_PUT_I_LINKU_492.md; nie zamyka całej #492. Niezależny reviewer obejrzał oba PNG i potwierdził wąski zakres; jego trzy zastrzeżenia (dodatkowe przewinięcie, snapshot zamiast całej bazy, tylko minutniki kroków) uwzględniono w tym opisie. REVIEW.md zawiera pełny wynik. Dowody dołączono w katalogu evidence/put-zoom492. Pakiet dokumentacji wymaga zwykłej wysyłki i CI. Nie dołączać prywatnego fixture, sesji ani tokenów.
