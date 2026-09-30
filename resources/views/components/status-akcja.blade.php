{{--
    Przycisk `status_akcja` z sesji: jedna akcja pod komunikatem („Zobacz swój
    wpis”, „Cofnij usunięcie konta”, „Odwołaj się”). Odnośnik, nie formularz:
    to samo OGLĄDANIE, a nie zmiana stanu. Domyślnie stoi w układzie nad
    treścią strony (`components/layout`); strona z podsumowaniem błędów
    (logowanie) ustawia `akcja-pod-bledami` i wstawia ten komponent sama,
    bezpośrednio pod podsumowaniem.
--}}
@php $statusAkcja = session('status_akcja'); @endphp
@if(is_array($statusAkcja) && isset($statusAkcja['url'], $statusAkcja['etykieta']))
    <p class="flash-akcja">
        <a class="btn btn-primary" href="{{ $statusAkcja['url'] }}">{{ $statusAkcja['etykieta'] }}</a>
    </p>
@endif
