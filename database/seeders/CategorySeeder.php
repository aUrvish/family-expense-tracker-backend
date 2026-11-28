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
            // Expense categories
            [
                'name' => 'Grocery',
                'slug' => 'grocery',
                'type' => 'expense',
                'icon' => 'shopping-cart',
                'color' => '#10B981',
            ],
            [
                'name' => 'Bills',
                'slug' => 'bills',
                'type' => 'expense',
                'icon' => 'receipt',
                'color' => '#3B82F6',
            ],
            [
                'name' => 'Recharge',
                'slug' => 'recharge',
                'type' => 'expense',
                'icon' => 'smartphone',
                'color' => '#6366F1',
            ],
            [
                'name' => 'Subscription',
                'slug' => 'subscription',
                'type' => 'expense',
                'icon' => 'play-circle',
                'color' => '#8B5CF6',
            ],
            [
                'name' => 'Medical',
                'slug' => 'medical',
                'type' => 'expense',
                'icon' => 'heart-pulse',
                'color' => '#EF4444',
            ],
            [
                'name' => 'Education',
                'slug' => 'education',
                'type' => 'expense',
                'icon' => 'graduation-cap',
                'color' => '#F59E0B',
            ],
            [
                'name' => 'Fuel',
                'slug' => 'fuel',
                'type' => 'expense',
                'icon' => 'fuel',
                'color' => '#EC4899',
            ],
            [
                'name' => 'Food',
                'slug' => 'food',
                'type' => 'expense',
                'icon' => 'utensils',
                'color' => '#F97316',
            ],
            [
                'name' => 'Shopping',
                'slug' => 'shopping',
                'type' => 'expense',
                'icon' => 'shopping-bag',
                'color' => '#14B8A6',
            ],
            [
                'name' => 'Entertainment',
                'slug' => 'entertainment',
                'type' => 'expense',
                'icon' => 'film',
                'color' => '#A855F7',
            ],
            [
                'name' => 'Rent',
                'slug' => 'rent',
                'type' => 'expense',
                'icon' => 'home',
                'color' => '#64748B',
            ],
            [
                'name' => 'Insurance',
                'slug' => 'insurance',
                'type' => 'expense',
                'icon' => 'shield-check',
                'color' => '#0EA5E9',
            ],
            [
                'name' => 'Maintenance',
                'slug' => 'maintenance',
                'type' => 'expense',
                'icon' => 'wrench',
                'color' => '#78716C',
            ],
            [
                'name' => 'Investment',
                'slug' => 'investment',
                'type' => 'expense',
                'icon' => 'trending-up',
                'color' => '#22C55E',
            ],
            [
                'name' => 'Gifts',
                'slug' => 'gifts',
                'type' => 'expense',
                'icon' => 'gift',
                'color' => '#D946EF',
            ],
            [
                'name' => 'Others',
                'slug' => 'others',
                'type' => 'expense',
                'icon' => 'more-horizontal',
                'color' => '#94A3B8',
            ],

            // Income categories
            [
                'name' => 'Salary',
                'slug' => 'salary',
                'type' => 'income',
                'icon' => 'briefcase',
                'color' => '#10B981',
            ],
            [
                'name' => 'Business',
                'slug' => 'business-income',
                'type' => 'income',
                'icon' => 'building-2',
                'color' => '#3B82F6',
            ],
            [
                'name' => 'Freelance',
                'slug' => 'freelance',
                'type' => 'income',
                'icon' => 'laptop',
                'color' => '#8B5CF6',
            ],
            [
                'name' => 'Rent Received',
                'slug' => 'rent-received',
                'type' => 'income',
                'icon' => 'home',
                'color' => '#14B8A6',
            ],
            [
                'name' => 'Interest',
                'slug' => 'interest',
                'type' => 'income',
                'icon' => 'percent',
                'color' => '#0EA5E9',
            ],
            [
                'name' => 'Gift Received',
                'slug' => 'gift-received',
                'type' => 'income',
                'icon' => 'gift',
                'color' => '#EC4899',
            ],
            [
                'name' => 'Refund',
                'slug' => 'refund',
                'type' => 'income',
                'icon' => 'rotate-ccw',
                'color' => '#F59E0B',
            ],
            [
                'name' => 'Other Income',
                'slug' => 'other-income',
                'type' => 'income',
                'icon' => 'plus-circle',
                'color' => '#64748B',
            ],
        ];

        foreach ($categories as $category) {
            $record = Category::firstOrCreate(
                ['slug' => $category['slug']],
                $category
            );

            if ($record->type !== $category['type']) {
                $record->update(['type' => $category['type']]);
            }
        }
    }
}
