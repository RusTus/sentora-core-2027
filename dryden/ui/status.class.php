<?php

/**
 * The panel's status icons: good (green check), bad (red cross) and warning (amber
 * exclamation), drawn as inline SVG so they are sharp at any size and need no image
 * files. Styled by .sn-status in the theme's default.css; every module uses these
 * instead of its own up/down pictures.
 *
 *   ui_status::Ok('Enabled')    ui_status::Bad('Port 25 is closed')    ui_status::Warn('Update available')
 */
class ui_status
{
    const PATHS = array(
        'ok' => '<path d="M6.3 10.4l2.4 2.4 5-5.2" />',
        'bad' => '<path d="M7.2 7.2l5.6 5.6M12.8 7.2l-5.6 5.6" />',
        'warn' => '<path d="M10 6v5" /><path d="M10 14.1v.1" />',
    );

    static function Icon($kind, $title = '')
    {
        $kind = isset(self::PATHS[$kind]) ? $kind : 'warn';
        $label = ($title !== '') ? (string) $title : array('ok' => 'OK', 'bad' => 'Not OK', 'warn' => 'Warning')[$kind];
        $t = htmlspecialchars(ui_language::translate($label), ENT_QUOTES, 'UTF-8');
        return '<span class="sn-status sn-' . $kind . '" role="img" aria-label="' . $t . '" title="' . $t . '">'
            . '<svg viewBox="0 0 20 20" width="20" height="20" aria-hidden="true" focusable="false"><circle cx="10" cy="10" r="9" />' . self::PATHS[$kind] . '</svg></span>';
    }

    static function Ok($title = '')
    {
        return self::Icon('ok', $title);
    }

    static function Bad($title = '')
    {
        return self::Icon('bad', $title);
    }

    static function Warn($title = '')
    {
        return self::Icon('warn', $title);
    }
}
