<?php
/**
 * Plugin Name: Products Callback URL
 * Author: Anwar
 */

if (!defined('ABSPATH')) {
    die;
}

add_action('rest_api_init', 'register_products_callback_url');

function register_products_callback_url() {
    register_rest_route(
        'myPlugin/v1',
        'products',
        array(
            'methods' => 'POST',
            'callback' => 'handle_products_callback_url',
            'permission_callback' => 'check_authorization_header',
        )
    );
}

function check_authorization_header($request) {
    $token = $request->get_header('x-authorization');
    if ($token !== 'Af0I0YIjRPGQJIBAtz9sF0vRXQF7Yy2YFjB092CthMHSzCol3lDDU7ZgbeVrmVvZ') {
        return new WP_Error('invalid_token', 'Not valid token', array('status' => 403));
    }
    return true;
}

function handle_products_callback_url($request) {
    global $wpdb;
    $parameters = $request->get_params();
    $skippedItems = array();
    $processedItems = array();

    if (isset($parameters['products'])) {
        foreach ($parameters['products'] as $product_data) {
            $errors = array();

            if (empty($product_data['name'])) {
                $errors[] = 'Name is required';
            }
            if (empty($product_data['sku'])) {
                $errors[] = 'Sku is required';
            }
            if (empty($product_data['stock'])) {
                $errors[] = 'Stock is required';
            }
            if (empty($product_data['price'])) {
                $errors[] = 'Price is required';
            }
            if (empty($product_data['category'])) {
                $errors[] = 'Category is required';
            }
            if (empty($product_data['subcategory'])) {
                $errors[] = 'Subcategory is required';
            }

            if (!empty($errors)) {
                $skippedItems[] = array(
                    'product' => $product_data,
                    'errors' => $errors
                );
                continue;
            }

            $product_sku = sanitize_text_field($product_data['sku']);
            $existingProductID = wc_get_product_id_by_sku($product_sku);
            $update = false;
            $wc_product = $existingProductID ? wc_get_product($existingProductID) : new WC_Product();

            // Check if product needs updating or creation
            if ($existingProductID) {
                if ($wc_product->get_name() !== sanitize_text_field($product_data['name'])) {
                    $wc_product->set_name(sanitize_text_field($product_data['name']));
                    $update = true;
                }
                if ($wc_product->get_regular_price() !== sanitize_text_field($product_data['price'])) {
                    $wc_product->set_regular_price(sanitize_text_field($product_data['price']));
                    $update = true;
                }
                if ($wc_product->get_stock_quantity() !== intval($product_data['stock'])) {
                    $wc_product->set_stock_quantity(intval($product_data['stock']));
                    $update = true;
                }
                if (!empty($product_data['description']) && $wc_product->get_description() !== sanitize_textarea_field($product_data['description'])) {
                    $wc_product->set_description(sanitize_textarea_field($product_data['description']));
                    $update = true;
                }
                if (!empty($product_data['tags'])) {
                    $tags = array_map('sanitize_text_field', (array)$product_data['tags']);
                    if ($wc_product->get_tag_ids() !== $tags) {
                        $wc_product->set_tag_ids($tags);
                        $update = true;
                    }
                }
                if (!$update) {
                    $skippedItems[] = array(
                        'product' => $product_data,
                        'errors' => array('No changes detected for existing product')
                    );
                    continue;
                }
            } else {
                $wc_product->set_name(sanitize_text_field($product_data['name']));
                $wc_product->set_regular_price(sanitize_text_field($product_data['price']));
                $wc_product->set_status('publish');

                if (!empty($product_data['description'])) {
                    $wc_product->set_description(sanitize_textarea_field($product_data['description']));
                }
                if (!empty($product_data['sku'])) {
                    $wc_product->set_sku(sanitize_text_field($product_data['sku']));
                }
                if (!empty($product_data['stock'])) {
                    $wc_product->set_stock_quantity(intval($product_data['stock']));
                }
                if (!empty($product_data['tags'])) {
                    $tags = array_map('sanitize_text_field', (array)$product_data['tags']);
                    $wc_product->set_tag_ids($tags);
                }
            }

            // Handle categories and subcategories
            $category_id = null;
            $subcategory_id = null;

            if (!empty($product_data['category'])) {
                $categoryName = sanitize_text_field($product_data['category']);
                $category_id = term_exists($categoryName, 'product_cat');
                if (!$category_id) {
                    $category_id = wp_insert_term($categoryName, 'product_cat');
                    $category_id = is_array($category_id) ? $category_id['term_id'] : $category_id;
                } else {
                    $category_id = is_array($category_id) ? $category_id['term_id'] : $category_id;
                }
            }

            if (!empty($product_data['subcategory'])) {
                $subcategoryName = sanitize_text_field($product_data['subcategory']);
                $subcategory_id = term_exists($subcategoryName, 'product_cat');
                if (!$subcategory_id) {
                    $subcategory_id = wp_insert_term($subcategoryName, 'product_cat', array('parent' => $category_id));
                    $subcategory_id = is_array($subcategory_id) ? $subcategory_id['term_id'] : $subcategory_id;
                } else {
                    $subcategory_id = is_array($subcategory_id) ? $subcategory_id['term_id'] : $subcategory_id;
                }
            }

            if ($category_id && $subcategory_id) {
                $wc_product->set_category_ids(array($category_id, $subcategory_id));
            }

            // Save the product
            $wc_product->save();
            $processedItems[] = $product_data;
        }

        $response = array(
            'status' => 'success',
            'message' => 'Products received and processed successfully.',
            'processed_items' => $processedItems,
        );

        if (!empty($skippedItems)) {
            $response['skipped_products'] = $skippedItems;
            $response['status'] = 'partial_success';
            $response['message'] = 'Some products were skipped due to errors or no changes detected.';
        }

    } else {
        $response = array(
            'status' => 'error',
            'message' => 'No products data received.',
        );
    }

    return rest_ensure_response($response);
}
?>