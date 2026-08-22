<?php
namespace App\Services;

use App\Models\User;
use App\Models\Doctor;
use App\Models\Patient;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use App\Models\Treatment_Category;
use App\Models\Treatment_Session;
use App\Models\Treatment_Plan;
use OwenIt\Auditing\Models\Audit;
use App\Models\Supplier;
use App\Models\Item;
use App\Models\Invoice;
use Carbon\Carbon;
use Exception;

class AdminService
{
    public function createEmployee($data)
    {
         return DB::transaction(function () use ($data) {

        // $allowedRoles = ['doctor', 'accountant','secretary','storekeeper'];

        // if (!in_array($data['role'], $allowedRoles)) {
        //     throw new \Exception("نوع المستخدم غير صالح");
        // }
          if (
            $data['role'] === 'doctor' &&
            empty($data['specialization_id'])
        ) {
            return [
                'success' => false,
                'message' => 'يجب تحديد الاختصاص للطبيب'
            ];
        }

        // 1️⃣ إنشاء User
        $user = User::create([
            'name' => $data['name'],
            'phone_number' => $data['phone_number'],
            'email' => $data['email'] ?? null,
            'password' => Hash::make($data['password']),
            'is_verified' => true
        ]);

        // 2️⃣ assign role
        $user->assignRole($data['role']);

        // 3️⃣ doctor profile
        if ($data['role'] === 'doctor') {

            Doctor::create([
                'user_id' => $user->id,
                'specialization_id' => $data['specialization_id'],
                'percentage' => $data['percentage'] ?? 0,
                'is_active' => true
            ]);
        }

        return [
            'message' => 'تم إنشاء الموظف بنجاح',
            'user_id' => $user->id,
            'role' => $data['role']
        ];
    });
}
public function getEmployees()
{
    $employees = User::with([
        'roles',
        'doctor.specialization'
    ])
    ->whereHas('roles', function ($q) {
        $q->whereIn('name', [
            'doctor',
            'accountant',
            'secretary',
            'storekeeper'
        ]);
    })
    ->latest()
    ->get();

    return $employees->map(function ($user) {

       return array_filter([
            'id' => $user->id,
            'name' => $user->name,
            'phone_number' => $user->phone_number,
            'email' => $user->email,
            'role' => $user->roles->first()?->name,
            'doctor_info' => $user->doctor ? [
                'doctor_id' => $user->doctor->id,
                'specialization' => $user->doctor->specialization?->name,
                'percentage' => $user->doctor->percentage,
            ] : null,
        ]);
    });
}

    public function deleteUser(int $userId): array
    {
        $user = User::findOrFail($userId);

        if ($user->hasRole('admin')) {
            throw new \Exception('لا يمكن حذف الأدمن');
        }

        DB::transaction(function () use ($user) {
            // 1️⃣ إذا كان طبيب، احذف ملف الطبيب (Soft Delete)
            if ($user->doctor) {
                $user->doctor->delete();
            }

            // 2️⃣ إذا كان مريض، احذف ملف المريض (Soft Delete)
            if ($user->patient) {
                $user->patient->delete();
            }
            
            // 3️⃣ احذف المستخدم نفسه (Soft Delete)
            $user->delete();
        });

        return [
            'message' => 'User and associated profile deleted successfully'
        ];
    }

    public function restoreUser(int $userId): array
    {
        $user = User::withTrashed()->findOrFail($userId);

        DB::transaction(function () use ($user) {
            // 1️⃣ استعادة المستخدم
            $user->restore();

            // 2️⃣ استعادة سجل الطبيب إن وجد
            $doctor = Doctor::withTrashed()->where('user_id', $user->id)->first();
            if ($doctor) {
                $doctor->restore();
            }

            // 3️⃣ استعادة سجل المريض إن وجد
            $patient = Patient::withTrashed()->where('user_id', $user->id)->first();
            if ($patient) {
                $patient->restore();
            }
        });

        return [
            'message' => 'User and associated profile restored successfully'
        ];
    }

    public function getDeletedUsers()
    {
        return User::onlyTrashed()
            ->with('roles')
            ->latest('deleted_at')
            ->get()
            ->map(function ($user) {
                return [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone_number' => $user->phone_number,
                    'role' => $user->roles->first()?->name,
                    'deleted_at' => $user->deleted_at,
                ];
            });
    }


