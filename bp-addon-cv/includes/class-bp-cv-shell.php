<?php
namespace BP_CV;

defined('ABSPATH') || exit;

final class Shell {

    public static function init(): void {
        add_filter('template_include', [__CLASS__, 'maybe_override_template'], 99);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_shell_assets']);
    }

    public static function enqueue_shell_assets(): void {
        if (self::is_cv_shell_request()) {
            wp_enqueue_style('bp-cv-shell', BP_CV_URL . 'assets/css/shell.css', [], BP_CV_VERSION);
            if (function_exists('bp_core_get_brand_colors')) {
                $c = bp_core_get_brand_colors();
                $css = ':root{'
                    . '--kb-blue:' . esc_attr((string) $c['blue']) . ';'
                    . '--kb-mid:' . esc_attr((string) $c['mid']) . ';'
                    . '--kb-orange:' . esc_attr((string) $c['orange']) . ';'
                    . '--kb-purple:' . esc_attr((string) $c['purple']) . ';'
                    . '--kb-bg:' . esc_attr((string) $c['bg']) . ';'
                    . '--kb-border:' . esc_attr((string) $c['border']) . ';'
                    . '--kb-text:' . esc_attr((string) $c['text']) . ';'
                    . '--kb-muted:' . esc_attr((string) $c['muted']) . ';'
                    . '}';
                wp_add_inline_style('bp-cv-shell', $css);
            }
        }
    }

    public static function maybe_override_template($template) {
        if (!self::is_cv_shell_request()) return $template;
        return BP_CV_DIR . 'templates/bp-cv-shell.php';
    }

    private static function is_cv_shell_request(): bool {
        if (!is_singular() || !isset($GLOBALS['post']) || !is_object($GLOBALS['post'])) return false;
        $post = $GLOBALS['post'];
        $content = (string) $post->post_content;

        if (stripos($content, '[bp_cv') === false) return false;

        // If user explicitly sets shell="0" then do NOT override.
        if (preg_match('/\[bp_cv[^\]]*shell\s*=\s*["\']?0["\']?/i', $content)) {
            return false;
        }

        // Only override on frontend.
        if (is_admin()) return false;

        // Only if logged in OR it will still show login, that's fine.
        return true;
    }

    public static function nav_items(): array {
        // Try to use existing pages
        $items = [];
        $candidates = [
            ['slug' => 'dashboard', 'label' => 'Dashboard', 'icon' => '👤'],
            ['slug' => 'uitleg', 'label' => 'Uitleg', 'icon' => '❓'],
            ['slug' => 'beheer', 'label' => 'Beheer', 'icon' => '⚙️'],
        ];
        foreach ($candidates as $c) {
            $url = Util::get_portal_url($c['slug']);
            $items[] = ['url' => $url, 'label' => $c['label'], 'icon' => $c['icon']];
        }
        return $items;
    }
}
