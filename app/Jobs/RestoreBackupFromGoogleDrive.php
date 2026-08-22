<?php

// namespace App\Jobs;

// use Illuminate\Bus\Queueable;
// use Illuminate\Contracts\Queue\ShouldQueue;
// use Illuminate\Foundation\Bus\Dispatchable;
// use Illuminate\Queue\InteractsWithQueue;
// use Illuminate\Queue\Middleware\WithoutOverlapping;
// use Illuminate\Queue\SerializesModels;
// use Illuminate\Support\Facades\Artisan;
// use Illuminate\Support\Facades\File;
// use Illuminate\Support\Facades\Storage;
// use Illuminate\Support\Str;
// use RuntimeException;
// use Symfony\Component\Process\Process;
// use ZipArchive;

// class RestoreBackupFromGoogleDrive implements ShouldQueue
// {
//     use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

//     public $timeout = 60;


//     public $tries = 1;

//     public function __construct(
//         public string $backupPath,
//     ) {
//     }

//     public function middleware(): array
//     {
//         return [
//             (new WithoutOverlapping('restore-backup-from-google-drive'))
//                 ->expireAfter(1800)
//                 ->dontRelease(),
//         ];
//     }

//     public function handle(): void
//     {
//         $google = Storage::disk('google');

//         if (! $google->exists($this->backupPath)) {
//             throw new RuntimeException('ملف النسخة المختار غير موجود على Google Drive.');
//         }

//         // // 1) نسخة أمان للوضع الحالي قبل أن نلمس أي بيانات.
//         // $safetyExitCode = Artisan::call('backup:run');
//         // if ($safetyExitCode !== 0) {
//         //     throw new RuntimeException(
//         //         "لم تُنشأ نسخة الأمان قبل الاستعادة. تم إلغاء الاستعادة.\n" . Artisan::output()
//         //     );
//         // }

//         $restoreId = now()->format('Ymd_His') . '_' . Str::random(8);
//         $restoreRoot = storage_path("app/restore-temp/{$restoreId}");
//         $archivePath = "{$restoreRoot}/backup.zip";
//         $stagedReportsPath = "{$restoreRoot}/medical_reports";
//         $sqlPath = "{$restoreRoot}/database.sql";
//         $currentReportsSafetyPath = storage_path("app/restore-safety/{$restoreId}/medical_reports");
//         $currentReportsPath = storage_path('app/public/medical_reports');

//         File::ensureDirectoryExists($restoreRoot, 0755, true);
//         File::ensureDirectoryExists($stagedReportsPath, 0755, true);

//         try {
//             // 2) تنزيل الأرشيف المختار من Google Drive إلى ملف مؤقت.
//             // $readStream = $google->readStream($this->backupPath);
//             // if ($readStream === false) {
//             //     throw new RuntimeException('تعذر قراءة النسخة من Google Drive.');
//             // }

//             // $writeStream = fopen($archivePath, 'wb');
//             // if ($writeStream === false) {
//             //     throw new RuntimeException('تعذر إنشاء الملف المؤقت للاستعادة.');
//             // }

//             // stream_copy_to_stream($readStream, $writeStream);
//             // fclose($readStream);
//             // fclose($writeStream);
//             // تنزيل الملف كاملاً من Google Drive قبل محاولة فتحه كـ ZIP.
// $expectedSize = $google->size($this->backupPath);
// $contents = $google->get($this->backupPath);

// if (! is_string($contents) || $contents === '') {
//     throw new RuntimeException('تعذر تنزيل محتوى النسخة من Google Drive.');
// }

// $writtenBytes = File::put($archivePath, $contents);
// $actualSize = File::size($archivePath);

// if ($writtenBytes === false || $actualSize !== $expectedSize) {
//     throw new RuntimeException(
//         "تنزيل ملف النسخة من Google Drive غير مكتمل. " .
//         "المتوقع: {$expectedSize} بايت، والمُنزل: {$actualSize} بايت."
//     );
// }

// // أي ملف ZIP يبدأ بـ PK.
// $signature = file_get_contents($archivePath, false, null, 0, 2);

