<?php

namespace App\Http\Controllers;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\File;
use Illuminate\Http\Request;
use App\Services\AdminService;
use Throwable;
use App\Jobs\RunManualBackup;
use Carbon\Carbon;



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

//     public function runBackupNow()
// {
//     Artisan::call('backup:run');

//     return response()->json([
//         'status' => 'success',
//         'message' => 'تم إنشاء نسخة احتياطية جديدة بنجاح',
//         'output' => Artisan::output(),
//     ]);
// }


// public function runBackupNow()
// {
//     $exitCode = Artisan::call('backup:run');
//     $output = Artisan::output();

//     if ($exitCode !== 0) {
//         return response()->json([
//             'status' => 'error',
//             'message' => 'فشلت عملية النسخ الاحتياطي',
//             'output' => $output,
//         ], 500);
//     }

//     return response()->json([
//         'status' => 'success',
//         'message' => 'تم إنشاء نسخة احتياطية جديدة بنجاح',
//         'output' => $output,
//     ]);
// }

//   public function runBackupNow()
// {
//     // مجلد مؤقت خاص بالنسخ اليدوي.
//     $temporaryDirectory = storage_path('app/temp');

//     // ينشئ المجلد إذا لم يكن موجوداً.
//     File::ensureDirectoryExists($temporaryDirectory, 0755, true);

//     // يتأكد أنه قابل للكتابة.
//     if (! is_writable($temporaryDirectory)) {
//         return response()->json([
//             'status' => 'error',
//             'message' => 'مجلد النسخ المؤقت غير قابل للكتابة: ' . $temporaryDirectory,
//         ], 500);
//     }

//     // نفس PHP CLI الذي يعمل معه الأمر اليدوي في Terminal.
//     $phpCli = 'C:/xampp/php/php.exe';

//     if (! file_exists($phpCli)) {
//         return response()->json([
//             'status' => 'error',
//             'message' => 'لم يتم العثور على PHP CLI في: ' . $phpCli,
//         ], 500);
//     }

//     $process = new Process(
//         [
//             $phpCli,
//             base_path('artisan'),
//             'backup:run',
//             '--no-interaction',
//         ],
//         base_path(),
//         [
//             // يجعل tmpfile() يستخدم مجلد المشروع القابل للكتابة.
//             'TEMP' => $temporaryDirectory,
//             'TMP' => $temporaryDirectory,
//             'TMPDIR' => $temporaryDirectory,
//         ]
//     );

//     // النسخة قد تأخذ وقتاً، لذلك نسمح بـ10 دقائق.
//     $process->setTimeout(600);

//     try {
//         $process->run();

//         $output = trim($process->getOutput());
//         $errorOutput = trim($process->getErrorOutput());

//         if (! $process->isSuccessful()) {
//             return response()->json([
//                 'status' => 'error',
//                 'message' => 'فشلت عملية النسخ الاحتياطي',
//                 'exit_code' => $process->getExitCode(),
//                 'output' => $output,
//                 'error_output' => $errorOutput,
//             ], 500);
//         }

//         return response()->json([
//             'status' => 'success',
//             'message' => 'تم إنشاء نسخة كاملة من قاعدة البيانات وملفات المشروع بنجاح',
//             'output' => $output,
//         ]);
//     } catch (\Throwable $e) {
//         report($e);

//         return response()->json([
//             'status' => 'error',
//             'message' => $e->getMessage(),
//         ], 500);
//     }
// }
public function runBackupNow()
{
    RunManualBackup::dispatch();

    return response()->json([
        'status' => 'queued',
        'message' => 'تم طلب النسخة الاحتياطية. ستبدأ الآن في الخلفية، وستشمل قاعدة البيانات وملفات المشروع.',
    ], 202);
}


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

