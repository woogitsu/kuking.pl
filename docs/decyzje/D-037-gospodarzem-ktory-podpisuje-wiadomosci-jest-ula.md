## D-037 · Gospodarzem, który podpisuje wiadomości, jest Ula

**Data:** 8 września 2026 · **Decyzja właściciela** · Status: **obowiązuje**

`COPY_STYLE.md` §8 trzymał to jako otwarte od początku projektu: „Imię
gospodarza w e-mailach. Bez prawdziwego imienia digest traci większość swojej
wartości". Rozstrzygnięcie: **Ula**.

**To nie jest to samo pole, co `host_user_id`.** `host_user_id` to stabilny
UUID KONTA, który czytają mechanizmy auto-obserwowania, alertu pierwszego
wpisu i wykluczeń analitycznych. Nazwa profilu jest edytowalnym adresem i nie
może być tożsamością gospodarza: po przemianowaniu może przejąć ją inna osoba
(#1089). `host_name` to imię, którym serwis PODPISUJE się przed człowiekiem.

`host_username` zostaje wyłącznie jako zgodność przejściowa dla wdrożeń sprzed
#1089. Jest czytane tylko przy pustym `host_user_id`. Ustawiony, lecz błędny
UUID nie cofa się do nazwy — takie cofnięcie mogłoby oddać funkcję gospodarza
osobie, która przejęła dawny adres profilu.

Imię mieszka w jednym miejscu, `config('kuking.community.host_name')`, i stamtąd
składa się nazwa nadawcy poczty („Ula z Kuking"). Nie jest wpisane osobno
w żadnym szablonie — gospodarz może się zmienić i wtedy to ma być jedna
linijka, nie przeszukiwanie widoków.

**Uwaga wdrożeniowa:** `MAIL_FROM_NAME` ustawione w panelu Railway **wygrywa**
z tą konfiguracją. Jeśli tam stoi stara wartość, e-maile dalej będą podpisane
po staremu — trzeba ją usunąć albo zaktualizować ręcznie.

**Zmiana wymaga:** zmiany osoby, która prowadzi społeczność.

📄 `config/kuking.php` (`community.host_user_id`, `community.host_name`) · `config/mail.php` ·
`docs/brand/COPY_STYLE.md` §6 · `docs/product/RETENTION_LOOPS.md` §4
