<?php
if (!defined('ABSPATH')) {
  exit;
}

$labels = Article_Feedback_Plugin::get_labels();
?>

<div class="article-feedback js-article-feedback">
  <p class="article-feedback__title">
    この記事は役に立ちましたか？
  </p>

  <div class="article-feedback__buttons">
    <button type="button" class="article-feedback__button" data-choice="helpful">
      <?php echo esc_html($labels['helpful']); ?>
    </button>

    <button type="button" class="article-feedback__button" data-choice="not_helpful">
      <?php echo esc_html($labels['not_helpful']); ?>
    </button>

    <button type="button" class="article-feedback__button" data-choice="not_found">
      <?php echo esc_html($labels['not_found']); ?>
    </button>
  </div>

  <p class="article-feedback__thanks js-af-thanks" style="display:none;">
    フィードバックありがとうございます。
  </p>
</div>