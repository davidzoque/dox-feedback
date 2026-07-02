<?php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
    exit;
}

class DXF_Autoloader {

    public static function register(): void {
        spl_autoload_register([self::class, 'load']);
    }

    public static function load(string $class): void {
        if ( strpos($class, 'DXF_') !== 0 ) {
            return;
        }

        // DXF_Foo_Bar -> class-foo-bar.php
        $suffix = substr($class, strlen('DXF'));
        $file   = 'class' . strtolower(str_replace('_', '-', $suffix)) . '.php';

        $locations = [
            DXF_DIR . 'includes/' . $file,
            DXF_DIR . 'includes/admin/' . $file,
            DXF_DIR . 'includes/modules/comments/' . $file,
            DXF_DIR . 'includes/modules/review-mode/' . $file,
            DXF_DIR . 'includes/modules/reviews/' . $file,
            DXF_DIR . 'includes/modules/approvals/' . $file,
            DXF_DIR . 'includes/modules/dashboard/' . $file,
        ];

        foreach ($locations as $path) {
            if ( file_exists($path) ) {
                require_once $path;
                return;
            }
        }
    }
}
