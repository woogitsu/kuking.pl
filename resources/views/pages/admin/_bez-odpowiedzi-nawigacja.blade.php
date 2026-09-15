<nav class="tabs" aria-label="Rodzaj treści bez odpowiedzi">
    @foreach(['wpisy' => 'Wpisy', 'przepisy' => 'Przepisy', 'ugotowane' => 'Ugotowałem'] as $key => $label)
        <a class="tab"
           href="{{ route('admin.unanswered', ['typ' => $key]) }}"
           @if($type === $key) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
