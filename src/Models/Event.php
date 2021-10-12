<?php
namespace kodeops\OpenSeaWrapper\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $table = 'opensea_events';
    public $guarded = [];
    public $timestamps = false;
    protected $casts = [
        'raw' => 'array',
        'created_at' => 'datetime',
        'event_at' => 'datetime',
    ];

    public function events()
    {
        return \kodeops\OpenSeaWrapper\Models\Event::where('token_id', $this->token_id)
            ->where('asset_contract_address', $this->asset_contract_address);
    }

    public function getAssetKey($key)
    {
        // Single asset
        if (! is_null($this->raw['asset'])) {
            return $this->raw['asset'][$key];
        }

        // Bundle (Array of assets)
        if (! is_null($this->raw['asset_bundle'])) {
            switch ($key) {
                case 'image_url':
                    return $this->raw['asset_bundle']['assets'][array_rand($this->raw['asset_bundle']['assets'])]['image_url'];
                break;

                default:
                    return $this->raw['asset_bundle'][$key];
                break;
            }
        }
    }
}
