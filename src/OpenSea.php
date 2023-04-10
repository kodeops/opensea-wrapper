<?php
namespace kodeops\OpenSeaWrapper;

use Illuminate\Support\Facades\Http;
use kodeops\OpenSeaWrapper\Helpers\ConsoleOutput;
use kodeops\OpenSeaWrapper\Models\Event;
use kodeops\OpenSeaWrapper\Events\OpenSeaEventAdded;
use kodeops\OpenSeaWrapper\Exceptions\OpenSeaWrapperException;
use kodeops\OpenSeaWrapper\Exceptions\OpenSeaWrapperRequestException;
use Illuminate\Support\Str;

class OpenSea
{
    // https://docs.opensea.io/reference/api-overview

    protected $base_url;
    protected $limit;
    protected $options;
    protected $consoleOutput;
    protected $cursor;

    public function __construct($options = [])
    {
        // https://docs.opensea.io/reference/api-overview
        $this->options = $options;
        $this->base_url = 'https://api.opensea.io';
        $this->limit = 50;
        $this->consoleOutput = new ConsoleOutput();
        $this->cursor = [
            'next' => null,
            'previous' => null,
        ];
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

    public function collectionStats($collection_slug)
    {
        return $this->request("/api/v1/collection/{$collection_slug}/stats");
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
        $occurred_after = null;
        if (isset($params['occurred_after_value'])) {
            if (! isset($params['occurred_after_key'])) {
                throw new OpenSeaWrapperException("Occurred after key not found");
            }
            $occurred_after['key'] = $params['occurred_after_key'];
            $occurred_after['value'] = $params['occurred_after_value'];
            unset($params['occurred_after_key']);
            unset($params['occurred_after_value']);
        }

        if (! $crawl) {
            if (! isset($params['limit'])) {
                $params['limit'] = $this->limit;
            }

            return $this->request('/api/v1/events', $params);
        }

        switch ($crawl) {
            case 'all':
                return $this->crawlAll('events', $params, $sleep, $occurred_after);
            break;
            
            default:
                return $this->crawlWithMaxRequests('events', $params, $crawl, $sleep, $occurred_after);
            break;
        }
    }

    public function request($endpoint, $params = [], $query = false, $raw = false)
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
            throw new OpenSeaWrapperRequestException("OpenSea request failed: " . $response->getBody());
        }

        $results = $response->json();
        if (is_null($results)) {
            throw new OpenSeaWrapperRequestException("OpenSea null response");
        }

        // Because of the change of the offset type to a cursor type pagination
        // we will need to preserve cursors.
        $raw_results = $results;
        
        $key = $this->getResponseKey($endpoint, key($results));
        if ($key AND ! isset($response[$key])) {
            throw new OpenSeaWrapperRequestException("Response key ({$key}) is null");
        }

        // Remove the primary key that is included in all OpenSea responses
        // e.g.: <asset_events>, <assets>, etc.
        $results = $key ? $results[$key] : $results;

        if (in_array('order_by_desc', $this->options)) {
            $results = collect($results)->reverse()->toArray();
        }

        if (
            // Should we persist the results on database?
            in_array($endpoint, $this->persistEndpoints())
            AND
            // There are results in the response?
            count($results)
        ) {
            self::addEvents($results);
        }

        if (isset($raw_results['next'])) {
            $this->cursor['next'] = $raw_results['next'];
            $this->cursor['previous'] = $raw_results['previous'];
        }

        return $raw ? $raw_results : $results;
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
            );
            
            $mergedResponses = array_merge($mergedResponses, $response);

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
        $eventExists = Event::where('event_id', $openSeaEvents[0]['id'])->first();
        if ($eventExists) {
            return;
        }
        foreach ($openSeaEvents as $openSeaEvent) {
            self::addEvent($openSeaEvent);
        }
    }

    public function crawlWithMaxRequests($endpoint, $params, $requests, $sleep)
    {
        return $this->crawl($endpoint, $params, $requests, $sleep, $ocurred_after);
    }

    public function crawlAll($endpoint, $params, $sleep = 0, $ocurred_after = null)
    {
        return $this->crawl($endpoint, $params, 9999999999999, $sleep, $ocurred_after);
    }

    public function crawl($endpoint, $params, $max_requests = 5, $sleep = 0, $occurred_after)
    {
        $data = [];

        $requests_count = 0;
        $combinedResponses = [];
        // Whatever limit has been set in params, force the maximum allowed by OpenSea API
        $params['limit'] = $this->limit;
        while ($requests_count <= $max_requests) {
            $this->consoleOutput->comment("OpenSea “{$endpoint}” Request #" . ($requests_count + 1));
            
            $response = $this->{$endpoint}(array_merge($params, ['cursor' => $this->cursor['next']]));
            foreach ($response as $r) {
                // If the event is equal than $occurred_after stop crawling
                if ($r[$occurred_after['key']] <= $occurred_after['value']) {
                    $this->consoleOutput->info("Found stopping point at {$occurred_after['value']}");
                    return $data;
                }
                array_push($data, $r);
            }

            // Stop if API returns fewer results than limit
            if (count($response) < $this->limit) {
                break;
            }

            $this->consoleOutput->comment("Cursor “{$endpoint}” Request {$this->cursor['next']}");

            // Stop if API returns a null next cursor
            if (is_null($this->cursor['next'])) {
                break;
            }

            sleep($sleep);

            $requests_count++;
        }

        // Mantain the same structure that the original call has
        return $combinedResponses;
    }

    private function getResponseKey($endpoint, $key)
    {
        if (Str::contains($endpoint, '/api/v1/asset/')) {
            $endpoint = '/api/v1/asset';
        } else if (Str::contains($endpoint, '/api/v1/collection/')) {
            $endpoint = '/api/v1/collection';
        }
        
        switch ($endpoint) {
            case '/wyvern/v1/orders':
                return 'orders';
            break;

            case '/api/v1/assets':
                return 'assets';
            break;

            case '/api/v1/asset':
                return;
            break;

            case '/api/v1/events':
                return 'asset_events';
            break;

            case '/api/v1/collection':
                return 'stats';
            break;

            default:
                throw new OpenSeaWrapperException("Undefined key endpoint: {$endpoint}");
            break;
        }
    }
}
