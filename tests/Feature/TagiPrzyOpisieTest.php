<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Tag;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TagiPrzyOpisieTest extends TestCase
{
    use RefreshDatabase;

    public static function forms(): array
    {
        return [
            'nowy wpis' => [false, false, 5],
            'edycja wpisu' => [true, false, 5],
            'nowe pytanie' => [false, true, 3],
            'edycja pytania' => [true, true, 3],
        ];
    }

    #[DataProvider('forms')]
    public function test_reczne_tagi_sa_przy_opisie_i_nadal_maja_droge_bez_skryptu(bool $edit, bool $question, int $limit): void
    {
        config(['kuking.questions.enabled' => true]);
        $user = $this->user();
        $this->actingAs($user);
        $name = 'zupa pomidorowa';
        if ($edit) {
            $factory = Post::factory();
            $post = ($question ? $factory->question() : $factory)->create(['author_id' => $user->id]);
            $post->tags()->attach(Tag::factory()->create(['name' => $name]), ['dodany_recznie' => true]);
            $url = route('posts.edit', $post);
        } else {
            $url = route($question ? 'questions.create' : 'posts.create');
            $this->withSession(['_old_input' => ['tag_names' => [$name], 'body' => 'Mój opis #obiad']]);
        }

        $html = $this->get($url)->assertOk()->getContent();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $xpath = new DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//main//textarea[@name="body"]')->length);
        $this->assertSame(1, $xpath->query('//main//*[@id="f-tagi"]')->length);
        $section = $xpath->query('//main//*[@data-tagi-opis]//*[@id="f-tagi"]');
        $this->assertSame(1, $section->length, 'Lista tagów ma być przy opisie, przed kolejnym polem formularza.');
        $this->assertStringContainsString('(maksymalnie '.$limit.')', $section->item(0)->textContent);
        $this->assertSame(1, $xpath->query('.//input[@name="tag_names[]" and @value="'.$name.'"]', $section->item(0))->length);
        $remove = $xpath->query('.//button[@name="usun_tag" and @value="'.$name.'"]', $section->item(0));
        $this->assertSame(1, $remove->length);
        $this->assertSame('submit', self::elementDom($remove->item(0))->getAttribute('type'));
        $this->assertTrue(self::elementDom($remove->item(0))->hasAttribute('formnovalidate'));
        $this->assertSame(0, $xpath->query('.//details[@open]', $section->item(0))->length);
        $this->assertSame(1, $xpath->query('.//details//button[@name="szukaj_tagu"]', $section->item(0))->length);
    }
}
