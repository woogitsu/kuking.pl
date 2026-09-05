<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Models\AuditLogEntry;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Zgłoszenie treści.
 *
 * Wymóg DSA (art. 16): mechanizm zgłaszania musi być łatwo dostępny i
 * przyjazny. U nas to znaczy: wyraźny przycisk z napisem "Zgłoś", nie ikonka
 * flagi, oraz powody napisane po polsku, a nie w żargonie prawniczym.
 *
 * To samo zgłoszenie od tej samej osoby nie tworzy duplikatów — zgłaszający
 * dostaje potwierdzenie, a kolejka moderacji nie puchnie od podwójnych kliknięć.
 */
final class ReportContent
{
    private const TARGET_TYPES = [
        User::class => 'user',
        Post::class => 'post',
        Recipe::class => 'recipe',
        Comment::class => 'comment',
        CookedEvent::class => 'cooked_event',
    ];

    public function handle(
        ?User $reporter,
        Model $target,
        string $reason,
        ?string $details = null,
        ?string $ip = null,
    ): Report {
        $targetType = self::TARGET_TYPES[$target::class] ?? null;

        if ($targetType === null) {
            throw new RuntimeException('Tej treści nie można zgłosić.');
        }

        if (! array_key_exists($reason, Report::REASONS)) {
            throw new RuntimeException('Wybierz powód zgłoszenia.');
        }

        $existing = Report::query()
            ->where('target_type', $targetType)
            ->where('target_id', $target->getKey())
            ->when($reporter !== null, fn ($query) => $query->where('reporter_id', $reporter->getKey()))
            ->whereIn('status', [Report::STATUS_OPEN, Report::STATUS_TRIAGE, Report::STATUS_REVIEWING])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $report = Report::create([
            'reporter_id' => $reporter?->getKey(),
            'target_type' => $targetType,
            'target_id' => $target->getKey(),
            'reason' => $reason,
            'details' => $details,
            'status' => Report::STATUS_OPEN,
        ]);

        AuditLogEntry::record(
            action: 'content.reported',
            actor: $reporter,
            subject: $report,
            metadata: ['target_type' => $targetType, 'reason' => $reason],
            ip: $ip,
        );

        return $report;
    }
}
