<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PeerConnected implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public string $pairingCode, public mixed $data = null)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('transfer.' . $this->pairingCode),
        ];
    }
}