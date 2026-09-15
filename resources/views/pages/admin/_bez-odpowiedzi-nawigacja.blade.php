<nav class="flex flex-wrap gap-2 mb-6" aria-label="Rodzaj treści bez odpowiedzi">
    @foreach(['wpisy' => 'Wpisy', 'przepisy' => 'Przepisy', 'ugotowane' => 'Ugotowałem'] as $key => $label)
        <a class="btn {{ $type === $key ? 'btn-primary' : 'btn-secondary' }}"
           href="{{ route('admin.unanswered', ['typ' => $key]) }}"
           @if($type === $key) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
