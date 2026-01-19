<?php


namespace Inc\Api;

if (!defined('ABSPATH')) {
    die;
}

class PropertiesApi
{

    private $post_type = 'property';

    public function register()
    {
        add_action('wp_ajax_get_properties', array($this, 'get_properties'));
        add_action('wp_ajax_nopriv_get_properties', array($this, 'get_properties'));

        add_action('wp_ajax_search_property_by_name', array($this, 'get_property_by_name_or_id'));
        add_action('wp_ajax_nopriv_search_property_by_name', array($this, 'get_property_by_name_or_id'));

    }

    public function get_property_by_name_or_id()
    {

        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $id = isset($_POST['id']) ? sanitize_text_field($_POST['id']) : '';

        $args = array(
            'post_type' => $this->post_type,
            's' => $name,
            'fields' => 'ids',
            'search_columns' => array('post_title'),
            'posts_per_page' => -1,
            'post_status' => 'publish'
        );

        if (!empty($id) && is_numeric($id)) {
            $args['p'] = intval($id);
            unset($args['s']);
            unset($args['search_columns']);
        }

        $query = new \WP_Query($args);

        $data = [];

        foreach ($query->posts as $id) {
            $title = get_the_title($id);
            $data[] = [
                'title' => $title,
                'id' => $id,
            ];
        }

        return wp_send_json($data);
    }

    public function get_properties()
    {

        $filters = $_POST['filters'];
        $locations = isset($_POST['location']) ? rest_sanitize_boolean($_POST['location']) : false;
        $draw = $_POST['draw'];
        $row = $_POST['start'];
        $row_per_page = $_POST['length'];
        $column_index = $_POST['order'][0]['column'];
        $column_name = $_POST['columns'][$column_index]['data'];
        $column_sort_order = $_POST['order'][0]['dir'];
        $searchValue = $_POST['search']['value'];

        $data_arr = [];

        $field_groups = acf_get_field_groups([
            'post_type' => $this->post_type
        ]);

        $fields = [];
        foreach ($field_groups as $group) {
            $fields = acf_get_fields($group['key']);
        }

        $fields = wp_list_pluck($fields, 'name');

        $taxonomies = get_taxonomies(['object_type' => [$this->post_type]]);
        $taxonomies_arr = [];

        foreach ($taxonomies as $taxonomy => $value) {
            $taxonomies_arr[] = $value;
        }

        $filters = sanitize_associative_array($filters);

        $order = isset($_POST['order']) ? sanitize_text_field($_POST['order']) : '';
        $orderby = isset($_POST['orderby']) ? sanitize_text_field($_POST['orderby']) : '';

        $per_page = isset($_POST['per_page']) ? sanitize_text_field($_POST['per_page']) : '';


        $args = array(
            'post_type' => $this->post_type,
            'fields' => 'ids',
            'posts_per_page' => intval($row_per_page),
            'offset' => $row,
            'meta_query' => [],
            'tax_query' => [],
            'post_status' => 'publish',
        );


        if (!empty($per_page)) {
            $per_page = intval($per_page);
            $max_per_page = 100; // define a reasonable max if not already defined
            if ($per_page > $max_per_page)
                $per_page = $max_per_page;
            $args['posts_per_page'] = $per_page;
        }

        if (!empty($order)) {
            $args['order'] = $order;
        }

        if (!empty($orderby)) {

            if (str_contains($orderby, 'price')) {
                $args['orderby'] = 'meta_value_num';
                $args['meta_key'] = 'price';
            } else {
                $args['orderby'] = $orderby;
            }

        }



        if (!empty($filters)) {
            $args['tax_query']['relation'] = 'AND';
            $args['meta_query'][] = ['relation' => 'AND'];

            foreach ($filters as $item => $value) {

                if (in_array($item, $taxonomies_arr)) {
                    $args['tax_query'][] = [
                        'taxonomy' => $item,
                        'terms' => array($value),
                        'field' => 'term_id'
                    ];
                }

                if (in_array($item, $fields) || strpos($item, 'price') !== false || strpos($item, 'size') !== false) {

                    if ($value === 'yes') {
                        $value = 1;
                    } elseif ($value === 'no') {
                        $value = 0;
                    }

                    if (strpos($item, 'price') !== false || strpos($item, 'size') !== false) {

                        $meta_name = explode('_', $item)[1];
                        $min_key = 'min_' . $meta_name;
                        $max_key = 'max_' . $meta_name;
                        $min = $filters[$min_key] ?? null;
                        $max = $filters[$max_key] ?? null;

                        if (empty($max)) {
                            $args['meta_query'][0][] = [
                                'key' => $meta_name,
                                'value' => intval($min),
                                'type' => 'numeric',
                                'compare' => '>='
                            ];
                        } elseif (empty($min)) {
                            $args['meta_query'][0][] = [
                                'key' => $meta_name,
                                'value' => intval($max),
                                'type' => 'numeric',
                                'compare' => '<='
                            ];
                        } else {
                            $args['meta_query'][0][] = [
                                'key' => $meta_name,
                                'value' => [intval($min), intval($max)],
                                'type' => 'numeric',
                                'compare' => 'BETWEEN'
                            ];
                        }

                    } else {
                        $args['meta_query'][0][] = [
                            'key' => $item,
                            'value' => $value,
                            'compare' => '='
                        ];
                    }
                }
            }
        }

        if (isset($filters['search_term']) && !empty($filters['search_term'])) {
            $args['search_title'] = $filters['search_term'];
        }

        add_filter('posts_where', 'title_filter', 10, 2);
        $query = new \WP_Query($args);
        remove_filter('posts_where', 'title_filter', 10, 2);


        $locations_to_send = [];


        if ($locations) {
            $args['posts_per_page'] = 9999;
            $args['no_found_rows'] = true;
            unset($args["paged"]);

            add_filter('posts_where', 'title_filter', 10, 2);
            $locations_query = new WP_Query($args);
            remove_filter('posts_where', 'title_filter', 10, 2);

            foreach ($locations_query->posts as $item) {
                $data_obj = construct_object($item, true);
                $locations_to_send[] = $data_obj;
            }

        }

        foreach ($query->posts as $id) {
            $data_arr[] = $this->construct_object($id);
        }

        $counts = wp_count_posts($this->post_type);
        $total_records = $counts->publish;

        $total_records_filtered = $query->found_posts;

        $response = array(
            'draw' => intval($draw),
            'recordsTotal' => intval($total_records),
            'recordsFiltered' => $total_records_filtered,
            'data' => $data_arr
        );

        if ($locations)
            $response['locations'] = $locations_to_send;

        return wp_send_json($response);

    }

