<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Grocery',
                'slug' => 'grocery',
                'icon' => 'shopping-cart',
                'color' => '#10B981',
            ],
            [
                'name' => 'Bills',
                'slug' => 'bills',
                'icon' => 'receipt',
                'color' => '#3B82F6',
            ],
            [
                'name' => 'Recharge',
                'slug' => 'recharge',
                'icon' => 'smartphone',
                'color' => '#6366F1',
            ],
            [
                'name' => 'Subscription',
                'slug' => 'subscription',
                'icon' => 'play-circle',
                'color' => '#8B5CF6',
            ],
            [
                'name' => 'Medical',
                'slug' => 'medical',
                'icon' => 'heart-pulse',
                'color' => '#EF4444',
            ],
            [
                'name' => 'Education',
                'slug' => 'education',
                'icon' => 'graduation-cap',
                'color' => '#F59E0B',
            ],
            [
                'name' => 'Fuel',
                'slug' => 'fuel',
                'icon' => 'fuel',
                'color' => '#EC4899',
            ],
            [
                'name' => 'Food',
                'slug' => 'food',
                'icon' => 'utensils',
                'color' => '#F97316',
            ],
            [
                'name' => 'Shopping',
                'slug' => 'shopping',
                'icon' => 'shopping-bag',
                'color' => '#14B8A6',
            ],
            [
                'name' => 'Entertainment',
                'slug' => 'entertainment',
                'icon' => 'film',
                'color' => '#A855F7',
            ],
            [
                'name' => 'Rent',
                'slug' => 'rent',
                'icon' => 'home',
                'color' => '#64748B',
            ],
            [
                'name' => 'Insurance',
                'slug' => 'insurance',
                'icon' => 'shield-check',
                'color' => '#0EA5E9',
            ],
            [
                'name' => 'Maintenance',
                'slug' => 'maintenance',
                'icon' => 'wrench',
                'color' => '#78716C',
            ],
            [
                'name' => 'Investment',
                'slug' => 'investment',
                'icon' => 'trending-up',
                'color' => '#22C55E',
            ],
            [
                'name' => 'Gifts',
                'slug' => 'gifts',
                'icon' => 'gift',
                'color' => '#D946EF',
            ],
            [
                'name' => 'Others',
                'slug' => 'others',
                'icon' => 'more-horizontal',
                'color' => '#94A3B8',
            ],
        ];

        foreach ($categories as $category) {
            Category::firstOrCreate(
                ['slug' => $category['slug']],
                $category
            );
        }
    }
}
