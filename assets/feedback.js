(() => {
  const widgets = document.querySelectorAll('.js-article-feedback');

  if (!widgets.length || typeof ArticleFeedback === 'undefined') {
    return;
  }

  const LIMIT_MS = 60000;

  const getKey = () => {
    const postId = ArticleFeedback.postId || 'no-post-id';
    return `article_feedback_${postId}_${location.pathname}`;
  };

  const canSend = () => {
    const last = localStorage.getItem(getKey());

    if (!last) {
      return true;
    }

    return Date.now() - parseInt(last, 10) > LIMIT_MS;
  };

  const markSent = () => {
    localStorage.setItem(getKey(), String(Date.now()));
  };

  widgets.forEach((widget) => {
    const buttons = widget.querySelectorAll('[data-choice]');
    const thanks = widget.querySelector('.js-af-thanks');

    buttons.forEach((button) => {
      button.addEventListener('click', async () => {
        if (!canSend()) {
          alert('少し時間をおいてください。');
          return;
        }

        buttons.forEach((btn) => {
          btn.disabled = true;
        });

        const body = new URLSearchParams({
          action: 'article_feedback_submit',
          nonce: ArticleFeedback.nonce,
          post_id: ArticleFeedback.postId || '',
          choice: button.dataset.choice,
          url: location.href,
        });

        try {
          const response = await fetch(ArticleFeedback.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            },
            body,
          });

          if (response.status === 429) {
            alert('連続送信はできません。少し時間をおいてください。');
            buttons.forEach((btn) => {
              btn.disabled = false;
            });
            return;
          }

          if (!response.ok) {
            throw new Error('Request failed');
          }

          markSent();

          buttons.forEach((btn) => {
            btn.remove();
          });

          if (thanks) {
            thanks.style.display = 'block';
          }
        } catch (error) {
          buttons.forEach((btn) => {
            btn.disabled = false;
          });

          alert('送信に失敗しました。時間をおいて再度お試しください。');
        }
      });
    });
  });
})();
