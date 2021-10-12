<?php
namespace kodeops\OpenSeaWrapper\Model;

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
        return \kodeops\OpenSeaWrapper\Model\Event::where('token_id', $this->token_id)
            ->where('asset_contract_address', $this->asset_contract_address);
    }
}
