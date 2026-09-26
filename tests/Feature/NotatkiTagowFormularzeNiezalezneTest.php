<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\TagPromotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotatkiTagowFormularzeNiezalezneTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_note_stays_in_submitted_row(): void
    {
        $this->checkInvalidRow(1);
    }

    public function test_invalid_first_note_stays_in_submitted_row(): void
    {
        $this->checkInvalidRow(0);
    }

    private function checkInvalidRow(int $submitted): void
    {
        $first = Tag::create(['slug' => 'probe-first', 'name' => 'First', 'normalized_name' => 'first']);
        $second = Tag::create(['slug' => 'probe-second', 'name' => 'Second', 'normalized_name' => 'second']);
        TagPromotion::create(['tag_id' => $first->id, 'position' => 0, 'note' => 'FIRST ORIGINAL']);
        TagPromotion::create(['tag_id' => $second->id, 'position' => 1, 'note' => 'SECOND ORIGINAL']);
        $tags = [$first, $second];
        $original = ['FIRST ORIGINAL', 'SECOND ORIGINAL'];
        $target = $tags[$submitted];
        $invalid = str_repeat('X', 201);
        $this->actingAs($this->moderator());
        $initial = $this->get(route('admin.tag-promotions'))->assertOk()->getContent();
        $initialDom = new \DOMDocument;
        @$initialDom->loadHTML($initial);
        $initialXpath = new \DOMXPath($initialDom);
        $rowValues = [];
        foreach ($tags as $tag) {
            $row = $initialXpath->query('//form[@action="'.route('admin.tag-promotions.update', $tag).'"][input[@name="_method" and @value="PUT"]]/input[@name="_wiersz"]');
            $this->assertCount(1, $row);
            $this->assertSame((string) $tag->id, self::elementDom($row->item(0))->getAttribute('value'));
            $rowValues[] = self::elementDom($row->item(0))->getAttribute('value');
        }
        $this->from(route('admin.tag-promotions'))
            ->put(route('admin.tag-promotions.update', $target), ['note' => $invalid, '_wiersz' => $rowValues[$submitted]])
            ->assertRedirect(route('admin.tag-promotions'));
        $html = $this->get(route('admin.tag-promotions'))->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $fields = $xpath->query('//input[@name="note"]');
        $this->assertCount(2, $fields);
        foreach ([0, 1] as $index) {
            $this->assertSame($index === $submitted ? $invalid : $original[$index], self::elementDom($fields->item($index))->getAttribute('value'));
            $id = self::elementDom($fields->item($index))->getAttribute('id');
            $this->assertSame('f-note-'.$tags[$index]->id, $id);
            $this->assertCount(1, $xpath->query('//label[@for="'.$id.'"]'));
            $this->assertSame($index === $submitted ? 'true' : '', self::elementDom($fields->item($index))->getAttribute('aria-invalid'));
        }
        $this->assertNotSame(self::elementDom($fields->item(0))->getAttribute('id'), self::elementDom($fields->item(1))->getAttribute('id'));
        $this->assertCount(1, $xpath->query('//a[@href="#f-note-'.$target->id.'"]'));
        $this->assertCount(1, $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," field-error ")]'));
        $error = self::elementDom($xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," field-error ")]')->item(0));
        $errorId = $error->getAttribute('id');
        $this->assertNotSame('', $errorId);
        $this->assertContains($errorId, explode(' ', self::elementDom($fields->item($submitted))->getAttribute('aria-describedby')));
        $this->assertNotContains($errorId, explode(' ', self::elementDom($fields->item(1 - $submitted))->getAttribute('aria-describedby')));
        $this->assertTrue($xpath->query('ancestor::form', $error)->item(0)->isSameNode($xpath->query('ancestor::form', $fields->item($submitted))->item(0)));
        $this->assertSame('FIRST ORIGINAL', $first->fresh()->promotion->note);
        $this->assertSame('SECOND ORIGINAL', $second->fresh()->promotion->note);
    }
}
