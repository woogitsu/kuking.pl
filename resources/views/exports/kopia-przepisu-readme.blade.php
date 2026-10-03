{{--
    Zwykły tekst, bez HTML-a (#2531). Otwiera się w Notatniku na każdym
    komputerze. Wywołujący dopisuje na początku sygnaturę UTF-8 (BOM).
--}}
KOPIA PRZEPISU Z KUKING.PL
==========================

Przepis: {{ $tytul }}
Kopia przygotowana: {{ \App\Support\Czas::data($kiedy, 'j F Y, H:i') }}

JAK PRZECZYTAĆ BEZ INTERNETU
----------------------------

Kliknij dwa razy na plik "{{ $nazwaPliku }}".

Otworzy się w przeglądarce (Chrome, Edge, Firefox, Safari — w tej, którą
masz) i zobaczysz cały przepis: składniki, kroki i to, skąd go masz.
Internet NIE jest do tego potrzebny. Możesz go też wydrukować (Ctrl+P).

CO JEST W ŚRODKU
----------------

{{ $nazwaPliku }}
    Przepis do czytania.

dane.json
    Ten sam przepis w postaci, którą rozumie Kuking. Nie otwieraj go do
    czytania — służy do wczytania przepisu z powrotem.

CZEGO TU NIE MA
---------------

Zdjęć. Ta kopia zawiera sam tekst przepisu; zdjęcia zostają w Kuking.
Nie ma też komentarzy innych osób, danych Twojego konta ani innych
przepisów.

JAK WCZYTAĆ PRZEPIS Z POWROTEM
------------------------------

1. Zaloguj się w Kuking.pl.
2. Wejdź w Ustawienia, potem "Twoje dane", potem "Wczytaj swoją paczkę".
3. Wybierz ten plik ZIP i sprawdź podgląd.
4. Kliknij "Wczytaj zaznaczone".

Przepis wróci jako PRYWATNY szkic, do sprawdzenia. Nic nie zostanie
opublikowane samo. Wczytujemy tekst: nazwę, opis, składniki z grupami,
uwagami i zamiennikami oraz kroki z etapami. Jeśli ten sam przepis już
masz, podgląd to pokaże i nie wczyta go drugi raz.

Ten plik nie zawiera hasła ani niczego, co pozwalałoby wejść na Twoje konto.
