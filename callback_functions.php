<?php
/**
 * Plugin Name: Products Callback URL
 * Author: Anwar
 */

add_action('rest_api_init', 'register_products_callback_url');

function register_products_callback_url() {
    error_log('register_products_callback_url function called'); 
    register_rest_route(
        'myPlugin/v1',  
        'receive-callback', 
        array(
            'methods'   => 'POST',
            'callback'  => 'handle_products_callback_url',
            'permission_callback' => '__return_true', 
        )
    );
}

function handle_products_callback_url($request) {
    $parameters = $request->get_params();
    $skippedItems = array();
    $processedItems = array();

    if (isset($parameters['products'])) {
        foreach ($parameters['products'] as $index => $product) {
            $errors = array();

            if (!isset($product['name'])) {
                $errors[] = 'Name is required';
            }
            if (!isset($product['sku'])) {
                $errors[] = 'Sku is required';
            }
            if (!isset($product['stock'])) {
                $errors[] = 'Stock is required';
            }
            if (!isset($product['price'])) {
                $errors[] = 'Price is required';
            }
            if (!isset($product['category'])) {
                $errors[] = 'Category is required';
            }
            if (!isset($product['subcategory'])) {
                $errors[] = 'Subcategory is required';
            }

            if (!empty($errors)) {
                $skippedItems[] = array(
                    'product' => $product,
                    'errors' => $errors
                );
                continue;
            }

            $existingProduct = wc_get_product_id_by_sku(sanitize_text_field($product['sku']));
            if ($existingProduct) {
                $skippedItems[] = array(
                    'product' => $product,
                    'errors' => array('Duplicate SKU, product already exists')
                );
                continue;
            }

            // Create new WooCommerce product
            $wc_product = new WC_Product();
            $wc_product->set_name(sanitize_text_field($product['name']));
            $wc_product->set_regular_price(sanitize_text_field($product['price']));
            $wc_product->set_status('publish');

            if (isset($product['description'])) {
                $wc_product->set_description(sanitize_textarea_field($product['description']));
            }
            if (isset($product['sku'])) {
                $wc_product->set_sku(sanitize_text_field($product['sku']));
            }
            if (isset($product['stock'])) {
                $wc_product->set_stock_quantity(intval($product['stock']));
            }
            
            if (isset($product['tags'])) {
                $tags = array_map('sanitize_text_field', (array)$product['tags']); 
                $wc_product->set_tag_ids($tags);
            }

            //handling categories and subcategories
            $category_ids = array();
            $subcategory_ids = array();

            $categoryName = sanitize_text_field($product['category']);
            $category_id = term_exists($categoryName, 'product_cat');
            if (!$category_id) {
                $category_id = wp_insert_term($categoryName, 'product_cat');
                $category_id = is_array($category_id) ? $category_id['term_id'] : $category_id;
            }
            $category_ids[] = $category_id;

            foreach ((array)$product['subcategory'] as $subcategory_name) {
                $subcategory_name = sanitize_text_field($subcategory_name);
                $subcategory_id = term_exists($subcategory_name, 'product_cat');

                if (!$subcategory_id) {
                    $subcategory_id = wp_insert_term($subcategory_name, 'product_cat');
                    $subcategory_id = is_array($subcategory_id) ? $subcategory_id['term_id'] : $subcategory_id;
                }

                $subcategory_ids[] = $subcategory_id;
            }

            $wc_product->set_category_ids(array_merge($category_ids, $subcategory_ids));

            // Save the product
            $wc_product->save();
            $processedItems[] = $product;
        }
        
        $response = array(
            'status' => 'success',
            'message' => 'Products received and processed successfully.',
            'processed_items' => $processedItems,
        );

        if (!empty($skippedItems)) {
            $response['skipped_products'] = $skippedItems;
            $response['status'] = 'partial_success';
            $response['message'] = 'Some products were skipped due to errors.';
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