(() => {
  const widgets = document.querySelectorAll('.js-article-feedback');
  if (!widgets.length || typeof AF_DATA === 'undefined') return;

  widgets.forEach((el) => {
    const buttons = el.querySelectorAll('[data-choice]');
    const thanks = el.querySelector('.js-af-thanks');

    buttons.forEach((btn) => {
      btn.addEventListener('click', async () => {
        buttons.forEach((b) => (b.disabled = true));

        const body = new URLSearchParams({
          action: 'article_feedback_submit',
          nonce: AF_DATA.nonce,
          post_id: AF_DATA.post_id,
          choice: btn.dataset.choice,
          url: location.href,
        });

        try {
          const res = await fetch(AF_DATA.ajax, {
            method: 'POST',
            body,
          });

          if (!res.ok) throw new Error();

          if (thanks) {
            thanks.style.display = 'block';
          }

          buttons.forEach((b) => b.remove());
        } catch (e) {
          buttons.forEach((b) => (b.disabled = false));
          alert('送信失敗');
        }
      });
    });
  });
})();
