<?php

/**
 * Plugin Name: Article Feedback
 * Description: 任意ページに設置できるクリック式フィードバック
 * Version: 1.0.1
 */

if (!defined('ABSPATH')) {
  exit;
}

class Article_Feedback_Plugin {
  private string $table;

  public function __construct() {
    global $wpdb;
    $this->table = $wpdb->prefix . 'article_feedback';

    register_activation_hook(__FILE__, [$this, 'activate']);

    add_shortcode('article_feedback', [$this, 'render']);

    add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    add_action('wp_ajax_article_feedback_submit', [$this, 'submit']);
    add_action('wp_ajax_nopriv_article_feedback_submit', [$this, 'submit']);

    add_action('admin_menu', [$this, 'menu']);
    add_action('admin_init', [$this, 'save_settings']);
  }

  /**
   * テーブル作成
   */
  public function activate(): void {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$this->table} (
      id BIGINT UNSIGNED AUTO_INCREMENT,
      post_id BIGINT UNSIGNED NULL,
      page_url TEXT NOT NULL,
      choice VARCHAR(50) NOT NULL,
      ip_hash VARCHAR(64),
      user_agent TEXT,
      created_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      KEY post_id (post_id),
      KEY choice (choice)
    ) {$wpdb->get_charset_collate()};";

    dbDelta($sql);
  }

  /**
   * フロント出力
   */
  public function render(): string {
    ob_start();

    $theme = locate_template('article-feedback/feedback.php');
    $plugin = plugin_dir_path(__FILE__) . 'templates/feedback.php';

    if ($theme) {
      include $theme;
    } else {
      include $plugin;
    }

    return ob_get_clean();
  }

  /**
   * JS読み込み
   */
  public function enqueue(): void {
    wp_enqueue_script(
      'article-feedback',
      plugin_dir_url(__FILE__) . 'assets/feedback.js',
      [],
      '1.0',
      true
    );

    wp_localize_script('article-feedback', 'AF_DATA', [
      'ajax' => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce('af_nonce'),
      'post_id' => get_queried_object_id(),
    ]);
  }

  /**
   * 送信処理
   */
  public function submit(): void {
    check_ajax_referer('af_nonce', 'nonce');

    global $wpdb;

    $choice = sanitize_key($_POST['choice'] ?? '');
    $post_id = absint($_POST['post_id'] ?? 0);
    $url = esc_url_raw($_POST['url'] ?? '');

    if (!$choice) {
      wp_send_json_error();
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $wpdb->insert(
      $this->table,
      [
        'post_id' => $post_id ?: null,
        'page_url' => $url,
        'choice' => $choice,
        'ip_hash' => $ip ? hash('sha256', $ip . wp_salt()) : '',
        'user_agent' => sanitize_text_field($ua),
        'created_at' => current_time('mysql'),
      ]
    );

    wp_send_json_success();
  }

  /**
   * ラベル取得
   */
  public static function get_labels(): array {
    $default = [
      'helpful' => '役に立った',
      'not_helpful' => '役に立たなかった',
      'not_found' => '探している内容ではない',
    ];

    return wp_parse_args(get_option('af_labels', []), $default);
  }

  /**
   * 設定保存
   */
  public function save_settings(): void {
    if (!isset($_POST['af_nonce_setting'])) return;
    if (!wp_verify_nonce($_POST['af_nonce_setting'], 'af_setting')) return;

    update_option('af_labels', [
      'helpful' => sanitize_text_field($_POST['helpful'] ?? ''),
      'not_helpful' => sanitize_text_field($_POST['not_helpful'] ?? ''),
      'not_found' => sanitize_text_field($_POST['not_found'] ?? ''),
    ]);
  }

  /**
   * 管理画面
   */
  public function menu(): void {
    add_menu_page(
      'Feedback',
      'Feedback',
      'manage_options',
      'af',
      [$this, 'admin']
    );
  }

  public function admin(): void {
    global $wpdb;
    $rows = $wpdb->get_results("SELECT * FROM {$this->table} ORDER BY created_at DESC LIMIT 100");
    $labels = self::get_labels();
?>
    <div class="wrap">
      <h1>フィードバック</h1>

      <form method="post">
        <?php wp_nonce_field('af_setting', 'af_nonce_setting'); ?>

        <h2>ラベル設定</h2>
        <input name="helpful" value="<?php echo esc_attr($labels['helpful']); ?>"><br>
        <input name="not_helpful" value="<?php echo esc_attr($labels['not_helpful']); ?>"><br>
        <input name="not_found" value="<?php echo esc_attr($labels['not_found']); ?>"><br>
        <?php submit_button(); ?>
      </form>

      <h2>ログ</h2>
      <table class="widefat">
        <tr>
          <th>日時</th>
          <th>URL</th>
          <th>選択</th>
        </tr>

        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?php echo esc_html($r->created_at); ?></td>
            <td><?php echo esc_html($r->page_url); ?></td>
            <td><?php echo esc_html($r->choice); ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
<?php
  }
}

new Article_Feedback_Plugin();