// if ($signature !== 'PK') {
//     throw new RuntimeException('الملف الذي تم تنزيله من Google Drive ليس ZIP صالحاً.');
// }


//             // 3) التحقق من الأرشيف واستخراج SQL وملفات المرضى فقط.
//             $zip = new ZipArchive();
//             if ($zip->open($archivePath) !== true) {
//                 throw new RuntimeException('ملف النسخة ليس ZIP صالحاً أو لا يمكن فتحه.');
//             }

//             $sqlEntry = null;
//            // $reportsPrefix = 'medical_reports/';
//            $reportsPrefixes = [
//     // النسخ الجديدة الصغيرة بعد تعديل backup.php
//     'medical_reports/',

//     // النسخ القديمة التي كانت تأخذ مسار Windows الكامل
//     'C:/Karam_dent/storage/app/public/medical_reports/',
// ];

//             $reportsFound = 0;

//             for ($i = 0; $i < $zip->numFiles; $i++) {
//                // $entryName = str_replace('\\', '/', $zip->getNameIndex($i));
//                $rawEntryName = $zip->getNameIndex($i);
// $entryName = str_replace('\\', '/', $rawEntryName);

//                 $lowerName = strtolower($entryName);

//                 if (str_starts_with($lowerName, 'db-dumps/') && str_ends_with($lowerName, '.sql')) {
//                   //  $sqlEntry = $entryName;
//                   $sqlEntry = $rawEntryName;

//                 }

//                // if (str_starts_with($entryName, $reportsPrefix)) {
//                $matchingPrefix = null;

// foreach ($reportsPrefixes as $candidatePrefix) {
//     if (str_starts_with($entryName, $candidatePrefix)) {
//         $matchingPrefix = $candidatePrefix;
//         break;
//     }
// }

// if ($matchingPrefix !== null) {

//                     $relativePath = substr($entryName, strlen($reportsPrefix));

//                     // يمنع أي Path Traversal داخل ZIP.
//                     if ($relativePath === '' || str_contains($relativePath, '..')) {
//                         continue;
//                     }

//                     $destination = $stagedReportsPath . DIRECTORY_SEPARATOR
//                         . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

//                     if (str_ends_with($entryName, '/')) {
//                         File::ensureDirectoryExists($destination, 0755, true);
//                         continue;
//                     }

//                     File::ensureDirectoryExists(dirname($destination), 0755, true);

//                     $input = $zip->getStream($rawEntryName);
//                     $output = fopen($destination, 'wb');

//                     if ($input === false || $output === false) {
//                         throw new RuntimeException("تعذر استخراج ملف المرضى: {$entryName}");
//                     }

//                     stream_copy_to_stream($input, $output);
//                     fclose($input);
//                     fclose($output);
//                     $reportsFound++;
//                 }
//             }

//             if ($sqlEntry === null) {
//                 $zip->close();
//                 throw new RuntimeException('لم يتم العثور على ملف SQL داخل db-dumps في النسخة المختارة.');
//             }

//             // $sqlInput = $zip->getStream($sqlEntry);
//             // $sqlOutput = fopen($sqlPath, 'wb');

//             // if ($sqlInput === false || $sqlOutput === false) {
//             //     $zip->close();
//             //     throw new RuntimeException('تعذر استخراج ملف قاعدة البيانات من النسخة.');
//             // }

//             // stream_copy_to_stream($sqlInput, $sqlOutput);
//             // fclose($sqlInput);
//             // fclose($sqlOutput);
//             // $zip->close();
//             $sqlInput = $zip->getStream($sqlEntry);

// if ($sqlInput === false) {
//     $zip->close();

//     throw new RuntimeException(
//         'تعذر فتح ملف SQL داخل ZIP. الاسم الأصلي: ' . $sqlEntry
//     );
// }

// $sqlOutput = fopen($sqlPath, 'wb');

// if ($sqlOutput === false) {
//     fclose($sqlInput);
//     $zip->close();

//     throw new RuntimeException(
//         'تعذر إنشاء ملف SQL المؤقت في: ' . $sqlPath
//     );
// }

