<?php

use Ninthspace\Hunch\Questions\Choice;

/*
 * The large-Choice group: one Choice of 45 departments, which is the shape a
 * roster question takes in practice. It is reported separately because a
 * long option list is where providers most often return something outside
 * the schema, and its instructions are long enough to exceed a provider's
 * minimum cacheable prefix, so it also carries the prompt-cache check.
 */
$departments = [
    'womenswear' => 'Women\'s clothing',
    'menswear' => 'Men\'s clothing',
    'childrenswear' => 'Clothing for children',
    'babywear' => 'Clothing for babies and toddlers',
    'footwear' => 'Shoes and boots',
    'accessories' => 'Bags, belts, scarves and hats',
    'jewellery' => 'Jewellery and watches',
    'beauty' => 'Cosmetics and skincare',
    'fragrance' => 'Perfume and aftershave',
    'homeware' => 'Kitchen and dining goods',
    'bedding' => 'Sheets, duvets and pillows',
    'furniture' => 'Chairs, tables and storage',
    'lighting' => 'Lamps and light fittings',
    'garden' => 'Plants, tools and outdoor furniture',
    'sports' => 'Sportswear and equipment',
    'outdoor' => 'Camping and hiking gear',
    'cycling' => 'Bicycles and cycling kit',
    'swimwear' => 'Swimming costumes and trunks',
    'lingerie' => 'Underwear and nightwear',
    'hosiery' => 'Socks and tights',
    'outerwear' => 'Coats and jackets',
    'knitwear' => 'Jumpers and cardigans',
    'denim' => 'Jeans and denim jackets',
    'tailoring' => 'Suits and formal wear',
    'occasionwear' => 'Party and wedding outfits',
    'workwear' => 'Uniforms and protective clothing',
    'vintage' => 'Second-hand and vintage pieces',
    'alterations' => 'Tailoring and repairs',
    'giftcards' => 'Gift cards and vouchers',
    'stationery' => 'Cards, pens and paper',
    'books' => 'Books and magazines',
    'toys' => 'Toys and games',
    'electronics' => 'Small electrical goods',
    'luggage' => 'Suitcases and travel bags',
    'petwear' => 'Clothing and accessories for pets',
    'maternity' => 'Maternity clothing',
    'adaptive' => 'Adaptive clothing for disabled customers',
    'sustainability' => 'Recycling and repair schemes',
    'deliveries' => 'Delivery and collection',
    'returns' => 'Returns and refunds',
    'payments' => 'Payments and billing',
    'accounts' => 'Online accounts and passwords',
    'loyalty' => 'Loyalty scheme and points',
    'stores' => 'Shop locations and opening hours',
    'careers' => 'Jobs and recruitment',
];

return [
    'context' => 'A department store routing desk. Choose the one department that should answer.',
    'questions' => [
        'department' => new Choice('Which department should handle this message?', $departments),
    ],
    'items' => [
        ['id' => 'large-01', 'state' => 'The heel snapped off my boot after a fortnight. Can it be repaired or replaced?', 'labels' => ['department' => 'footwear']],
        ['id' => 'large-02', 'state' => 'I cannot sign in. The site says my password is wrong and the reset email never comes.', 'labels' => ['department' => 'accounts']],
        ['id' => 'large-03', 'state' => 'Do you have the striped duvet cover in king size?', 'labels' => ['department' => 'bedding']],
        ['id' => 'large-04', 'state' => 'I was charged twice for the same order. Please refund the duplicate payment.', 'labels' => ['department' => 'payments']],
        ['id' => 'large-05', 'state' => 'My points balance has not gone up since March, despite several orders.', 'labels' => ['department' => 'loyalty']],
        ['id' => 'large-06', 'state' => 'Do you take in trousers that are too long, and what does it cost?', 'labels' => ['department' => 'alterations']],
        ['id' => 'large-07', 'state' => 'Is the Leeds shop open on Sunday, and until what time?', 'labels' => ['department' => 'stores']],
        ['id' => 'large-08', 'state' => 'Looking for a suit for a wedding in June. Do you do fittings?', 'labels' => ['department' => 'tailoring']],
        ['id' => 'large-09', 'state' => 'I want to send back two items from one order. What is the process?', 'labels' => ['department' => 'returns']],
        ['id' => 'large-10', 'state' => 'Do you stock dresses with magnetic fastenings for someone who cannot manage buttons?', 'labels' => ['department' => 'adaptive']],
        ['id' => 'large-11', 'state' => 'Are you hiring seasonal staff for the winter, and where do I apply?', 'labels' => ['department' => 'careers']],
        ['id' => 'large-12', 'state' => 'Can I bring in old jumpers to be recycled in exchange for a voucher?', 'labels' => ['department' => 'sustainability']],
    ],
];
