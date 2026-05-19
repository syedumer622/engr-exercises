<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;

class IndexController extends Controller
{

    private function sendResponse($success, $data = null, $error = null)
    {
        return response()->json(compact('success', 'data', 'error'));
    }
    public function artworkVersion(Request $request) // exercise = 1
    {
        $validator = validator($request->all(), [
            'input' => 'required|array',
            'input.*.id' => 'required|integer',
            'input.*.approved' => 'required|boolean',
            'input.*.rejected' => 'required|boolean',
            'input.*.time|integer'
        ]);

        if ($validator->fails()) {
            return $this->sendResponse(false, null, $validator->errors()->first());
        }
        $input = $request->input('input');
        $array = array_filter($input, function ($value) {
            return $value['approved'] == true && $value['rejected'] == false;
        });
        $array = array_values($array);
        usort($array, function ($a, $b) {
            return $a['time'] < $b['time'];
        });
        usort($array, function ($a, $b) {
            return $a['id'] < $b['id'] && $a['time'] == $b['time'];
        });
        $first = array_first($array);
        $result = [
            "id" => $first['id'] ?? null,
        ];
        return $this->sendResponse(true, $result);
    }

    public function tierPricing(Request $request)
    {
        $validator = validator($request->all(), [
            'input' => 'required|array',
            'input.quantity' => 'required',
            'input.tiers' => 'required|array',
            'input.tiers.*.min' => 'required|int|distinct',
            'input.tiers.*.price' => 'required|numeric'
        ], [
            'input.array' => 'The input field must be an object.'
        ]);

        if ($validator->fails()) {
            return $this->sendResponse(false, null, $validator->errors()->first());
        }
        $quantity = $request->input('input.quantity');
        $tiers = $request->collect('input.tiers');
        $selectedTier = $tiers->sortByDesc('min')->where('min', '<=', $quantity)->first();
        if (!$selectedTier) {
            return $this->sendResponse(false, null, 'There is no tier applied against the input quantity.');
        }
        $result = [
            'price' => $selectedTier['price']
        ];
        return $this->sendResponse(true, $result);
    }

    public function cartValidator(Request $request)
    {
        $validator = validator($request->all(), [
            'input' => 'required|array',
            'input.*.id' => 'required|int|distinct',
            'input.*.required' => 'required|boolean',
            'input.*.done' => 'required|boolean'
        ]);

        if ($validator->fails()) {
            return $this->sendResponse(false, null, $validator->errors()->first());
        }

        $input = $request->collect('input');

        $invalid_items = $input->filter(function ($value) {
            return $value['required'] == true && $value['done'] == false;
        })->values();
        $invalid_item_ids = $invalid_items->pluck('id')->toArray();
        $isValid = $invalid_items->count() < 1;

        return $this->sendResponse(true, [
            'valid' => $isValid,
            'invalid_items' => $invalid_item_ids
        ]);
    }

    public function vendorAllocation(Request $request)
    {
        $validator = validator($request->all(), [
            'input' => 'required|array',
            'input.order_qty' => 'required|integer|min:1',
            'input.vendors' => 'required|array',
            'input.vendors.*.id' => 'required|integer|numeric:strict|min:1',
            'input.vendors.*.stock' => 'required|integer|numeric:strict|min:0',
        ]);
        if ($validator->fails()) {
            return $this->sendResponse(false, null, $validator->errors()->first());
        }
        $order_qty = $request->input('input.order_qty');
        $vendors = $request->collect('input.vendors');
        $remaining_qty = $order_qty;
        $vendors_allocation = $vendors->map(function ($vendor) use (&$remaining_qty) {
            $allocated_qty = $remaining_qty > $vendor['stock'] ? $vendor['stock'] : $remaining_qty;
            $remaining_qty -= $allocated_qty;

            return [
                'vendor_id' => $vendor['id'],
                'allocated' => $allocated_qty,
            ];
        })->where('allocated', '>', 0)->values();

        $error = null;
        $is_qty_exceeded = $order_qty > $vendors->sum('stock');
        if ($is_qty_exceeded) {
            $error = 'Input total quantity exceeds the available quantity, placing the order with the quantity available.';
        }

        return $this->sendResponse(true, $vendors_allocation, $error);
    }