// stream_copy_to_stream($sqlInput, $sqlOutput);
// fclose($sqlInput);
// fclose($sqlOutput);


//             if ($reportsFound === 0) {
//                 throw new RuntimeException('لم يتم العثور على ملفات مرضى ضمن المسار المتوقع في النسخة. أُلغيَت الاستعادة.');
//             }

//             // 4) حفظ ملفات المرضى الحالية محلياً كنقطة رجوع إضافية.
//             if (File::isDirectory($currentReportsPath)) {
//                 File::ensureDirectoryExists(dirname($currentReportsSafetyPath), 0755, true);
//                 File::copyDirectory($currentReportsPath, $currentReportsSafetyPath);
//             }

//             // 5) وضع النظام في Maintenance Mode خلال الاستبدال.
//             Artisan::call('down');

//             try {
//                 // 6) استيراد قاعدة البيانات من SQL باستخدام mysql.exe.
//                 $mysqlConfig = config('database.connections.mysql');
//                 $mysqlBinaryDirectory = rtrim((string) data_get($mysqlConfig, 'dump.dump_binary_path'), '/\\');
//                 $mysqlBinary = $mysqlBinaryDirectory . '/mysql.exe';

//                 if (! File::exists($mysqlBinary)) {
//                     throw new RuntimeException("لم يتم العثور على mysql.exe في: {$mysqlBinary}");
//                 }

//                 $command = [
//                     $mysqlBinary,
//                     '--host=' . data_get($mysqlConfig, 'host', '127.0.0.1'),
//                     '--port=' . data_get($mysqlConfig, 'port', '3306'),
//                     '--user=' . data_get($mysqlConfig, 'username'),
//                 ];

//                 $password = (string) data_get($mysqlConfig, 'password', '');
//                 $command[] = $password === '' ? '--skip-password' : '--password=' . $password;
//                 $command[] = (string) data_get($mysqlConfig, 'database');

//                 $importProcess = new Process($command, base_path());
//                 $importProcess->setTimeout(1200);
//                 $sqlInputStream = fopen($sqlPath, 'rb');
//                 $importProcess->setInput($sqlInputStream);
//                 $importProcess->run();
//                 fclose($sqlInputStream);

//                 if (! $importProcess->isSuccessful()) {
//                     throw new RuntimeException(
//                         "فشل استيراد قاعدة البيانات.\n" . $importProcess->getErrorOutput()
//                     );
//                 }

//                 // 7) استبدال ملفات المرضى فقط بعد نجاح استيراد الداتا.
//                 if (File::isDirectory($currentReportsPath)) {
//                     File::deleteDirectory($currentReportsPath);
//                 }

//                 File::ensureDirectoryExists($currentReportsPath, 0755, true);
//                 File::copyDirectory($stagedReportsPath, $currentReportsPath);

//                 // 8) تنظيف كاش التطبيق بعد الاستعادة.
//                 Artisan::call('optimize:clear');
//             } finally {
//                 Artisan::call('up');
//             }
//         } finally {
//             // إزالة التنزيلات والملفات المرحلية فقط؛ تبقى نسخة الأمان على Drive
//             // ونسخة ملفات المرضى السابقة في storage/app/restore-safety.
//             File::deleteDirectory($restoreRoot);
//         }
//     }
// }



namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

