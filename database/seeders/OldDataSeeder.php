<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class OldDataSeeder extends Seeder
{
    public function run(): void
    {
        $json = <<<'JSON'
[
    {
        "title": "Felibite Adult Cat Salmon Mentai Flavor 500gr",
        "price": 13000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 500,
        "image": "/assets/produk/felibite1.webp",
        "isBestProduct": false
    },
    {
        "title": "Felibite Adult Cat Tuna O Toro Flavor 500gr",
        "price": 13000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 500,
        "image": "/assets/produk/felibite1.webp",
        "isBestProduct": false
    },
    {
        "title": "Felibite Adult Cat Tuna & Chicken Flavor 500gr",
        "price": 13000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 500,
        "image": "/assets/produk/felibite1.webp",
        "isBestProduct": false
    },
    {
        "title": "Felibite Adult Cat Donut Tuna & Chicken Flavor 500gr",
        "price": 13000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 500,
        "image": "/assets/produk/felibite1.webp",
        "isBestProduct": false
    },
    {
        "title": "Lezato Kitten Cat Food Tuna Flavor 800gr",
        "price": 20000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 800,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "Lezato Adult Cat Food Tuna Flavor 1Kg",
        "price": 22000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 1000,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "BOLT Adult Cat Food Tuna Flavor 800gr",
        "price": 19000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 800,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "BOLT Adult Cat Food Salmon Flavor 800gr",
        "price": 19000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 800,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "BOLT Adult Cat Food Donut Tuna Flavor 800gr",
        "price": 19000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 800,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "BOLT Mother & Kitten Cat Food Salmon Flavor 500gr",
        "price": 14000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 500,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "BOLT Mother & Kitten Cat Food Tuna Flavor 500gr",
        "price": 14000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 500,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "Cuties Catz Adult Cat Food Tuna Flavor 500gr",
        "price": 14000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 500,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "Chester Adult Cat Food Tuna Flavor 800gr",
        "price": 17000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 800,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "Ori Cat Adult Cat Food Tuna Flavor 800gr",
        "price": 19000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 800,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "Ori Cat Adult Cat Food Tuna & Chicken Flavor 800gr",
        "price": 19000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 800,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "Ori Cat Adult Cat Food Salmon Flavor 800gr",
        "price": 19000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 800,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "Ori Cat Kitten Cat Food Tuna Flavor 800gr",
        "price": 21000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 800,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "Ori Cat Kitten Cat Food Salmon Flavor 800gr",
        "price": 21000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 800,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "Excel Donut Tuna Flavor 500gr",
        "price": 12000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 500,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "Excel Tuna Flavor 500gr",
        "price": 12000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 500,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "Excel Chicken & Tuna Flavor 500gr",
        "price": 12000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 500,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "Excel Salmon Flavor 500gr",
        "price": 12000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 500,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "Excel Mother & Kitten Cat Food Chicken & Tuna Flavor 500gr",
        "price": 13000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 500,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "Hepito Tasty Tuna Flavor 400gr",
        "price": 9000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 400,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "Nice Adult Cat Food Seafood Flavor 800gr",
        "price": 17000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 800,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "Nice Adult Cat Food Tuna Flavor 800gr",
        "price": 17000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 800,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "Mister Puss Tuna Flavor 500gr",
        "price": 12000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 500,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "Mister Puss Salmon Flavor 500gr",
        "price": 12000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 500,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "Cattie Care Tuna Flavor 800gr",
        "price": 22000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 800,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "Cattie Care Beauty Chicken & Tuna Flavor 800gr",
        "price": 23000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 800,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "Kucing KU Adult Cat Food Tuna Steak Flavor 800gr",
        "price": 17000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 800,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "Kucing KU Kitten Cat Food Tuna Steak Flavor 800gr",
        "price": 18000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 800,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "Beauty Premium Cat Food Adult & Kitten  Chicken & Salmon Flavor 800gr",
        "price": 28000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 800,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "Whiskas Adult Cat Food Tuna Flavor 480gr",
        "price": 32000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 480,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "Whiskas Mother & Kitten Cat Food Ocean Fish Flavor 450gr",
        "price": 34000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 450,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "TopPet Cat Food Tuna Flavor 800gr",
        "price": 16000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 800,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "Amigo Adult Cat Food Salmon Flavor 800gr",
        "price": 17000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 800,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "Amigo Adult Cat Food Tuna Flavor 800gr",
        "price": 17000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 800,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "Cat Choize Adult Cat Food Salmon Flavor 800gr",
        "price": 18000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 800,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "Cat Choize Adult Cat Food Tuna Flavor 800gr",
        "price": 18000,
        "blurb": "",
        "category": "Pets",
        "category_pets": "Kucing",
        "weight": 800,
        "image": "/assets/produk/whiskas1.webp",
        "isBestProduct": true
    },
    {
        "title": "Bekatul Halus",
        "price": 8500,
        "blurb": "Bekatul berkualitas dari lapisan halus kulit ari beras. Kaya vitamin B kompleks, serat, dan lemak sehat — cocok sebagai bahan pakan ayam, burung, ikan, sapi, dan kambing.",
        "category": "Poultry",
        "category_pets": "Unggas",
        "weight": "500gr",
        "image": "/assets/produk/bekatul.webp",
        "isBestProduct": true
    },
    {
        "title": "Millet Putih",
        "price": 15000,
        "blurb": "Biji millet putih pilihan, kaya karbohidrat dan protein. Disukai kenari, lovebird, parkit, dan burung kecil lainnya. Sumber energi tinggi dengan pencernaan mudah.",
        "category": "Poultry",
        "category_pets": "Burung",
        "weight": "500gr",
        "image": "/assets/produk/miletputih.webp",
        "isBestProduct": true
    },
    {
        "title": "Millet Merah",
        "price": 17000,
        "blurb": "Biji millet merah berkualitas untuk variasi pakan burung. Mengandung serat dan lemak lebih tinggi, baik untuk merpati, parrot, ayam kecil, dan burung hias.",
        "category": "Poultry",
        "category_pets": "Burung",
        "weight": "500gr",
        "image": "/assets/produk/miletmerah.webp",
        "isBestProduct": true
    }
]
JSON;

        $data = json_decode($json, true);

        if (!$data) {
            $this->command->error("Failed to decode JSON data.");
            return;
        }

        // Acak 3 indeks untuk menjadi "Best Product"
        $bestProductIndices = array_rand($data, min(3, count($data)));
        if (!is_array($bestProductIndices)) {
            $bestProductIndices = [$bestProductIndices];
        }

        foreach ($data as $index => $item) {
            // 1. Simpan/Ambil Kategori Induk (Contoh: Pets)
            $parentCategory = Category::firstOrCreate([
                'name' => $item['category'],
                'parent_id' => null,
            ]);

            // 2. Simpan/Ambil Sub-Kategori (Contoh: Kucing)
            $childCategory = Category::firstOrCreate([
                'name' => $item['category_pets'],
                'parent_id' => $parentCategory->id,
            ]);

            // 3. Ekstrak dan Simpan Brand dari Title
            $brandName = $this->extractBrand($item['title']);
            $brand = null;
            if ($brandName) {
                $brand = Brand::firstOrCreate(['name' => $brandName]);
            }

            // 4. Standarisasi format berat (Weight) ke integer gram
            $weight = $item['weight'];
            if (is_string($weight)) {
                $weight = (int) str_replace(['gr', 'kg', 'Kg', ' '], ['', '000', '000', ''], strtolower($weight));
            }

            // 5. Masukkan Produk
            Product::create([
                'sku' => null, // Dikosongkan sesuai permintaan
                'title' => $item['title'],
                'blurb' => $item['blurb'],
                'price' => $item['price'],
                'cost_price' => (int) ($item['price'] * 0.8), // Konversi ke int agar tidak error float
                'weight_gram' => $weight,
                'image_url' => $item['image'],
                'is_best_product' => in_array($index, $bestProductIndices),
                'current_stock' => 0,
                'is_active' => true,
                'category_id' => $childCategory->id,
                'brand_id' => $brand?->id,
            ]);
        }
    }

    /**
     * Mengekstrak nama merek berdasarkan daftar merek umum di industri pet shop
     */
    private function extractBrand(string $title): ?string
    {
        $knownBrands = [
            'Felibite', 'Lezato', 'BOLT', 'Cuties Catz', 'Chester', 'Ori Cat', 
            'Excel', 'Hepito', 'Nice', 'Mister Puss', 'Cattie Care', 'Kucing KU', 
            'Beauty Premium', 'Whiskas', 'TopPet', 'Amigo', 'Cat Choize',
            'Bekatul', 'Millet'
        ];

        foreach ($knownBrands as $brand) {
            if (str_starts_with(strtolower($title), strtolower($brand))) {
                return $brand;
            }
        }

        return 'Tanpa Merek';
    }
}