    public function discountSelection(Request $request)
    {
        $validator = validator($request->all(), [
            'input' => 'required|array',
            'input.price' => 'required|numeric:strict|min:0',
            'input.discounts' => 'required|array',
            'input.discounts.*.type' => 'required|in:flat,percentage',
            'input.discounts.*.value' => [
                'required',
                'numeric:strict',
                'min:0',
                function ($attribute, $value, $fail) use ($request) {
                    // Extract the index to find the matching 'type'
                    // input.discounts.0.value -> 0
                    $index = explode('.', $attribute)[2];
                    $type = $request->input("input.discounts.{$index}.type");

                    if ($type === 'percentage' && $value > 100) {
                        $fail("The discount percentage cannot exceed 100.");
                    }
                },
            ],
        ]);

        if ($validator->fails()) {
            return $this->sendResponse(false, null, $validator->errors()->first());
        }

        $input_price = $request->input('input.price');
        $lowest_price = $input_price;

        $discounts = $request->collect('input.discounts');

        foreach ($discounts as $discount) {
            $final_price = $discount['type'] == 'percentage' ? ($input_price - ($input_price * ($discount['value'] / 100))) : ($input_price - $discount['value']);
            if ($final_price < $lowest_price) {
                $lowest_price = $final_price;
            }
        }

        $result = [
            'final_price' => $lowest_price > 0 ? $lowest_price : 0,
        ];

        return $this->sendResponse(true, $result);
    }

    private function isDependsOnExists($steps, $currentStep, $currentKey)
    {
        foreach ($steps as $key => $step) {
            if ($step['id'] == $currentStep['depends_on'] && $key <= $currentKey) {
                return true;
            }
        }
        return false;
    }

