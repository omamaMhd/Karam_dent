<?php

namespace App\Http\Controllers;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\File;
use Illuminate\Http\Request;
use App\Services\AdminService;
use Throwable;
use App\Jobs\RunManualBackup;
use Carbon\Carbon;
use App\Jobs\RestoreBackupFromGoogleDrive;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;



class AdminController extends Controller
{
     protected $service;

    public function __construct(AdminService $service)
    {
        $this->service = $service;
    }

    public function createEmployee(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'phone_number' => ['required', 'unique:users', 'regex:/^09[0-9]{8}$/'],
            'email' => 'required|email|unique:users',
            'password' => 'required|min:8',
            'role' => 'required|in:doctor,accountant,secretary,storekeeper',
            'specialization_id' => 'nullable|exists:specializations,id',
            'percentage' => 'nullable|numeric|min:0|max:100',
        ]);

        return response()->json(
            $this->service->createEmployee($data)
        );
    }
    public function employees()
    {
        return response()->json(
            $this->service->getEmployees()
        );
    }
    public function deleteUser($id)
    {
        return response()->json(
            $this->service->deleteUser($id)
        );
    }
    public function restoreUser($id)
    {
        return response()->json(
            $this->service->restoreUser($id)
        );
    }
    public function deletedUsers()
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->getDeletedUsers()
        ]);
    }
    public function updateDoctorInfo(Request $request, int $doctorId)
    {
        $request->validate([
            'percentage' => 'sometimes|numeric|min:0|max:100',
            'specialization_id' => 'sometimes|exists:specializations,id',
        ]);

        return response()->json([
            'message' => 'Doctor updated successfully',
            'data' => $this->service->updateDoctorInfo(
                $doctorId,
                $request->all()
            )
        ]);
    }

        public function getTreatmentCategories()
    {
        return response()->json(
            $this->service->getTreatmentCategories()
        );
    }

    public function createTreatmentCategory(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:treatment_categories,name',
            'price_usd' => 'required|numeric|min:0',
        ]);

        return response()->json(
            $this->service->createTreatmentCategory($validated),
            201
        );
    }


    public function updateTreatmentCategory(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255|unique:treatment_categories,name,' . $id,
            'price_usd' => 'sometimes|numeric|min:0',
        ]);

        return response()->json(
            $this->service->updateTreatmentCategory($id, $validated)
        );
    }

    public function deleteTreatmentCategory($id)
    {
        return response()->json(
            $this->service->deleteTreatmentCategory($id)
        );
    }
    public function getDoctorsPerformance(Request $request)
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        return response()->json(
            $this->service->getDoctorsPerformance(
                $validated['from'],
                $validated['to']
            )
        );
    }

    public function getPatientsCount(Request $request)
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        return response()->json(
            $this->service->getPatientsCount(
                $validated['from'],
                $validated['to']
            )
        );
    }
    
public function runBackupNow()
{
    RunManualBackup::dispatch();

    return response()->json([
        'status' => 'queued',
        'message' => 'تم طلب النسخة الاحتياطية. ستبدأ الآن في الخلفية، وستشمل قاعدة البيانات وملفات المشروع.',
    ], 202);
}
/**
 * يعرض ملفات ZIP الموجودة على قرص Google Drive، بما في ذلك الملفات
 * الموجودة داخل مجلدات فرعية مثل KaramDent/.
 */

public function listBackups()
{
    $google = Storage::disk('google');

    $backups = collect($google->allFiles(''))
        ->filter(fn (string $path) => Str::endsWith(Str::lower($path), '.zip'))
        ->map(function (string $path) use ($google) {
            return [
                // هذه القيمة الكاملة هي التي تُستخدم في restoreBackup.
                'path' => $path,
                'name' => basename($path),
                'size' => $google->size($path),
                'last_modified' => $google->lastModified($path),
            ];
        })
        ->sortByDesc('last_modified')
        ->values();

    return response()->json([
        'status' => 'success',
        'backups' => $backups,
    ]);
}
/**
 * يطلب استعادة نسخة محددة من Google Drive.
 * لا يقبل الطلب إلا إذا أرسل الأدمن نص التأكيد المطلوب.
 */
public function restoreBackup(Request $request)
{
    $data = $request->validate([
        'backup_path' => ['required', 'string'],
        'confirmation' => ['required', 'string', 'in:RESTORE_KARAM_DENT_DATA'],
    ]);

    $google = Storage::disk('google');

    $allowedBackup = collect($google->allFiles(''))
        ->contains(
            fn (string $path) => $path === $data['backup_path']
                && Str::endsWith(Str::lower($path), '.zip')
        );

    if (! $allowedBackup) {
        return response()->json([
            'status' => 'error',
            'message' => 'النسخة المطلوبة غير موجودة على Google Drive.',
        ], 404);
    }

    RestoreBackupFromGoogleDrive::dispatch($data['backup_path']);

    return response()->json([
        'status' => 'queued',
        'message' => 'تم قبول طلب الاستعادة. ستتم استعادة قاعدة البيانات والتقارير الطبية من النسخة المحددة.',
    ], 202);
}
/////////////////////////////////////////

    public function getCompletedTreatmentPlansCount()
    {
        return response()->json(
            $this->service->getCompletedTreatmentPlansCount()
        );
    }

    public function auditLogs(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'nullable|integer|exists:users,id',
            'role' => 'nullable|string', // أو integer|exists:roles,id إذا عندك جدول roles
            'user_name' => 'nullable|string|max:255',
            'user_phone' => 'nullable|string|max:255',
            'event' => 'nullable|string',
            'auditable_type' => 'nullable|string',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب سجل التدقيق بنجاح',
            'data' => $this->service->getAuditLogs($validated),
        ]);
    }


    public function deleteAuditsBeforeDate(Request $request)
    {
        $request->validate([
            'date' => ['required', 'date'],
        ]);

        try {
            $result = $this->service->deleteAuditsBeforeDate(
                $request->date
            );

            return response()->json($result);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء حذف سجلات التدقيق.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    

}

