<?php

namespace Database\Seeders;

final class DemoInventoryCatalog
{
    /** @return list<string> */
    public static function categories(): array
    {
        return [
            'Baking & Cooking', 'Beverages', 'Cleaning Supplies', 'Dairy & Refrigerated',
            'First Aid & Medicine', 'Frozen Foods', 'Laundry', 'Office & School Supplies',
            'Outdoor & Garden', 'Paper Goods', 'Personal Care', 'Pet Supplies',
            'Snacks', 'Tools & Hardware', 'Travel & Seasonal',
        ];
    }

    /**
     * @return list<array{name: string, parent: ?string}>
     */
    public static function locations(): array
    {
        return [
            ['name' => 'Kitchen', 'parent' => null],
            ['name' => 'Main Pantry', 'parent' => 'Kitchen'],
            ['name' => 'Kitchen Cabinet', 'parent' => 'Kitchen'],
            ['name' => 'Refrigerator', 'parent' => 'Kitchen'],
            ['name' => 'Freezer', 'parent' => 'Kitchen'],
            ['name' => 'Laundry Room', 'parent' => null],
            ['name' => 'Hall Closet', 'parent' => null],
            ['name' => 'Primary Bathroom', 'parent' => null],
            ['name' => 'Guest Bathroom', 'parent' => null],
            ['name' => 'Hall Bathroom', 'parent' => null],
            ['name' => 'Bedroom Closet', 'parent' => null],
            ['name' => 'Home Office', 'parent' => null],
            ['name' => 'Garage', 'parent' => null],
            ['name' => 'Tool Bench', 'parent' => 'Garage'],
            ['name' => 'Garden Shed', 'parent' => null],
            ['name' => 'Basement', 'parent' => null],
            ['name' => 'Basement Storage Shelf', 'parent' => 'Basement'],
            ['name' => 'Entryway', 'parent' => null],
            ['name' => 'Car Emergency Kit', 'parent' => null],
            ['name' => 'Travel Tote', 'parent' => 'Bedroom Closet'],
        ];
    }

