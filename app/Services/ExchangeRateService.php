<?php

namespace App\Services;

use App\Models\Exchange_Rate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExchangeRateService
{
    public function getCurrentUsdToSypRate(): Exchange_Rate
    {
        return $this->getCurrentUsdToSypRateWithMeta()['rate'];
    }

    public function getCurrentUsdToSypRateWithMeta(bool $forceRefresh = false): array
    {
        if ($forceRefresh) {
            Cache::forget('exchange_rate:usd_syp');
        }

        // 1. محاولة جلب السعر من الكاش أولاً (أسرع خطوة)
        $cached = Cache::get('exchange_rate:usd_syp');
        if ($cached !== null) {
            return $cached;
        }

        // 2. إذا لم يكن موجوداً، نحاول الحصول على "قفل" (Lock) لمدة 15 ثانية
        $lock = Cache::lock('exchange_rate:usd_syp:lock', 15);

        if ($lock->get()) {
            try {
                // هذا الجزء سيُنفذ بواسطة "طلب واحد فقط" من بين كل الطلبات المتزامنة
                $result = $this->buildRateResult();
                
                // حفظ النتيجة في الكاش لمدة 8 ساعات
                Cache::put('exchange_rate:usd_syp', $result, now()->addHours(8));
                
                return $result;
            } finally {
                // تحرير القفل في جميع الأحوال (حتى لو حدث خطأ) لمنع تعليق النظام
                $lock->release();
            }
        }

        // 3. إذا لم نستطع الحصول على القفل (معنى ذلك أن طلباً آخر يقوم بالتحديث الآن)
        // ننتظر بحد أقصى 5 ثوانٍ حتى ينتهي الطلب الآخر
        $lock->block(5); 
        
        // نعيد المحاولة لجلب القيمة من الكاش، أو نرجع آخر سعر محفوظ كحل طوارئ
        return Cache::get('exchange_rate:usd_syp') ?? $this->getStaleRateFallback();
    }

    /**
     * دالة مساعدة لبناء نتيجة السعر (جلب من API أو العودة للسعر القديم)
     */
    private function buildRateResult(): array
    {
        $apiRate = $this->fetchUsdToSypFromSPToday();

        if ($apiRate !== null) {
            $freshRate = Exchange_Rate::create([
                'base_currency' => 'USD',
                'target_currency' => 'SYP',
                'rate' => $apiRate,
                'source' => 'sptoday',
                'fetched_at' => now(),
            ]);

            return [
                'rate' => $freshRate,
                'is_stale' => false,
                'warning' => null,
            ];
        }

        // إذا فشل الـ API، نعود للسعر القديم
        return $this->getStaleRateFallback();
    }

    /**
     * دالة مساعدة لجلب آخر سعر محفوظ كحل طوارئ (Fallback)
     */
    private function getStaleRateFallback(): array
    {
        $lastStoredRate = Exchange_Rate::where('base_currency', 'USD')
            ->where('target_currency', 'SYP')
            ->orderByDesc('fetched_at')
            ->first();

        if ($lastStoredRate) {
            $fetchedAt = optional($lastStoredRate->fetched_at)->format('Y-m-d H:i:s');

            return [
                'rate' => $lastStoredRate,
                'is_stale' => true,
                'warning' => 'تعذر جلب سعر جديد من API الخارجي، تم استخدام آخر سعر محفوظ بتاريخ ' . $fetchedAt,
            ];
        }

        throw new \Exception('تعذر جلب سعر الصرف من API ولا يوجد سعر محفوظ مسبقا');
    }

    /**
     * الدالة الجديدة لجلب البيانات من موقع الليرة اليوم (SPToday)
     */
    private function fetchUsdToSypFromSPToday(): ?float
    {
        $baseUrl = rtrim((string) config('services.sptoday.base_url'), '/');
        $apiKey = config('services.sptoday.api_key');
        $timeout = (int) config('services.sptoday.timeout', 10);

        $url = $baseUrl . '/overview';

        try {
            $response = Http::timeout($timeout)
                ->withHeaders([
                    'X-API-Key' => $apiKey
                ])
                ->acceptJson()
                ->get($url);

            if ($response->successful()) {
                $payload = $response->json();

                if (isset($payload['ok']) && $payload['ok'] === true && isset($payload['data']['rates'])) {
                    foreach ($payload['data']['rates'] as $rate) {
                        if (isset($rate['code']) && strtoupper($rate['code']) === 'USD') {
                            if (isset($rate['cities']['damascus']['sell'])) {
                                $sellRate = $rate['cities']['damascus']['sell'];
                                
                                if (is_numeric($sellRate) && $sellRate > 100) {
                                    return (float) $sellRate;
                                }
                            }
                        }
                    }
                }
            } else {
                Log::error('SPToday API Failed. Status: ' . $response->status() . ' Body: ' . $response->body());
            }
        } catch (\Throwable $e) {
            Log::error('SPToday API Exception: ' . $e->getMessage());
        }

        return null;
    }
}
