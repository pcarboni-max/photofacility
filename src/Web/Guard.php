<?php

declare(strict_types=1);

namespace PhotoFacility\Web;

use PhotoFacility\Config;

/**
 * Difesa in profondità OPZIONALE per la UI, oltre alla basic auth del webserver.
 *
 * Se UI_TOKEN è vuoto: nessun gate (ci si affida solo alla basic auth).
 * Se valorizzato: l'accesso richiede ?k=TOKEN (poi salvato in un cookie
 * httponly), così un'eventuale disattivazione accidentale della basic auth non
 * lascia la galleria e i download completamente aperti.
 */
final class Guard
{
    public static function enforce(Config $config): void
    {
        $token = $config->uiToken;
        if ($token === null || $token === '') {
            return; // gate disattivato
        }

        $provided = $_COOKIE['pf_ui'] ?? ($_GET['k'] ?? '');
        if (is_string($provided) && hash_equals($token, $provided)) {
            if (isset($_GET['k'])) {
                @setcookie('pf_ui', $token, [
                    'expires' => time() + 30 * 86400,
                    'path' => '/',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
            return;
        }

        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Forbidden\n";
        exit;
    }
}
