<?php

namespace App\Observers;

use App\Models\Product;
use App\Models\User;
use App\Services\NotificationService;

class ProductObserver
{
    /**
     * Custom function
     */
    private function notifyPriceUpdateToCashiers($product): void
    {
        $cashiers = User::where('role', 'cashier')->get();

        $formattedPrice = 'Rp' . number_format($product->sell_price, 0, ',', '.');

        foreach ($cashiers as $cashier) {
            NotificationService::sendToUser(
                $cashier,
                '🏷️ Pembaruan Harga Master',
                "Harga {$product->product_name} berubah menjadi {$formattedPrice}",
                'info'
            );
        }
    }

    /**
     * Handle the Product "created" event.
     */
    public function created(Product $product): void
    {
        //
    }

    /**
     * Handle the Product "updated" event.
     */
    public function updated(Product $product): void
    {
        if ($product->wasChanged('sell_price')) {

            $oldPrice = (float) $product->getOriginal('sell_price');
            $newPrice = (float) $product->sell_price;

            if ($oldPrice !== $newPrice) {
                $this->notifyPriceUpdateToCashiers($product);
            }
        }
    }

    /**
     * Handle the Product "deleted" event.
     */
    public function deleted(Product $product): void
    {
        //
    }

    /**
     * Handle the Product "restored" event.
     */
    public function restored(Product $product): void
    {
        //
    }

    /**
     * Handle the Product "force deleted" event.
     */
    public function forceDeleted(Product $product): void
    {
        //
    }
}
