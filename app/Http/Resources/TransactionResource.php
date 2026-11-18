<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
          return [
            'id'         => $this->id,
            'type'       => $this->type,
            'amount'     => $this->amount,
            'status'     => $this->status,
            'created_at' => $this->created_at,
            'failure_reason'=>$this->failure_reason,

            'order' => $this->order ? [
                'id'           => $this->order->id,
                'order_number' => $this->order->order_number,
            ] : null,

            'vendor' => $this->vendor ? [
                'id'   => $this->vendor->id,
                'name_store' => $this->vendor->name_store,
            ] : null,
        ];
    }
}
