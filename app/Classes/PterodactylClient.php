<?php

namespace App\Classes;

use App\Models\Pterodactyl\Egg;
use App\Models\Pterodactyl\Node;
use App\Models\Product;
use App\Models\Server;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use App\Settings\PelicanSettings;
use App\Settings\ServerSettings;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PterodactylClient
{
    //TODO: Extend error handling (maybe logger for more errors when debugging)

    private int $per_page_limit = 200;

    private int $allocation_limit = 200;

    public PendingRequest $client;

    public PendingRequest $application;

    public function __construct(PelicanSettings $ptero_settings)
    {
        $server_settings = new ServerSettings();

        try {
            $this->client = $this->client($ptero_settings);
            $this->application = $this->clientAdmin($ptero_settings);
            $this->per_page_limit = $ptero_settings->per_page_limit;
            $this->allocation_limit = $server_settings->allocation_limit;
        } catch (Exception $exception) {
            logger('Failed to construct Pelican client, Settings table not available?', ['exception' => $exception]);
        }
    }
    /**
     * @return PendingRequest
     */
    public function client(PelicanSettings $ptero_settings)
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $ptero_settings->user_token,
            'Content-type' => 'application/json',
            'Accept' => 'Application/json',
        ])->baseUrl($ptero_settings->getUrl() . 'api' . '/');
    }

    public function clientAdmin(PelicanSettings $ptero_settings)
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $ptero_settings->admin_token,
            'Content-type' => 'application/json',
            'Accept' => 'Application/json',
        ])->baseUrl($ptero_settings->getUrl() . 'api' . '/');
    }

    /**
     * @return HttpException
     */
    private function getException(string $message = '', ?int $status = null): HttpException|Exception
    {
        Log::Error('PterodactylClient: ' . $message);
        if ($status == 404) {
            return new HttpException(404,'Resource Pelican not exist on Pelican - ' . $message . ' Was a Server deleted from Pelican but not from the Panel? Have an Admin Remove it from the Panel');
        }

        if ($status == 403) {
            return new HttpException(403, 'No permission on pterodactyl, check Pelican token and permissions - ' . $message);
        }

        if ($status == 401) {
            return new HttpException(401,'No Pelican token set - ' . $message);
        }

        if ($status == 500) {
            return new HttpException(500,'Pelican server error - ' . $message);
        }

        if ($status == 0) {
            return new HttpException(500, 'Unable to connect to Pelican node - Please check if the node is online and accessible' . $message);
        }

        if ($status >= 500 && $status < 600) {
            return new HttpException($status,'Pelican node error (HTTP ' . $status . ') - ' . $message);
        }

        return new Exception('Request Failed, is Pelican set-up correctly? - ' . $message);
    }

    /**
     * @return mixed
     *
     * @throws Exception
     */
    public function getEggs()
    {
        try {
            $response = $this->application->get("application/eggs?include=variables&per_page=" . $this->per_page_limit);
        } catch (Exception $e) {
            throw self::getException($e->getMessage());
        }
        if ($response->failed()) {
            throw self::getException('Failed to get eggs from Pelican - ', $response->status());
        }

        return $response->json()['data'];
    }

    /**
     * @return mixed
     *
     * @throws Exception
     */
    public function getNodes()
    {
        try {
            $response = $this->application->get('application/nodes?per_page=' . $this->per_page_limit);
        } catch (Exception $e) {
            throw self::getException($e->getMessage());
        }
        if ($response->failed()) {
            throw self::getException('Failed to get nodes from Pelican - ', $response->status());
        }

        return $response->json()['data'];
    }

    /**
     * @return mixed
     *
     * @throws Exception
     * @description Returns the infos of a single node
     */
    public function getNode($id)
    {
        try {
            $response = $this->application->get('application/nodes/' . $id);
        } catch (Exception $e) {
            throw self::getException($e->getMessage());
        }
        if ($response->failed()) {
            throw self::getException('Failed to get node id ' . $id . ' - ' . $response->status());
        }

        return $response->json()['attributes'];
    }

    public function getServers()
    {
        try {
            $response = $this->application->get('application/servers?per_page=' . $this->per_page_limit);
        } catch (Exception $e) {
            throw self::getException($e->getMessage());
        }
        if ($response->failed()) {
            throw self::getException('Failed to get list of servers - ', $response->status());
        }

        return $response->json()['data'];
    }

    /**
     * @param  Node  $node
     * @return mixed
     *
     * @throws Exception
     */
    public function getFreeAllocationId(Node $node)
    {
        return self::getFreeAllocations($node)[0]['attributes']['id'] ?? null;
    }

    /**
     * @param  Node  $node
     * @return array|mixed|null
     *
     * @throws Exception
     */
    public function getFreeAllocations(Node $node)
    {
        $response = self::getAllocations($node);
        $freeAllocations = [];

        if (isset($response['data'])) {
            if (!empty($response['data'])) {
                foreach ($response['data'] as $allocation) {
                    if (!$allocation['attributes']['assigned']) {
                        array_push($freeAllocations, $allocation);
                    }
                }
            }
        }

        return $freeAllocations;
    }

    /**
     * @param  Node  $node
     * @return array|mixed
     *
     * @throws Exception
     */
    public function getAllocations(Node $node)
    {
        try {
            $response = $this->application->get("application/nodes/{$node->id}/allocations?per_page={$this->allocation_limit}");
        } catch (Exception $e) {
            throw self::getException($e->getMessage());
        }
        if ($response->failed()) {
            throw self::getException('Failed to get allocations from Pelican - ', $response->status());
        }

        return $response->json();
    }

    /**
     * @param  Server  $server
     * @param  Egg  $egg
     * @param  int  $allocationId
     * @return Response
     */
    public function createServer(Server $server, Egg $egg, int $allocationId, mixed $eggVariables = null)
    {
       try {
            $response = $this->application->post('application/servers', [
                'name' => $server->name,
                'external_id' => $server->id,
                'user' => $server->user->pterodactyl_id,
                'egg' => $egg->id,
                'docker_image' => $egg->docker_image,
                'startup' => $egg->startup,
                'environment' => $this->getEnvironmentVariables($egg, $eggVariables),
                'oom_disabled' => !$server->product->oom_killer,
                'limits' => [
                    'memory' => $server->product->memory,
                    'swap' => $server->product->swap,
                    'disk' => $server->product->disk,
                    'io' => $server->product->io,
                    'cpu' => $server->product->cpu,
                ],
                'feature_limits' => [
                    'databases' => $server->product->databases,
                    'backups' => $server->product->backups,
                    'allocations' => $server->product->allocations,
                ],
                'allocation' => [
                    'default' => $allocationId,
                ],
            ]);

            return $response;
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Get a server by external_id on Pterodactyl.
     *
     * @param string $externalId
     * @return \Illuminate\Http\Client\Response
     * @throws Exception
     */
    public function getServerByExternalId(string $externalId)
    {
        try {
            return $this->application->get("application/servers/external/{$externalId}");
        } catch (Exception $e) {
            throw self::getException('Failed to get server by external_id from Pelican - ' . $e->getMessage());
        }
    }

    public function suspendServer(Server $server)
    {
        try {
            $response = $this->application->post("application/servers/$server->pterodactyl_id/suspend");
        } catch (Exception $e) {
            throw self::getException($e->getMessage());
        }
        if ($response->failed()) {
            throw self::getException('Failed to suspend server from Pelican - ', $response->status());
        }

        return $response;
    }

    public function unSuspendServer(Server $server)
    {
        try {
            $response = $this->application->post("application/servers/$server->pterodactyl_id/unsuspend");
        } catch (Exception $e) {
            throw self::getException($e->getMessage());
        }
        if ($response->failed()) {
            throw self::getException('Failed to unsuspend server from Pelican - ', $response->status());
        }

        return $response;
    }

    /**
     * Get user by Pelican id
     *
     * @param  int  $pterodactylId
     * @return mixed
     */
    public function getUser(int $pterodactylId)
    {
        try {
            $response = $this->application->get("application/users/{$pterodactylId}");
        } catch (Exception $e) {
            throw self::getException($e->getMessage());
        }
        if ($response->failed()) {
            throw self::getException('Failed to get user from Pelican - ', $response->status());
        }

        return $response->json()['attributes'];
    }

    /**
     * Update user on Pterodactyl
     *
     * @param int $pterodactylId
     * @param array $data
     * @throws HttpException
     * @return \Illuminate\Http\Client\Response
     */
    public function updateUser(int $pterodactylId, array $data)
    {
        try {
            $response = $this->application->patch("application/users/{$pterodactylId}", $data);
        } catch (Exception $e) {
            throw self::getException($e->getMessage());
        }
        if ($response->failed()) {
            throw self::getException('Failed to update user on Pelican - ', $response->status());
        }

        return $response;
    }

    /**
     * Get serverAttributes by Pelican id
     *
     * @param  int  $pterodactylId
     * @return mixed
     */
    public function getServerAttributes(int $pterodactylId, bool $deleteOn404 = false)
    {
        try {
            $response = $this->application->get("application/servers/{$pterodactylId}?include=egg,node");
        } catch (Exception $e) {
            throw self::getException($e->getMessage());
        }

        //print response body

        if ($response->failed()) {
            if ($deleteOn404) {  //Delete the server if it does not exist (server deleted on pterodactyl)
                Server::where('pterodactyl_id', $pterodactylId)->first()->delete();

                return;
            } else {
                throw self::getException('Failed to get server attributes from Pelican - ', $response->status());
            }
        }

        return $response->json()['attributes'];
    }

    /**
     * Update Server Resources
     *
     * @param  Server  $server
     * @param  Product  $product
     * @return Response
     *
     * @deprecated Use updateServerBuild instead.
     */
    public function updateServer(Server $server, Product $product)
    {
        return $this->application->patch("application/servers/{$server->pterodactyl_id}/build", [
            'allocation' => $server->allocation,
            'memory' => $product->memory,
            'swap' => $product->swap,
            'disk' => $product->disk,
            'io' => $product->io,
            'cpu' => $product->cpu,
            'threads' => null,
            'oom_disabled' => !$server->product->oom_killer,
            'feature_limits' => [
                'databases' => $product->databases,
                'backups' => $product->backups,
                'allocations' => $product->allocations,
            ],
        ]);
    }

    /**
     * Update server build.
     *
     * @param  Server  $server
     * @return Response
     *
     * @throws Exception
     */
    public function updateServerBuild(string $pterodactylId, int $pterodactylAllocation, Product $product)
    {
        try {
            $response = $this->application->patch("application/servers/{$pterodactylId}/build", [
                'allocation' => $pterodactylAllocation,
                'memory' => $product->memory,
                'swap' => $product->swap,
                'disk' => $product->disk,
                'io' => $product->io,
                'cpu' => $product->cpu,
                'threads' => null,
                'oom_disabled' => $product->oom_killer,
                'feature_limits' => [
                    'databases' => $product->databases,
                    'backups' => $product->backups,
                    'allocations' => $product->allocations,
                ],
            ]);

            if ($response->failed()) {
                throw self::getException('Server not found on Pterodactyl', 404);
            }

            return $response;
        } catch (Exception $e) {
            throw self::getException($e->getMessage());
        }
    }

    /**
     * Update the owner of a server
     *
     * @param  int  $userId
     * @param  Server  $server
     * @return mixed
     */
    public function updateServerOwner(Server $server, int $userId)
    {
        return $this->application->patch("application/servers/{$server->pterodactyl_id}/details", [
            'name' => $server->name,
            'user' => $userId,
        ]);
    }

    /**
     * Update server details
     *
     * @param  Server  $server
     * @param  array  $data
     * @return Response
     *
     * @throws HttpException
     * @throws Exception
     */
    public function updateServerDetails(Server $server, array $data)
    {
        try {
            return $this->application->patch("application/servers/{$server->pterodactyl_id}/details", $data);
        } catch (Exception $e) {
            throw self::getException($e->getMessage());
        }
    }

    /**
     * Power Action Specific Server
     *
     * @param  Server  $server
     * @param  string  $action
     * @return Response
     */
    public function powerAction(Server $server, $action)
    {
        return $this->client->post("client/servers/{$server->identifier}/power", [
            'signal' => $action,
        ]);
    }

    /**
     * Get info about user
     */
    public function getClientUser()
    {
        return $this->client->get('client/account');
    }

    /**
     * Check if node has enough free resources to allocate the given resources
     *
     * @param  Node  $node
     * @param  int  $requireMemory
     * @param  int  $requireDisk
     * @return bool
     */
    public function checkNodeResources(Node $node, int $requireMemory, int $requireDisk)
    {
        try {
            $response = $this->application->get("application/nodes/{$node->id}");
        } catch (Exception $e) {
            throw self::getException($e->getMessage());
        }
        $node = $response['attributes'];
        $freeMemory = ($node['memory'] * ($node['memory_overallocate'] + 100) / 100) - $node['allocated_resources']['memory'];
        $freeDisk = ($node['disk'] * ($node['disk_overallocate'] + 100) / 100) - $node['allocated_resources']['disk'];
        if ($freeMemory < $requireMemory) {
            return false;
        }
        if ($freeDisk < $requireDisk) {
            return false;
        }

        return true;
    }

    private function getEnvironmentVariables(Egg $egg, $variables)
    {
        $environment = [];
        // Support for front-end and api variables format.
        $variables = collect(is_string($variables) ? json_decode($variables, true) : $variables);

        foreach ($egg->environment as $envVariable) {
            if (!empty($envVariable['default_value'])) {
                $environment[$envVariable['env_variable']] = $envVariable['default_value'];
            }
        }

        $environment = array_merge($environment, $variables->toArray());

        return $environment;
    }
}
