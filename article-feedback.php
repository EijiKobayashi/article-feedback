<?php

/**
 * Plugin Name: Article Feedback
 * Description: 記事・固定ページ・任意テンプレート用のクリック式フィードバック機能
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
  exit;
}

class Article_Feedback_Plugin {
  private string $table_name;

  public function __construct() {
    global $wpdb;
    $this->table_name = $wpdb->prefix . 'article_feedback';

    register_activation_hook(__FILE__, [$this, 'activate']);

    add_shortcode('article_feedback', [$this, 'render_shortcode']);

    add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);

    add_action('wp_ajax_article_feedback_submit', [$this, 'handle_submit']);
    add_action('wp_ajax_nopriv_article_feedback_submit', [$this, 'handle_submit']);

    add_action('admin_menu', [$this, 'add_admin_menu']);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
  }

  public function activate(): void {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$this->table_name} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      post_id BIGINT UNSIGNED NULL,
      page_url TEXT NOT NULL,
      choice VARCHAR(100) NOT NULL,
      ip_hash VARCHAR(64) NULL,
      user_agent TEXT NULL,
      created_at DATETIME NOT NULL,
      PRIMARY KEY  (id),
      KEY post_id (post_id),
      KEY choice (choice),
      KEY created_at (created_at)
    ) {$charset_collate};";

    dbDelta($sql);
  }

  public function render_shortcode(): string {
    return '<div class="js-article-feedback"></div>';
  }

  public function enqueue_frontend_assets(): void {
    wp_enqueue_script(
      'article-feedback',
      plugin_dir_url(__FILE__) . 'assets/feedback.js',
      [],
      '1.0.0',
      true
    );

    wp_localize_script('article-feedback', 'ArticleFeedback', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'nonce'   => wp_create_nonce('article_feedback_nonce'),
      'postId'  => get_queried_object_id(),
    ]);
  }

  public function handle_submit(): void {
    check_ajax_referer('article_feedback_nonce', 'nonce');

    global $wpdb;

    $post_id  = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
    $choice   = isset($_POST['choice']) ? sanitize_key($_POST['choice']) : '';
    $page_url = isset($_POST['page_url']) ? esc_url_raw($_POST['page_url']) : '';

    $allowed_choices = [
      'helpful',
      'not_helpful',
      'not_found',
    ];

    if (!$choice || !in_array($choice, $allowed_choices, true)) {
      wp_send_json_error(['message' => 'Invalid choice.'], 400);
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $ip_hash = $ip ? hash('sha256', $ip . wp_salt()) : '';

    $inserted = $wpdb->insert(
      $this->table_name,
      [
        'post_id'    => $post_id ?: null,
        'page_url'   => $page_url,
        'choice'     => $choice,
        'ip_hash'    => $ip_hash,
        'user_agent' => sanitize_textarea_field($ua),
        'created_at' => current_time('mysql'),
      ],
      [
        '%d',
        '%s',
        '%s',
        '%s',
        '%s',
        '%s',
      ]
    );

    if (!$inserted) {
      wp_send_json_error(['message' => 'Failed to save feedback.'], 500);
    }

    wp_send_json_success(['message' => 'Thanks.']);
  }

  public function add_admin_menu(): void {
    add_menu_page(
      '記事フィードバック',
      '記事フィードバック',
      'manage_options',
      'article-feedback',
      [$this, 'render_admin_page'],
      'dashicons-chart-bar',
      58
    );
  }

  public function enqueue_admin_assets(string $hook): void {
    if ($hook !== 'toplevel_page_article-feedback') {
      return;
    }

    wp_enqueue_style(
      'article-feedback-admin',
      plugin_dir_url(__FILE__) . 'assets/admin.css',
      [],
      '1.0.0'
    );
  }

  public function render_admin_page(): void {
    if (!current_user_can('manage_options')) {
      return;
    }

    global $wpdb;

    $rows = $wpdb->get_results(
      "SELECT * FROM {$this->table_name} ORDER BY created_at DESC LIMIT 200"
    );

    $labels = [
      'helpful'     => '役に立った',
      'not_helpful' => '役に立たなかった',
      'not_found'   => '探している内容ではない',
    ];
?>
    <div class="wrap">
      <div class="af-container">
        <h1 class="af-title">記事フィードバック</h1>

        <table class="af-table">
          <thead>
            <tr>
              <th>日時</th>
              <th>ページ</th>
              <th>選択</th>
              <th>投稿ID</th>
              <th>User Agent</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($rows): ?>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td><?php echo esc_html($row->created_at); ?></td>
                  <td>
                    <a href="<?php echo esc_url($row->page_url); ?>" target="_blank" rel="noopener">
                      <?php echo esc_html($row->page_url); ?>
                    </a>
                  </td>
                  <td>
                    <span class="af-badge">
                      <?php echo esc_html($labels[$row->choice] ?? $row->choice); ?>
                    </span>
                  </td>
                  <td><?php echo esc_html($row->post_id); ?></td>
                  <td class="af-ua"><?php echo esc_html($row->user_agent); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="5">まだデータがありません。</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
<?php
  }
}

new Article_Feedback_Plugin();
