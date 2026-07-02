<?php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Feature bootstrap — wires on the multi-page / whole-site review and
 * email-invited reviewer features. Everything ships in this single plugin.
 */
final class DXF_Features {

    public function __construct() {
        new DXF_Multipage();
        new DXF_Email_Reviews();
    }
}
