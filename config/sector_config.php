<?php
/**
 * MIAUDITOPS — Sector Configuration
 * Defines supported industry sectors and their templates.
 * Used by Company Setup to auto-seed outlet types, departments, and categories.
 */

$SECTORS = [

    'hospitality' => [
        'label'       => 'Hospitality & Restaurants',
        'icon'        => 'utensils',
        'color'       => 'rose',
        'outlet_types' => [
            'restaurant' => 'Restaurant',
            'bar'        => 'Bar / Lounge',
            'kitchen'    => 'Kitchen',
            'reception'  => 'Reception / Front Desk',
            'banquet'    => 'Banquet Hall',
            'cafe'       => 'Café / Bakery',
            'hotel'      => 'Hotel',
        ],
        'dept_templates' => ['Main Store', 'Kitchen Dept', 'Bar Dept', 'Floor / Service'],
        'expense_categories' => [
            'operating' => ['Food Supplies', 'Beverages', 'Kitchen Utensils', 'Cleaning Supplies', 'Gas / Cooking Fuel'],
            'admin'     => ['Staff Uniforms', 'Linen & Laundry', 'Pest Control', 'Decoration'],
        ],
        'has_kitchen'  => true,
        'description'  => 'Hotels, restaurants, bars, lounges, bakeries, and catering businesses.',
    ],

    'petroleum' => [
        'label'       => 'Gas / Filling Station',
        'icon'        => 'fuel',
        'color'       => 'orange',
        'outlet_types' => [
            'filling_station' => 'Filling Station',
            'depot'           => 'Fuel Depot',
            'lpg_plant'       => 'LPG / Gas Plant',
            'mini_mart'       => 'Station Mini-Mart',
            'lube_bay'        => 'Lube Bay / Car Wash',
            'office'          => 'Admin Office',
        ],
        'dept_templates' => ['Fuel Store', 'Lubricants Store', 'Mini-Mart Store', 'LPG Store'],
        'expense_categories' => [
            'operating' => ['PMS (Petrol)', 'AGO (Diesel)', 'DPK (Kerosene)', 'LPG (Gas)', 'Lubricants / Engine Oil', 'Pump Maintenance'],
            'admin'     => ['Generator Fuel', 'Security', 'Uniforms', 'Station Maintenance', 'Fire Safety Equipment'],
        ],
        'has_kitchen'  => false,
        'description'  => 'Petrol stations, fuel depots, LPG plants, and lube bays.',
    ],

    'retail' => [
        'label'       => 'Retail & Supermarkets',
        'icon'        => 'shopping-cart',
        'color'       => 'blue',
        'outlet_types' => [
            'store'     => 'Retail Store',
            'warehouse' => 'Warehouse',
            'kiosk'     => 'Kiosk / Stand',
            'ecommerce' => 'E-Commerce',
            'showroom'  => 'Showroom',
        ],
        'dept_templates' => ['Main Store', 'Groceries Dept', 'Electronics Dept', 'General Merchandise'],
        'expense_categories' => [
            'operating' => ['Merchandise / Stock', 'Packaging Materials', 'POS Supplies', 'Store Signage'],
            'admin'     => ['Store Maintenance', 'Security', 'Staff Uniforms', 'Cleaning'],
        ],
        'has_kitchen'  => false,
        'description'  => 'Supermarkets, retail chains, kiosks, and online stores.',
    ],

    'healthcare' => [
        'label'       => 'Healthcare & Pharmacy',
        'icon'        => 'heart-pulse',
        'color'       => 'emerald',
        'outlet_types' => [
            'clinic'    => 'Clinic / Hospital',
            'pharmacy'  => 'Pharmacy',
            'lab'       => 'Laboratory',
            'ward'      => 'Ward / ICU',
            'dental'    => 'Dental Clinic',
        ],
        'dept_templates' => ['Pharmacy Store', 'Lab Supplies', 'Ward Supplies', 'Consumables'],
        'expense_categories' => [
            'operating' => ['Drugs & Medicine', 'Lab Reagents', 'Medical Consumables', 'Surgical Supplies'],
            'admin'     => ['Equipment Maintenance', 'Waste Disposal', 'Scrubs / PPE', 'Sterilization'],
        ],
        'has_kitchen'  => false,
        'description'  => 'Hospitals, clinics, pharmacies, and diagnostic centers.',
    ],

    'construction' => [
        'label'       => 'Construction & Real Estate',
        'icon'        => 'hard-hat',
        'color'       => 'amber',
        'outlet_types' => [
            'site'      => 'Project Site',
            'office'    => 'Site Office',
            'warehouse' => 'Material Warehouse',
            'yard'      => 'Equipment Yard',
        ],
        'dept_templates' => ['Material Store', 'Equipment Yard', 'Site Office Store'],
        'expense_categories' => [
            'operating' => ['Cement / Concrete', 'Steel / Iron', 'Sand / Gravel', 'Timber', 'Plumbing Supplies', 'Electrical Materials'],
            'admin'     => ['Equipment Hire', 'Labour Contractor', 'Safety Gear / PPE', 'Site Security'],
        ],
        'has_kitchen'  => false,
        'description'  => 'Construction firms, property developers, and real estate companies.',
    ],

    'education' => [
        'label'       => 'Schools & Education',
        'icon'        => 'graduation-cap',
        'color'       => 'violet',
        'outlet_types' => [
            'campus'    => 'Campus / School',
            'admin'     => 'Admin Block',
            'hostel'    => 'Hostel / Boarding',
            'lab'       => 'Laboratory',
            'library'   => 'Library',
        ],
        'dept_templates' => ['Main Store', 'Science Lab Store', 'Library', 'Sports Store'],
        'expense_categories' => [
            'operating' => ['Textbooks / Materials', 'Lab Equipment', 'Stationery', 'Sports Equipment'],
            'admin'     => ['Maintenance', 'Printing / Photocopying', 'Security', 'Transport'],
        ],
        'has_kitchen'  => false,
        'description'  => 'Schools, universities, training centers, and educational institutions.',
    ],

    'logistics' => [
        'label'       => 'Logistics & Transport',
        'icon'        => 'truck',
        'color'       => 'cyan',
        'outlet_types' => [
            'depot'     => 'Transport Depot',
            'fleet'     => 'Fleet Yard',
            'office'    => 'Dispatch Office',
            'loading'   => 'Loading Bay / Terminal',
        ],
        'dept_templates' => ['Parts Store', 'Fuel Depot', 'Tyre Store'],
        'expense_categories' => [
            'operating' => ['Diesel / Fuel', 'Spare Parts', 'Tyres', 'Vehicle Insurance', 'Toll / Road Fees'],
            'admin'     => ['Driver Welfare', 'Tracking / GPS', 'Licensing / Permits', 'Vehicle Inspection'],
        ],
        'has_kitchen'  => false,
        'description'  => 'Haulage, delivery services, fleet management, and courier companies.',
    ],

    'manufacturing' => [
        'label'       => 'Manufacturing',
        'icon'        => 'factory',
        'color'       => 'indigo',
        'outlet_types' => [
            'factory'   => 'Factory / Plant',
            'warehouse' => 'Finished Goods Warehouse',
            'assembly'  => 'Assembly Line',
            'qc_lab'    => 'Quality Control Lab',
        ],
        'dept_templates' => ['Raw Materials Store', 'WIP Store', 'Finished Goods Store', 'Packaging Store'],
        'expense_categories' => [
            'operating' => ['Raw Materials', 'Machine Parts', 'Packaging Materials', 'Quality Testing'],
            'admin'     => ['Machine Maintenance', 'Factory Safety', 'Waste Management', 'Worker PPE'],
        ],
        'has_kitchen'  => false,
        'description'  => 'Factories, assembly plants, and production facilities.',
    ],

    'other' => [
        'label'       => 'Other / General',
        'icon'        => 'briefcase',
        'color'       => 'slate',
        'outlet_types' => [
            'branch'    => 'Branch Office',
            'office'    => 'Head Office',
            'warehouse' => 'Warehouse',
            'other'     => 'Other',
        ],
        'dept_templates' => ['Main Store'],
        'expense_categories' => [
            'operating' => ['Supplies', 'Maintenance', 'Services'],
            'admin'     => ['Office Supplies', 'Utilities', 'Cleaning'],
        ],
        'has_kitchen'  => false,
        'description'  => 'Any other business type not listed above.',
    ],
];

/**
 * Get sector config by key.
 * @param string $sector_key
 * @return array|null
 */
function get_sector_config($sector_key) {
    global $SECTORS;
    return $SECTORS[$sector_key] ?? $SECTORS['other'];
}

/**
 * Get outlet types for a given sector.
 * @param string $sector_key
 * @return array  ['key' => 'Label', ...]
 */
function get_sector_outlet_types($sector_key) {
    $config = get_sector_config($sector_key);
    return $config['outlet_types'];
}

/**
 * Check if a sector supports kitchen departments.
 * @param string $sector_key
 * @return bool
 */
function sector_has_kitchen($sector_key) {
    $config = get_sector_config($sector_key);
    return $config['has_kitchen'] ?? false;
}

/**
 * Get all sectors as a simple array for dropdowns.
 * @return array  [['key' => '...', 'label' => '...', 'icon' => '...', 'description' => '...'], ...]
 */
function get_all_sectors() {
    global $SECTORS;
    $result = [];
    foreach ($SECTORS as $key => $cfg) {
        $result[] = [
            'key'         => $key,
            'label'       => $cfg['label'],
            'icon'        => $cfg['icon'],
            'color'       => $cfg['color'],
            'description' => $cfg['description'],
        ];
    }
    return $result;
}
?>