    public function updateDoctorInfo(int $doctorId, array $data)
    {
        $doctor = Doctor::with('specialization')
            ->findOrFail($doctorId);

        $doctor->update(array_filter([
            'percentage' => $data['percentage'] ?? null,
            'specialization_id' => $data['specialization_id'] ?? null,
        ], fn ($value) => !is_null($value)));

        $doctor->load('specialization');

        return [
            'doctor_id' => $doctor->id,
            'doctor_name' => $doctor->user?->name,
            'percentage' => $doctor->percentage,
            'specialization_id' => $doctor->specialization_id,
            'specialization' => $doctor->specialization?->name,
        ];
    }

    public function getTreatmentCategories()
    {
        $rate = app(ExchangeRateService::class)
            ->getCurrentUsdToSypRate()
            ->rate;

        return Treatment_Category::all()->map(function ($category) use ($rate) {
            return [
                'id' => $category->id,
                'name' => $category->name,
                'price_usd' => (float)$category->price_usd,
                'price_syp' => round($category->price_usd * $rate),
            ];
        });
    }

    public function createTreatmentCategory(array $data)
    {
        return Treatment_Category::create([
            'name' => $data['name'],
            'price_usd' => $data['price_usd'],
        ]);
    }

    public function updateTreatmentCategory(int $id, array $data)
    {
        $category = Treatment_Category::findOrFail($id);

        $category->update(array_filter([
            'name' => $data['name'] ?? null,
            'price_usd' => $data['price_usd'] ?? null,
        ], fn ($value) => !is_null($value)));

        return $category;
    }

    public function deleteTreatmentCategory(int $id)
    {
        $category = Treatment_Category::findOrFail($id);

        $category->delete();

        return [
            'message' => 'Treatment category deleted successfully.'
        ];
    }
    public function getDoctorsPerformance(?string $from = null, ?string $to = null)
    {
        $fromDate = Carbon::parse($from)->startOfDay();
        $toDate = Carbon::parse($to)->endOfDay();

        return Treatment_Session::with([
            'planItem.plan.doctor.user',
            'planItem.plan.doctor.specialization'
        ])
            ->where('status', 'completed')
            ->whereBetween('session_date', [$fromDate, $toDate])
            ->get()
            ->groupBy(function ($session) {
                return optional($session->planItem?->plan?->doctor)->id;
            })
            ->filter()
            ->map(function ($sessions) {

                $doctor = $sessions->first()->planItem->plan->doctor;

                return [
                    'doctor_name' => $doctor->user->name,
                    'specialization' => $doctor->specialization->name,
                    'completed_sessions' => $sessions->count(),
                ];
            })
            ->sortByDesc('completed_sessions')
            ->values();
    }

    public function getPatientsCount(string $from, string $to): array
    {
        $count = Patient::whereBetween('created_at', [
            Carbon::parse($from)->startOfDay(),
            Carbon::parse($to)->endOfDay(),
        ])->count();

        return [
            'total_patients' => $count,
        ];
    }

    public function getCompletedTreatmentPlansCount(): array
    {
        $count = Treatment_Plan::where('status', 'completed')->count();

        return [
            'completed_treatment_plans' => $count,
        ];
    }

    private array $fieldMappings = [
        'user_id'       => ['model' => User::class, 'with' => [], 'display' => 'name'],
        'created_by'    => ['model' => User::class, 'with' => [], 'display' => 'name'],
        'approved_by'   => ['model' => User::class, 'with' => [], 'display' => 'name'],
        'executed_by'   => ['model' => User::class, 'with' => [], 'display' => 'name'],
        'conducted_by'  => ['model' => User::class, 'with' => [], 'display' => 'name'],
        'paid_by'       => ['model' => User::class, 'with' => [], 'display' => 'name'],
        'requested_by'  => ['model' => User::class, 'with' => [], 'display' => 'name'],
        'doctor_id'     => ['model' => Doctor::class, 'with' => ['user'], 'display' => 'user.name'],
        'patient_id'    => ['model' => Patient::class, 'with' => ['user'], 'display' => 'user.name'],
        'item_id'       => ['model' => Item::class, 'with' => [], 'display' => 'name'],
        'inventory_id'  => ['model' => Inventory::class, 'with' => ['item'], 'display' => 'item.name'],
        'supplier_id'   => ['model' => Supplier::class, 'with' => [], 'display' => 'name'],
        'invoice_id'    => ['model' => Invoice::class, 'with' => ['patient.user'], 'display' => 'invoice_number'],
        'plan_id'             => ['model' => Treatment_Plan::class, 'with' => ['patient.user'], 'display' => 'name'],
        'treatment_plan_id'   => ['model' => Treatment_Plan::class, 'with' => ['patient.user'], 'display' => 'name'],
        'treatment_plans_id'  => ['model' => Treatment_Plan::class, 'with' => ['patient.user'], 'display' => 'name'],
        'salary_payment_id'   => ['model' => SalaryPayment::class, 'with' => ['employee'], 'display' => 'employee.name'],
        'exchange_rate_id'    => ['model' => \App\Models\Exchange_Rate::class, 'with' => [], 'display' => 'rate'], 
    ];

