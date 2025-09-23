<?php

namespace Inc\Api;

if (!defined('ABSPATH')) {
    die;
}

class EventsApi
{
    private $post_type = 'event';


    public function register()
    {
        add_action('wp_ajax_create_event', array($this, 'create_event'));
        add_action('wp_ajax_nopriv_create_event', array($this, 'create_event'));
    }

    public function create_event()
    {
        $fields = $_POST['fields'];
        $user_id = isset($fields['assigned_to']) ? $fields['assigned_to'] : get_current_user_id();
        $relation_id = $fields['relation'];

        $data = [
            'post_title' => $fields['subject'],
            'post_author' => $user_id,
            'post_type' => $this->post_type,
        ];


        $id = wp_insert_post($data);


        if (is_wp_error($id)) {
            return wp_send_json_error([
                'ok' => false,
                'msg' => 'error creating event'
            ]);
        }

        update_field('assigned_to', $user_id, $id);
        update_field('from', $fields['from'], $id);
        update_field('to', $fields['to'], $id);

        if (!empty($relation_id))
            update_field('relation', $relation_id, $id);


        wp_set_post_terms($id, $fields['status'], 'event-status');
        wp_set_post_terms($id, $fields['type'], 'event-type');

        return wp_send_json([
            'ok' => true,
            'msg' => 'event created'
        ]);

    }

    public function update_event()
    {

    }

    public function get_all_events()
    {

    }

    public function get_related_events()
    {

    }


    private function construct_object($id)
    {

        $obj = [];



        return $obj;

    }


}