    public function approvalFlow(Request $request)
    {
        $validator = validator($request->all(), [
            'input' => 'required|array',
            'input.steps' => 'required|array',
            'input.steps.*.id' => 'required|string|distinct',
            'input.steps.*.depends_on' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendResponse(false, null, $validator->errors()->first());
        }

        $steps = $request->collect('input.steps');

        $valid = true;
        foreach ($steps as $key => $step) {
            if (isset($step['depends_on'])) {
                if ($step['id'] == $step['depends_on']) {
                    $valid = false;
                } else {
                    $exists = $this->isDependsOnExists($steps, $step, $key);
                    if (!$exists) {
                        $valid = false;
                    }
                }
            }
        }
        $result = [
            'valid' => $valid,
        ];
        return $this->sendResponse(true, $result);
    }

    public function inventoryReservationEngine(Request $request)
    {
        $validator = validator($request->all(), [
            'input' => 'required|array',
            'input.stock' => 'required|integer|numeric:strict|min:0',
            'input.requests' => 'required|array',
            'input.requests.*' => 'required|integer|min:1'
        ]);

        if ($validator->fails()) {
            return $this->sendResponse(false, null, $validator->errors()->first());
        }

        $stock = $request->input('input.stock');
        $remaining_stock = $stock;
        $requests = $request->collect('input.requests');

        $result = $requests->map(function ($value) use (&$remaining_stock) {
            if ($value <= $remaining_stock) {
                $remaining_stock -= $value;
                return true;
            }
            return false;
        });

        return $this->sendResponse(true, $result);
    }

    public function partialShipmentTracker(Request $request)
    {
        $validator = validator($request->all(), [
            'input' => 'required|array',
            'input.ordered' => 'required|integer|numeric:strict|min:1',
            'input.shipped' => 'required|array',
            'input.shipped.*' => 'required|integer|numeric:strict|min:0'
        ]);

        if ($validator->fails()) {
            return $this->sendResponse(false, null, $validator->errors()->first());
        }

        $ordered_qty = $request->input('input.ordered');
        $shipped = $request->collect('input.shipped');
        $total_shipped = $shipped->sum();
        if ($total_shipped > $ordered_qty) {
            return $this->sendResponse(false, null, 'The total shipped quantity must not exceed the ordered quantity.');
        }
        $remaining_qty = $ordered_qty - $total_shipped;

        $result = [
            'remaining' => $remaining_qty,
        ];

        return $this->sendResponse(true, $result);
    }

    public function webhookDeduplicator(Request $request)
    {
        $validator = validator($request->all(), [
            'input' => 'required|array',
            'input.*.id' => 'required|alpha_num',
            'input.*.time' => 'required|integer:strict|min:1'
        ]);

        if ($validator->fails()) {
            return $this->sendResponse(false, null, $validator->errors()->first());
        }

        $input = $request->collect('input');
        $unique_values = $input->unique('id')->pluck('id')->toArray();

        return $this->sendResponse(true, $unique_values);
    }

    public function queryExpiryEngine(Request $request)
    {
        $validator = validator($request->all(), [
            'input' => 'required|array',
            'input.created_at' => 'required|date|before_or_equal:input.current_date',
            'input.valid_days' => 'required|integer:strict|min:0',
            'input.current_date' => 'required|date',
        ]);

        if ($validator->fails()) {
            return $this->sendResponse(false, null, $validator->errors()->first());
        }

        $currentDate = $request->date('input.current_date', 'Y-m-d');
        $createdAt = $request->date('input.created_at', 'Y-m-d');
        $validDays = $request->input('input.valid_days');

        $valid = $createdAt->diffInDays($currentDate) <= $validDays;
        return $this->sendResponse(true, compact('valid'));
    }

    private function tagsExists($array, $tags)
    {
        return collect($array)->filter(function ($value) use ($tags) {
            return in_array($value, $tags);
        })->values()->count() > 0;
    }

    public function productVisibilityEngine(Request $request)
    {
        $validator = validator($request->all(), [
            'input' => 'required|array',
            'input.customer' => 'required|array',
            'input.customer.tags' => 'present|array',
            'input.products' => 'required|array',
            'input.products.*.id' => 'required|integer|distinct',
            'input.products.*.allow' => 'present|array',
            'input.products.*.block' => 'present|array',
        ]);

        if ($validator->fails()) {
            return $this->sendResponse(false, $validator->errors()->first());
        }



        $tags = $request->collect('input.customer.tags');
        $products = $request->collect('input.products');

        $visibleProducts = $products->filter(function ($product) use ($tags) {
            $allowed = $this->tagsExists($product['allow'], $tags->toArray());
            $blocked = $this->tagsExists($product['block'], $tags->toArray());
            if (empty($product['allow']) && !$blocked) {
                return true;
            }
            if ($blocked) {
                return false;
            }
            if ($allowed) {
                return true;
            }
            return false;
        })->values()->pluck('id');

        return $this->sendResponse(true, $visibleProducts);
    }

    public function bundlePricingEngine(Request $request)
    {
        $validator = validator($request->all(), [
            'input' => 'required',
            'input.items' => 'required|array',
            'input.items.*.id' => 'required|integer:strict|min:1',
            'input.items.*.price' => 'required|numeric:strict|min:0',
            'input.bundle_price' => 'required|numeric:strict|min:0',
            'input.apply_bundle' => 'required|boolean:strict'
        ]);

        if ($validator->fails()) {
            return $this->sendResponse(false, $validator->errors()->first());
        }

        $apply_bundle = $request->boolean('input.apply_bundle');
        $bundle_price = $request->input('input.bundle_price');
        $items_price = $request->collect('input.items')->sum('price');

        $final_price = $items_price;
        if ($apply_bundle && $bundle_price < $items_price) {
            $final_price = $bundle_price;
        }
        return $this->sendResponse(true, compact('final_price'));
    }

    private function findIdInArray(&$array, $id)
    {
        foreach ($array as $key => $value) {
            if ($value['id'] == $id) {
                return $key;
            }
        }
        return null;
    }

    public function cartMergeEngine(Request $request)
    {
        $validator = validator($request->all(), [
            'input' => 'required|array',
            'input.guest' => 'present|array',
            'input.guest.*.id' => 'required|integer:strict|min:1',
            'input.guest.*.qty' => 'required|integer:strict|min:1',
            'input.user' => 'present|array',
            'input.user.*.id' => 'required|integer:strict|min:1',
            'input.user.*.qty' => 'required|integer:strict|min:1',
        ]);

        if ($validator->fails()) {
            return $this->sendResponse(false, $validator->errors()->first());
        }

        $user_cart_items = $request->collect('input.user')->toArray();
        $guest_cart_items = $request->collect('input.guest')->toArray();
        $result = [];

        foreach ($guest_cart_items as $guest_cart_item) {
            $user_cart_items[] = $guest_cart_item;
        }

        foreach ($user_cart_items as $user_cart_item) {
            $key = $this->findIdInArray($result, $user_cart_item['id']);
            if (!isset($key)) {
                $result[] = $user_cart_item;
            } else {
                $result[$key]['qty'] += $user_cart_item['qty'];
            }
        }

        usort($result, function ($a, $b) {
            if ($a['id'] == $b['id']) {
                return 0;
            }
            return ($a['id'] < $b['id']) ? -1 : 1;
        });

        return $this->sendResponse(true, $result);
    }

    private function isTargetFirstAndLast($array, $target)
    {
        if (count($array) < 2) {
            return false;
        }
        if ($array[0] + $array[(count($array) - 1)] == $target) {
            return true;
        }
        return false;
    }

    private function isConsistantGap($array)
    {
        if (count($array) < 2) {
            return false;
        }
        $initialDiff = $array[1] - $array[0];
        foreach ($array as $key => $value) {
            if ($key < count($array) - 1 && ($array[$key + 1] - $value) != $initialDiff) {
                return false;
            }
        }
        return true;
    }

    public function numberIndices(Request $request)
    {
        $validator = validator($request->all(), [
            'input' => 'required|array',
            'input.nums' => 'required|array',
            'input.nums.*' => 'required|integer:strict',
            'input.target' => 'required|integer:strict',
        ]);

        if ($validator->fails()) {
            return $this->sendResponse(false, $validator->errors()->first());
        }

        $nume = $request->collect('input.nums');
        $target = $request->input('input.target');


        $indexes = collect([]);
        if (
            $indexes->count() < 1 &&
            !$this->isConsistantGap($nume->toArray()) &&
            !$this->isTargetFirstAndLast($nume->toArray(), $target)
        ) {
            foreach ($nume as $key => $val) {
                if ($key < $nume->count() - 1 && $val + ($nume[$key + 1]) == $target) {
                    $indexes->push($key, $key + 1);
                    break;
                }
            }
        }
        if ($indexes->count() < 1) {
            foreach ($nume as $key => $val) {
                foreach ($nume as $key2 => $val2) {
                    if (($val + $val2) == $target && $key2 > $key) {
                        $indexes->push($key, $key2);
                        break;
                    }
                }
                if ($indexes->count() > 1) {
                    break;
                }
            }
        }


        return $this->sendResponse(true, $indexes);
    }

    public function shippingRuleEngine(Request $request)
    {
        $validator = validator($request->all(), [
            'input' => 'required|array',
            'input.order' => 'required',
            'input.order.weight' => 'nullable|numeric:strict',
            'input.order.country' => 'nullable|string',
            'method.order.method' => 'nullable|string',
            'input.rules' => 'required|array',
            'input.rules.*.id' => 'required|integer:strict',
            'input.rules.*.method' => 'required|string',
            'input.rules.*.priority' => 'nullable|integer:strict|distinct',
        ]);

        if ($validator->fails()) {
            return $this->sendResponse(false, $validator->errors()->first());
        }

        $weight = $request->input('input.order.weight');
        $country = $request->input('input.order.country');
        $method = $request->input('input.order.method');

        $rules = $request->collect('input.rules');

        $selected_rule = $rules->filter(function ($rule) use ($weight, $country, $method) {
            return (isset($rule['max_weight']) && $weight > 0 && $weight <= $rule['max_weight']) ||
                (isset($rule['country']) && $rule['country'] == $country) ||
                (isset($rule['method']) && $rule['method'] == $method);
        })->sortBy('priority')->first();

        $shipping_method = data_get($selected_rule, 'method');
        return $this->sendResponse(true, compact('shipping_method'));
    }

    public function fraudPatternDetector(Request $request)
    {
        $validator = validator($request->all(), [
            'input' => 'required|array',
            'input.order' => 'present|array',
            'input.order.amount' => 'required|integer:strict',
            'input.order.country' => 'nullable|string',
            'input.order.previous_orders' => 'nullable|integer:stric',
            'input.rules.max_amount' => 'nullable|integer:strict',
            'input.rules.blocked_countries' => 'present|array',
            'input.rules.blocked_countries.*' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return $this->sendResponse(false, $validator->errors()->first());
        }

        $orderAmount = $request->input('input.order.amount');
        $orderCountry = $request->input('input.order.country');
        $previousOrders = $request->input('input.order.previous_orders');

        $maxAmount = $request->input('input.rules.max_amount');
        $blockedCountries = $request->array('input.rules.blocked_countries');
        $flagged = false;

        if($orderAmount > $maxAmount) {
          $flagged = true;
        } else if(in_array($orderCountry, $blockedCountries)) {
            $flagged = true;
        }

        return $this->sendResponse(true, compact('flagged'));
    }
}
