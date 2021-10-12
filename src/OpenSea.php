<?php
namespace kodeops\OpenSeaWrapper;

use Illuminate\Support\Facades\Http;
use kodeops\OpenSeaWrapper\Helpers\ConsoleOutput;
use kodeops\OpenSeaWrapper\Model\Event;
use kodeops\OpenSeaWrapper\Events\OpenSeaEventAdded;

class OpenSea
{
    // The endpoints declared here will be persisted in database
    const PERSIST_METHODS = ['events'];
    protected $base_url;
    protected $limit;
    protected $consoleOutput;

    public function __construct()
    {
        // https://docs.opensea.io/reference/api-overview
        $this->base_url = 'https://api.opensea.io/api';
        $this->limit = 50;
        $this->consoleOutput = new ConsoleOutput();
    }

    public function asset($asset_contract_address, $token_id)
    {
        return $this->request("/v1/asset/{$asset_contract_address}/{$token_id}");
    }

    public function assets($params)
    {
        return $this->requestUsingTokenIds('/v1/assets', $params);
    }

    public function bundles($params)
    {
        return $this->requestUsingTokenIds('/v1/assets', $params);
    }

    public function events($params, $crawl = false)
    {
        if ($crawl) {
            return $this->crawlAll('events', $params);
        }
        return $this->request('/v1/events', $params);
    }

    public function request($endpoint, $params = [], $query = false)
    {
        $query = $query ? $query : http_build_query($params);
        $url = "{$this->base_url}{$endpoint}?{$query}";
        $response = Http::withHeaders($this->getRequestHeaders())->get($url)->throw();

        if (
            // Should we persist the results on database?
            in_array(explode('/', $endpoint)[2], self::PERSIST_METHODS)
            AND
            // There are results in the response?
            count($response->json()[key($response->json())])
        ) {
            self::addEvents($response->json()[key($response->json())]);
        }

        return $response;
    }

    private function getRequestHeaders()
    {
        if (app()->environment('production')) {
            return ['X-API-KEY' => env('OPENSEA_API_KEY')];
        }

        return [];
    }

    private function requestUsingTokenIds($endpoint, $params)
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
            $mergedResponses = array_merge($mergedResponses, $response[key($response)]);
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
            $output->comment("Skipping event #{$openSeaEvent['id']}");
            return;
        }

        $event = Event::create([
            'asset_contract_address' => is_null($openSeaEvent['asset']) ? null : $openSeaEvent['asset']['asset_contract']['address'],
            'token_id' => is_null($openSeaEvent['asset']) ? null : $openSeaEvent['asset']['token_id'],
            'asset_id' => is_null($openSeaEvent['asset']) ? null : $openSeaEvent['asset']['id'],
            'event_id' => $openSeaEvent['id'],
            'event_type' => $openSeaEvent['event_type'],
            'raw' => $openSeaEvent,
            'created_at' => now(),
            'event_at' => $openSeaEvent['created_date'],
        ]);

        OpenSeaEventAdded::dispatch($event);

        $output->info("Adding event #{$event->id}");

        return $event;
    }

    public static function addEvents($openSeaEvents) {
        foreach ($openSeaEvents as $openSeaEvent) {
            self::addEvent($openSeaEvent);
        }
    }

    public function crawlAll($endpoint, $params)
    {
        return $this->crawl($endpoint, $params, 9999999999999, 0);
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

            $key = key($response);

            if (empty($response[key($response)])) {
                break;
            }

            $combinedResponses = array_merge($combinedResponses, $response[key($response)]);

            if (count($response[key($response)]) < $this->limit) {
                break;
            }

            sleep($sleep);
            
            $requests_count++;
        }

        // Mantain the same structure that the original call has
        return [$key => $combinedResponses];
    }
}