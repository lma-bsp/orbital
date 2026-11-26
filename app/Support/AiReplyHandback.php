<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ConversationActivity;
use App\Models\EmailThread;
use App\Models\MessageThread;
use Illuminate\Support\Facades\Log;

/**
 * Hands an email or text thread back to the human queue when the AI
 * can't reply to it.
 *
 * The thread itself is left alone: it is already in the operators'
 * inbox (AI-assigned threads are not hidden there), so "handing back"
 * means telling the operator why no AI reply is coming. Without the
 * note, the customer waits on a reply that will never be sent and
 * nobody can see why.
 */
final class AiReplyHandback
{
    public const NO_ANTHROPIC_KEY = 'AI reply skipped: no Anthropic key configured';

    public const KIND_SKIPPED = 'ai_reply_skipped';

    public const KIND_FAILED = 'ai_reply_failed';

    /** Whether the AI reply jobs can call the model at all. */
    public static function anthropicConfigured(): bool
    {
        return trim((string) config('services.anthropic.api_key')) !== '';
    }

    /**
     * Record the handback on the thread.
     *
     * A skip is logged and noted once per thread: a customer sending
     * five emails into a thread with no key configured gives the
     * operator one explanation, not five. A failure after retries is
     * noted every time, since each one is a reply that didn't go out.
     */
    public static function toHuman(EmailThread|MessageThread $thread, string $note, string $kind = self::KIND_SKIPPED, ?string $error = null): void
    {
        $already = $kind === self::KIND_SKIPPED && ConversationActivity::query()
            ->where('subject_type', $thread->getMorphClass())
            ->where('subject_id', $thread->getKey())
            ->where('action', 'note')
            ->where('metadata->kind', $kind)
            ->exists();

        if ($already) {
            return;
        }

        Log::warning('AI reply handed back to a human', [
            'thread_type' => $thread->getMorphClass(),
            'thread_id' => $thread->getKey(),
            'kind' => $kind,
            'error' => $error,
        ]);

        ConversationActivity::log($thread, 'note', array_filter([
            'note' => $note,
            'kind' => $kind,
            'error' => $error,
        ], fn ($v): bool => $v !== null));
    }
}
