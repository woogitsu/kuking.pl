#!/usr/bin/env python3
"""Generator fixture'ów do `OryginalTraciGpsZXmpTest` (issue #1004).

Pliki zapisuje Pillow — NIEZALEŻNA od `UsunGps` biblioteka, więc kontener
(APP1 w JPEG, iTXt w PNG, chunk `XMP ` w WebP, element w AVIF) jest taki,
jaki robi prawdziwe narzędzie, a nie taki, jakiego spodziewa się nasz kod.

Współrzędne są SYNTETYCZNE (środek Morza Bałtyckiego), nie należą do nikogo.
Uruchomienie: python3 tests/Fixtures/xmp-gps/zrob-fixtury.py (Pillow >= 11).
"""
from pathlib import Path

from PIL import Image, PngImagePlugin

TU = Path(__file__).resolve().parent

XMP = b"""<?xpacket begin="\xef\xbb\xbf" id="W5M0MpCehiHzreSzNTczkc9d"?>
<x:xmpmeta xmlns:x="adobe:ns:meta/">
 <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
  <rdf:Description rdf:about=""
    xmlns:exif="http://ns.adobe.com/exif/1.0/"
    xmlns:Iptc4xmpExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/"
    exif:GPSLatitude="55,31.4159N"
    exif:GPSLongitude="17,27.1828E">
   <exif:GPSDestLatitude>55,31.4159N</exif:GPSDestLatitude>
   <Iptc4xmpExt:LocationCreated>
    <rdf:Bag><rdf:li rdf:parseType="Resource">
     <exif:GPSLongitude>17,27.1828E</exif:GPSLongitude>
    </rdf:li></rdf:Bag>
   </Iptc4xmpExt:LocationCreated>
  </rdf:Description>
 </rdf:RDF>
</x:xmpmeta>
<?xpacket end="w"?>"""


def obraz() -> Image.Image:
    im = Image.new("RGB", (64, 48), (200, 120, 40))
    for x in range(32):
        for y in range(48):
            im.putpixel((x, y), (30, 90, 160))
    return im


def main() -> None:
    im = obraz()
    im.save(TU / "xmp-gps.jpg", "JPEG", quality=90, xmp=XMP)

    info = PngImagePlugin.PngInfo()
    info.add_itxt("XML:com.adobe.xmp", XMP.decode("utf-8"), zip=False)
    im.save(TU / "xmp-gps.png", "PNG", pnginfo=info)

    info = PngImagePlugin.PngInfo()
    info.add_itxt("XML:com.adobe.xmp", XMP.decode("utf-8"), zip=True)
    im.save(TU / "xmp-gps-skompresowany.png", "PNG", pnginfo=info)

    im.save(TU / "xmp-gps.webp", "WEBP", quality=90, xmp=XMP)
    im.save(TU / "xmp-gps.avif", "AVIF", quality=90, xmp=XMP)


if __name__ == "__main__":
    main()
