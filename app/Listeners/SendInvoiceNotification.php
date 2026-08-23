<?php

namespace App\Listeners;

use App\Events\InvoiceCreated;
use App\Jobs\SendNotificationJob;
use App\Models\User;
use Illuminate\Support\Facades\Log;
class SendInvoiceNotification
{
    public function handle(InvoiceCreated $event): void
    {
         Log::info('🔥 InvoiceApproved Listener FIRED', [
        'invoice_id' => $event->invoice->id,
    ]);

        $admins = User::role('admin')
            ->pluck('id')
            ->toArray();

        if (empty($admins)) {
            return;
        }

        $invoice = $event->invoice;

        SendNotificationJob::dispatch(
            $admins,
            'فاتورة مورد جديدة',
            "تم إنشاء فاتورة مورد رقم {$invoice->invoice_number} وهي بانتظار موافقتك.",
            'supplier_invoice_created',
            [
                'invoice_id' => $invoice->id,
            ]
        );
    }
}