    private function construct_object($id)
    {
        $title = get_the_title($id);
        $terms = wp_get_post_terms($id, array('property-type'));
        $link = get_post_permalink($id);

        $image_url = get_the_post_thumbnail_url($id, 'medium_large');


        $price = get_field('price', $id);
        $property_id = get_field('property_id', $id);
        $bedrooms = get_field('rooms', $id);
        $size = get_field('size', $id);
        $location = get_field('property_short_location', $id);

        $gallery = get_field('gallery', $id);

        if (is_array($gallery))
            $gallery = wp_list_pluck($gallery, 'id');
        if (is_string($gallery))
            $gallery = explode(',', $gallery);

        $gallery_arr = [];
        $gallery_arr[] = $image_url;


        if (is_array($gallery)) {

            foreach ($gallery as $gallery_item) {
                $gallery_arr[] = wp_get_attachment_image_url($gallery_item, 'medium_large');
            }

        }


        $obj = array(
            'id' => $id,
            'property_id' => $property_id,
            'title' => $title,
            'location' => $location,
            'gallery' => $gallery_arr,
            'link' => $link,
            'bedrooms' => $bedrooms,
            'size' => $size,
            'price' => $price ? format_price($price, $id) : 'Çmimi sipas kërkesës',
        );

        foreach ($terms as $term) {
            $term_taxonomy = $term->taxonomy;
            $obj[$term_taxonomy] = $term->name;
        }

        return $obj;
    }

}
