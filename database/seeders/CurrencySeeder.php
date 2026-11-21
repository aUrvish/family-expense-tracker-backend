<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

/**
 * Seeder for supported ISO 4217 currencies and their display metadata.
 */
class CurrencySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $currencies = [
            ['code' => 'USD', 'name' => 'United States Dollar', 'symbol' => '$', 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£', 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'JPY', 'name' => 'Japanese Yen', 'symbol' => '¥', 'decimal_places' => 0, 'is_active' => true],
            ['code' => 'AUD', 'name' => 'Australian Dollar', 'symbol' => 'A$', 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'BRL', 'name' => 'Brazilian Real', 'symbol' => 'R$', 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'CAD', 'name' => 'Canadian Dollar', 'symbol' => 'CA$', 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'CHF', 'name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'CNY', 'name' => 'Chinese Renminbi Yuan', 'symbol' => '¥', 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'CZK', 'name' => 'Czech Koruna', 'symbol' => 'Kč', 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'DKK', 'name' => 'Danish Krone', 'symbol' => 'kr', 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'HKD', 'name' => 'Hong Kong Dollar', 'symbol' => 'HK$', 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'HUF', 'name' => 'Hungarian Forint', 'symbol' => 'Ft', 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'IDR', 'name' => 'Indonesian Rupiah', 'symbol' => 'Rp', 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'ILS', 'name' => 'Israeli New Shekel', 'symbol' => '₪', 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹', 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'ISK', 'name' => 'Icelandic Króna', 'symbol' => 'kr', 'decimal_places' => 0, 'is_active' => true],
            ['code' => 'KRW', 'name' => 'South Korean Won', 'symbol' => '₩', 'decimal_places' => 0, 'is_active' => true],
            ['code' => 'MXN', 'name' => 'Mexican Peso', 'symbol' => 'MX$', 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'MYR', 'name' => 'Malaysian Ringgit', 'symbol' => 'RM', 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'NOK', 'name' => 'Norwegian Krone', 'symbol' => 'kr', 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'NZD', 'name' => 'New Zealand Dollar', 'symbol' => 'NZ$', 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'PHP', 'name' => 'Philippine Peso', 'symbol' => '₱', 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'PLN', 'name' => 'Polish Złoty', 'symbol' => 'zł', 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'RON', 'name' => 'Romanian Leu', 'symbol' => 'lei', 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'SEK', 'name' => 'Swedish Krona', 'symbol' => 'kr', 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'SGD', 'name' => 'Singapore Dollar', 'symbol' => 'S$', 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'THB', 'name' => 'Thai Baht', 'symbol' => '฿', 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'TRY', 'name' => 'Turkish Lira', 'symbol' => '₺', 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'ZAR', 'name' => 'South African Rand', 'symbol' => 'R', 'decimal_places' => 2, 'is_active' => true],
            ['code' => 'KWD', 'name' => 'Kuwaiti Dinar', 'symbol' => 'KD', 'decimal_places' => 3, 'is_active' => true],
        ];

        foreach ($currencies as $currency) {
            Currency::updateOrCreate(
                ['code' => $currency['code']],
                $currency
            );
        }
    }
}
