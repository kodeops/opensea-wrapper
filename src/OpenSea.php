<?php
namespace kodeops\OpenSeaWrapper;

use Illuminate\Support\Facades\Http;
use kodeops\OpenSeaWrapper\Helpers\ConsoleOutput;
use kodeops\OpenSeaWrapper\Models\Event;
use kodeops\OpenSeaWrapper\Events\OpenSeaEventAdded;
use kodeops\OpenSeaWrapper\Exceptions\OpenSeaWrapperException;
use kodeops\OpenSeaWrapper\Exceptions\OpenSeaWrapperRequestException;

class OpenSea
{
    // https://docs.opensea.io/reference/api-overview

    protected $base_url;
    protected $limit;
    protected $options;
    protected $consoleOutput;

    public function __construct($options = [])
    {
        // https://docs.opensea.io/reference/api-overview
        $this->options = $options;
        $this->base_url = 'https://api.opensea.io';
        $this->limit = 50;
        $this->consoleOutput = new ConsoleOutput();
    }

    private function persistEndpoints()
    {
        // The endpoints declared in the environment file will be persisted in database
        if (env('OPENSEA_WRAPPER_PERSIST_ENDPOINTS')) {
            return explode(',', env('OPENSEA_WRAPPER_PERSIST_ENDPOINTS'));
        }

        return [];
    }

    public function asset($asset_contract_address, $token_id)
    {
        return $this->request("/api/v1/asset/{$asset_contract_address}/{$token_id}");
    }

    public function assets($params)
    {
        if (! isset($params['limit'])) {
            $params['limit'] = $this->limit;
        }
        return $this->requestUsingTokenIds('/api/v1/assets', $params);
    }

    public function orders($params, $sleep = 0)
    {
        if (! isset($params['limit'])) {
            $params['limit'] = $this->limit;
        }
        return $this->requestUsingTokenIds('/wyvern/v1/orders', $params, $sleep);
    }

    public function bundles($params)
    {
        if (! isset($params['limit'])) {
            $params['limit'] = $this->limit;
        }
        return $this->requestUsingTokenIds('/api/v1/assets', $params);
    }

    public function events($params, $crawl = false, $sleep = 0)
    {
        if (! $crawl) {
            if (! isset($params['limit'])) {
                $params['limit'] = $this->limit;
            }

            return $this->request('/api/v1/events', $params);
        }
        switch ($crawl) {
            case 'all':
                return $this->crawlAll('events', $params, $sleep);
            break;
            
            default:
                return $this->crawlWithMaxRequests('events', $params, $crawl, $sleep);
            break;
        }
    }

    public function request($endpoint, $params = [], $query = false)
    {
        $query = $query ? $query : http_build_query($params);
        $query .= "&format=json";
        $url = "{$this->base_url}{$endpoint}?{$query}";

        $this->consoleOutput->debug("{$url}");

        if (env('OPENSEA_WRAPPER_PROXY')) {
            if (! env('OPENSEA_WRAPPER_PROXY_TOKEN')) {
                throw new OpenSeaWrapperException("OpenSea wrapper is missing “OPENSEA_WRAPPER_PROXY_TOKEN” environment setting");
            }

            $url = "https://"
                . env('OPENSEA_WRAPPER_PROXY_ENDPOINT')
                . "/?token="
                . env('OPENSEA_WRAPPER_PROXY_TOKEN')
                . "&url=" . urlencode($url);
        }

        $response = Http::withHeaders($this->getRequestHeaders())->get($url);

        if (
            ($response->status() == 200)
            AND
            (! $response->json())
        ) {
            if (str_contains($response->body(), 'Access denied')) {
                $proxy_mode = env('OPENSEA_WRAPPER_PROXY') ? 'Proxy: ON' : 'Proxy: OFF';
                throw new OpenSeaWrapperRequestException("OpenSea request failed: Access denied ({$proxy_mode})");
            }
        }

        if ($response->failed()) {
            if (config('app.debug')) {
                \Facade\Ignition\Facades\Flare::context('url', $url);
                \Facade\Ignition\Facades\Flare::context('endpoint', $endpoint);
                \Facade\Ignition\Facades\Flare::context('params', $params);
            }

            throw new OpenSeaWrapperRequestException("OpenSea request failed: " . $response->getBody());
        }

        $results = $response->json();
        if (is_null($results)) {
            throw new OpenSeaWrapperRequestException("OpenSea null response");
        }

        $key = $this->getResponseKey($endpoint, key($results));

        if (in_array('order_by_desc', $this->options)) {
            $results[$key] = collect($results[$key])->reverse()->toArray();
        }

        // Remove the primary key that is included in all OpenSea responses
        // e.g.: <asset_events>, <assets>, etc.

        $results = $results[$key];

        if (
            // Should we persist the results on database?
            in_array($endpoint, $this->persistEndpoints())
            AND
            // There are results in the response?
            count($results)
        ) {
            self::addEvents($results);
        }

        return $response;
    }

