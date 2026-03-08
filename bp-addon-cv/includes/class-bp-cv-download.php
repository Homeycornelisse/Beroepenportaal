<?php
namespace BP_CV;

defined('ABSPATH') || exit;

final class Download {

    public static function init(): void {
        add_action('admin_post_bp_cv_download', [__CLASS__, 'handle']);
    }

    public static function handle(): void {
        $owner_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
        $nonce    = isset($_GET['_wpnonce']) ? sanitize_text_field((string) wp_unslash($_GET['_wpnonce'])) : '';

        if ($owner_id <= 0 || !wp_verify_nonce($nonce, 'bp_cv_download_' . $owner_id)) {
            wp_die('Ongeldige download-link.');
        }

        $requester_id = get_current_user_id();
        if (!Util::can_download_cv($requester_id, $owner_id)) {
            wp_die('Geen toegang tot dit CV.');
        }

        $row = Util::get_kb_cv_row($owner_id);
        if (!$row || empty($row['pad'])) {
            wp_die('Geen CV gevonden.');
        }

        $file = (string) $row['pad'];
        $base = realpath(Util::upload_dir());
        $real = realpath($file);

        // Extra veiligheid: bestand moet binnen de kb-cv map liggen.
        if (!$base || !$real || strpos($real, $base) !== 0) {
            wp_die('Ongeldig bestandspad.');
        }

        if (!file_exists($real) || !is_readable($real)) {
            wp_die('Bestand niet gevonden.');
        }

        $filename = !empty($row['bestandsnaam']) ? (string)$row['bestandsnaam'] : basename($real);
        $filename = sanitize_file_name($filename);
        if ($filename === '') {
            $filename = 'cv';
        }
        if (strpos($filename, '.') === false) {
            $filename .= '.' . (string) (pathinfo($real, PATHINFO_EXTENSION) ?: 'pdf');
        }
        $check = wp_check_filetype_and_ext($real, $filename, Util::allowed_mimes());
        $mime = !empty($check['type']) ? $check['type'] : 'application/octet-stream';

        nocache_headers();
        header('Content-Type: ' . $mime);
	    header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Content-Length: ' . filesize($real));
        header('X-Content-Type-Options: nosniff');

        readfile($real);
        exit;
    }

    public static function download_url(int $owner_id): string {
        return wp_nonce_url(
            admin_url('admin-post.php?action=bp_cv_download&user_id=' . (int)$owner_id),
            'bp_cv_download_' . (int)$owner_id
        );
    }
}
