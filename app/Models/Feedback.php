<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message sent from the Settings page's Feedback card.
 *
 * A problem goes to the developers by email as it arrives, because a broken
 * system is never the administrator's call to leave as it is. An idea, a
 * question or anything else waits as "new" until the administrator answers
 * it, turns it down, or sends it to the developers.
 */
class Feedback extends Model
{
    public const TYPE_PROBLEM = 'problem';
    public const TYPE_IDEA = 'idea';
    public const TYPE_QUESTION = 'question';
    public const TYPE_OTHER = 'other';

    public const TYPES = [self::TYPE_PROBLEM, self::TYPE_IDEA, self::TYPE_QUESTION, self::TYPE_OTHER];

    public const STATUS_NEW = 'new';
    // Sent to the developers: an idea they are to build, a problem to fix.
    public const STATUS_PLANNED = 'planned';
    public const STATUS_DONE = 'done';
    // Won't do, or for a problem, not a problem after all.
    public const STATUS_DECLINED = 'declined';
    public const STATUS_ANSWERED = 'answered';

    public const STATUSES = [self::STATUS_NEW, self::STATUS_PLANNED, self::STATUS_DONE, self::STATUS_DECLINED, self::STATUS_ANSWERED];

    protected $table = 'feedback';

    protected $fillable = [
        'user_id',
        'type',
        'rating',
        'message',
        'may_contact',
        'context',
        'status',
        'admin_reply',
        'developer_note',
        'developer_emailed_at',
        'developer_email_error',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'rating' => 'integer',
        'may_contact' => 'boolean',
        'context' => 'array',
        'developer_emailed_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_PROBLEM => 'Problem',
            self::TYPE_IDEA => 'Idea',
            self::TYPE_QUESTION => 'Question',
            default => 'Other',
        };
    }
}