    public function getAuditLogs(array $filters = [])
    {
        $query = Audit::query()
            ->with(['user.roles'])
            ->latest('created_at')
            ->latest('id');

        // 1. تطبيق الفلاتر
        if (!empty($filters['role'])) {
            $query->whereHas('user.roles', fn($q) => $q->where('name', $filters['role']));
        }
        if (!empty($filters['user_name'])) {
            $query->whereHas('user', fn($q) => $q->where('name', 'like', "%{$filters['user_name']}%"));
        }
        if (!empty($filters['user_phone'])) {
            $query->whereHas('user', fn($q) => $q->where('phone_number', 'like', "%{$filters['user_phone']}%"));
        }
        if (!empty($filters['event'])) {
            $event = match (mb_strtolower(trim($filters['event']))) {
                'created', 'انشاء' => 'created',
                'updated', 'تعديل' => 'updated',
                'deleted', 'حذف' => 'deleted',
                'restored', 'استعادة' => 'restored',
                default => $filters['event'],
            };
            $query->where('event', $event);
        }
        if (!empty($filters['auditable_type'])) {
            $modelClass = $this->resolveAuditableClass($filters['auditable_type']);
            if ($modelClass) $query->where('auditable_type', $modelClass);
        }
        if (!empty($filters['from_date'])) $query->whereDate('created_at', '>=', $filters['from_date']);
        if (!empty($filters['to_date'])) $query->whereDate('created_at', '<=', $filters['to_date']);

        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);
        $logs = $query->paginate($perPage);

        // 2. جمع الـ IDs وتجميعها حسب "نوع الموديل" لتقليل عدد الاستعلامات
        $neededIdsByModel = [];
        $keyToModelMap = [];

        foreach ($logs->items() as $audit) {
            foreach ([$audit->old_values, $audit->new_values] as $values) {
                $values = is_string($values) ? json_decode($values, true) : $values;
                
                if (is_array($values)) {
                    foreach ($values as $key => $value) {
                        if (isset($this->fieldMappings[$key]) && !is_null($value)) {
                            $modelClass = $this->fieldMappings[$key]['model'];
                            if (!isset($neededIdsByModel[$modelClass])) {
                                $neededIdsByModel[$modelClass] = [];
                                $keyToModelMap[$key] = $modelClass;
                            }
                            $neededIdsByModel[$modelClass][] = $value;
                        }
                    }
                }
            }
        }

        // 3. جلب البيانات دفعة واحدة
        $cachedModels = [];
        foreach ($neededIdsByModel as $modelClass => $ids) {
            $uniqueIds = array_unique($ids);
            $firstKey = array_search($modelClass, $keyToModelMap);
            $relations = $this->fieldMappings[$firstKey]['with'] ?? [];

            $cachedModels[$modelClass] = $modelClass::with($relations)
                ->whereIn('id', $uniqueIds)
                ->get()
                ->keyBy('id');
        }

        // 4. تنسيق الرد النهائي
        $logs->getCollection()->transform(function ($audit) use ($cachedModels) {
            $userName = $audit->user?->name ?? 'النظام';
            $eventLabel = $this->getAuditEventLabel($audit->event);

            return [
                'audit_id'          => $audit->id,
                'action_summary'    => $this->generateActionSummary($audit, $userName, $eventLabel), // 👈 الملخص الجاهز للفرونت إند
                'auditable_type'    => $this->getAuditableName($audit->auditable_type),
                'auditable_details' => $this->getAuditableDetails($audit),
                'user' => $audit->user ? [
                    'user_id' => $audit->user->id,
                    'name'    => $audit->user->name,
                    'role'    => $audit->user->roles->first()?->name,
                ] : null,
                'event'         => $audit->event,
                'event_label'   => $eventLabel,
                'old_values'    => $this->enrichValues($audit->old_values, $cachedModels),
                'new_values'    => $this->enrichValues($audit->new_values, $cachedModels),
                'url'           => $audit->url,
                'created_at'    => Carbon::parse($audit->created_at)->format('Y-m-d H:i:s'),
            ];
        });

