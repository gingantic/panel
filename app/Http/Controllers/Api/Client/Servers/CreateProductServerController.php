<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Illuminate\Http\JsonResponse;
use Pterodactyl\Models\User;
use Illuminate\Support\Arr;
use Pterodactyl\Models\Objects\DeploymentObject;
use Pterodactyl\Services\Servers\ServerCreationService;
use Pterodactyl\Services\Deployment\FindViableNodesService;
use Pterodactyl\Services\Deployment\AllocationSelectionService;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\StoreProductServerRequest;
use Pterodactyl\Repositories\Eloquent\ProductRepository;
use Pterodactyl\Repositories\Eloquent\ServerRepository;
use Pterodactyl\Contracts\Repository\EggVariableRepositoryInterface;
use Pterodactyl\Contracts\Repository\EggRepositoryInterface;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Services\Credits\CreditTransactionService;
use Pterodactyl\Models\UserProductPurchase;
use Carbon\Carbon;

class CreateProductServerController extends ClientApiController
{
    public function __construct(
        private ProductRepository $productRepository,
        private ServerCreationService $creationService,
        private FindViableNodesService $findViableNodesService,
        private AllocationSelectionService $allocationSelectionService,
        private CreditTransactionService $creditTransactionService,
        private EggVariableRepositoryInterface $eggVariableRepository,
        private EggRepositoryInterface $eggRepository,
    ) {
        parent::__construct();
    }

    /**
     * Purchase a product and create a server for the requesting user.
     *
     * @throws \Throwable
     */
    public function __invoke(StoreProductServerRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $product = $this->productRepository->find($request->input('product_id'));

        // Respect max per user limit
        if ($product->max_per_user > 0) {
            $activeCount = UserProductPurchase::query()
                ->where('user_id', $user->id)
                ->where('product_id', $product->id)
                ->whereIn('status', ['creating', 'active'])
                ->count();

            if ($activeCount >= $product->max_per_user) {
                throw new DisplayException('You have reached the maximum number of servers for this plan.');
            }
        }

        // Check credits
        if ($product->credits > 0 && $user->credits < $product->credits) {
            throw new DisplayException('Insufficient credits.');
        }

        // Find nodes capable of handling this product
        $nodes = $this->findViableNodesService
            ->setLocations([]) // optional: pass allowed locations
            ->setDisk($product->disk)
            ->setMemory($product->memory)
            ->handle();

        if ($nodes->isEmpty()) {
            throw new DisplayException('Node full');
        }

        // Select allocation on one of the viable nodes
        $allocation = $this->allocationSelectionService
            ->setNodes($nodes->pluck('id')->toArray())
            ->handle();

        // build default environment variables for egg
        $env = [];
        $eggId = $request->input('egg_id');
        $egg = null;
        if ($eggId) {
            $variables = $this->eggVariableRepository->findWhere([ 'egg_id' => $eggId ]);
            foreach ($variables as $variable) {
                $env[$variable->env_variable] = $variable->default_value;
            }

            // pull egg details for startup/image
            $egg = $this->eggRepository->find($eggId);
        }

        // Build server data for creation
        $data = [
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'owner_id' => $user->id,
            'memory' => $product->memory,
            'disk' => $product->disk,
            'cpu' => $product->cpu,
            'swap' => $product->swap,
            'io' => 500,
            'allocation_id' => $allocation->id,
            'egg_id' => $eggId,
            'nest_id' => $egg ? $egg->nest_id : null,
            'node_id' => $allocation->node_id,
            'startup' => $egg ? $egg->startup : '',
            'image' => $egg ? (is_array($egg->docker_images) ? array_values($egg->docker_images)[0] : $egg->docker_image) : '',
            'environment' => $env,
            'database_limit' => 0,
            'allocation_limit' => 0,
            'backup_limit' => 0,
            // other defaults...
        ];

        $server = $this->creationService->handle($data);

        // Record purchase
        $cycle = $product->billing_cycle;
        $nextRenew = match ($cycle) {
            'hourly' => Carbon::now()->addHour(),
            'daily' => Carbon::now()->addDay(),
            'weekly' => Carbon::now()->addWeek(),
            'monthly' => Carbon::now()->addMonth(),
            'yearly' => Carbon::now()->addYear(),
            default => Carbon::now()->addMonth(),
        };

        UserProductPurchase::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'egg_id' => $eggId,
            'server_id' => $server->id,
            'credits_charged' => $product->credits,
            'billing_cycle' => $product->billing_cycle,
            'next_renew_at' => $nextRenew,
            'uptime_seconds' => 0,
            'status' => 'active',
        ]);

        // Debit credits if cost greater than zero
        if ($product->credits > 0) {
            $this->creditTransactionService->deductCredits($user, $product->credits, 'Product purchase #' . $product->id, 'purchase', (string) $product->id);
        }

        return $this->fractal->item($server)
            ->transformWith($this->getTransformer(\Pterodactyl\Transformers\Api\Client\ServerTransformer::class))
            ->respond(201);
    }
} 