    /**
     * @return list<array{name: string, unit: string, categories: list<string>, locations: list<string>}>
     */
    public static function items(): array
    {
        return [
            ['name' => 'All-Purpose Flour', 'unit' => 'bag', 'categories' => ['Baking & Cooking'], 'locations' => ['Main Pantry']],
            ['name' => 'Baking Powder', 'unit' => 'can', 'categories' => ['Baking & Cooking'], 'locations' => ['Main Pantry']],
            ['name' => 'Baking Soda', 'unit' => 'box', 'categories' => ['Baking & Cooking', 'Cleaning Supplies'], 'locations' => ['Main Pantry', 'Laundry Room']],
            ['name' => 'Brown Sugar', 'unit' => 'bag', 'categories' => ['Baking & Cooking'], 'locations' => ['Main Pantry']],
            ['name' => 'Canola Oil', 'unit' => 'bottle', 'categories' => ['Baking & Cooking'], 'locations' => ['Main Pantry']],
            ['name' => 'Chicken Broth', 'unit' => 'carton', 'categories' => ['Baking & Cooking'], 'locations' => ['Main Pantry']],
            ['name' => 'Cinnamon', 'unit' => 'jar', 'categories' => ['Baking & Cooking'], 'locations' => ['Kitchen Cabinet']],
            ['name' => 'Cooking Spray', 'unit' => 'can', 'categories' => ['Baking & Cooking'], 'locations' => ['Kitchen Cabinet']],
            ['name' => 'Olive Oil', 'unit' => 'bottle', 'categories' => ['Baking & Cooking'], 'locations' => ['Kitchen Cabinet']],
            ['name' => 'Pasta Sauce', 'unit' => 'jar', 'categories' => ['Baking & Cooking'], 'locations' => ['Main Pantry']],
            ['name' => 'Peanut Butter', 'unit' => 'jar', 'categories' => ['Baking & Cooking'], 'locations' => ['Main Pantry']],
            ['name' => 'White Rice', 'unit' => 'bag', 'categories' => ['Baking & Cooking'], 'locations' => ['Main Pantry']],
            ['name' => 'Ground Coffee', 'unit' => 'bag', 'categories' => ['Beverages'], 'locations' => ['Main Pantry']],
            ['name' => 'Herbal Tea Bags', 'unit' => 'box', 'categories' => ['Beverages'], 'locations' => ['Main Pantry']],
            ['name' => 'Sparkling Water', 'unit' => 'can', 'categories' => ['Beverages'], 'locations' => ['Basement Storage Shelf', 'Refrigerator']],
            ['name' => 'Apple Juice', 'unit' => 'bottle', 'categories' => ['Beverages'], 'locations' => ['Refrigerator']],
            ['name' => 'Dish Soap', 'unit' => 'bottle', 'categories' => ['Cleaning Supplies'], 'locations' => ['Kitchen Cabinet']],
            ['name' => 'Dishwasher Detergent Pods', 'unit' => 'bag', 'categories' => ['Cleaning Supplies'], 'locations' => ['Kitchen Cabinet']],
            ['name' => 'Disinfecting Wipes', 'unit' => 'canister', 'categories' => ['Cleaning Supplies'], 'locations' => ['Kitchen Cabinet', 'Primary Bathroom']],
            ['name' => 'Glass Cleaner', 'unit' => 'bottle', 'categories' => ['Cleaning Supplies'], 'locations' => ['Laundry Room']],
            ['name' => 'Kitchen Sponges', 'unit' => 'pack', 'categories' => ['Cleaning Supplies'], 'locations' => ['Kitchen Cabinet']],
            ['name' => 'Microfiber Cloths', 'unit' => 'pack', 'categories' => ['Cleaning Supplies'], 'locations' => ['Laundry Room']],
            ['name' => 'Toilet Bowl Cleaner', 'unit' => 'bottle', 'categories' => ['Cleaning Supplies'], 'locations' => ['Primary Bathroom', 'Guest Bathroom']],
            ['name' => 'Trash Bags', 'unit' => 'box', 'categories' => ['Cleaning Supplies'], 'locations' => ['Basement Storage Shelf', 'Kitchen Cabinet']],
            ['name' => 'Butter', 'unit' => 'box', 'categories' => ['Dairy & Refrigerated'], 'locations' => ['Refrigerator']],
            ['name' => 'Cheddar Cheese', 'unit' => 'block', 'categories' => ['Dairy & Refrigerated'], 'locations' => ['Refrigerator']],
            ['name' => 'Eggs', 'unit' => 'dozen', 'categories' => ['Dairy & Refrigerated'], 'locations' => ['Refrigerator']],
            ['name' => 'Milk', 'unit' => 'carton', 'categories' => ['Dairy & Refrigerated'], 'locations' => ['Refrigerator']],
            ['name' => 'Plain Yogurt', 'unit' => 'tub', 'categories' => ['Dairy & Refrigerated'], 'locations' => ['Refrigerator']],
            ['name' => 'Sour Cream', 'unit' => 'tub', 'categories' => ['Dairy & Refrigerated'], 'locations' => ['Refrigerator']],
            ['name' => 'Antacid Tablets', 'unit' => 'bottle', 'categories' => ['First Aid & Medicine'], 'locations' => ['Primary Bathroom']],
            ['name' => 'Bandages', 'unit' => 'box', 'categories' => ['First Aid & Medicine'], 'locations' => ['Hall Closet', 'Car Emergency Kit']],
            ['name' => 'Cold Medicine', 'unit' => 'box', 'categories' => ['First Aid & Medicine'], 'locations' => ['Hall Closet']],
            ['name' => 'First Aid Kit', 'unit' => 'kit', 'categories' => ['First Aid & Medicine'], 'locations' => ['Hall Closet', 'Car Emergency Kit']],
            ['name' => 'Ibuprofen', 'unit' => 'bottle', 'categories' => ['First Aid & Medicine'], 'locations' => ['Primary Bathroom']],
            ['name' => 'Thermometer', 'unit' => 'each', 'categories' => ['First Aid & Medicine'], 'locations' => ['Hall Closet']],
            ['name' => 'Frozen Blueberries', 'unit' => 'bag', 'categories' => ['Frozen Foods'], 'locations' => ['Freezer']],
            ['name' => 'Frozen Pizza', 'unit' => 'box', 'categories' => ['Frozen Foods'], 'locations' => ['Freezer']],
            ['name' => 'Frozen Vegetables', 'unit' => 'bag', 'categories' => ['Frozen Foods'], 'locations' => ['Freezer']],
            ['name' => 'Ice Cream', 'unit' => 'pint', 'categories' => ['Frozen Foods'], 'locations' => ['Freezer']],
            ['name' => 'Laundry Detergent', 'unit' => 'bottle', 'categories' => ['Laundry'], 'locations' => ['Laundry Room']],
            ['name' => 'Dryer Sheets', 'unit' => 'box', 'categories' => ['Laundry'], 'locations' => ['Laundry Room']],
            ['name' => 'Fabric Softener', 'unit' => 'bottle', 'categories' => ['Laundry'], 'locations' => ['Laundry Room']],
            ['name' => 'Stain Remover', 'unit' => 'bottle', 'categories' => ['Laundry'], 'locations' => ['Laundry Room']],
            ['name' => 'Ballpoint Pens', 'unit' => 'pack', 'categories' => ['Office & School Supplies'], 'locations' => ['Home Office']],
            ['name' => 'Copy Paper', 'unit' => 'ream', 'categories' => ['Office & School Supplies'], 'locations' => ['Home Office']],
            ['name' => 'Envelopes', 'unit' => 'box', 'categories' => ['Office & School Supplies'], 'locations' => ['Home Office']],
            ['name' => 'Permanent Markers', 'unit' => 'pack', 'categories' => ['Office & School Supplies'], 'locations' => ['Home Office', 'Tool Bench']],
            ['name' => 'Sticky Notes', 'unit' => 'pad', 'categories' => ['Office & School Supplies'], 'locations' => ['Home Office']],
            ['name' => 'Printer Ink', 'unit' => 'cartridge', 'categories' => ['Office & School Supplies'], 'locations' => ['Home Office']],
            ['name' => 'AA Batteries', 'unit' => 'pack', 'categories' => ['Office & School Supplies', 'Tools & Hardware'], 'locations' => ['Home Office', 'Tool Bench']],
            ['name' => 'Bird Seed', 'unit' => 'bag', 'categories' => ['Outdoor & Garden'], 'locations' => ['Garden Shed']],
            ['name' => 'Garden Gloves', 'unit' => 'pair', 'categories' => ['Outdoor & Garden'], 'locations' => ['Garden Shed']],
            ['name' => 'Grass Seed', 'unit' => 'bag', 'categories' => ['Outdoor & Garden'], 'locations' => ['Garden Shed']],
            ['name' => 'Plant Food', 'unit' => 'bottle', 'categories' => ['Outdoor & Garden'], 'locations' => ['Garden Shed']],
            ['name' => 'Potting Soil', 'unit' => 'bag', 'categories' => ['Outdoor & Garden'], 'locations' => ['Garden Shed']],
            ['name' => 'Paper Towels', 'unit' => 'roll', 'categories' => ['Paper Goods'], 'locations' => ['Basement Storage Shelf', 'Kitchen Cabinet']],
            ['name' => 'Facial Tissues', 'unit' => 'box', 'categories' => ['Paper Goods'], 'locations' => ['Hall Closet', 'Home Office']],
            ['name' => 'Napkins', 'unit' => 'pack', 'categories' => ['Paper Goods'], 'locations' => ['Kitchen Cabinet']],
            ['name' => 'Toilet Paper', 'unit' => 'roll', 'categories' => ['Paper Goods'], 'locations' => ['Basement Storage Shelf', 'Primary Bathroom', 'Guest Bathroom']],
            ['name' => 'Aluminum Foil', 'unit' => 'roll', 'categories' => ['Paper Goods'], 'locations' => ['Kitchen Cabinet']],
            ['name' => 'Body Wash', 'unit' => 'bottle', 'categories' => ['Personal Care'], 'locations' => ['Primary Bathroom', 'Guest Bathroom']],
            ['name' => 'Dental Floss', 'unit' => 'pack', 'categories' => ['Personal Care'], 'locations' => ['Primary Bathroom']],
            ['name' => 'Deodorant', 'unit' => 'stick', 'categories' => ['Personal Care'], 'locations' => ['Primary Bathroom', 'Travel Tote']],
            ['name' => 'Hand Soap', 'unit' => 'bottle', 'categories' => ['Personal Care'], 'locations' => ['Primary Bathroom', 'Guest Bathroom', 'Kitchen Cabinet']],
            ['name' => 'Razor Blades', 'unit' => 'pack', 'categories' => ['Personal Care'], 'locations' => ['Primary Bathroom']],
            ['name' => 'Shampoo', 'unit' => 'bottle', 'categories' => ['Personal Care'], 'locations' => ['Primary Bathroom', 'Guest Bathroom']],
            ['name' => 'Toothpaste', 'unit' => 'tube', 'categories' => ['Personal Care'], 'locations' => ['Primary Bathroom', 'Travel Tote']],
            ['name' => 'Cat Food', 'unit' => 'can', 'categories' => ['Pet Supplies'], 'locations' => ['Basement Storage Shelf']],
            ['name' => 'Cat Litter', 'unit' => 'bag', 'categories' => ['Pet Supplies'], 'locations' => ['Basement Storage Shelf']],
            ['name' => 'Dog Treats', 'unit' => 'bag', 'categories' => ['Pet Supplies'], 'locations' => ['Kitchen Cabinet']],
            ['name' => 'Pet Waste Bags', 'unit' => 'roll', 'categories' => ['Pet Supplies'], 'locations' => ['Entryway', 'Car Emergency Kit']],
            ['name' => 'Chocolate Chips', 'unit' => 'bag', 'categories' => ['Snacks'], 'locations' => ['Main Pantry']],
            ['name' => 'Crackers', 'unit' => 'box', 'categories' => ['Snacks'], 'locations' => ['Main Pantry']],
            ['name' => 'Granola Bars', 'unit' => 'box', 'categories' => ['Snacks'], 'locations' => ['Main Pantry', 'Car Emergency Kit']],
            ['name' => 'Popcorn', 'unit' => 'bag', 'categories' => ['Snacks'], 'locations' => ['Main Pantry']],
            ['name' => 'Trail Mix', 'unit' => 'bag', 'categories' => ['Snacks'], 'locations' => ['Main Pantry', 'Travel Tote']],
            ['name' => 'Car Wash Soap', 'unit' => 'bottle', 'categories' => ['Tools & Hardware'], 'locations' => ['Garage']],
            ['name' => 'Duct Tape', 'unit' => 'roll', 'categories' => ['Tools & Hardware'], 'locations' => ['Tool Bench']],
            ['name' => 'Hammer', 'unit' => 'each', 'categories' => ['Tools & Hardware'], 'locations' => ['Tool Bench']],
            ['name' => 'Light Bulbs', 'unit' => 'pack', 'categories' => ['Tools & Hardware'], 'locations' => ['Tool Bench', 'Hall Closet']],
            ['name' => 'Measuring Tape', 'unit' => 'each', 'categories' => ['Tools & Hardware'], 'locations' => ['Tool Bench']],
            ['name' => 'Nails', 'unit' => 'box', 'categories' => ['Tools & Hardware'], 'locations' => ['Tool Bench']],
            ['name' => 'Screwdriver Set', 'unit' => 'set', 'categories' => ['Tools & Hardware'], 'locations' => ['Tool Bench']],
            ['name' => 'WD-40', 'unit' => 'can', 'categories' => ['Tools & Hardware'], 'locations' => ['Tool Bench']],
            ['name' => 'Beach Towels', 'unit' => 'each', 'categories' => ['Travel & Seasonal'], 'locations' => ['Basement Storage Shelf']],
            ['name' => 'Extension Cord', 'unit' => 'each', 'categories' => ['Travel & Seasonal'], 'locations' => ['Garage']],
            ['name' => 'Flashlight', 'unit' => 'each', 'categories' => ['Travel & Seasonal', 'Tools & Hardware'], 'locations' => ['Hall Closet', 'Car Emergency Kit']],
            ['name' => 'Reusable Water Bottle', 'unit' => 'each', 'categories' => ['Travel & Seasonal'], 'locations' => ['Entryway', 'Travel Tote']],
            ['name' => 'Sunscreen', 'unit' => 'bottle', 'categories' => ['Travel & Seasonal', 'Personal Care'], 'locations' => ['Hall Closet', 'Travel Tote']],
            ['name' => 'Umbrella', 'unit' => 'each', 'categories' => ['Travel & Seasonal'], 'locations' => ['Entryway']],
            ['name' => 'Zip-Top Bags', 'unit' => 'box', 'categories' => ['Baking & Cooking'], 'locations' => ['Kitchen Cabinet']],
            ['name' => 'Canned Tomatoes', 'unit' => 'can', 'categories' => ['Baking & Cooking'], 'locations' => ['Main Pantry']],
            ['name' => 'Black Beans', 'unit' => 'can', 'categories' => ['Baking & Cooking'], 'locations' => ['Main Pantry']],
            ['name' => 'Oatmeal', 'unit' => 'box', 'categories' => ['Baking & Cooking'], 'locations' => ['Main Pantry']],
            ['name' => 'Cereal', 'unit' => 'box', 'categories' => ['Baking & Cooking'], 'locations' => ['Main Pantry']],
            ['name' => 'Cornstarch', 'unit' => 'box', 'categories' => ['Baking & Cooking'], 'locations' => ['Main Pantry']],
            ['name' => 'Maple Syrup', 'unit' => 'bottle', 'categories' => ['Baking & Cooking'], 'locations' => ['Main Pantry']],
            ['name' => 'Vegetable Stock', 'unit' => 'carton', 'categories' => ['Baking & Cooking'], 'locations' => ['Main Pantry']],
            ['name' => 'Worcestershire Sauce', 'unit' => 'bottle', 'categories' => ['Baking & Cooking'], 'locations' => ['Kitchen Cabinet']],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function legacyNames(): array
    {
        return [
            'Demo Toilet Paper' => 'Toilet Paper',
            'Demo Dish Soap' => 'Dish Soap',
            'Demo Paper Goods' => 'Paper Goods',
            'Demo Basement' => 'Basement',
            'Demo Main Pantry' => 'Main Pantry',
            'Demo Bathroom 1' => 'Primary Bathroom',
            'Demo Bathroom 2' => 'Guest Bathroom',
            'Demo Bathroom 3' => 'Hall Bathroom',
        ];
    }
}