        return $logs;
    }

    /**
     * 👈 الدالة الجديدة: توليد ملخص نصي طبيعي ومقروء للحركة
     */
    private function generateActionSummary($audit, string $userName, string $eventLabel): string
    {
        if (!$audit->auditable) {
            $baseType = $this->getAuditableName($audit->auditable_type) ?? 'سجل';
            return "قام {$userName} بـ {$eventLabel} {$baseType} (سجل محذوف)";
        }

        $model = $audit->auditable;
        $baseType = $this->getAuditableName(get_class($model));

        $targetDescription = match (get_class($model)) {
            \App\Models\Invoice::class         => "فاتورة رقم {$model->invoice_number} للمريض {$model->patient_name}",
            \App\Models\Payment::class         => "دفعة مالية للفاتورة رقم {$model->invoice?->invoice_number}",
            \App\Models\Doctor::class          => "طبيب باسم {$model->user?->name}",
            \App\Models\Patient::class         => "مريض باسم {$model->user?->name}",
            \App\Models\Item::class            => "مادة باسم {$model->name}",
            \App\Models\Treatment_Plan::class  => "خطة علاج \"{$model->name}\" للمريض {$model->patient?->user?->name}",
            \App\Models\MaterialRequest::class => "طلب مواد رقم {$model->requisition_number}",
            \App\Models\User::class            => "مستخدم باسم {$model->name}",
            \App\Models\Supplier::class        => "مورد باسم {$model->name}",
            \App\Models\Medical_Report::class  => "تقرير طبي للمريض {$model->patient?->user?->name}",
            \App\Models\SalaryPayment::class   => "راتب للموظف {$model->employee?->name}",
            default                            => "{$baseType} رقم {$model->id}",
        };

        return "قام {$userName} بـ {$eventLabel} {$targetDescription}";
    }

    /**
     * ترجع تفاصيل العنصر كـ Object منفصل لتسهيل العرض على الفرونت إند
     */
    private function getAuditableDetails($audit): array
    {
        if (!$audit->auditable) {
            return ['type' => $this->getAuditableName($audit->auditable_type), 'status' => 'سجل محذوف'];
        }

        $model = $audit->auditable;
        $baseType = $this->getAuditableName(get_class($model));

        return match (get_class($model)) {
            \App\Models\Invoice::class => [
                'type'           => $baseType,
                'invoice_number' => $model->invoice_number,
                'patient_name'   => $model->patient_name,
            ],
            \App\Models\Payment::class => [
                'type'           => 'دفعة مالية',
                'invoice_number' => $model->invoice?->invoice_number ?? 'غير معروف',
                'patient_name'   => $model->invoice?->patient_name ?? 'غير معروف',
            ],
            \App\Models\Doctor::class => [
                'type'  => $baseType,
                'name'  => $model->user?->name ?? 'غير معروف',
            ],
            \App\Models\Patient::class => [
                'type'  => $baseType,
                'name'  => $model->user?->name ?? 'غير معروف',
            ],
            \App\Models\Item::class => [
                'type' => $baseType,
                'name' => $model->name,
            ],
            \App\Models\Treatment_Plan::class => [
                'type'         => $baseType,
                'plan_name'    => $model->name,
                'patient_name' => $model->patient?->user?->name ?? 'غير معروف',
            ],
            \App\Models\MaterialRequest::class => [
                'type'               => $baseType,
                'requisition_number' => $model->requisition_number,
                'doctor_name'        => $model->doctor?->user?->name ?? 'غير معروف',
            ],
            \App\Models\User::class => [
                'type' => $baseType,
                'name' => $model->name,
            ],
            \App\Models\Supplier::class => [
                'type' => $baseType,
                'name' => $model->name,
            ],
            \App\Models\Medical_Report::class => [
                'type'         => $baseType,
                'patient_name' => $model->patient?->user?->name ?? 'غير معروف',
            ],
            \App\Models\SalaryPayment::class => [
                'type'          => $baseType,
                'employee_name' => $model->employee?->name ?? 'غير معروف',
            ],
            default => [
                'type' => $baseType,
                'id'   => $model->id,
            ],
        };
    }

    private function enrichValues(?array $values, array $cachedModels): array
    {
        if (empty($values) || !is_array($values)) {
            return $values ?? [];
        }

        $enriched = $values;

        foreach ($values as $key => $value) {
            if (isset($this->fieldMappings[$key]) && !is_null($value)) {
                $mapping = $this->fieldMappings[$key];
                $modelClass = $mapping['model'];
                $collection = $cachedModels[$modelClass] ?? collect();

                if ($collection->has($value)) {
                    $model = $collection->get($value);
                    $displayName = $this->resolveNestedValue($model, $mapping['display']);
                    
                    $nameKey = str_replace('_id', '_name', $key);
                    if (in_array($key, ['created_by', 'approved_by', 'executed_by', 'conducted_by', 'paid_by', 'requested_by'])) {
                        $nameKey = $key . '_name';
                    }
                    
                    $enriched[$nameKey] = $displayName;
                }
            }
        }

        return $enriched;
    }

    private function resolveNestedValue($model, string $path): string
    {
        $segments = explode('.', $path);
        $value = $model;

        foreach ($segments as $segment) {
            if (is_object($value) && isset($value->{$segment})) {
                $value = $value->{$segment};
            } elseif (is_array($value) && isset($value[$segment])) {
                $value = $value[$segment];
            } else {
                return 'غير معروف';
            }
        }

        return $value ?: 'غير معروف';
    }

    private function getAuditEventLabel(string $event): string
    {
        return match ($event) {
            'created' => 'إنشاء',
            'updated' => 'تعديل',
            'deleted' => 'حذف',
            'restored' => 'استعادة',
            default => $event,
        };
    }

    private function getAuditableName(?string $type): ?string
    {
        if (!$type) return null;
        return match (class_basename($type)) {
            'User' => 'مستخدم',
            'Patient' => 'مريض',
            'Doctor' => 'طبيب',
            'Doctor_Schedule' => 'أوقات طبيب',
            'Doctor_Payment' => 'دفعة طبيب',
            'Doctor_Earning' => 'أرباح طبيب',
            'Treatment_Plan' => 'خطة علاج',
            'Invoice' => 'فاتورة',
            'Payment' => 'دفعة فاتورة مريض',
            'Item' => 'مادة',
            'Inventory' => 'مخزون',
            'InventoryTransaction' => 'حركة مخزون',
            'InventoryAudit' => 'جرد مخزون',
            'AuditItem' => 'عنصر جرد',
            'Supplier' => 'مورد',
            'MaterialRequest' => 'طلب مواد',
            'Disposal' => 'إتلاف مواد',
            'SalaryPayment' => 'راتب موظف',
            'SalaryAdjustment' => 'تعديل راتب',
            'Medical_Report' => 'تقرير طبي',
            'ToothTreatment' => 'خارطة سنية',
            default => class_basename($type),
        };
    }

    private function resolveAuditableClass(string $key): ?string
    {
        $key = mb_strtolower(trim($key));
        return match ($key) {
            'user', 'مستخدم' => User::class,
            'patient', 'مريض' => \App\Models\Patient::class,
            'doctor', 'طبيب' => Doctor::class,
            'doctor_schedule', 'أوقات طبيب' => \App\Models\Doctor_Schedule::class,
            'doctor_payment', 'دفعة طبيب' => \App\Models\Doctor_Payment::class,
            'doctor_earning', 'أرباح طبيب' => \App\Models\Doctor_Earning::class,
            'treatment_plan', 'خطة علاج' => Treatment_Plan::class,
            'invoice', 'فاتورة' => \App\Models\Invoice::class,
            'payment', 'دفعة فاتورة مريض' => \App\Models\Payment::class,
            'item', 'مادة' => \App\Models\Item::class,
            'inventory', 'مخزون' => \App\Models\Inventory::class,
            'inventory_transaction', 'حركة مخزون' => \App\Models\InventoryTransaction::class,
            'inventory_audit', 'جرد مخزون' => \App\Models\InventoryAudit::class,
            'audit_item', 'عنصر جرد' => \App\Models\AuditItem::class,
            'supplier', 'مورد' => Supplier::class,
            'material_request', 'طلب مواد' => MaterialRequest::class,
            'disposal', 'إتلاف' => \App\Models\Disposal::class,
            'salary_payment', 'راتب موظف' => SalaryPayment::class,
            'salary_adjustment', 'تعديل راتب' => \App\Models\SalaryAdjustment::class,
            'medical_report', 'تقرير طبي' => \App\Models\Medical_Report::class,
            'tooth_treatment', 'خارطة سنية' => \App\Models\ToothTreatment::class,
            default => null,
        };
    }


        public function deleteAuditsBeforeDate(string $date): array
    {
        $date = Carbon::parse($date)->startOfDay();

        // عدد السجلات التي سيتم حذفها
        $count = Audit::where('created_at', '<', $date)->count();

        if ($count === 0) {
            return [
                'success' => false,
                'message' => 'لا توجد سجلات تدقيق قبل التاريخ المحدد.',
                'deleted_count' => 0,
            ];
        }

        // حذف على دفعات حتى لا نضغط على قاعدة البيانات
        $deleted = 0;

        do {
            $ids = Audit::where('created_at', '<', $date)
                ->orderBy('id')
                ->limit(1000)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += Audit::whereIn('id', $ids)->delete();

        } while ($ids->count() === 1000);

        return [
            'success' => true,
            'message' => "تم حذف سجلات التدقيق الأقدم من {$date->format('Y-m-d')} بنجاح.",
            'deleted_count' => $deleted,
        ];
    }

}
    /*
    private array $fieldMappings = [
        'user_id'       => ['model' => User::class, 'with' => [], 'display' => 'name'],
        'created_by'    => ['model' => User::class, 'with' => [], 'display' => 'name'],
        'approved_by'   => ['model' => User::class, 'with' => [], 'display' => 'name'],
        'executed_by'   => ['model' => User::class, 'with' => [], 'display' => 'name'],
        'conducted_by'  => ['model' => User::class, 'with' => [], 'display' => 'name'],
        'paid_by'       => ['model' => User::class, 'with' => [], 'display' => 'name'],
        'requested_by'  => ['model' => User::class, 'with' => [], 'display' => 'name'],
        'doctor_id'     => ['model' => Doctor::class, 'with' => ['user'], 'display' => 'user.name'],
        'patient_id'    => ['model' => Patient::class, 'with' => ['user'], 'display' => 'user.name'],
        'item_id'       => ['model' => Item::class, 'with' => [], 'display' => 'name'],
        'inventory_id'  => ['model' => Inventory::class, 'with' => ['item'], 'display' => 'item.name'],
        'supplier_id'   => ['model' => Supplier::class, 'with' => [], 'display' => 'name'],
        'invoice_id'    => ['model' => Invoice::class, 'with' => ['patient.user'], 'display' => 'invoice_number'],
        'plan_id'             => ['model' => Treatment_Plan::class, 'with' => ['patient.user'], 'display' => 'name'],
        'treatment_plan_id'   => ['model' => Treatment_Plan::class, 'with' => ['patient.user'], 'display' => 'name'],
        'treatment_plans_id'  => ['model' => Treatment_Plan::class, 'with' => ['patient.user'], 'display' => 'name'],
        'salary_payment_id'   => ['model' => SalaryPayment::class, 'with' => ['employee'], 'display' => 'employee.name'],
        'exchange_rate_id'    => ['model' => \App\Models\Exchange_Rate::class, 'with' => [], 'display' => 'rate'], 
    ];

    public function getAuditLogs(array $filters = [])
    {
        $query = Audit::query()
            ->with(['user.roles'])
            ->latest('created_at')
            ->latest('id');

        // 1. تطبيق الفلاتر
        if (!empty($filters['role'])) {
            $query->whereHas('user.roles', fn($q) => $q->where('name', $filters['role']));
        }
        if (!empty($filters['user_name'])) {
            $query->whereHas('user', fn($q) => $q->where('name', 'like', "%{$filters['user_name']}%"));
        }
        if (!empty($filters['user_phone'])) {
            $query->whereHas('user', fn($q) => $q->where('phone_number', 'like', "%{$filters['user_phone']}%"));
        }
        if (!empty($filters['event'])) {
            $event = match (mb_strtolower(trim($filters['event']))) {
                'created', 'انشاء' => 'created',
                'updated', 'تعديل' => 'updated',
                'deleted', 'حذف' => 'deleted',
                'restored', 'استعادة' => 'restored',
                default => $filters['event'],
            };
            $query->where('event', $event);
        }
        if (!empty($filters['auditable_type'])) {
            $modelClass = $this->resolveAuditableClass($filters['auditable_type']);
            if ($modelClass) $query->where('auditable_type', $modelClass);
        }
        if (!empty($filters['from_date'])) $query->whereDate('created_at', '>=', $filters['from_date']);
        if (!empty($filters['to_date'])) $query->whereDate('created_at', '<=', $filters['to_date']);

        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);
        $logs = $query->paginate($perPage);

        // 2. جمع الـ IDs وتجميعها حسب "نوع الموديل" لتقليل عدد الاستعلامات
        $neededIdsByModel = [];
        $keyToModelMap = [];

        foreach ($logs->items() as $audit) {
            foreach ([$audit->old_values, $audit->new_values] as $values) {
                $values = is_string($values) ? json_decode($values, true) : $values;
                
                if (is_array($values)) {
                    foreach ($values as $key => $value) {
                        if (isset($this->fieldMappings[$key]) && !is_null($value)) {
                            $modelClass = $this->fieldMappings[$key]['model'];
                            if (!isset($neededIdsByModel[$modelClass])) {
                                $neededIdsByModel[$modelClass] = [];
                                $keyToModelMap[$key] = $modelClass;
                            }
                            $neededIdsByModel[$modelClass][] = $value;
                        }
                    }
                }
            }
        }

        // 3. جلب البيانات دفعة واحدة
        $cachedModels = [];
        foreach ($neededIdsByModel as $modelClass => $ids) {
            $uniqueIds = array_unique($ids);
            $firstKey = array_search($modelClass, $keyToModelMap);
            $relations = $this->fieldMappings[$firstKey]['with'] ?? [];

            $cachedModels[$modelClass] = $modelClass::with($relations)
                ->whereIn('id', $uniqueIds)
                ->get()
                ->keyBy('id');
        }

        // 4. تنسيق الرد النهائي
        $logs->getCollection()->transform(function ($audit) use ($cachedModels) {
            return [
                'audit_id'       => $audit->id,
                'auditable_type' => $this->getAuditableName($audit->auditable_type), // اسم النوع فقط (مثال: فاتورة)
                'auditable_details' => $this->getAuditableDetails($audit), // 👈 هنا التفاصيل المفصلة للفرونت إند
                'user' => $audit->user ? [
                    'user_id'      => $audit->user->id,
                    'name'         => $audit->user->name,
                    'role'         => $audit->user->roles->first()?->name,
                ] : null,
                'event'         => $audit->event,
                'event_label'   => $this->getAuditEventLabel($audit->event),
                'old_values'    => $this->enrichValues($audit->old_values, $cachedModels),
                'new_values'    => $this->enrichValues($audit->new_values, $cachedModels),
                'url'           => $audit->url,
               // 'ip_address'    => $audit->ip_address,
                'created_at'    => $audit->created_at->format('Y-m-d H:i:s'),
            ];
        });

        return $logs;
    }


    private function getAuditableDetails($audit): array
    {
        if (!$audit->auditable) {
            return ['type' => $this->getAuditableName($audit->auditable_type), 'status' => 'سجل محذوف'];
        }

        $model = $audit->auditable;
        $baseType = $this->getAuditableName(get_class($model));

        return match (get_class($model)) {
            \App\Models\Invoice::class => [
                'type'           => $baseType,
                'invoice_number' => $model->invoice_number,
                'patient_name'   => $model->patient_name,
            ],
            \App\Models\Payment::class => [
                'type'           => 'دفعة مالية',
                'invoice_number' => $model->invoice?->invoice_number ?? 'غير معروف',
                'patient_name'   => $model->invoice?->patient_name ?? 'غير معروف',
            ],
            \App\Models\Doctor::class => [
                'type'  => $baseType,
                'name'  => $model->user?->name ?? 'غير معروف',
            ],
            \App\Models\Patient::class => [
                'type'  => $baseType,
                'name'  => $model->user?->name ?? 'غير معروف',
            ],
            \App\Models\Item::class => [
                'type' => $baseType,
                'name' => $model->name,
            ],
            \App\Models\Treatment_Plan::class => [
                'type'         => $baseType,
                'plan_name'    => $model->name,
                'patient_name' => $model->patient?->user?->name ?? 'غير معروف',
            ],
            \App\Models\MaterialRequest::class => [
                'type'               => $baseType,
                'requisition_number' => $model->requisition_number,
                'doctor_name'        => $model->doctor?->user?->name ?? 'غير معروف',
            ],
            \App\Models\User::class => [
                'type' => $baseType,
                'name' => $model->name,
            ],
            \App\Models\Supplier::class => [
                'type' => $baseType,
                'name' => $model->name,
            ],
            \App\Models\Medical_Report::class => [
                'type'         => $baseType,
                'patient_name' => $model->patient?->user?->name ?? 'غير معروف',
            ],
            \App\Models\SalaryPayment::class => [
                'type'          => $baseType,
                'employee_name' => $model->employee?->name ?? 'غير معروف',
            ],
            default => [
                'type' => $baseType,
                'id'   => $model->id,
            ],
        };
    }

    private function enrichValues(?array $values, array $cachedModels): array
    {
        if (empty($values) || !is_array($values)) {
            return $values ?? [];
        }

        $enriched = $values;

        foreach ($values as $key => $value) {
            if (isset($this->fieldMappings[$key]) && !is_null($value)) {
                $mapping = $this->fieldMappings[$key];
                $modelClass = $mapping['model'];
                $collection = $cachedModels[$modelClass] ?? collect();

                if ($collection->has($value)) {
                    $model = $collection->get($value);
                    $displayName = $this->resolveNestedValue($model, $mapping['display']);
                    
                    $nameKey = str_replace('_id', '_name', $key);
                    if (in_array($key, ['created_by', 'approved_by', 'executed_by', 'conducted_by', 'paid_by', 'requested_by'])) {
                        $nameKey = $key . '_name';
                    }
                    
                    $enriched[$nameKey] = $displayName;
                }
            }
        }

        return $enriched;
    }

    private function resolveNestedValue($model, string $path): string
    {
        $segments = explode('.', $path);
        $value = $model;

        foreach ($segments as $segment) {
            if (is_object($value) && isset($value->{$segment})) {
                $value = $value->{$segment};
            } elseif (is_array($value) && isset($value[$segment])) {
                $value = $value[$segment];
            } else {
                return 'غير معروف';
            }
        }

        return $value ?: 'غير معروف';
    }

    private function getAuditEventLabel(string $event): string
    {
        return match ($event) {
            'created' => 'إنشاء',
            'updated' => 'تعديل',
            'deleted' => 'حذف',
            'restored' => 'استعادة',
            default => $event,
        };
    }

    private function getAuditableName(?string $type): ?string
    {
        if (!$type) return null;
        return match (class_basename($type)) {
            'User' => 'مستخدم',
            'Patient' => 'مريض',
            'Doctor' => 'طبيب',
            'Doctor_Schedule' => 'أوقات طبيب',
            'Doctor_Payment' => 'دفعة طبيب',
            'Doctor_Earning' => 'أرباح طبيب',
            'Treatment_Plan' => 'خطة علاج',
            'Invoice' => 'فاتورة',
            'Payment' => 'دفعة فاتورة مريض',
            'Item' => 'مادة',
            'Inventory' => 'مخزون',
            'InventoryTransaction' => 'حركة مخزون',
            'InventoryAudit' => 'جرد مخزون',
            'AuditItem' => 'عنصر جرد',
            'Supplier' => 'مورد',
            'MaterialRequest' => 'طلب مواد',
            'Disposal' => 'إتلاف مواد',
            'SalaryPayment' => 'راتب موظف',
            'SalaryAdjustment' => 'تعديل راتب',
            'Medical_Report' => 'تقرير طبي',
            'ToothTreatment' => 'خارطة سنية',
            default => class_basename($type),
        };
    }

    private function resolveAuditableClass(string $key): ?string
    {
        $key = mb_strtolower(trim($key));
        return match ($key) {
            'user', 'مستخدم' => User::class,
            'patient', 'مريض' => \App\Models\Patient::class,
            'doctor', 'طبيب' => Doctor::class,
            'doctor_schedule', 'أوقات طبيب' => \App\Models\Doctor_Schedule::class,
            'doctor_payment', 'دفعة طبيب' => \App\Models\Doctor_Payment::class,
            'doctor_earning', 'أرباح طبيب' => \App\Models\Doctor_Earning::class,
            'treatment_plan', 'خطة علاج' => Treatment_Plan::class,
            'invoice', 'فاتورة' => \App\Models\Invoice::class,
            'payment', 'دفعة فاتورة مريض' => \App\Models\Payment::class,
            'item', 'مادة' => \App\Models\Item::class,
            'inventory', 'مخزون' => \App\Models\Inventory::class,
            'inventory_transaction', 'حركة مخزون' => \App\Models\InventoryTransaction::class,
            'inventory_audit', 'جرد مخزون' => \App\Models\InventoryAudit::class,
            'audit_item', 'عنصر جرد' => \App\Models\AuditItem::class,
            'supplier', 'مورد' => Supplier::class,
            'material_request', 'طلب مواد' => MaterialRequest::class,
            'disposal', 'إتلاف' => \App\Models\Disposal::class,
            'salary_payment', 'راتب موظف' => SalaryPayment::class,
            'salary_adjustment', 'تعديل راتب' => \App\Models\SalaryAdjustment::class,
            'medical_report', 'تقرير طبي' => \App\Models\Medical_Report::class,
            'tooth_treatment', 'خارطة سنية' => \App\Models\ToothTreatment::class,
            default => null,
        };
    }


    
}
    */