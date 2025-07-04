<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int $id
 * @property int $user_id
 * @property int $amount
 * @property string $type
 * @property string|null $description
 * @property string $status
 * @property string|null $transaction_id
 * @property string|null $reference_type
 * @property string|null $reference_id
 * @property int|null $balance_before
 * @property int|null $balance_after
 * @property array|null $metadata
 * @property \Carbon\Carbon|null $processed_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property User $user
 */
class Transaction extends Model
{
    use HasFactory;

    /**
     * The resource name for this model when it is transformed into an
     * API representation using fractal.
     */
    public const RESOURCE_NAME = 'transaction';

    /**
     * The table associated with the model.
     */
    protected $table = 'transactions_credits';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'amount',
        'type',
        'description',
        'status',
        'transaction_id',
        'reference_type',
        'reference_id',
        'balance_before',
        'balance_after',
        'metadata',
        'processed_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'amount' => 'integer',
        'balance_before' => 'integer',
        'balance_after' => 'integer',
        'metadata' => 'array',
        'processed_at' => 'datetime',
    ];

    /**
     * Transaction types
     */
    public const TYPE_CREDIT = 'credit';
    public const TYPE_DEBIT = 'debit';

    /**
     * Transaction statuses
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * Reference types
     */
    public const REFERENCE_MANUAL = 'manual';
    public const REFERENCE_PURCHASE = 'purchase';
    public const REFERENCE_REFUND = 'refund';
    public const REFERENCE_SYSTEM = 'system';

    /**
     * Validation rules
     */
    public static array $validationRules = [
        'user_id' => 'required|exists:users,id',
        'amount' => 'required|integer|min:1',
        'type' => 'required|in:credit,debit',
        'description' => 'nullable|string|max:255',
        'status' => 'in:pending,completed,failed',
        'transaction_id' => 'nullable|string|unique:transactions_credits,transaction_id',
        'reference_type' => 'nullable|string|max:50',
        'reference_id' => 'nullable|string|max:100',
        'metadata' => 'nullable|array',
    ];

    /**
     * Get the user that owns the transaction.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if transaction is a credit.
     */
    public function isCredit(): bool
    {
        return $this->type === self::TYPE_CREDIT;
    }

    /**
     * Check if transaction is a debit.
     */
    public function isDebit(): bool
    {
        return $this->type === self::TYPE_DEBIT;
    }

    /**
     * Check if transaction is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if transaction is pending.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if transaction is failed.
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Get the signed amount (negative for debits, positive for credits).
     */
    public function getSignedAmountAttribute(): int
    {
        return $this->isDebit() ? -$this->amount : $this->amount;
    }

    /**
     * Scope to get only credit transactions.
     */
    public function scopeCredits($query)
    {
        return $query->where('type', self::TYPE_CREDIT);
    }

    /**
     * Scope to get only debit transactions.
     */
    public function scopeDebits($query)
    {
        return $query->where('type', self::TYPE_DEBIT);
    }

    /**
     * Scope to get only completed transactions.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope to get only pending transactions.
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope to get transactions for a specific user.
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get transactions by reference type.
     */
    public function scopeByReferenceType($query, $referenceType)
    {
        return $query->where('reference_type', $referenceType);
    }
} 