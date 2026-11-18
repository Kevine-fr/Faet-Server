<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromoCodeShowResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'vendor'           => [
                'id'   => $this->vendor?->id,
                'name_store' => $this->vendor?->name_store,
            ],
            'user' => $this->vendor && $this->vendor->user ? [
                'first_name' => $this->vendor->user->first_name,
                'last_name'  => $this->vendor->user->last_name,
                'email'      => $this->vendor->user->email,
            ] : null,

            'code'             => $this->code,
            'name'             => $this->name,
            'description'      => $this->description,
            'type'             => $this->type,
            'value'            => $this->value,
            'min_order_amount' => $this->min_order_amount,
            'max_uses'         => $this->max_uses,
            'used_count'       => $this->used_count,
            'status'           => $this->status,
            'valid_from'       => $this->valid_from,
            'valid_until'      => $this->valid_until,
            'is_active'        => $this->is_active,

            'products' => $this->products->map(function ($product) {
                return [
                    'id'              => $product->id,
                    'name'            => $product->name,
                    'sku'            => $product->sku,
                    'price'           => $product->price,
                    'final_price'     => $product->pivot?->final_price,
                ];
            }),

            'orders' => $this->orders->map(function ($order) {
                return [
                    'id'              => $order->id,
                    'order_number'    => $order->order_number,
                    'user_id'         => $order->user_id,
                    'subtotal'        => $order->subtotal,
                    'discount_amount' => $order->discount_amount,
                    'total_paid'      => $order->subtotal - $order->discount_amount,
                    'status'          => $order->status ?? null,
                    'created_at'      => $order->created_at,
                ];
            }),

            'stats' => [
                'total_orders'    => $this->orders->count(),
                'total_saved'     => $this->orders->sum('discount_amount'),
                'total_revenue'   => $this->orders->sum(fn ($o) => $o->subtotal - $o->discount_amount),
                'avg_subtotal'    => $this->orders->avg('subtotal'),
                'avg_final_total' => $this->orders->avg(fn ($o) => $o->subtotal - $o->discount_amount),
            ],

            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
        ];
    }
}