{{--
    EKRAN-ROZDROŻE USTAWIEŃ (`/ustawienia`) — issue #344, koszt zapisany w D-168.

    CO BYŁO ŹLE
    Napis „Ustawienia" prowadził na ekran o nagłówku „Czytelność". Oba miejsca
    z tym napisem — nawigacja boczna na komputerze i rząd akcji własnego
    profilu — celowały w `settings.accessibility`, bo rozdroża po prostu nie
    było. Człowiek naciskał jedno słowo, a dostawał inne; dopiero spis w prawej
    szynie mówił mu, że reszta ustawień też istnieje.

    DLACZEGO TEN EKRAN UŻYWA `<x-ustawienia-nawigacja>`, A NIE MA WŁASNEGO SPISU
    Bo własny spis byłby DRUGĄ listą ekranów ustawień w repozytorium. Komponent
    powstał właśnie po to, żeby taka lista była jedna: nowy ekran dopisuje się
    w jednym miejscu i od razu widzą go wszystkie pozostałe. Rozdroże z własną
    kopią byłoby dziesiątym miejscem do zapomnienia — i to najgorszym, bo
    nazywa się „Ustawienia" i człowiek wierzy, że widzi tam wszystko.
    Kompletności tamtej listy pilnuje `UstawieniaNawigacjaTest`; kopia nie
    miałaby takiego strażnika.

    Komponent pasuje tu bez jednej zmiany: bez `aktywne` żadna pozycja nie jest
    „tu jesteś", więc wszystkie dziewięć są odnośnikami — dokładnie to, czym
    ma być rozdroże.

    DLACZEGO W `<main>`, A NIE W `<x-slot:rail>` JAK NA POZOSTAŁYCH EKRANACH
    Bo tutaj ten spis JEST treścią ekranu, a nie towarzyszy formularzowi.
    Na dziewięciu ekranach ustawień stoi w prawej szynie, bo główną kolumnę
    zajmuje formularz (`UstawieniaDwieKolumnyTest`). Na rozdrożu
    formularza nie ma — spis w szynie zostawiłby `<main>` z samym nagłówkiem,
    a na telefonie (gdzie szyna ląduje POD treścią) człowiek zobaczyłby
    najpierw pusty ekran. Dlatego ten jeden ekran świadomie NIE podaje slotu
    `rail`: dwa egzemplarze tej samej listy na jednej stronie byłyby dla
    czytnika ekranu podwójną nawigacją o tej samej nazwie.

    DLACZEGO `noindex`
    Tak jak każdy ekran ustawień: to strona konta, nie treść dla wyszukiwarki.
--}}
<x-layout title="Ustawienia" :noindex="true">
    <h1>Ustawienia</h1>

    <p class="mb-5">
        Wybierz, co chcesz zmienić. Wszystko zapisuje się na Twoim koncie —
        będzie tak samo na telefonie, tablecie i komputerze.
    </p>

    <x-ustawienia-nawigacja />
</x-layout>
