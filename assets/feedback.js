(() => {
  const targets = document.querySelectorAll('.js-article-feedback');

  if (!targets.length || typeof ArticleFeedback === 'undefined') {
    return;
  }

  const choices = [
    {
      value: 'helpful',
      label: '役に立った',
    },
    {
      value: 'not_helpful',
      label: '役に立たなかった',
    },
    {
      value: 'not_found',
      label: '探している内容ではない',
    },
  ];

  targets.forEach((target) => {
    const wrapper = document.createElement('div');
    wrapper.className = 'article-feedback';

    const title = document.createElement('p');
    title.textContent = 'この記事は役に立ちましたか？';
    title.className = 'article-feedback__title';

    const buttons = document.createElement('div');
    buttons.className = 'article-feedback__buttons';

    choices.forEach((choice) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = choice.label;
      button.dataset.choice = choice.value;
      button.className = 'article-feedback__button';

      button.addEventListener('click', async () => {
        buttons.querySelectorAll('button').forEach((btn) => {
          btn.disabled = true;
        });

        const body = new URLSearchParams({
          action: 'article_feedback_submit',
          nonce: ArticleFeedback.nonce,
          post_id: ArticleFeedback.postId || '',
          choice: choice.value,
          page_url: location.href,
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

          if (!response.ok) {
            throw new Error('Request failed');
          }

          wrapper.innerHTML = '<p class="article-feedback__thanks">フィードバックありがとうございます。</p>';
        } catch (error) {
          buttons.querySelectorAll('button').forEach((btn) => {
            btn.disabled = false;
          });

          alert('送信に失敗しました。時間をおいて再度お試しください。');
        }
      });

      buttons.appendChild(button);
    });

    wrapper.appendChild(title);
    wrapper.appendChild(buttons);
    target.appendChild(wrapper);
  });
})();
