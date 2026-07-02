<?php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Thin event bus. Feature modules emit normalized events; listeners (e.g. the
 * Integrations module) react. Keeps the comment/approval code decoupled from
 * any specific outbound destination.
 */
class DXF_Events {

    public const HOOK = 'dxf_event';

    /**
     * @param string $event One of: comment.created, comment.replied, approval.created
     * @param array  $data  Normalized, already-sanitized event payload.
     */
    public static function emit( string $event, array $data ): void {
        do_action( 'dxf_event', $event, $data );
    }
}
