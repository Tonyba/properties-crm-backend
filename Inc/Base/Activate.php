<?php 

namespace Inc\Base;

if(!defined('ABSPATH')) {
    die;
}

class Activate {


    public static function activate() {


        flush_rewrite_rules(  );
    }

}
