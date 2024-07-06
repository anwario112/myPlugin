<?php
/**
 * Plugin Name: Products Callback URL
 * Author: Anwar
 */

if (! defined('ABSPATH')) {
    die;
}

add_action('rest_api_init', 'register_products_callback_url');

function register_products_callback_url() {
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
        foreach ($parameters['products'] as $index => $product_data) {
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

            // Check if product with same SKU already exists
            $existingProductID = wc_get_product_id_by_sku(sanitize_text_field($product_data['sku']));

            if ($existingProductID) {
                //check if there is any updated product
                $existingProduct = wc_get_product($existingProductID);
                $update = false;

                if ($existingProduct->get_name() !== sanitize_text_field($product_data['name'])) {
                    $existingProduct->set_name(sanitize_text_field($product_data['name']));
                    $update = true;
                }
                if ($existingProduct->get_regular_price() !== sanitize_text_field($product_data['price'])) {
                    $existingProduct->set_regular_price(sanitize_text_field($product_data['price']));
                    $update = true;
                }
                if ($existingProduct->get_stock_quantity() !== intval($product_data['stock'])) {
                    $existingProduct->set_stock_quantity(intval($product_data['stock']));
                    $update = true;
                }
                if (!empty($product_data['description']) && $existingProduct->get_description() !== sanitize_textarea_field($product_data['description'])) {
                    $existingProduct->set_description(sanitize_textarea_field($product_data['description']));
                    $update = true;
                }
                if (!empty($product_data['tags'])) {
                    $tags = array_map('sanitize_text_field', (array)$product_data['tags']);
                    if ($existingProduct->get_tag_ids() !== $tags) {
                        $existingProduct->set_tag_ids($tags);
                        $update = true;
                    }
                }

                if ($update) {
                    //save the updated product
                    $existingProduct->save();
                    $processedItems[] = $product_data;
                } else {
                   //no updated product being reconized
                    $skippedItems[] = array(
                        'product' => $product_data,
                        'errors' => array('No changes detected for existing product')
                    );
                }
            } else {
                
                $wc_product = new WC_Product();
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

                // Handle categories and subcategories
                $category_ids = array();
                $subcategory_ids = array();

                $categoryName = sanitize_text_field($product_data['category']);
                $category_id = term_exists($categoryName, 'product_cat');
                if (!$category_id) {
                    $category_id = wp_insert_term($categoryName, 'product_cat');
                    $category_id = is_array($category_id) ? $category_id['term_id'] : $category_id;
                }
                $category_ids[] = $category_id;

                foreach ((array)$product_data['subcategory'] as $subcategory_name) {
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
                $processedItems[] = $product_data;
            }
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
