<?php

/**
 * Plugin Name: Article Feedback
 * Description: 任意ページに設置できるクリック式フィードバック機能
 * Version: 1.0.4
 *
 * <?php echo do_shortcode('[article_feedback]'); ?>
 */

if (!defined('ABSPATH')) {
  exit;
}

class Article_Feedback_Plugin {
  private $table;

  public function __construct() {
    global $wpdb;

    $this->table = $wpdb->prefix . 'article_feedback';

    register_activation_hook(__FILE__, [$this, 'activate']);

    add_shortcode('article_feedback', [$this, 'render_shortcode']);

    add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);

    add_action('wp_ajax_article_feedback_submit', [$this, 'handle_submit']);
    add_action('wp_ajax_nopriv_article_feedback_submit', [$this, 'handle_submit']);

    add_action('admin_menu', [$this, 'add_admin_menu']);
    add_action('admin_init', [$this, 'save_settings']);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
  }

  public function activate() {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$this->table} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      post_id BIGINT UNSIGNED NULL,
      page_url TEXT NOT NULL,
      choice VARCHAR(100) NOT NULL,
      ip_hash VARCHAR(64) NULL,
      user_agent TEXT NULL,
      device_type VARCHAR(20) NULL,
      referer TEXT NULL,
      created_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      KEY post_id (post_id),
      KEY choice (choice),
      KEY ip_hash (ip_hash),
      KEY device_type (device_type),
      KEY created_at (created_at)
    ) {$charset_collate};";

    dbDelta($sql);
  }

  public static function get_labels() {
    $defaults = [
      'helpful'     => '役に立った',
      'not_helpful' => '役に立たなかった',
      'not_found'   => '探している内容ではない',
    ];

    $saved = get_option('article_feedback_labels', []);

    return wp_parse_args($saved, $defaults);
  }

  private function detect_device_type($user_agent) {
    $ua = strtolower((string) $user_agent);

    if (preg_match('/iphone|ipod|android.*mobile|windows phone|blackberry|mobile/', $ua)) {
      return 'mobile';
    }

    if (preg_match('/ipad|android|tablet|kindle|silk/', $ua)) {
      return 'tablet';
    }

    return 'pc';
  }

  public function render_shortcode() {
    ob_start();

    $theme_template  = locate_template('article-feedback/feedback.php');
    $plugin_template = plugin_dir_path(__FILE__) . 'templates/feedback.php';

    if ($theme_template && file_exists($theme_template)) {
      include $theme_template;
    } elseif (file_exists($plugin_template)) {
      include $plugin_template;
    }

    return ob_get_clean();
  }

  public function enqueue_frontend_assets() {
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

  public function enqueue_admin_assets($hook) {
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

  public function handle_submit() {
    check_ajax_referer('article_feedback_nonce', 'nonce');

    global $wpdb;

    $choice  = sanitize_key($_POST['choice'] ?? '');
    $post_id = absint($_POST['post_id'] ?? 0);
    $url     = esc_url_raw($_POST['url'] ?? '');

    $allowed = ['helpful', 'not_helpful', 'not_found'];

    if (!$choice || !in_array($choice, $allowed, true)) {
      wp_send_json_error(['message' => 'invalid_choice'], 400);
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $ip_hash     = $ip ? hash('sha256', $ip . wp_salt()) : '';
    $device_type = $this->detect_device_type($ua);
    $referer     = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : '';

    /**
     * サーバー側：10秒以内の連続送信だけ制限
     */
    if ($ip_hash) {
      $exists = $wpdb->get_var(
        $wpdb->prepare(
          "SELECT id FROM {$this->table}
           WHERE ip_hash = %s
           AND created_at >= DATE_SUB(%s, INTERVAL 10 SECOND)
           LIMIT 1",
          $ip_hash,
          current_time('mysql')
        )
      );

      if ($exists) {
        wp_send_json_error(['message' => 'too_fast'], 429);
      }
    }

    $inserted = $wpdb->insert(
      $this->table,
      [
        'post_id'     => $post_id ?: null,
        'page_url'    => $url,
        'choice'      => $choice,
        'ip_hash'     => $ip_hash,
        'user_agent'  => sanitize_textarea_field($ua),
        'device_type' => $device_type,
        'referer'     => $referer,
        'created_at'  => current_time('mysql'),
      ],
      [
        '%d',
        '%s',
        '%s',
        '%s',
        '%s',
        '%s',
        '%s',
        '%s',
      ]
    );

    if (!$inserted) {
      wp_send_json_error(['message' => 'insert_failed'], 500);
    }

    wp_send_json_success();
  }

  public function add_admin_menu() {
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

  public function save_settings() {
    if (!isset($_POST['article_feedback_settings_nonce'])) {
      return;
    }

    if (!wp_verify_nonce($_POST['article_feedback_settings_nonce'], 'article_feedback_settings')) {
      return;
    }

    if (!current_user_can('manage_options')) {
      return;
    }

    update_option('article_feedback_labels', [
      'helpful'     => sanitize_text_field($_POST['label_helpful'] ?? ''),
      'not_helpful' => sanitize_text_field($_POST['label_not_helpful'] ?? ''),
      'not_found'   => sanitize_text_field($_POST['label_not_found'] ?? ''),
    ]);

    wp_safe_redirect(admin_url('admin.php?page=article-feedback&updated=1'));
    exit;
  }

  public function render_admin_page() {
    if (!current_user_can('manage_options')) {
      return;
    }

    global $wpdb;

    $labels = self::get_labels();

    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");

    $choice_counts_raw = $wpdb->get_results(
      "SELECT choice, COUNT(*) AS count
       FROM {$this->table}
       GROUP BY choice"
    );

    $choice_counts = [
      'helpful'     => 0,
      'not_helpful' => 0,
      'not_found'   => 0,
    ];

    foreach ($choice_counts_raw as $row) {
      if (isset($choice_counts[$row->choice])) {
        $choice_counts[$row->choice] = (int) $row->count;
      }
    }

    $device_counts_raw = $wpdb->get_results(
      "SELECT device_type, COUNT(*) AS count
       FROM {$this->table}
       GROUP BY device_type"
    );

    $device_counts = [
      'pc'     => 0,
      'mobile' => 0,
      'tablet' => 0,
    ];

    foreach ($device_counts_raw as $row) {
      $device = $row->device_type ?: 'unknown';

      if (!isset($device_counts[$device])) {
        $device_counts[$device] = 0;
      }

      $device_counts[$device] = (int) $row->count;
    }

    $page_ranking = $wpdb->get_results(
      "SELECT page_url, COUNT(*) AS count
       FROM {$this->table}
       GROUP BY page_url
       ORDER BY count DESC
       LIMIT 10"
    );

    $referer_ranking = $wpdb->get_results(
      "SELECT referer, COUNT(*) AS count
       FROM {$this->table}
       WHERE referer IS NOT NULL
       AND referer != ''
       GROUP BY referer
       ORDER BY count DESC
       LIMIT 10"
    );

    $rows = $wpdb->get_results(
      "SELECT *
       FROM {$this->table}
       ORDER BY created_at DESC
       LIMIT 200"
    );
?>
    <div class="wrap af-wrap">
      <div class="af-header">
        <h1 class="af-title">記事フィードバック</h1>
      </div>

      <?php if (isset($_GET['updated'])): ?>
        <div class="af-notice">設定を保存しました。</div>
      <?php endif; ?>

      <div class="af-grid">
        <div class="af-card">
          <p class="af-card-label">総件数</p>
          <p class="af-card-number"><?php echo esc_html(number_format($total)); ?></p>
        </div>

        <?php foreach ($choice_counts as $choice => $count): ?>
          <div class="af-card">
            <p class="af-card-label"><?php echo esc_html($labels[$choice] ?? $choice); ?></p>
            <p class="af-card-number"><?php echo esc_html(number_format($count)); ?></p>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="af-layout">
        <div>
          <div class="af-panel af-mb">
            <h2 class="af-panel-title">ラベル設定</h2>

            <form method="post">
              <?php wp_nonce_field('article_feedback_settings', 'article_feedback_settings_nonce'); ?>

              <div class="af-field">
                <label for="label_helpful">1つ目のラベル</label>
                <input id="label_helpful" type="text" name="label_helpful" value="<?php echo esc_attr($labels['helpful']); ?>">
              </div>

              <div class="af-field">
                <label for="label_not_helpful">2つ目のラベル</label>
                <input id="label_not_helpful" type="text" name="label_not_helpful" value="<?php echo esc_attr($labels['not_helpful']); ?>">
              </div>

              <div class="af-field">
                <label for="label_not_found">3つ目のラベル</label>
                <input id="label_not_found" type="text" name="label_not_found" value="<?php echo esc_attr($labels['not_found']); ?>">
              </div>

              <?php submit_button('保存', 'primary', 'submit', false); ?>
            </form>
          </div>

          <div class="af-panel af-mb">
            <h2 class="af-panel-title">選択肢別 集計</h2>

            <?php foreach ($choice_counts as $choice => $count): ?>
              <?php $percent = $total > 0 ? round(($count / $total) * 100, 1) : 0; ?>

              <div class="af-progress-item">
                <div class="af-progress-head">
                  <strong><?php echo esc_html($labels[$choice] ?? $choice); ?></strong>
                  <span class="af-muted">
                    <?php echo esc_html(number_format($count)); ?>件 / <?php echo esc_html($percent); ?>%
                  </span>
                </div>
                <div class="af-progress">
                  <div class="af-progress-bar" style="width: <?php echo esc_attr($percent); ?>%;"></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="af-panel">
            <h2 class="af-panel-title">デバイス別 集計</h2>

            <?php foreach ($device_counts as $device => $count): ?>
              <?php $percent = $total > 0 ? round(($count / $total) * 100, 1) : 0; ?>

              <div class="af-progress-item">
                <div class="af-progress-head">
                  <strong><?php echo esc_html($device); ?></strong>
                  <span class="af-muted">
                    <?php echo esc_html(number_format($count)); ?>件 / <?php echo esc_html($percent); ?>%
                  </span>
                </div>
                <div class="af-progress">
                  <div class="af-progress-bar" style="width: <?php echo esc_attr($percent); ?>%;"></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div>
          <div class="af-panel af-mb">
            <h2 class="af-panel-title">ページ別ランキング</h2>

            <div class="af-table-wrap">
              <table class="af-table">
                <thead>
                  <tr>
                    <th>URL</th>
                    <th>件数</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($page_ranking): ?>
                    <?php foreach ($page_ranking as $page): ?>
                      <tr>
                        <td class="af-url">
                          <a href="<?php echo esc_url($page->page_url); ?>" target="_blank" rel="noopener">
                            <?php echo esc_html($page->page_url); ?>
                          </a>
                        </td>
                        <td><?php echo esc_html(number_format((int) $page->count)); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr>
                      <td colspan="2">データがありません。</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="af-panel af-mb">
            <h2 class="af-panel-title">リファラ別ランキング</h2>

            <div class="af-table-wrap">
              <table class="af-table">
                <thead>
                  <tr>
                    <th>リファラ</th>
                    <th>件数</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($referer_ranking): ?>
                    <?php foreach ($referer_ranking as $referer): ?>
                      <tr>
                        <td class="af-url">
                          <a href="<?php echo esc_url($referer->referer); ?>" target="_blank" rel="noopener">
                            <?php echo esc_html($referer->referer); ?>
                          </a>
                        </td>
                        <td><?php echo esc_html(number_format((int) $referer->count)); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr>
                      <td colspan="2">データがありません。</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="af-panel">
            <h2 class="af-panel-title">最新ログ</h2>

            <div class="af-table-wrap">
              <table class="af-table">
                <thead>
                  <tr>
                    <th>日時</th>
                    <th>URL</th>
                    <th>選択</th>
                    <th>デバイス</th>
                    <th>リファラ</th>
                    <th>Post ID</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($rows): ?>
                    <?php foreach ($rows as $row): ?>
                      <tr>
                        <td><?php echo esc_html($row->created_at); ?></td>
                        <td class="af-url">
                          <a href="<?php echo esc_url($row->page_url); ?>" target="_blank" rel="noopener">
                            <?php echo esc_html($row->page_url); ?>
                          </a>
                        </td>
                        <td>
                          <span class="af-badge">
                            <?php echo esc_html($labels[$row->choice] ?? $row->choice); ?>
                          </span>
                        </td>
                        <td><?php echo esc_html($row->device_type ?: '-'); ?></td>
                        <td class="af-url">
                          <?php if (!empty($row->referer)): ?>
                            <a href="<?php echo esc_url($row->referer); ?>" target="_blank" rel="noopener">
                              <?php echo esc_html($row->referer); ?>
                            </a>
                          <?php else: ?>
                            <span class="af-muted">-</span>
                          <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($row->post_id); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr>
                      <td colspan="6">データがありません。</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
<?php
  }
}

new Article_Feedback_Plugin();
