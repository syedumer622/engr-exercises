<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\IndexController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/exercise-1-artwork-version', [IndexController::class, 'artworkVersion']); // // exercise = 1
Route::post('/exercise-2-tier-pricing', [IndexController::class, 'tierPricing']); // exercise = 2
Route::post('/exercise-3-cart-validator', [IndexController::class, 'cartValidator']); // exercise = 3
Route::post('/exercise-4-vendor-allocation', [IndexController::class, 'vendorAllocation']); // exercise = 4
Route::post('/exercise-5-discount', [IndexController::class, 'discountSelection']); // exercise = 5
Route::post('/exercise-6-approval-flow', [IndexController::class, 'approvalFlow']); // exercise = 6
Route::post('/exercise-7-inventory', [IndexController::class, 'inventoryReservationEngine']); // exercise = 7
Route::post('/exercise-8-shipment', [IndexController::class, 'partialShipmentTracker']); // exercise = 8
Route::post('/exercise-9-webhook', [IndexController::class, 'webhookDeduplicator']); // exercise = 9
Route::post('/exercise-10-quote-expiry', [IndexController::class, 'queryExpiryEngine']); // exercise = 10
Route::post('/exercise-11-product-visibility', [IndexController::class, 'productVisibilityEngine']); // exercise = 11
Route::post('/exercise-12-bundle-pricing', [IndexController::class, 'bundlePricingEngine']); // exercise = 12
Route::post('/exercise-13-cart-merge', [IndexController::class, 'cartMergeEngine']); // exercise = 13
Route::post('/exercise-14-upsell', [IndexController::class, 'numberIndices']); // exercise = 14
Route::post('/exercise-15-shipping-rule', [IndexController::class, 'shippingRuleEngine']); // exercise = 15
Route::post('/exercise-16-fraud-check', [IndexController::class, 'fraudPatternDetector']); // exercise = 16
Route::post('/exercise-17-shopify-price-adjustment', [IndexController::class, 'shopifyPriceAdjustmentEngine']); // exercise = 17
Route::post('/exercise-18-data-sync', [IndexController::class, 'dataSyncConflictResolver']); // exercise = 18
