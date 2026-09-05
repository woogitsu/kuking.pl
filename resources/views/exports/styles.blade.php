{{--
    Styl paczki z danymi. Wszystko w jednym <style> w każdym pliku, świadomie.

    Powód: te pliki mają się otwierać z pendrive'a, bez internetu i bez Kuking,
    także za dziesięć lat. Osobny plik CSS jest jedną rzeczą więcej, którą można
    zgubić przy kopiowaniu — a wtedy strona wygląda jak surowy tekst.

    Rozmiary są wzięte ze standardu UX 50+ (docs/UX_50_PLUS.md): tekst
    podstawowy 20 px, duże odstępy, jedna kolumna, wysoki kontrast.
--}}
<style>
    :root {
        --tlo: #FAF6F0;
        --karta: #FFFFFF;
        --tekst: #2B241D;
        --tekst-jasny: #5C5347;
        --ramka: #E4DACB;
        --marka: #B3401F;
    }

    * { box-sizing: border-box; }

    body {
        margin: 0;
        padding: 24px 16px 64px;
        background: var(--tlo);
        color: var(--tekst);
        font-family: Georgia, "Times New Roman", serif;
        font-size: 20px;
        line-height: 1.65;
    }

    .strona {
        max-width: 44rem;
        margin: 0 auto;
    }

    h1 {
        font-size: 34px;
        line-height: 1.25;
        margin: 0 0 8px;
    }

    h2 {
        font-size: 26px;
        line-height: 1.3;
        margin: 40px 0 12px;
        padding-bottom: 8px;
        border-bottom: 2px solid var(--ramka);
    }

    h3 {
        font-size: 22px;
        margin: 28px 0 8px;
    }

    p { margin: 0 0 16px; }

    a {
        color: var(--marka);
        text-decoration: underline;
    }

    .naglowek {
        border-bottom: 4px solid var(--marka);
        padding-bottom: 20px;
        margin-bottom: 28px;
    }

    .podpis {
        color: var(--tekst-jasny);
        font-size: 17px;
        margin: 0;
    }

    .karta {
        background: var(--karta);
        border: 1px solid var(--ramka);
        border-radius: 10px;
        padding: 20px 24px;
        margin: 0 0 20px;
    }

    ul, ol { padding-left: 28px; margin: 0 0 16px; }
    li { margin-bottom: 10px; }

    .spis li { margin-bottom: 14px; font-size: 21px; }

    .skladniki { list-style: none; padding-left: 0; }
    .skladniki li {
        padding: 10px 0;
        border-bottom: 1px solid var(--ramka);
    }
    .skladniki .ile { font-weight: bold; }

    .kroki li { margin-bottom: 22px; }

    img.zdjecie {
        display: block;
        max-width: 100%;
        height: auto;
        border-radius: 10px;
        border: 1px solid var(--ramka);
        margin: 0 0 12px;
    }

    .fakty { color: var(--tekst-jasny); font-size: 18px; margin: 0 0 20px; }
    .fakty span { margin-right: 18px; white-space: nowrap; }

    .plakietka {
        display: inline-block;
        background: #FCEACB;
        color: #6B4E0C;
        border: 1px solid #7A5C10;
        border-radius: 6px;
        padding: 2px 10px;
        font-size: 16px;
        font-family: Arial, Helvetica, sans-serif;
    }

    .stopka {
        margin-top: 48px;
        padding-top: 20px;
        border-top: 2px solid var(--ramka);
        color: var(--tekst-jasny);
        font-size: 17px;
    }

    .powrot { font-size: 20px; }

    @media print {
        body { background: #FFFFFF; font-size: 12pt; }
        .karta { border: none; padding: 0; }
    }
</style>