    private function getRequestHeaders()
    {
        if (! env('OPENSEA_API_KEY')) {
            throw new OpenSeaWrapperRequestException("Missing OPENSEA_API_KEY");
        }
        
        return ['X-API-KEY' => env('OPENSEA_API_KEY')];
    }

    private function requestUsingTokenIds($endpoint, $params, $sleep = false)
    {
        // Force limit to the maximum allowed
        $params['limit'] = $this->limit;

        // If the request contains token_ids parameter, it must be treated according
        // OpenSea API docs: https://docs.opensea.io/reference/getting-assets
        if (! isset($params['token_ids'])) {
            return $this->request($endpoint, $params);
        }

        // Ensure token_ids value has at most 30 items
        $mergedResponses = [];
        foreach (collect($params['token_ids'])->chunk(30) as $chunkOfTokenIds) {
            $limitedParams = $params;
            $limitedParams['token_ids'] = $chunkOfTokenIds->toArray();
            $response = $this->request(
                $endpoint, 
                $params,
                self::convertTokenIdsToHttpQueryBuild($limitedParams)
            )->json();
            
            if (! $response[$this->getResponseKey($endpoint, key($response))]) {
                throw new OpenSeaWrapperRequestException("Response key is null");
            }

            $mergedResponses = array_merge($mergedResponses, $response[$this->getResponseKey($endpoint, key($response))]);

            if ($sleep) {
                $this->consoleOutput->debug("Sleeping {$sleep} seconds...");
                sleep($sleep);
            }
        }

        return $mergedResponses;
    }

    public static function convertTokenIdsToHttpQueryBuild($params)
    {
        // An array of token IDs to search for (e.g. ?token_ids=1&token_ids=209). 
        // Will return a list of assets with token_id matching any of the IDs in this array.

        // Convert ['5760', 'xxxx'] to ['token_ids=5760', 'token_ids=xxxx']
        $token_ids = collect($params['token_ids'])
            ->transform(function ($token_id) {
                return "token_ids={$token_id}";
            })
            ->toArray();
        // Convert ['token_ids=5760', 'token_ids=xxxx'] to 'token_ids=5760&token_ids=xxxx'
        $token_ids = implode("&", $token_ids);
        unset($params['token_ids']);
        return http_build_query($params) . "&{$token_ids}";
    }

    public static function addEvent($openSeaEvent)
    {
        $output = new ConsoleOutput();

        if (Event::where('event_id', $openSeaEvent['id'])->first()) {
            $output->comment("Skipping OpenSea event #{$openSeaEvent['id']}");
            return;
        }
        $event = Event::create([
            'asset_contract_address' => is_null($openSeaEvent['asset']) ? null : $openSeaEvent['asset']['asset_contract']['address'],
            'token_id' => is_null($openSeaEvent['asset']) ? null : $openSeaEvent['asset']['token_id'],
            'event_id' => $openSeaEvent['id'],
            'event_type' => isset($openSeaEvent['order_hash']) ? 'order' : $openSeaEvent['event_type'],
            'raw' => $openSeaEvent,
            'created_at' => now(),
            'event_at' => $openSeaEvent['created_date'],
        ]);

        OpenSeaEventAdded::dispatch($event);

        $output->comment("Added OpenSea event #{$event->id}");

        return $event;
    }

    public static function addEvents($openSeaEvents) {
        foreach ($openSeaEvents as $openSeaEvent) {
            self::addEvent($openSeaEvent);
        }
    }

    public function crawlWithMaxRequests($endpoint, $params, $requests, $sleep)
    {
        return $this->crawl($endpoint, $params, $requests, $sleep);
    }

    public function crawlAll($endpoint, $params, $sleep = 0)
    {
        return $this->crawl($endpoint, $params, 9999999999999, $sleep);
    }

    public function crawl($endpoint, $params, $max_requests = 5, $sleep = 0)
    {
        $requests_count = 0;
        $combinedResponses = [];
        // Whatever limit has been set in params, force the maximum allowed by OpenSea API
        $params['limit'] = $this->limit;
        while ($requests_count <= $max_requests) {
            $this->consoleOutput->comment("OpenSea “{$endpoint}” Request #" . ($requests_count + 1));
            
            $offset = ($requests_count * $this->limit);
            $response = $this->{$endpoint}(array_merge($params, ['offset' => $offset]))
                ->json();

            $key = $this->getResponseKey($endpoint, key($response));

            if (empty($response[$key])) {
                break;
            }

            $combinedResponses = array_merge($combinedResponses, $response[$key]);

            if (count($response[$key]) < $this->limit) {
                break;
            }

            sleep($sleep);
            
            $requests_count++;
        }

        // Mantain the same structure that the original call has
        return [$key => $combinedResponses];
    }

    private function getResponseKey($endpoint, $key)
    {
        switch ($endpoint) {
            case '/wyvern/v1/orders':
                return 'orders';
            break;

            case '/api/v1/assets':
                return 'assets';
            break;

            case '/api/v1/events':
                return 'asset_events';
            break;

            default:
                return $key;
            break;
        }
    }
}
