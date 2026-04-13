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
                    return "Talking to existing customer {$name}. ID: {$customer->id}";
                }
            }
        } catch (\Exception $e) {
            \Anwar\AgentOrchestrator\Jobs\ProcessAsyncLog::dispatch('warning', 'Failed to load Customer context: ' . $e->getMessage());
        }

        return "Talking to an unknown customer.";
    }
}
