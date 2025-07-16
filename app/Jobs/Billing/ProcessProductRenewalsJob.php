<?php

namespace Pterodactyl\Jobs\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Collection;
use Pterodactyl\Jobs\Job;
use Pterodactyl\Models\UserProductPurchase;
use Pterodactyl\Models\Transaction;
use Pterodactyl\Services\Credits\CreditTransactionService;
use Pterodactyl\Services\Servers\SuspensionService;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;

class ProcessProductRenewalsJob extends Job implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public const MAX_FULL_CYCLE_ITERATIONS = 1000; // safety cap to avoid runaway billing loops
    private const GRACE_PERIOD_MINUTES = 15;
    private const CACHE_TTL_HOURS = 24; // Cache unspent credits for 24 hours
    private const UNSPENT_CREDITS_CACHE_PREFIX = 'server_unspent_credits:';
    
    private const BILLING_CYCLE_SECONDS = [
        'hourly' => 3600,
        'daily' => 86400,
        'weekly' => 604800,
        'monthly' => 2592000,
        'yearly' => 31536000,
    ];

    public function __construct()
    {
        $this->queue = 'high';
    }

    public function handle(
        CreditTransactionService $creditService,
        SuspensionService $suspensionService,
        DaemonServerRepository $daemonServerRepository
    ): void {
        $now = CarbonImmutable::now();
        Log::info('ProcessProductRenewalsJob started at ' . $now->toDateTimeString());

        $processedCount = 0;
        $errorCount = 0;

        UserProductPurchase::query()
            ->with(['user', 'product', 'server'])
            ->whereIn('status', ['active', 'creating', 'suspended'])
            ->chunkById(100, function (Collection $purchases) use ($creditService, $suspensionService, $now, $daemonServerRepository, &$processedCount, &$errorCount) {
                Log::info('Processing chunk of ' . count($purchases) . ' purchases...');

                foreach ($purchases as $purchase) {
                    try {
                        DB::transaction(function () use ($purchase, $creditService, $suspensionService, $daemonServerRepository, $now) {
                            $this->processPurchase($purchase, $creditService, $suspensionService, $daemonServerRepository, $now);
                        });
                        $processedCount++;
                    } catch (\Throwable $ex) {
                        $errorCount++;
                        Log::error("Failed to process purchase #{$purchase->id}: {$ex->getMessage()}", [
                            'purchase_id' => $purchase->id,
                            'user_id' => $purchase->user_id,
                            'server_id' => $purchase->server_id,
                            'exception' => $ex,
                        ]);
                        // Continue processing other purchases
                    }
                }
            });

        $this->enforceGraceDeadlines($suspensionService, $now);
        
        Log::info("ProcessProductRenewalsJob completed. Processed: {$processedCount}, Errors: {$errorCount}");
    }

    private function processPurchase(
        UserProductPurchase $purchase,
        CreditTransactionService $creditService,
        SuspensionService $suspensionService,
        DaemonServerRepository $daemonServerRepository,
        CarbonImmutable $now
    ): void {
        $lockedPurchase = $this->lockPurchase($purchase);
        if (!$lockedPurchase) {
            return;
        }

        $server = $this->validatePurchase($lockedPurchase);
        if (!$server) {
            return;
        }

        // Check credit availability including cached unspent credits
        $hasSufficientCredits = $this->checkCreditAvailability($lockedPurchase);
        $cachedUnspentCredits = $this->getCachedUnspentCredits($server->id);

        Log::debug("Processing purchase #{$lockedPurchase->id} for user #{$lockedPurchase->user_id}", [
            'purchase_id' => $lockedPurchase->id,
            'user_id' => $lockedPurchase->user_id,
            'server_id' => $lockedPurchase->server_id,
            'status' => $lockedPurchase->status,
            'uptime_seconds' => $lockedPurchase->uptime_seconds,
            'next_renew_at' => $lockedPurchase->next_renew_at?->toDateTimeString(),
            'user_credits' => $lockedPurchase->user->credits,
            'product_cost_per_cycle' => $lockedPurchase->product->credits,
            'cached_unspent_credits' => $cachedUnspentCredits,
            'has_sufficient_credits' => $hasSufficientCredits,
        ]);

        $serverState = $this->getServerState($server, $daemonServerRepository);
        
        Log::debug("Server #{$server->id} state: {$serverState}");
        
        // Skip billing for suspended purchases, but still manage grace periods and restoration
        if ($lockedPurchase->status === 'suspended') {
            Log::debug("Purchase #{$lockedPurchase->id} is suspended - skipping billing but checking for restoration");
            
            // Check if user now has sufficient credits to restore the purchase
            if ($hasSufficientCredits) {
                $lockedPurchase->status = 'active';
                Log::info("Restored purchase #{$lockedPurchase->id} to active - user now has sufficient credits");
            }
            
            // Clear grace deadline for offline suspended servers
            if (!in_array($serverState, ['running', 'starting'])) {
                $this->clearGraceDeadlineForOfflineServer($lockedPurchase->user, $server);
            }
            
            $this->updatePurchaseAndUser($lockedPurchase, $now);
            return;
        }

        if (in_array($serverState, ['running', 'starting'])) {
            // Only check credit availability for running servers
            if (!$hasSufficientCredits) {
                Log::warning("Insufficient credits detected for running server #{$server->id} (including cached unspent credits)");
                $this->handleInsufficientCredits($lockedPurchase, $lockedPurchase->user, $suspensionService, $now);
                $this->updatePurchaseAndUser($lockedPurchase, $now);
                return;
            }
            $this->processRunningServer($lockedPurchase, $server, $creditService, $suspensionService, $now);
        } else {
            // Clear grace deadline for offline servers since they're not consuming credits
            $this->clearGraceDeadlineForOfflineServer($lockedPurchase->user, $server);
            $this->processOfflineServer($lockedPurchase, $creditService, $suspensionService, $now);
        }
        
        $this->updatePurchaseAndUser($lockedPurchase, $now);
    }

    private function lockPurchase(UserProductPurchase $purchase): ?UserProductPurchase
    {
        $lockedPurchase = UserProductPurchase::query()
            ->whereKey($purchase->id)
            ->lockForUpdate()
            ->first();

        if (!$lockedPurchase) {
            Log::info("Skipping purchase #{$purchase->id} — could not obtain lock.");
            return null;
        }

        return $lockedPurchase;
    }

    private function validatePurchase(UserProductPurchase $lockedPurchase)
    {
        $product = $lockedPurchase->product;
        if (!$product) {
            Log::warning("Purchase #{$lockedPurchase->id} skipped: product missing.");
            return null;
        }

        // Ensure the associated server exists and is installed.
        $lockedPurchase->loadMissing('server');
        $server = $lockedPurchase->server;

        if (!$server || !$server->isInstalled()) {
            Log::info("Skipping purchase #{$lockedPurchase->id} — server missing or not installed.");
            return null;
        }

        // Allow processing suspended servers for grace period enforcement and billing

        return $server;
    }

    private function getServerState($server, DaemonServerRepository $daemonServerRepository): string
    {
        $state = 'offline';
        try {
            $details = $daemonServerRepository->setServer($server)->getDetails();
            $state = $details['state'] ?? 'offline';
        } catch (\Throwable $ex) {
            // If Wings is unreachable assume offline for billing accuracy, but log the error.
            Log::warning("Failed to fetch power state for server #{$server->id}: {$ex->getMessage()}", [
                'server_id' => $server->id,
                'exception_type' => get_class($ex),
            ]);
        }

        return $state;
    }

    private function processRunningServer(
        UserProductPurchase $lockedPurchase,
        $server,
        CreditTransactionService $creditService,
        SuspensionService $suspensionService,
        CarbonImmutable $now
    ): void {
        // Calculate elapsed time since last update (when this purchase was last processed)
        $lastProcessedAt = $lockedPurchase->updated_at ? CarbonImmutable::parse($lockedPurchase->updated_at) : $now;
        // Use signed difference then abs() to avoid negative values and ensure integer seconds
        $elapsedSeconds = abs($now->diffInRealSeconds($lastProcessedAt, false));
        
        // Add elapsed time to uptime counter
        $previousUptime = $lockedPurchase->uptime_seconds;
        $lockedPurchase->uptime_seconds += $elapsedSeconds;
        
        Log::debug("Updated uptime for purchase #{$lockedPurchase->id}", [
            'last_processed_at' => $lastProcessedAt->toDateTimeString(),
            'current_time' => $now->toDateTimeString(),
            'elapsed_seconds_this_run' => $elapsedSeconds,
            'previous_uptime' => $previousUptime,
            'new_uptime' => $lockedPurchase->uptime_seconds,
            'calculation' => "{$previousUptime} + {$elapsedSeconds} = {$lockedPurchase->uptime_seconds}",
        ]);

        $cycleSeconds = $this->secondsForCycle($lockedPurchase->billing_cycle);

        // Initialize next_renew_at if needed (for new purchases)
        if (is_null($lockedPurchase->next_renew_at)) {
            $lockedPurchase->next_renew_at = $this->calculateNextRenewAt($lockedPurchase->billing_cycle, $now);
            Log::debug("Initialized next_renew_at for purchase #{$lockedPurchase->id}", [
                'next_renew_at' => $lockedPurchase->next_renew_at->toDateTimeString(),
                'current_time' => $now->toDateTimeString(),
                'billing_cycle' => $lockedPurchase->billing_cycle,
                'cycle_seconds' => $this->secondsForCycle($lockedPurchase->billing_cycle),
            ]);
        }

        // Update cached unspent credits for running server
        if ($lockedPurchase->product->credits > 0) {
            $projectedCreditsNeeded = $this->calculateProjectedCreditsNeeded($lockedPurchase);
            $this->setCachedUnspentCredits($server->id, $projectedCreditsNeeded);
        }

        // Accumulate usage but only charge at billing events
        if ($elapsedSeconds > 0 && $lockedPurchase->product->credits > 0) {
            $this->accumulateUsage($lockedPurchase, $creditService, $suspensionService, $now, $elapsedSeconds, $cycleSeconds);
        }
    }

    private function accumulateUsage(
        UserProductPurchase $lockedPurchase,
        CreditTransactionService $creditService,
        SuspensionService $suspensionService,
        CarbonImmutable $now,
        int $elapsedSeconds,
        int $cycleSeconds
    ): void {
        Log::debug("Accumulating usage for purchase #{$lockedPurchase->id} - {$elapsedSeconds}s added to usage tracking");
        
        // Check if any full billing cycles have completed since last billing
        if ($lockedPurchase->next_renew_at && $now->greaterThanOrEqualTo($lockedPurchase->next_renew_at)) {
            Log::debug("Full billing cycle completed for purchase #{$lockedPurchase->id} - processing billing");
            $this->processBillingEvent($lockedPurchase, $creditService, $suspensionService, $now, $cycleSeconds, 'full_cycle');
        }
    }

    private function processBillingEvent(
        UserProductPurchase $lockedPurchase,
        CreditTransactionService $creditService,
        SuspensionService $suspensionService,
        CarbonImmutable $now,
        int $cycleSeconds,
        string $eventType
    ): void {
        // Calculate how many complete cycles have elapsed since last billing
        $secondsOverdue = $now->diffInSeconds($lockedPurchase->next_renew_at);
        $cyclesOwed = max(1, intdiv($secondsOverdue, $cycleSeconds) + 1); // At least 1 cycle for completed period
        
        Log::info("Processing billing event '{$eventType}' for purchase #{$lockedPurchase->id}", [
            'purchase_id' => $lockedPurchase->id,
            'user_id' => $lockedPurchase->user_id,
            'event_type' => $eventType,
            'cycles_owed' => $cyclesOwed,
            'seconds_overdue' => $secondsOverdue,
            'cycle_seconds' => $cycleSeconds,
            'cost_per_cycle' => $lockedPurchase->product->credits,
            'total_cost' => $cyclesOwed * $lockedPurchase->product->credits,
        ]);

        if ($lockedPurchase->product->credits > 0) {
            $totalCost = $cyclesOwed * $lockedPurchase->product->credits;
            
            // Check if user has sufficient credits
            if ($lockedPurchase->user->credits < $totalCost) {
                Log::warning("User #{$lockedPurchase->user->id} has insufficient credits for billing event - has {$lockedPurchase->user->credits}, needs {$totalCost}", [
                    'user_id' => $lockedPurchase->user->id,
                    'user_credits' => $lockedPurchase->user->credits,
                    'required_credits' => $totalCost,
                    'cycles_owed' => $cyclesOwed,
                    'event_type' => $eventType,
                ]);
                $this->handleInsufficientCredits($lockedPurchase, $lockedPurchase->user, $suspensionService, $now);
                return;
            }
            
            try {
                Log::info("Charging {$totalCost} credits for {$cyclesOwed} cycle(s) - {$eventType} event for user #{$lockedPurchase->user->id}", [
                    'user_credits_before' => $lockedPurchase->user->credits,
                    'cycles_charged' => $cyclesOwed,
                    'total_cost' => $totalCost,
                ]);
                
                $creditService->deductCredits(
                    $lockedPurchase->user,
                    $totalCost,
                    "Server billing: {$cyclesOwed} cycle(s) ({$lockedPurchase->product->name} - {$lockedPurchase->server->name})",
                    Transaction::REFERENCE_PURCHASE,
                    (string) $lockedPurchase->id,
                );
                $lockedPurchase->credits_charged += $totalCost;
                
                // Advance next_renew_at by the number of cycles charged
                $currentRenewAt = $lockedPurchase->next_renew_at;
                for ($i = 0; $i < $cyclesOwed; $i++) {
                    $currentRenewAt = $this->calculateNextRenewAt($lockedPurchase->billing_cycle, $currentRenewAt);
                }
                $lockedPurchase->next_renew_at = $currentRenewAt;
                
                // Refresh user to get updated credits balance
                $lockedPurchase->user->refresh();
                
                // Update cached unspent credits after successful billing
                if ($lockedPurchase->server) {
                    $newProjectedCredits = $this->calculateProjectedCreditsNeeded($lockedPurchase);
                    $this->setCachedUnspentCredits($lockedPurchase->server->id, $newProjectedCredits);
                }
                
                Log::info("Successfully charged billing event - user credits now: {$lockedPurchase->user->credits}, next renewal: {$lockedPurchase->next_renew_at->toDateTimeString()}");
                
            } catch (DisplayException $ex) {
                Log::warning("Insufficient credits for user #{$lockedPurchase->user->id} during billing event: {$ex->getMessage()}", [
                    'user_id' => $lockedPurchase->user->id,
                    'user_credits' => $lockedPurchase->user->credits,
                    'required_credits' => $totalCost,
                    'event_type' => $eventType,
                ]);
                $this->handleInsufficientCredits($lockedPurchase, $lockedPurchase->user, $suspensionService, $now);
            } catch (\Throwable $ex) {
                Log::error("Unexpected error during billing event for purchase #{$lockedPurchase->id}: {$ex->getMessage()}", [
                    'purchase_id' => $lockedPurchase->id,
                    'user_id' => $lockedPurchase->user->id,
                    'total_cost' => $totalCost,
                    'event_type' => $eventType,
                    'exception' => $ex,
                ]);
            }
        } else {
            // Free product - just advance the renewal date
            $currentRenewAt = $lockedPurchase->next_renew_at;
            for ($i = 0; $i < $cyclesOwed; $i++) {
                $currentRenewAt = $this->calculateNextRenewAt($lockedPurchase->billing_cycle, $currentRenewAt);
            }
            $lockedPurchase->next_renew_at = $currentRenewAt;
            
            // Clear cached unspent credits for free products
            if ($lockedPurchase->server) {
                $this->setCachedUnspentCredits($lockedPurchase->server->id, 0);
            }
            
            Log::debug("Free product - advanced renewal date by {$cyclesOwed} cycles to: {$lockedPurchase->next_renew_at->toDateTimeString()}");
        }
    }

    private function processFullCycles(
        UserProductPurchase $lockedPurchase,
        CreditTransactionService $creditService,
        SuspensionService $suspensionService,
        CarbonImmutable $now,
        int $cycleSeconds
    ): void {
        $secondsOverdue = $now->diffInSeconds($lockedPurchase->next_renew_at);
        
        Log::debug("Checking seconds overdue for purchase #{$lockedPurchase->id}", [
            'next_renew_at' => $lockedPurchase->next_renew_at->toDateTimeString(),
            'now' => $now->toDateTimeString(),
            'seconds_overdue' => $secondsOverdue,
        ]);
        
        // Fix: Only calculate cycles if actually overdue
        if ($secondsOverdue <= 0) {
            Log::debug("Purchase #{$lockedPurchase->id} not overdue, skipping full cycle processing");
            return;
        }
        
        $cyclesOwed = intdiv($secondsOverdue, $cycleSeconds);
        $partialSeconds = $secondsOverdue % $cycleSeconds;
        
        Log::debug("Purchase #{$lockedPurchase->id} owes {$cyclesOwed} cycles (overdue by {$secondsOverdue}s)", [
            'purchase_id' => $lockedPurchase->id,
            'user_id' => $lockedPurchase->user_id,
            'cycles_owed' => $cyclesOwed,
            'seconds_overdue' => $secondsOverdue,
            'cycle_seconds' => $cycleSeconds,
            'partial_seconds' => $partialSeconds,
            'cost_per_cycle' => $lockedPurchase->product->credits,
        ]);
        
        // If no full cycles are owed, just log the partial time and return
        if ($cyclesOwed === 0) {
            Log::debug("Purchase #{$lockedPurchase->id} is {$partialSeconds}s overdue but hasn't completed a full cycle yet (cycle length: {$cycleSeconds}s). No charges applied.");
            return;
        }
        
        // Sanity check: reject impossible billing scenarios
        if ($cyclesOwed > self::MAX_FULL_CYCLE_ITERATIONS) {
            Log::error("Purchase #{$lockedPurchase->id} owes {$cyclesOwed} cycles (overdue by {$secondsOverdue}s), indicating data corruption or clock issues. Skipping billing cycle processing.");
            return;
        }

        // Validate that our cycle calculation method works correctly
        $testNextRenew = $this->calculateNextRenewAt($lockedPurchase->billing_cycle, $lockedPurchase->next_renew_at);
        if (!$testNextRenew->greaterThan($lockedPurchase->next_renew_at)) {
            Log::error("Purchase #{$lockedPurchase->id} has invalid billing_cycle '{$lockedPurchase->billing_cycle}' - calculateNextRenewAt() does not advance timestamp. Skipping billing cycle processing.");
            return;
        }

        // Check if user has sufficient credits for at least one cycle before processing
        if ($lockedPurchase->product->credits > 0 && $lockedPurchase->user->credits < $lockedPurchase->product->credits) {
            Log::warning("User #{$lockedPurchase->user->id} has insufficient credits for any cycles - has {$lockedPurchase->user->credits}, needs {$lockedPurchase->product->credits}", [
                'user_id' => $lockedPurchase->user->id,
                'user_credits' => $lockedPurchase->user->credits,
                'credits_per_cycle' => $lockedPurchase->product->credits,
                'cycles_owed' => $cyclesOwed,
            ]);
            $this->handleInsufficientCredits($lockedPurchase, $lockedPurchase->user, $suspensionService, $now);
            return;
        }

        // Process each owed cycle and track successful cycles
        $successfulCycles = 0;
        for ($i = 0; $i < $cyclesOwed; $i++) {
            if ($lockedPurchase->product->credits > 0) {
                // Check if user has enough credits for this specific cycle
                if ($lockedPurchase->user->credits < $lockedPurchase->product->credits) {
                    Log::warning("User #{$lockedPurchase->user->id} has insufficient credits for cycle " . ($i+1) . " - has {$lockedPurchase->user->credits}, needs {$lockedPurchase->product->credits}", [
                        'user_id' => $lockedPurchase->user->id,
                        'user_credits' => $lockedPurchase->user->credits,
                        'required_credits' => $lockedPurchase->product->credits,
                        'cycle_number' => $i + 1,
                        'total_cycles' => $cyclesOwed,
                    ]);
                    $this->handleInsufficientCredits($lockedPurchase, $lockedPurchase->user, $suspensionService, $now);
                    break; // exit billing loop for this purchase
                }
                
                try {
                    Log::debug("Attempting to charge {$lockedPurchase->product->credits} credits for cycle ".($i+1)." of {$cyclesOwed} for user #{$lockedPurchase->user->id}", [
                        'user_credits_before' => $lockedPurchase->user->credits,
                        'charge_amount' => $lockedPurchase->product->credits,
                    ]);
                    
                    $creditService->deductCredits(
                        $lockedPurchase->user,
                        $lockedPurchase->product->credits,
                        'Product renewal: ' . $lockedPurchase->product->name . ' (Server: ' . $lockedPurchase->server->name . ')',
                        Transaction::REFERENCE_PURCHASE,
                        (string) $lockedPurchase->id,
                    );
                    $lockedPurchase->credits_charged += $lockedPurchase->product->credits;
                    $successfulCycles++;
                    
                    // Refresh user to get updated credits balance
                    $lockedPurchase->user->refresh();
                    Log::debug("Successfully charged cycle ".($i+1)." - user credits now: {$lockedPurchase->user->credits}");
                } catch (DisplayException $ex) {
                    Log::warning("Insufficient credits for user #{$lockedPurchase->user->id} (full-cycle " . ($i+1) . "/{$cyclesOwed}): {$ex->getMessage()}", [
                        'user_id' => $lockedPurchase->user->id,
                        'user_credits' => $lockedPurchase->user->credits,
                        'required_credits' => $lockedPurchase->product->credits,
                        'cycle_number' => $i + 1,
                        'total_cycles' => $cyclesOwed,
                    ]);
                    $this->handleInsufficientCredits($lockedPurchase, $lockedPurchase->user, $suspensionService, $now);
                    break; // exit billing loop for this purchase
                } catch (\Throwable $ex) {
                    Log::error("Unexpected error during credit deduction for purchase #{$lockedPurchase->id}: {$ex->getMessage()}", [
                        'purchase_id' => $lockedPurchase->id,
                        'user_id' => $lockedPurchase->user->id,
                        'cycle_number' => $i + 1,
                        'exception' => $ex,
                    ]);
                    break; // exit billing loop for this purchase
                }
            } else {
                // Free product, count as successful
                $successfulCycles++;
                Log::debug("Free product - cycle ".($i+1)." processed without charge");
            }
        }
        
        // Only advance next_renew_at for cycles that were actually processed
        // Use calculateNextRenewAt consistently instead of manual addSeconds
        if ($successfulCycles > 0) {
            $currentRenewAt = $lockedPurchase->next_renew_at;
            for ($i = 0; $i < $successfulCycles; $i++) {
                $currentRenewAt = $this->calculateNextRenewAt($lockedPurchase->billing_cycle, $currentRenewAt);
            }
            $lockedPurchase->next_renew_at = $currentRenewAt;
            Log::debug("Advanced next_renew_at by {$successfulCycles} cycles to: {$lockedPurchase->next_renew_at->toDateTimeString()}");
        }
    }

    private function processOfflineServer(
        UserProductPurchase $lockedPurchase,
        CreditTransactionService $creditService,
        SuspensionService $suspensionService,
        CarbonImmutable $now
    ): void {
        $cycleSeconds = $this->secondsForCycle($lockedPurchase->billing_cycle);

        Log::debug("Processing offline server for purchase #{$lockedPurchase->id}", [
            'created_at' => $lockedPurchase->created_at->toDateTimeString(),
            'cycle_seconds' => $cycleSeconds,
            'first_cycle_ends' => $lockedPurchase->created_at->addSeconds($cycleSeconds)->toDateTimeString(),
            'next_renew_at' => $lockedPurchase->next_renew_at?->toDateTimeString(),
        ]);

        // Clear cached unspent credits for offline server
        if ($lockedPurchase->server) {
            $this->setCachedUnspentCredits($lockedPurchase->server->id, 0);
            Log::debug("Cleared cached unspent credits for offline server #{$lockedPurchase->server->id}");
        }

        // Server is offline/stopping — offline servers consume zero credits
        if ($lockedPurchase->product->credits > 0) {
            Log::debug("Server is offline - no charges applied as offline servers consume zero credits", [
                'purchase_status' => $lockedPurchase->status,
                'server_state' => 'offline',
                'billing_policy' => 'offline_servers_not_charged',
            ]);
            
            // Reset renewal timer until the server comes back online
            $lockedPurchase->next_renew_at = null;
            Log::debug("Reset next_renew_at for offline purchase #{$lockedPurchase->id}");
        }
    }

    private function getLastBillingTime(UserProductPurchase $lockedPurchase, CarbonImmutable $now): CarbonImmutable
    {
        // If there's a next_renew_at, calculate backwards to find when current billing period started
        if ($lockedPurchase->next_renew_at) {
            $cycleSeconds = $this->secondsForCycle($lockedPurchase->billing_cycle);
            $currentBillingPeriodStart = $lockedPurchase->next_renew_at->subSeconds($cycleSeconds);
            
            Log::debug("Calculated current billing period start for purchase #{$lockedPurchase->id}", [
                'next_renew_at' => $lockedPurchase->next_renew_at->toDateTimeString(),
                'cycle_seconds' => $cycleSeconds,
                'billing_period_start' => $currentBillingPeriodStart->toDateTimeString(),
            ]);
            
            return $currentBillingPeriodStart;
        }
        
        // If no next_renew_at, use updated_at as it represents when server was last processed
        // This is more accurate than created_at for actual running time
        if ($lockedPurchase->updated_at && $lockedPurchase->updated_at->greaterThan($lockedPurchase->created_at)) {
            Log::debug("Using updated_at as last billing time for purchase #{$lockedPurchase->id}", [
                'updated_at' => $lockedPurchase->updated_at->toDateTimeString(),
                'created_at' => $lockedPurchase->created_at->toDateTimeString(),
            ]);
            return CarbonImmutable::instance($lockedPurchase->updated_at);
        }
        
        // Fallback to created_at only if no other timestamps available
        Log::debug("No recent activity found, using created_at as billing start time for purchase #{$lockedPurchase->id}");
        return CarbonImmutable::instance($lockedPurchase->created_at);
    }

    private function chargeForActualUsage(
        UserProductPurchase $lockedPurchase,
        CreditTransactionService $creditService,
        SuspensionService $suspensionService,
        CarbonImmutable $now,
        int $elapsedSeconds,
        int $cycleSeconds
    ): void {
        // Calculate proportional cost based on actual elapsed time
        $proportionalCost = (int) ceil(($lockedPurchase->product->credits * $elapsedSeconds) / $cycleSeconds);
        
        Log::debug("Charging for actual usage time for purchase #{$lockedPurchase->id}", [
            'elapsed_seconds' => $elapsedSeconds,
            'cycle_seconds' => $cycleSeconds,
            'full_cycle_cost' => $lockedPurchase->product->credits,
            'proportional_cost' => $proportionalCost,
            'usage_percentage' => round(($elapsedSeconds / $cycleSeconds) * 100, 2),
            'usage_minutes' => round($elapsedSeconds / 60, 1),
        ]);

        if ($proportionalCost > 0) {
            // Check if user has sufficient credits
            if ($lockedPurchase->user->credits < $proportionalCost) {
                Log::warning("User #{$lockedPurchase->user->id} has insufficient credits for actual usage - has {$lockedPurchase->user->credits}, needs {$proportionalCost}", [
                    'user_id' => $lockedPurchase->user->id,
                    'user_credits' => $lockedPurchase->user->credits,
                    'required_credits' => $proportionalCost,
                    'elapsed_seconds' => $elapsedSeconds,
                    'usage_minutes' => round($elapsedSeconds / 60, 1),
                ]);
                $this->handleInsufficientCredits($lockedPurchase, $lockedPurchase->user, $suspensionService, $now);
                return;
            }
            
            try {
                $usageMinutes = round($elapsedSeconds / 60, 1);
                Log::info("Charging {$proportionalCost} credits for {$usageMinutes} minutes of actual server usage for user #{$lockedPurchase->user->id}", [
                    'user_credits_before' => $lockedPurchase->user->credits,
                    'elapsed_seconds' => $elapsedSeconds,
                    'usage_minutes' => $usageMinutes,
                ]);
                
                $creditService->deductCredits(
                    $lockedPurchase->user,
                    $proportionalCost,
                    "Server usage: {$usageMinutes}min ({$lockedPurchase->product->name} - {$lockedPurchase->server->name})",
                    Transaction::REFERENCE_PURCHASE,
                    (string) $lockedPurchase->id,
                );
                $lockedPurchase->credits_charged += $proportionalCost;
                
                // Refresh user to get updated credits balance
                $lockedPurchase->user->refresh();
                
                // Clear cached unspent credits after billing for actual usage
                if ($lockedPurchase->server) {
                    $this->setCachedUnspentCredits($lockedPurchase->server->id, 0);
                }
                
                Log::info("Successfully charged for actual usage - user credits now: {$lockedPurchase->user->credits}");
                
            } catch (DisplayException $ex) {
                Log::warning("Insufficient credits for user #{$lockedPurchase->user->id} (actual usage): {$ex->getMessage()}", [
                    'user_id' => $lockedPurchase->user->id,
                    'user_credits' => $lockedPurchase->user->credits,
                    'required_credits' => $proportionalCost,
                    'elapsed_seconds' => $elapsedSeconds,
                ]);
                $this->handleInsufficientCredits($lockedPurchase, $lockedPurchase->user, $suspensionService, $now);
            } catch (\Throwable $ex) {
                Log::error("Unexpected error during actual usage billing for purchase #{$lockedPurchase->id}: {$ex->getMessage()}", [
                    'purchase_id' => $lockedPurchase->id,
                    'user_id' => $lockedPurchase->user->id,
                    'proportional_cost' => $proportionalCost,
                    'elapsed_seconds' => $elapsedSeconds,
                    'exception' => $ex,
                ]);
            }
        } else {
            Log::debug("No charge needed for purchase #{$lockedPurchase->id} - proportional cost is 0 for {$elapsedSeconds}s usage");
        }
    }

    private function processPartialUsage(
        UserProductPurchase $lockedPurchase,
        CreditTransactionService $creditService,
        SuspensionService $suspensionService,
        CarbonImmutable $now,
        int $cycleSeconds
    ): void {
        $secondsRemaining = max(0, $lockedPurchase->next_renew_at->diffInSeconds($now, false));
        $elapsed = min($cycleSeconds, $cycleSeconds - $secondsRemaining);

        Log::debug("Calculating partial usage for purchase #{$lockedPurchase->id}", [
            'next_renew_at' => $lockedPurchase->next_renew_at->toDateTimeString(),
            'now' => $now->toDateTimeString(),
            'seconds_remaining' => $secondsRemaining,
            'elapsed' => $elapsed,
            'cycle_seconds' => $cycleSeconds,
        ]);

        if ($elapsed > 0) {
            $partialCost = (int) ceil(($lockedPurchase->product->credits * $elapsed) / $cycleSeconds);

            Log::debug("Partial cost calculation for purchase #{$lockedPurchase->id}", [
                'full_cycle_cost' => $lockedPurchase->product->credits,
                'elapsed_seconds' => $elapsed,
                'cycle_seconds' => $cycleSeconds,
                'partial_cost' => $partialCost,
            ]);

            if ($partialCost > 0) {
                // Check if user has sufficient credits for partial usage before attempting charge
                if ($lockedPurchase->user->credits < $partialCost) {
                    Log::warning("User #{$lockedPurchase->user->id} has insufficient credits for partial usage - has {$lockedPurchase->user->credits}, needs {$partialCost}", [
                        'user_id' => $lockedPurchase->user->id,
                        'user_credits' => $lockedPurchase->user->credits,
                        'required_credits' => $partialCost,
                        'elapsed_seconds' => $elapsed,
                    ]);
                    $this->handleInsufficientCredits($lockedPurchase, $lockedPurchase->user, $suspensionService, $now);
                    return;
                }
                
                try {
                    Log::debug("Charging partial usage: {$partialCost} credits for user #{$lockedPurchase->user->id}", [
                        'user_credits_before' => $lockedPurchase->user->credits,
                    ]);
                    
                    $creditService->deductCredits(
                        $lockedPurchase->user,
                        $partialCost,
                        'Partial server usage (Server: ' . $lockedPurchase->server->name . ')',
                        Transaction::REFERENCE_PURCHASE,
                        (string) $lockedPurchase->id,
                    );
                    $lockedPurchase->credits_charged += $partialCost;
                    
                    // Refresh user to get updated credits balance
                    $lockedPurchase->user->refresh();
                    Log::debug("Successfully charged partial usage - user credits now: {$lockedPurchase->user->credits}");
                } catch (DisplayException $ex) {
                    Log::warning("Insufficient credits for user #{$lockedPurchase->user->id} (partial): {$ex->getMessage()}", [
                        'user_id' => $lockedPurchase->user->id,
                        'user_credits' => $lockedPurchase->user->credits,
                        'required_credits' => $partialCost,
                        'elapsed_seconds' => $elapsed,
                    ]);
                    $this->handleInsufficientCredits($lockedPurchase, $lockedPurchase->user, $suspensionService, $now);
                } catch (\Throwable $ex) {
                    Log::error("Unexpected error during partial credit deduction for purchase #{$lockedPurchase->id}: {$ex->getMessage()}", [
                        'purchase_id' => $lockedPurchase->id,
                        'user_id' => $lockedPurchase->user->id,
                        'partial_cost' => $partialCost,
                        'exception' => $ex,
                    ]);
                }
            }
        }
    }

    private function updatePurchaseAndUser(UserProductPurchase $lockedPurchase, CarbonImmutable $now): void
    {
        if ($lockedPurchase->isDirty()) {
            $lockedPurchase->save();
            Log::debug("Saved changes to purchase #{$lockedPurchase->id}");
        }

        $user = $lockedPurchase->user;
        // Only clear grace deadline if user now has sufficient credits for their servers
        if ($user->grace_deadline && $this->checkCreditAvailability($lockedPurchase)) {
            $user->grace_deadline = null;
            if ($user->isDirty('grace_deadline')) {
                $user->save();
                
                // Update cached credits when grace period is cleared and purchase becomes active
                if ($lockedPurchase->status === 'active' && $lockedPurchase->server) {
                    $projectedCredits = $this->calculateProjectedCreditsNeeded($lockedPurchase);
                    $this->setCachedUnspentCredits($lockedPurchase->server->id, $projectedCredits);
                }
                
                Log::info("Cleared grace deadline for user #{$user->id} - credits now sufficient for server requirements");
            }
        }
    }

    private function calculateNextRenewAt(string $cycle, CarbonImmutable $from): CarbonImmutable
    {
        return match ($cycle) {
            'hourly' => $from->addHour(),
            'daily' => $from->addDay(),
            'weekly' => $from->addWeek(),
            'monthly' => $from->addMonth(),
            'yearly' => $from->addYear(),
            default => $from->addMonth(),
        };
    }

    private function secondsForCycle(string $cycle): int
    {
        return self::BILLING_CYCLE_SECONDS[$cycle] ?? self::BILLING_CYCLE_SECONDS['monthly'];
    }

    private function handleInsufficientCredits(
        UserProductPurchase $purchase,
        $user,
        SuspensionService $suspensionService,
        CarbonImmutable $now,
    ): void {
        Log::warning("Handling insufficient credits for user #{$user->id}", [
            'user_id' => $user->id,
            'user_credits' => $user->credits,
            'current_grace_deadline' => $user->grace_deadline?->toDateTimeString(),
            'purchase_status' => $purchase->status,
        ]);
        
        // Note: No DB::transaction wrapper here as we're already in a transaction
        if (!$user->grace_deadline) {
            $user->grace_deadline = $now->addMinutes(self::GRACE_PERIOD_MINUTES);
            $user->save();
            Log::info("Grace deadline set for user #{$user->id} until {$user->grace_deadline->toDateTimeString()} - 15 minute warning period started");
        } else {
            Log::debug("Grace deadline already exists for user #{$user->id} until {$user->grace_deadline->toDateTimeString()}");
        }

        if ($purchase->status !== 'suspended') {
            $purchase->status = 'suspended';
            $purchase->save();
            
            // Clear cached unspent credits when purchase is suspended
            if ($purchase->server) {
                $this->clearCachedUnspentCredits($purchase->server->id);
            }
            
            Log::info("Purchase #{$purchase->id} marked as suspended due to insufficient credits.");
        }

        // Check if grace period has expired and suspend server
        if ($user->grace_deadline && $now->greaterThanOrEqualTo($user->grace_deadline)) {
            if ($purchase->server && !$purchase->server->isSuspended()) {
                try {
                    $suspensionService->toggle($purchase->server, SuspensionService::ACTION_SUSPEND);
                    Log::info("Server #{$purchase->server->id} suspended after 15-minute grace period expired.");
                } catch (\Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException $ex) {
                    // Wings connection failed - server is suspended in database but Wings didn't sync
                    Log::warning("Wings connection failed while suspending server #{$purchase->server->id}, but server is marked as suspended in database: {$ex->getMessage()}", [
                        'server_id' => $purchase->server->id,
                        'request_id' => $ex->getRequestId(),
                    ]);
                } catch (\Throwable $ex) {
                    Log::error("Failed to suspend server #{$purchase->server->id}: {$ex->getMessage()}", [
                        'server_id' => $purchase->server->id,
                        'exception' => $ex,
                    ]);
                }
            }
        } else {
                    // Grace period is still active - log warning
        $timeRemaining = $user->grace_deadline ? $user->grace_deadline->diffInMinutes($now) : 0;
        Log::warning("User #{$user->id} has insufficient credits. Server will be suspended in {$timeRemaining} minutes if credits are not added.", [
            'user_id' => $user->id,
            'credits' => $user->credits,
            'grace_deadline' => $user->grace_deadline?->toDateTimeString(),
            'minutes_remaining' => $timeRemaining,
        ]);
        }
    }

    private function enforceGraceDeadlines(SuspensionService $suspensionService, CarbonImmutable $now): void
    {
        $users = \Pterodactyl\Models\User::query()
            ->whereNotNull('grace_deadline')
            ->where('grace_deadline', '<=', $now)
            ->where('credits', '<', 0)
            ->get();

        Log::info("Enforcing grace deadlines for " . count($users) . " users");

        foreach ($users as $user) {
            $user->load(['servers']);
            foreach ($user->servers as $server) {
                if (!$server->isSuspended()) {
                    try {
                        $suspensionService->toggle($server, SuspensionService::ACTION_SUSPEND);
                        Log::info("Server #{$server->id} suspended for user #{$user->id} after grace deadline.");
                    } catch (\Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException $ex) {
                        // Wings connection failed - server is suspended in database but Wings didn't sync
                        Log::warning("Wings connection failed while suspending server #{$server->id}, but server is marked as suspended in database: {$ex->getMessage()}", [
                            'server_id' => $server->id,
                            'user_id' => $user->id,
                            'request_id' => $ex->getRequestId(),
                        ]);
                    } catch (\Throwable $ex) {
                        Log::error("Failed to suspend server #{$server->id}: {$ex->getMessage()}", [
                            'server_id' => $server->id,
                            'user_id' => $user->id,
                            'exception' => $ex,
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Get cached unspent credits for a server
     */
    private function getCachedUnspentCredits(int $serverId): int
    {
        $cacheKey = self::UNSPENT_CREDITS_CACHE_PREFIX . $serverId;
        return Cache::get($cacheKey, 0);
    }

    /**
     * Set cached unspent credits for a server
     */
    private function setCachedUnspentCredits(int $serverId, int $credits): void
    {
        $cacheKey = self::UNSPENT_CREDITS_CACHE_PREFIX . $serverId;
        Cache::put($cacheKey, $credits, now()->addHours(self::CACHE_TTL_HOURS));
        
        Log::debug("Updated cached unspent credits for server #{$serverId}: {$credits}");
    }

    /**
     * Calculate projected credits needed for accumulated usage in current billing cycle
     */
    private function calculateProjectedCreditsNeeded(UserProductPurchase $purchase): int
    {
        if ($purchase->product->credits <= 0) {
            return 0; // Free product
        }

        // If server is offline (next_renew_at is null), no credits are being consumed
        if (!$purchase->next_renew_at) {
            return 0; // Offline server, no ongoing cost
        }

        $cycleSeconds = $this->secondsForCycle($purchase->billing_cycle);
        $now = CarbonImmutable::now();
        
        // Calculate elapsed time in current cycle (usage that needs to be billed)
        // Use timestamp difference for accurate calculation
        $renewalTimestamp = $purchase->next_renew_at->getTimestamp();
        $nowTimestamp = $now->getTimestamp();
        
        if ($renewalTimestamp > $nowTimestamp) {
            // Server is running within current cycle
            $secondsUntilRenewal = $renewalTimestamp - $nowTimestamp;
            $secondsElapsedInCycle = max(0, $cycleSeconds - $secondsUntilRenewal);
            $secondsOverdue = 0;
        } else {
            // Server is overdue (past renewal time)
            $secondsOverdue = $nowTimestamp - $renewalTimestamp;
            $secondsElapsedInCycle = $cycleSeconds + $secondsOverdue;
            $secondsUntilRenewal = 0;
        }
        
        // Ensure we don't have negative ratios
        $elapsedCycleRatio = max(0, $secondsElapsedInCycle / $cycleSeconds);
        $projectedCredits = (int) ceil($purchase->product->credits * $elapsedCycleRatio);

        Log::debug("Calculated projected credits needed for purchase #{$purchase->id}", [
            'next_renew_at' => $purchase->next_renew_at->toDateTimeString(),
            'now' => $now->toDateTimeString(),
            'renewal_timestamp' => $renewalTimestamp,
            'now_timestamp' => $nowTimestamp,
            'is_future_renewal' => $renewalTimestamp > $nowTimestamp,
            'seconds_until_renewal' => $secondsUntilRenewal,
            'seconds_overdue' => $secondsOverdue,
            'seconds_elapsed_in_cycle' => $secondsElapsedInCycle,
            'cycle_seconds' => $cycleSeconds,
            'elapsed_cycle_ratio' => round($elapsedCycleRatio, 3),
            'full_cycle_cost' => $purchase->product->credits,
            'projected_credits' => $projectedCredits,
        ]);
        
        return $projectedCredits;
    }

    /**
     * Check if user has enough credits including cached unspent credits
     */
    private function checkCreditAvailability(UserProductPurchase $purchase): bool
    {
        $user = $purchase->user;
        $server = $purchase->server;
        
        if (!$server) {
            return true; // No server to track
        }

        $userCredits = $user->credits;
        $cachedUnspentCredits = $this->getCachedUnspentCredits($server->id);
        $projectedCreditsNeeded = $this->calculateProjectedCreditsNeeded($purchase);
        $totalCreditsNeeded = $cachedUnspentCredits + $projectedCreditsNeeded;

        Log::debug("Credit availability check for purchase #{$purchase->id}", [
            'user_credits' => $userCredits,
            'cached_unspent_credits' => $cachedUnspentCredits,
            'projected_credits_needed' => $projectedCreditsNeeded,
            'total_credits_needed' => $totalCreditsNeeded,
            'sufficient' => $userCredits >= $totalCreditsNeeded,
        ]);

        return $userCredits >= $totalCreditsNeeded;
    }

    /**
     * Clear cached unspent credits for a server
     */
    private function clearCachedUnspentCredits(int $serverId): void
    {
        $cacheKey = self::UNSPENT_CREDITS_CACHE_PREFIX . $serverId;
        Cache::forget($cacheKey);
        
        Log::debug("Cleared cached unspent credits for server #{$serverId}");
    }

    /**
     * Clear grace deadline for offline servers since they're not consuming credits
     */
    private function clearGraceDeadlineForOfflineServer($user, $server): void
    {
        if ($user->grace_deadline) {
            $user->grace_deadline = null;
            $user->save();
            
            Log::info("Cleared grace deadline for user #{$user->id} - server #{$server->id} is offline and not consuming credits");
        }
    }

    /**
     * Check if server was previously running and consuming credits
     */
    private function wasServerPreviouslyRunning(UserProductPurchase $purchase): bool
    {
        // Server was previously running if:
        // 1. It has credits_charged > 0 (was billed before), OR
        // 2. It had uptime_seconds > 0 (was running), OR  
        // 3. It had a valid next_renew_at in the past (billing was established)
        
        $hasBeenCharged = $purchase->credits_charged > 0;
        $hasUptime = $purchase->uptime_seconds > 0;
        $hadBillingScheduled = !is_null($purchase->updated_at) && 
                               $purchase->updated_at->greaterThan($purchase->created_at->addMinutes(5));
        
        $wasPreviouslyRunning = $hasBeenCharged || $hasUptime || $hadBillingScheduled;
        
        Log::debug("Checking if server was previously running for purchase #{$purchase->id}", [
            'has_been_charged' => $hasBeenCharged,
            'credits_charged' => $purchase->credits_charged,
            'has_uptime' => $hasUptime,
            'uptime_seconds' => $purchase->uptime_seconds,
            'had_billing_scheduled' => $hadBillingScheduled,
            'was_previously_running' => $wasPreviouslyRunning,
        ]);
        
        return $wasPreviouslyRunning;
    }
}
