<?php

namespace Pterodactyl\Services\Credits;

use Pterodactyl\Models\User;
use Pterodactyl\Models\Transaction;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Exceptions\DisplayException;

class CreditTransactionService
{
    public function __construct(private ConnectionInterface $connection)
    {
    }

    /**
     * Add credits to a user account with transaction logging.
     *
     * @throws \Throwable
     */
    public function addCredits(
        User $user,
        int $amount,
        string $description = null,
        string $referenceType = Transaction::REFERENCE_MANUAL,
        string $referenceId = null,
        array $metadata = []
    ): Transaction {
        if ($amount < 1) {
            throw new DisplayException('Credit amount must be at least 1.');
        }

        return $this->connection->transaction(function () use ($user, $amount, $description, $referenceType, $referenceId, $metadata) {
            // Lock the user row to prevent race conditions
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            
            $balanceBefore = $user->credits ?? 0;
            $balanceAfter = $balanceBefore + $amount;

            // Create the transaction record
            $transaction = Transaction::create([
                'user_id' => $user->id,
                'amount' => $amount,
                'type' => Transaction::TYPE_CREDIT,
                'description' => $description,
                'status' => Transaction::STATUS_COMPLETED,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'metadata' => $metadata,
                'processed_at' => now(),
            ]);

            // Update user balance
            $user->update(['credits' => $balanceAfter]);

            return $transaction;
        });
    }

    /**
     * Deduct credits from a user account with transaction logging.
     *
     * @throws \Throwable
     */
    public function deductCredits(
        User $user,
        int $amount,
        string $description = null,
        string $referenceType = Transaction::REFERENCE_MANUAL,
        string $referenceId = null,
        array $metadata = [],
        bool $allowNegative = false
    ): Transaction {
        if ($amount < 1) {
            throw new DisplayException('Debit amount must be at least 1.');
        }

        return $this->connection->transaction(function () use ($user, $amount, $description, $referenceType, $referenceId, $metadata, $allowNegative) {
            // Lock the user row to prevent race conditions
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            
            $balanceBefore = $user->credits ?? 0;
            $balanceAfter = $balanceBefore - $amount;

            // Check if user has sufficient credits
            if (!$allowNegative && $balanceAfter < 0) {
                throw new DisplayException('Insufficient credits. User has ' . number_format($balanceBefore) . ' credits, but ' . number_format($amount) . ' is required.');
            }

            // Create the transaction record
            $transaction = Transaction::create([
                'user_id' => $user->id,
                'amount' => $amount,
                'type' => Transaction::TYPE_DEBIT,
                'description' => $description,
                'status' => Transaction::STATUS_COMPLETED,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'metadata' => $metadata,
                'processed_at' => now(),
            ]);

            // Update user balance
            $user->update(['credits' => $balanceAfter]);

            return $transaction;
        });
    }

    /**
     * Create a pending transaction.
     */
    public function createPendingTransaction(
        User $user,
        int $amount,
        string $type,
        string $description = null,
        string $referenceType = Transaction::REFERENCE_MANUAL,
        string $referenceId = null,
        array $metadata = []
    ): Transaction {
        if ($amount < 1) {
            throw new DisplayException('Transaction amount must be at least 1.');
        }

        if (!in_array($type, [Transaction::TYPE_CREDIT, Transaction::TYPE_DEBIT])) {
            throw new DisplayException('Invalid transaction type.');
        }

        return Transaction::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'type' => $type,
            'description' => $description,
            'status' => Transaction::STATUS_PENDING,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Complete a pending transaction.
     *
     * @throws \Throwable
     */
    public function completePendingTransaction(Transaction $transaction): Transaction
    {
        if (!$transaction->isPending()) {
            throw new DisplayException('Transaction is not in pending status.');
        }

        return $this->connection->transaction(function () use ($transaction) {
            // Lock the user row to prevent race conditions
            $user = User::query()->lockForUpdate()->findOrFail($transaction->user_id);
            
            $balanceBefore = $user->credits ?? 0;

            if ($transaction->isCredit()) {
                $balanceAfter = $balanceBefore + $transaction->amount;
            } else {
                $balanceAfter = $balanceBefore - $transaction->amount;
                
                // Check if user has sufficient credits for debit
                if ($balanceAfter < 0) {
                    $transaction->update(['status' => Transaction::STATUS_FAILED]);
                    throw new DisplayException('Insufficient credits to complete transaction.');
                }
            }

            // Update transaction
            $transaction->update([
                'status' => Transaction::STATUS_COMPLETED,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'processed_at' => now(),
            ]);

            // Update user balance
            $user->update(['credits' => $balanceAfter]);

            return $transaction->fresh();
        });
    }

    /**
     * Fail a pending transaction.
     */
    public function failPendingTransaction(Transaction $transaction, string $reason = null): Transaction
    {
        if (!$transaction->isPending()) {
            throw new DisplayException('Transaction is not in pending status.');
        }

        $metadata = $transaction->metadata ?? [];
        if ($reason) {
            $metadata['failure_reason'] = $reason;
        }

        $transaction->update([
            'status' => Transaction::STATUS_FAILED,
            'metadata' => $metadata,
            'processed_at' => now(),
        ]);

        return $transaction;
    }

    /**
     * Transfer credits between users.
     *
     * @throws \Throwable
     */
    public function transferCredits(
        User $fromUser,
        User $toUser,
        int $amount,
        string $description = null,
        array $metadata = []
    ): array {
        if ($amount < 1) {
            throw new DisplayException('Transfer amount must be at least 1.');
        }

        if ($fromUser->id === $toUser->id) {
            throw new DisplayException('Cannot transfer credits to the same user.');
        }

        return $this->connection->transaction(function () use ($fromUser, $toUser, $amount, $description, $metadata) {
            $transferId = 'transfer_' . uniqid();

            // Deduct from source user
            $debitTransaction = $this->deductCredits(
                $fromUser,
                $amount,
                $description ?: "Credit transfer to {$toUser->username}",
                Transaction::REFERENCE_SYSTEM,
                $transferId,
                array_merge($metadata, [
                    'transfer_type' => 'outgoing',
                    'transfer_to_user_id' => $toUser->id,
                    'transfer_to_username' => $toUser->username,
                ])
            );

            // Add to destination user
            $creditTransaction = $this->addCredits(
                $toUser,
                $amount,
                $description ?: "Credit transfer from {$fromUser->username}",
                Transaction::REFERENCE_SYSTEM,
                $transferId,
                array_merge($metadata, [
                    'transfer_type' => 'incoming',
                    'transfer_from_user_id' => $fromUser->id,
                    'transfer_from_username' => $fromUser->username,
                ])
            );

            return [
                'debit_transaction' => $debitTransaction,
                'credit_transaction' => $creditTransaction,
            ];
        });
    }

    /**
     * Get user's transaction history with pagination.
     */
    public function getTransactionHistory(User $user, int $perPage = 15, array $filters = [])
    {
        $query = $user->transactions()->orderBy('created_at', 'desc');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['reference_type'])) {
            $query->where('reference_type', $filters['reference_type']);
        }

        if (isset($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query->paginate($perPage);
    }
} 