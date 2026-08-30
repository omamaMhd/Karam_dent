<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
class SpecializationSeeder extends Seeder
{
    public function run(): void
    {
         DB::table('specializations')->insert([
        [
            'name' => 'اختصاص تقويم الأسنان',
            'description' => 'تصحيح ترتيب الأسنان والفكين',
        ],
        [
            'name' => 'اختصاص زراعةالأسنان',
            'description' => 'تعويض الأسنان المفقودة بزرعات',
        ],
        [
            'name' => 'اختصاص المعالجة اللبية',
            'description' => 'إزالة العصب و معالجة الأسنان',
        ],
         [
            'name' => 'اختصاص أطفال',
            'description' => 'علاج الأسنان للأطفال',
        ],
         [
            'name' => 'اختصاص جراحة الفكين',
            'description' => 'جراحة الفكين والأسنان',
        ],

    ]);

    }
}
