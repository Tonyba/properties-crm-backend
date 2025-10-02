<?php

namespace interfaces;

/**
 * @property string $post_type
 * */
interface ApiInterface
{
    public function register();
    public function construct_object($id): array;
    public function get_taxonomies();
}