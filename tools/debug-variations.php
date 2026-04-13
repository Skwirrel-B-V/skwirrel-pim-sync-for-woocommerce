<?php
/**
 * Debug Variation Attributes
 *
 * Place this file in your plugin root and access via:
 * yoursite.com/wp-content/plugins/skwirrel-woocommerce-sync/debug-variations.php
 *
 * Add ?key=debug123 to the URL for security
 */

// Security check
if (!isset($_GET['key']) || $_GET['key'] !== 'debug123') {
    die('Access denied');
}

// Load WordPress
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-load.php';

if (!current_user_can('manage_options')) {
    die('You must be an administrator');
}

header('Content-Type: text/plain; charset=utf-8');

echo "=== WooCommerce Variation Attributes Debug ===\n\n";

// Get the most recent variable product
$args = [
    'post_type' => 'product',
    'posts_per_page' => 1,
    'orderby' => 'ID',
    'order' => 'DESC',
    'tax_query' => [
        [
            'taxonomy' => 'product_type',
            'field' => 'slug',
            'terms' => 'variable',
        ],
    ],
];

$products = get_posts($args);

if (empty($products)) {
    echo "No variable products found!\n";
    exit;
}

$product_post = $products[0];
$product_id = $product_post->ID;

echo "Variable Product: #{$product_id} - {$product_post->post_title}\n";
echo str_repeat('=', 70) . "\n\n";

$product = wc_get_product($product_id);

if (!$product || !$product->is_type('variable')) {
    echo "Product is not variable!\n";
    exit;
}

echo "Parent Product Attributes:\n";
echo str_repeat('-', 70) . "\n";
foreach ($product->get_attributes() as $attr) {
    echo "Attribute: {$attr->get_name()}\n";
    echo "  - Is variation: " . ($attr->get_variation() ? 'YES' : 'NO') . "\n";
    echo "  - Is taxonomy: " . ($attr->is_taxonomy() ? 'YES' : 'NO') . "\n";
    echo "  - Options (term IDs): " . print_r($attr->get_options(), true);

    // Get actual term names
    if ($attr->is_taxonomy()) {
        $tax = $attr->get_name();
        $term_ids = $attr->get_options();
        echo "  - Term names: ";
        foreach ($term_ids as $tid) {
            $term = get_term($tid, $tax);
            if ($term && !is_wp_error($term)) {
                echo "{$term->name} (slug: {$term->slug}), ";
            }
        }
        echo "\n";
    }
    echo "\n";
}

echo "\nVariations:\n";
echo str_repeat('-', 70) . "\n";

$variations = $product->get_children();
if (empty($variations)) {
    echo "No variations found!\n";
} else {
    echo "Found " . count($variations) . " variations\n\n";

    foreach (array_slice($variations, 0, 5) as $var_id) {
        echo "--- Variation #{$var_id} ---\n";

        $variation = wc_get_product($var_id);
        if (!$variation) {
            echo "Could not load variation!\n\n";
            continue;
        }

        echo "SKU: {$variation->get_sku()}\n";

        // Check post meta directly
        global $wpdb;
        $meta = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta}
             WHERE post_id = %d AND meta_key LIKE 'attribute_%%'
             ORDER BY meta_key",
            $var_id
        ), ARRAY_A);

        echo "Post Meta (attribute_* keys):\n";
        if (empty($meta)) {
            echo "  *** NO ATTRIBUTE META FOUND! ***\n";
        } else {
            foreach ($meta as $row) {
                $value = maybe_unserialize($row['meta_value']);
                echo "  {$row['meta_key']} = " . print_r($value, true);
            }
        }

        // Check via WC object
        echo "WC_Product_Variation->get_attributes():\n";
        $attrs = $variation->get_attributes();
        if (empty($attrs)) {
            echo "  *** EMPTY! ***\n";
        } else {
            foreach ($attrs as $key => $value) {
                echo "  {$key} => {$value}\n";
            }
        }

        echo "\n";
    }
}

echo "\n=== Taxonomy Check ===\n";
echo str_repeat('-', 70) . "\n";

// Check if color and cups taxonomies exist
$taxonomies = ['pa_color', 'pa_cups', 'pa_skwirrel_variant'];
foreach ($taxonomies as $tax) {
    if (taxonomy_exists($tax)) {
        $terms = get_terms(['taxonomy' => $tax, 'hide_empty' => false]);
        echo "{$tax}: EXISTS\n";
        echo "  Terms: ";
        if (!empty($terms) && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                echo "{$term->name} (slug: {$term->slug}, id: {$term->term_id}), ";
            }
        } else {
            echo "NONE";
        }
        echo "\n";
    } else {
        echo "{$tax}: NOT FOUND\n";
    }
}

echo "\n=== Debug Complete ===\n";