class RestoreBackupFromGoogleDrive implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * الحد الأقصى للاستعادة: دقيقة واحدة.
     */
    public $timeout = 60;

    /**
     * لا نعيد المحاولة تلقائياً؛ لأن الاستعادة عملية حساسة.
     */
    public $tries = 1;

    public function __construct(
        public string $backupPath,
    ) {
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('restore-backup-from-google-drive'))
                ->expireAfter(60)
                ->dontRelease(),
        ];
    }

    public function handle(): void
    {
        $google = Storage::disk('google');

        if (! $google->exists($this->backupPath)) {
            throw new RuntimeException('ملف النسخة المختار غير موجود على Google Drive.');
        }

        $restoreId = now()->format('Ymd_His');
        $restoreRoot = storage_path("app/restore-temp/{$restoreId}");
        $archivePath = "{$restoreRoot}/backup.zip";
        $sqlPath = "{$restoreRoot}/database.sql";
        $stagedReportsPath = "{$restoreRoot}/medical_reports";

        // هذا هو المجلد الحقيقي للتقارير، وليس public/storage.
        $currentReportsPath = storage_path('app/public/medical_reports');

        // نقطة رجوع محلية للملفات الحالية فقط.
        $reportsSafetyPath = storage_path(
            "app/restore-safety/{$restoreId}/medical_reports"
        );

        File::ensureDirectoryExists($restoreRoot, 0755, true);
        File::ensureDirectoryExists($stagedReportsPath, 0755, true);

        try {
            /**
             * 1) تنزيل ZIP من Google Drive والتحقق من اكتمال التنزيل.
             */
            $expectedSize = $google->size($this->backupPath);
            $contents = $google->get($this->backupPath);

            if (! is_string($contents) || $contents === '') {
                throw new RuntimeException('تعذر تنزيل محتوى النسخة من Google Drive.');
            }

            $writtenBytes = File::put($archivePath, $contents);
            $actualSize = File::size($archivePath);

            if ($writtenBytes === false || $actualSize !== $expectedSize) {
                throw new RuntimeException(
                    "تنزيل ملف النسخة غير مكتمل. المتوقع: {$expectedSize} بايت، " .
                    "والمُنزل: {$actualSize} بايت."
                );
            }

            $signature = file_get_contents($archivePath, false, null, 0, 2);
            if ($signature !== 'PK') {
                throw new RuntimeException('الملف الذي نُزّل من Google Drive ليس ZIP صالحاً.');
            }

            /**
             * 2) فتح ZIP واستخراج SQL والتقارير فقط.
             */
            $zip = new ZipArchive();
            if ($zip->open($archivePath) !== true) {
                throw new RuntimeException('ملف النسخة ليس ZIP صالحاً أو لا يمكن فتحه.');
            }

            $sqlEntry = null;
            $reportsFound = 0;

            // يدعم النسخ الجديدة الصغيرة والنسخ القديمة ذات المسار الكامل.
            $reportsPrefixes = [
                'medical_reports/',
                'C:/Karam_dent/storage/app/public/medical_reports/',
            ];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                // raw: الاسم الأصلي الذي يجب تمريره إلى getStream().
                $rawEntryName = $zip->getNameIndex($i);

                // normalized: للمقارنة فقط، كي ندعم / و \\ داخل الأرشيف.
                $entryName = str_replace('\\', '/', $rawEntryName);
                $lowerName = strtolower($entryName);

                if (str_starts_with($lowerName, 'db-dumps/') && str_ends_with($lowerName, '.sql')) {
                    $sqlEntry = $rawEntryName;
                    continue;
                }

                $matchingPrefix = null;
                foreach ($reportsPrefixes as $candidatePrefix) {
                    if (str_starts_with($entryName, $candidatePrefix)) {
                        $matchingPrefix = $candidatePrefix;
                        break;
                    }
                }

                if ($matchingPrefix === null) {
                    continue;
                }

                $relativePath = substr($entryName, strlen($matchingPrefix));

                // حماية من Path Traversal داخل الأرشيف.
                if ($relativePath === '' || str_contains($relativePath, '..')) {
                    continue;
                }

                $destination = $stagedReportsPath . DIRECTORY_SEPARATOR
                    . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

                if (str_ends_with($entryName, '/')) {
                    File::ensureDirectoryExists($destination, 0755, true);
                    continue;
                }

                File::ensureDirectoryExists(dirname($destination), 0755, true);

                // يجب استخدام الاسم الأصلي، لا الاسم بعد تحويل \ إلى /.
                $input = $zip->getStream($rawEntryName);
                $output = fopen($destination, 'wb');

                if ($input === false || $output === false) {
                    if (is_resource($input)) {
                        fclose($input);
                    }

                    if (is_resource($output)) {
                        fclose($output);
                    }

                    $zip->close();

                    throw new RuntimeException(
                        'تعذر استخراج ملف تقرير من ZIP: ' . $entryName
                    );
                }

                stream_copy_to_stream($input, $output);
                fclose($input);
                fclose($output);
                $reportsFound++;
            }

            if ($sqlEntry === null) {
                $zip->close();
                throw new RuntimeException('لم يتم العثور على ملف SQL ضمن db-dumps داخل النسخة.');
            }

            if ($reportsFound === 0) {
                $zip->close();
                throw new RuntimeException(
                    'لم يتم العثور على أي ملف تقرير ضمن المسارات المتوقعة في النسخة.'
                );
            }

            // استخراج قاعدة البيانات باستخدام الاسم الأصلي داخل ZIP.
            $sqlInput = $zip->getStream($sqlEntry);
            if ($sqlInput === false) {
                $zip->close();
                throw new RuntimeException(
                    'تعذر فتح ملف SQL داخل ZIP. الاسم: ' . $sqlEntry
                );
            }

            $sqlOutput = fopen($sqlPath, 'wb');
            if ($sqlOutput === false) {
                fclose($sqlInput);
                $zip->close();
                throw new RuntimeException(
                    'تعذر إنشاء ملف SQL مؤقت في: ' . $sqlPath
                );
            }

            stream_copy_to_stream($sqlInput, $sqlOutput);
            fclose($sqlInput);
            fclose($sqlOutput);
            $zip->close();

            /**
             * 3) حفظ ملفات التقارير الحالية محلياً بسرعة كنقطة رجوع.
             */
            if (File::isDirectory($currentReportsPath)) {
                File::ensureDirectoryExists(dirname($reportsSafetyPath), 0755, true);
                File::copyDirectory($currentReportsPath, $reportsSafetyPath);
            }

            /**
             * 4) إيقاف التطبيق أثناء استبدال قاعدة البيانات والملفات.
             */
            Artisan::call('down');

            try {
                /**
                 * 5) استيراد SQL إلى MySQL المحلي.
                 */
                $mysqlConfig = config('database.connections.mysql');
                $mysqlBinDirectory = rtrim(
                    (string) data_get($mysqlConfig, 'dump.dump_binary_path'),
                    '/\\'
                );
                $mysqlBinary = $mysqlBinDirectory . '/mysql.exe';

                if (! File::exists($mysqlBinary)) {
                    throw new RuntimeException(
                        'لم يتم العثور على mysql.exe في: ' . $mysqlBinary
                    );
                }

                $command = [
                    $mysqlBinary,
                    '--host=' . data_get($mysqlConfig, 'host', '127.0.0.1'),
                    '--port=' . data_get($mysqlConfig, 'port', '3306'),
                    '--user=' . data_get($mysqlConfig, 'username'),
                ];

                $password = (string) data_get($mysqlConfig, 'password', '');
                $command[] = $password === ''
                    ? '--skip-password'
                    : '--password=' . $password;

                $command[] = (string) data_get($mysqlConfig, 'database');

                $importProcess = new Process($command, base_path());
                $importProcess->setTimeout(50);

                $sqlInputStream = fopen($sqlPath, 'rb');
                if ($sqlInputStream === false) {
                    throw new RuntimeException('تعذر فتح ملف SQL المؤقت للاستيراد.');
                }

                $importProcess->setInput($sqlInputStream);
                $importProcess->run();
                fclose($sqlInputStream);

                if (! $importProcess->isSuccessful()) {
                    throw new RuntimeException(
                        "فشل استيراد قاعدة البيانات:\n" . $importProcess->getErrorOutput()
                    );
                }

                /**
                 * 6) استبدال التقارير فقط بعد نجاح استيراد قاعدة البيانات.
                 */
                if (File::isDirectory($currentReportsPath)) {
                    File::deleteDirectory($currentReportsPath);
                }

                File::ensureDirectoryExists($currentReportsPath, 0755, true);
                File::copyDirectory($stagedReportsPath, $currentReportsPath);

                Artisan::call('optimize:clear');
            } finally {
                Artisan::call('up');
            }
        } finally {
            // لا نحذف restore-safety، بل نحذف ملفات التنزيل المؤقتة فقط.
            File::deleteDirectory($restoreRoot);
        }
    }
}
