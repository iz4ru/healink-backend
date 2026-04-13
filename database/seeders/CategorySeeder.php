<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [

            ['name' => 'Flu, Batuk & Pilek'],
            ['name' => 'Demam & Nyeri'],
            ['name' => 'Pencernaan & Maag'],
            ['name' => 'Alergi'],
            ['name' => 'Kulit & Infeksi Ringan'],

            ['name' => 'Obat Mata & Telinga'],
            ['name' => 'Obat Sariawan & Mulut'],
            ['name' => 'Obat Cacing'],

            ['name' => 'Vitamin & Suplemen'],
            ['name' => 'Herbal & Tradisional'],

            ['name' => 'Perawatan Luka'],
            ['name' => 'Alat Kesehatan'],
            ['name' => 'Kebersihan & Sanitasi'],

            ['name' => 'Ibu & Bayi'],
            ['name' => 'Minuman Kesehatan'],
        ];

        foreach ($categories as $category) {
            Category::create($category);
        }
    }
}
