## D-254 — Adres źródła przepisu: tylko HTTP/HTTPS, dawny adres może zostać (#900, 20 września 2026)

**Data:** 20 września 2026 · Decyzja właściciela

Pole „Adres strony, z której jest przepis” przyjmuje **nowy lub zmieniony**
adres tylko z protokołem HTTP albo HTTPS (`url:http,https`) — w formularzu
jednostronicowym (`RecipeController`) i w kreatorze (`recipe-wizard`), z tym
samym komunikatem. Pomoc pola od zawsze mówiła o stronie internetowej; ogólna
reguła `url` przepuszczała też `ftp://` i `ssh://`.

**Niezmieniony dawny adres** z bazy (np. FTP zapisany przed tą zmianą) nie
blokuje edycji innych pól — nie zmuszamy autora do poprawiania go przy okazji
i nie migrujemy cudzych danych automatycznie. Kreator porównuje wartość z
**bazą**, nie ze stanem komponentu, więc autozapis nie zrobi z dopiero
wpisanego FTP „historycznego wyjątku”: taki adres jest odrzucany przed
zapisem szkicu.

Strona przepisu linkuje wyłącznie adresy HTTP/HTTPS; inne zachowane wartości
pokazuje zwykłym tekstem, bez `href`.

### Wycofanie
Powrót do ogólnego `url` w obu regułach i do bezwarunkowego linku w
`show.blade.php`. Danych nie trzeba cofać — zmiana niczego nie zapisuje.
