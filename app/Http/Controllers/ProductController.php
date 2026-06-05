<?php

namespace App\Http\Controllers;

use App\Classes\PterodactylClient;
use App\Models\Pterodactyl\Egg;
use App\Models\Pterodactyl\Node;
use App\Models\Product;
use App\Models\User;
use App\Notifications\DynamicNotification;
use App\Settings\PterodactylSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use App\Models\Role;

class ProductController extends Controller
{
    private $pterodactyl;

    public function __construct(PterodactylSettings $ptero_settings)
    {
        $this->pterodactyl = new PterodactylClient($ptero_settings);
    }

    /**
     * @description get product based on selected egg
     *
     * @param  Egg  $egg
     * @return Collection|JsonResponse
     */
    public function getProductsBasedOnEgg(Egg $egg): Collection|JsonResponse
    {
        if (is_null($egg->id)) {
            return response()->json('Egg ID is required', 400);
        }

        $user = Auth::user();

        $products = Product::query()
            ->where('disabled', false)
            ->whereHas('eggs', function (Builder $builder) use ($egg) {
                $builder->where('id', $egg->id);
            })
            ->with([
                'nodes'
            ])
            ->withCount(['servers' => function ($query) use ($user) {
                $query->where('user_id', $user->id);
            }])
            ->get();

        foreach ($products as $product) {
            $product->doesNotFit = true;

            foreach ($product->nodes as $node) {

                $pteroNode = $this->pterodactyl->getNode($node->id);

                $availableMemory =
                    ($pteroNode['memory'] * ($pteroNode['memory_overallocate'] + 100) / 100)
                    - $pteroNode['allocated_resources']['memory'];

                $availableDisk =
                    ($pteroNode['disk'] * ($pteroNode['disk_overallocate'] + 100) / 100)
                    - $pteroNode['allocated_resources']['disk'];

                if (
                    $product->memory <= $availableMemory &&
                    $product->disk <= $availableDisk
                ) {
                    $product->doesNotFit = false;
                    break;
                }
            }
        }

        return $products;
    }

    /**
     * @param  Int $node
     * @param  Egg  $egg
     * @return Collection|JsonResponse
     */
    public function getProductsBasedOnNode(Egg $egg, int $node)
    {
        if (is_null($egg->id) || is_null($node)) {
            return response()->json('Node and Egg ID are required', 400);
        }

        $user = Auth::user();
        $products = Product::query()
            ->where('disabled', false)
            ->whereHas('nodes', function (Builder $builder) use ($node) {
                $builder->where('node', $node);
            })
            ->whereHas('eggs', function (Builder $builder) use ($egg) {
                $builder->where('id', $egg->id);
            })
            ->with(['nodes' => function ($query) use ($node) {
                $query->where('node', $node);
            }])
            ->withCount(['servers' => function ($query) use ($user) {
                $query->where('user_id', $user->id);
            }])
            ->get();

        // Check if the product fits in at least one node
        foreach ($products as $product) {
            $product->doesNotFit = true;

            foreach ($product->nodes as $node) {
                $pteroNode = $this->pterodactyl->getNode($node->id);

                $availableMemory = ($pteroNode['memory'] * ($pteroNode['memory_overallocate'] + 100) / 100) - $pteroNode['allocated_resources']['memory'];
                $availableDisk = ($pteroNode['disk'] * ($pteroNode['disk_overallocate'] + 100) / 100) - $pteroNode['allocated_resources']['disk'];

                // If the product fits in this node, mark it as fitting and break out of the loop
                if ($product->memory <= $availableMemory && $product->disk <= $availableDisk) {
                    $product->doesNotFit = false;
                    break;
                }
            }
        }

        return $products;
    }
}
