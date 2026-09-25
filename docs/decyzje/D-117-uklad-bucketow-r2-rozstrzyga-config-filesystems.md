## D-117 · Układ bucketów R2 rozstrzyga `config/filesystems.php`, nie dokument

**Data:** 11 września 2026 · Status: **obowiązuje**

Trzy dokumenty opisywały trzy różne układy: runbook jeden bucket `kuking-media`,
`railway.ts` trzy, `BRAMKA_R2.md` dwa pod innymi nazwami. Nie dało się wykonać
żadnego z nich do końca bez zgadywania.

**Rozstrzyga kod.** Czyta cztery osobne buckety: `AWS_BUCKET` (oryginały z EXIF),
`AWS_PUBLIC_BUCKET` (warianty), `AWS_EXPORTS_BUCKET` (eksporty RODO) i
`AWS_KOPIE_BUCKET` (kopie bazy, z **osobnym poświadczeniem tylko do odczytu**),
plus `AWS_LEGACY_BUCKET` na stan sprzed rozdziału.

**Pułapka warta nazwania: brak `AWS_PUBLIC_BUCKET` CICHO cofa konfigurację do
jednego bucketu** — `config/filesystems.php` ma tam zapas na `AWS_BUCKET`. Wtedy
warianty lądują tam, gdzie oryginały ze współrzędnymi kuchni.

Dokumenty podają **mapowanie zmienna → dysk → zawartość**. Nazwy bucketów są
wartościami i same w sobie niczego nie dowodzą.
