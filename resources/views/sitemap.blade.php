{{-- Deklaracja XML NIE MOŻE tu stać dosłownie.

     Blade kompiluje ten plik do PHP-a i przepisuje `<?xml` w niezmienionej
     postaci. Przy `short_open_tag = On` PHP czyta `<?` jako otwarcie bloku
     kodu, a `xml version="1.0"` jako instrukcje — i widok wybucha.

     Lokalnie tego nie widać, bo `short_open_tag` jest domyślnie wyłączone.
     Obraz produkcyjny ma je włączone, więc `/sitemap.xml` oddawał na żywo
     HTTP 500, podczas gdy ten sam kod na maszynie deweloperskiej działał.
     Google dostawał „Server Error" zamiast mapy strony i nikt tego nie
     zauważył, bo mapy nie odwiedzają ludzie.

     Wewnątrz łańcucha znaków `?>` jest zwykłym tekstem, więc ta postać
     jest odporna na ustawienie w obie strony. --}}
{!! '<'.'?xml version="1.0" encoding="UTF-8"?'.'>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach($urls as $url)
    <url>
        <loc>{{ $url['loc'] }}</loc>
@if(! empty($url['lastmod']))
        <lastmod>{{ $url['lastmod'] }}</lastmod>
@endif
@if(! empty($url['changefreq']))
        <changefreq>{{ $url['changefreq'] }}</changefreq>
@endif
@if(! empty($url['priority']))
        <priority>{{ $url['priority'] }}</priority>
@endif
    </url>
@endforeach
</urlset>
