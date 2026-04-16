<?php

namespace Anwar\AgentOrchestrator\Services;

class ContextManager
{
    /**
     * Get context about the customer based on their phone number.
     *
     * @param string $phone
     * @return string
     */
    public function getCustomerContext(string $phone): string
    {
        try {
            // Assuming App\Models\Customer exists in the host application
            if (class_exists('\App\Models\Customer')) {
                // Ensure the phone matching strategy handles country codes if necessary.
                // Depending on the exact db schema, you might need to clean the phone string first.
                $customer = \App\Models\Customer::where('contact_no', $phone)
                    ->orWhere('contact_no', 'like', '%' . ltrim($phone, '+') . '%')
                    ->first();

                if ($customer) {
                    $name = $customer->name ?? 'Unknown Name';
                    $context = "Talking to existing customer {$name}. ID: {$customer->id}.\n";

                    if (class_exists('\App\Models\Order')) {
                        $recentOrders = \App\Models\Order::without('claims')
                            ->with('orderItems')
                            ->where('customer_id', $customer->id)
                            ->latest('id')
                            ->take(3)
                            ->get();

                        if ($recentOrders->isNotEmpty()) {
                            $context .= "Recent Orders (for context if they ask for order status or history):\n";
                            foreach ($recentOrders as $order) {
                                $items = $order->orderItems->take(3)->map(function($item) {
                                    return $item->quantity . 'x ' . $item->product_title;
                                })->implode(', ');
                                $moreIndicator = $order->orderItems->count() > 3 ? '...' : '';
                                
                                $context .= "- Order #{$order->id}: Status: {$order->status}, Total: ¥" . ($order->total_amount ?? 0) . " on " . ($order->created_at ? $order->created_at->format('Y-m-d') : 'unknown date') . ". Items: {$items}{$moreIndicator}\n";
                            }
                        } else {
                            $context .= "Customer has no recent orders.\n";
                        }
                    }

                    return $context;
                }
            }
        } catch (\Exception $e) {
            \Anwar\AgentOrchestrator\Jobs\ProcessAsyncLog::dispatch('warning', 'Failed to load Customer context: ' . $e->getMessage());
        }

        return "Talking to an unknown customer.";
    }
}
