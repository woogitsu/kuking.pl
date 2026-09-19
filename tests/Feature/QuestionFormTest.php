<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class QuestionFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_question_form_names_its_four_fields_as_specified(): void
    {
        config(['kuking.questions.enabled' => true]);
        $this->actingAs($this->user('pytajacy'))->get('/pytania/zadaj')->assertOk()
            ->assertSee('O co chcesz zapytać?')->assertSee('Napisz trochę więcej')
            ->assertSee('Dodaj zdjęcie, jeśli pomoże')->assertSee('Z czym to jest związane?');
    }

    public function test_tag_actions_work_before_title_is_written_without_publishing(): void
    {
        config(['kuking.questions.enabled' => true]);
        $this->actingAs($this->user('pytajacy'))->from('/pytania/zadaj')->post('/pytania', [
            'title' => '', 'body' => 'Szkic opisu', 'dodaj_tag' => 'zupa',
        ])->assertRedirect('/pytania/zadaj#f-tagi')->assertSessionHasNoErrors()
            ->assertSessionHasInput('tag_names', ['zupa'])->assertSessionHasInput('body', 'Szkic opisu');
        $html = $this->get('/pytania/zadaj')->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $xpath = new \DOMXPath($dom);
        $buttons = $xpath->query('//button[@name="usun_tag" or @name="szukaj_tagu" or @name="dodaj_tag"]');
        $this->assertGreaterThanOrEqual(2, $buttons->length);
        foreach ($buttons as $button) {
            $this->assertTrue($button->hasAttribute('formnovalidate'));
        }
        $this->assertDatabaseCount('posts', 0);
        $this->from('/pytania/zadaj')->post('/pytania', [
            'tag_names' => ['zupa', 'obiad', 'bulion'], 'dodaj_tag' => 'warzywa',
        ])->assertSessionHasErrors('tagi')->assertSessionHasInput('tag_names', ['zupa', 'obiad', 'bulion']);
        $this->assertDatabaseCount('posts', 0);
    }

    public function test_removing_preserved_photo_keeps_unfinished_text_and_allows_replacement(): void
    {
        config(['kuking.questions.enabled' => true]);
        $user = $this->user('pytajacy');
        $photo = Media::factory()->create(['owner_id' => $user->id]);
        $replacement = Media::factory()->create(['owner_id' => $user->id]);
        $this->actingAs($user)->post('/pytania', [
            'title' => 'Zupa?', 'body' => 'Niedokończony opis', 'tag_names' => ['zupa'],
            'media_ids' => [$photo->id], 'usun_zdjecie' => $photo->id,
        ])->assertRedirect(route('questions.create'))->assertSessionHasNoErrors()
            ->assertSessionHasInput('title', 'Zupa?')->assertSessionHasInput('body', 'Niedokończony opis')
            ->assertSessionHasInput('tag_names', ['zupa'])->assertSessionHasInput('media_ids', []);
        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseHas('media', ['id' => $photo->id]);
        $this->get('/pytania/zadaj')->assertOk()->assertSee('name="photos[]"', false);
        $this->post('/pytania', ['title' => 'Jak uratować przesoloną zupę?', 'media_ids' => [$replacement->id]])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([$replacement->id], Post::query()->sole()->media()->pluck('media.id')->all());
    }

    public function test_uploaded_photo_survives_invalid_title_without_a_second_upload(): void
    {
        Storage::fake('public');
        config(['kuking.questions.enabled' => true]);
        $user = $this->user('pytajacy');
        $this->actingAs($user)->from('/pytania/zadaj')->post('/pytania', [
            'title' => 'Zupa?', 'body' => 'Nie chcę tracić zdjęcia.',
            'photos' => [UploadedFile::fake()->image('zupa.jpg', 400, 300)],
        ])->assertSessionHasErrors('title');
        $photo = Media::query()->where('owner_id', $user->id)->sole();
        $this->assertSame([$photo->id], session()->getOldInput('media_ids'));
        $this->get('/pytania/zadaj')->assertOk()->assertSee('Zdjęcie jest zachowane.');
        $this->post('/pytania', ['title' => 'Jak uratować przesoloną zupę?', 'media_ids' => [$photo->id]])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([$photo->id], Post::query()->sole()->media()->pluck('media.id')->all());
        $this->assertDatabaseCount('media', 1);
    }

    public function test_suspended_account_cannot_publish_question(): void
    {
        config(['kuking.questions.enabled' => true]);
        $user = $this->user('pytajacy');
        $user->forceFill(['status' => 'suspended'])->save();
        $this->actingAs($user)->from('/pytania/zadaj')->post('/pytania', ['title' => 'Jak uratować przesoloną zupę?'])
            ->assertRedirect('/pytania/zadaj')->assertSessionHasErrors('konto')
            ->assertSessionHasInput('title', 'Jak uratować przesoloną zupę?');
        $this->assertDatabaseCount('posts', 0);
    }

    public function test_preserved_photo_survives_validation_and_is_attached_after_correction(): void
    {
        config(['kuking.questions.enabled' => true]);
        $user = $this->user('pytajacy');
        $photo = Media::factory()->create(['owner_id' => $user->id]);
        $foreign = Media::factory()->create();
        $this->actingAs($user)->from('/pytania/zadaj')->post('/pytania', [
            'title' => 'Zupa?', 'media_ids' => [$photo->id, $foreign->id],
        ])->assertSessionHasErrors('title')->assertSessionHasInput('media_ids', [$photo->id]);
        $this->get('/pytania/zadaj')->assertOk()->assertSee('Zdjęcie jest zachowane.');
        $this->post('/pytania', ['title' => 'Jak uratować przesoloną zupę?', 'media_ids' => [$photo->id]])->assertRedirect();
        $this->assertSame([$photo->id], Post::query()->sole()->media()->pluck('media.id')->all());
    }

    public function test_form_publishes_title_only_and_retry_does_not_duplicate(): void
    {
        config(['kuking.questions.enabled' => true]);
        $this->actingAs($this->user('pytajacy'));
        $this->get('/pytania/zadaj')->assertOk()->assertSee('Opublikuj pytanie');
        $data = ['title' => 'Jak uratować przesoloną zupę?', 'klucz_wyslania' => (string) Str::uuid()];
        $this->post('/pytania', $data)->assertRedirect();
        $post = Post::query()->sole();
        $this->assertSame(Post::KIND_QUESTION, $post->kind);
        $this->post('/pytania', $data)->assertRedirect(route('questions.show', $post));
        $this->assertDatabaseCount('posts', 1);
    }

    public function test_validation_preserves_title_body_and_tags(): void
    {
        config(['kuking.questions.enabled' => true]);
        $this->actingAs($this->user('pytajacy'));
        $this->from('/pytania/zadaj')->post('/pytania', [
            'title' => 'Zupa?', 'body' => 'Opis mojego problemu', 'tag_names' => ['zupa'],
        ])->assertRedirect('/pytania/zadaj')->assertSessionHasErrors('title')
            ->assertSessionHasInput('title', 'Zupa?')->assertSessionHasInput('body', 'Opis mojego problemu')
            ->assertSessionHasInput('tag_names', ['zupa']);
        $this->get('/pytania/zadaj')->assertOk()->assertSee('Opis mojego problemu')->assertSee('Zupa?');
        $this->assertDatabaseCount('posts', 0);
    }

    public function test_guest_and_disabled_feature_cannot_publish(): void
    {
        config(['kuking.questions.enabled' => true]);
        $this->post('/pytania', ['title' => 'Jak uratować przesoloną zupę?'])->assertRedirect(route('login'));
        $this->actingAs($this->user('pytajacy'));
        config(['kuking.questions.enabled' => false]);
        $this->get('/pytania/zadaj')->assertNotFound();
        $this->post('/pytania', ['title' => 'Jak uratować przesoloną zupę?'])->assertNotFound();
        $this->assertDatabaseCount('posts', 0);
    }
}
