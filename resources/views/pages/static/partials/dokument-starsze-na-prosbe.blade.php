{{--
    Wersje sprzed początku archiwum wydajemy na prośbę (#2220; decyzja
    właściciela z 30.09.2026, D-333 — archiwum polityki tylko z czystą
    historią, od 25 września 2026). Kanał to ten, który polityka już podaje
    w „W sprawach dotyczących Twoich danych osobowych napisz do nas” —
    adres spółki i adres serwisu, oba z konfiguracji. Nowego kanału nie ma.
--}}
@php($spolka = (string) config('kuking.podmiot.email'))
@php($serwis = (string) config('kuking.community.contact_email'))
<div class="notice">
    <p>
        Wersje {{ $archiwum->dopelniacz }} sprzed {{ \App\Domain\Zgody\ArchiwumDokumentu::dataSlownie($archiwum->najstarsza()) }}
        nie mają tu pliku. Wcześniejsze brzmienie wydajemy na prośbę: napisz do nas na
        <a href="mailto:{{ $spolka }}">{{ $spolka }}</a> albo <a href="mailto:{{ $serwis }}">{{ $serwis }}</a>
        i podaj datę wersji, o którą Ci chodzi.
    </p>
</div>
