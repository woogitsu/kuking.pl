<x-layout :title="$event->user->displayName().' ugotował: '.$event->recipe->title" :noindex="true">
    <x-cooked-card :event="$event" :showRecipe="true" />

    @if(auth()->id() === $event->user_id)
        <div class="danger-zone">
            <x-confirm-button
                :action="route('cooked.destroy', $event)"
                label="Usuń to wykonanie"
                question="Na pewno usunąć? Zniknie także zdjęcie." />
        </div>
    @endif

    <x-comment-thread :comments="$event->comments" :action="route('cooked.comment', $event)" />
</x-layout>
