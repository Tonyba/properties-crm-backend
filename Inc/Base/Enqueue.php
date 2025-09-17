<?php

namespace Inc\Base;

class Enqueue
{


    private $handle = 'properties-crm';

    public function register()
    {
        add_action('admin_enqueue_scripts', array($this, 'load_assets'));
    }

    function load_assets()
    {
        $manifest_path = PLUGIN_PATH . '/build/asset-manifest.json';
        $current_url = "https://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";

        $uid = uniqid();

        if (file_exists($manifest_path) && str_contains($current_url, $this->handle)) {

            $manifest = json_decode(file_get_contents($manifest_path), true);

            if (isset($manifest['files']['main.js'])) {

                $js_file = $manifest['files']['main.js'];

                wp_register_script(
                    $this->handle,
                    str_replace('./', '/', $js_file),
                    array(),
                    $uid,
                    true
                );

                wp_enqueue_script($this->handle);
            }

            if (isset($manifest['files']['main.css'])) {

                $css_file = $manifest['files']['main.css'];

                wp_enqueue_style(
                    $this->handle,
                    str_replace('./', '/', $css_file),
                    array(),
                    $uid,
                    'all'
                );
            }
        }

    }

    public function add_as_module($tag, $handle, $src)
    {

        if ($this->handle === $handle) {
            $tag = '<script defer type="module" src="' . esc_url($src) . '"></script>';
        }

        return $tag;
    }

}