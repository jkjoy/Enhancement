(() => {
  'use strict';

  if (window.enhancementNotificationsReady) return;
  window.enhancementNotificationsReady = true;

  const role = (panel, name) => panel.querySelector('[data-role="' + name + '"]');
  const button = (panel, action) => panel.querySelector('[data-action="' + action + '"]');
  const message = (panel, text) => { role(panel, 'message').textContent = text; };
  const date = (value) => {
    const seconds = typeof value === 'number' || typeof value === 'string' ? Number(value) : NaN;
    const parsed = Number.isFinite(seconds) && seconds > 0 ? new Date(seconds * 1000) : null;
    return parsed && Number.isFinite(parsed.getTime()) ? parsed.toLocaleString() : '尚未收到';
  };
  const lastResult = (panel, result) => {
    role(panel, 'last-result').textContent = result && result.time
      ? '最近通知：' + (result.success ? '接口已受理' : '失败') + ' · ' + date(result.time) + (result.message ? ' · ' + result.message : '')
      : '尚无通知发送记录。';
  };

  async function request(panel, action, values) {
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 55000);
    try {
      const response = await fetch(panel.getAttribute('data-' + action), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        body: new URLSearchParams(values || {}),
        signal: controller.signal
      });
      const text = await response.text();
      let result;
      try { result = JSON.parse(text); }
      catch (_) { throw new Error('无法读取操作结果，登录或安全令牌可能已过期，请刷新页面后重试。'); }
      if (!response.ok || !result || result.success !== true) {
        throw new Error(result && result.message ? result.message : '操作失败，请刷新页面后重试。');
      }
      return result;
    } catch (error) {
      if (error.name === 'AbortError') throw new Error('请求超时，请稍后手动检查状态后重试。');
      if (error instanceof TypeError) throw new Error('请求失败，请检查网络连接后重试。');
      throw error;
    } finally {
      window.clearTimeout(timeout);
    }
  }

  async function perform(panel, operation) {
    if (panel.dataset.busy === '1') return;
    panel.dataset.busy = '1';
    panel.setAttribute('aria-busy', 'true');
    const buttons = Array.from(panel.querySelectorAll('button'));
    const disabled = buttons.map((item) => item.disabled);
    buttons.forEach((item) => { item.disabled = true; });
    message(panel, '正在处理，请稍候……');
    try {
      await operation();
    } catch (error) {
      message(panel, error.message || '操作失败，请刷新页面后重试。');
    } finally {
      buttons.forEach((item, index) => { item.disabled = disabled[index]; });
      panel.dataset.busy = '0';
      panel.removeAttribute('aria-busy');
      if (panel.updateButtons) panel.updateButtons();
    }
  }

  const weixin = document.getElementById('enhancement-weixin');
  if (weixin) {
    let state = null;
    let qrPayload = '';
    const candidate = document.getElementById('enhancement-weixin-candidate');
    const verifyCode = document.getElementById('enhancement-weixin-code');
    weixin.updateButtons = () => {
      const loggedIn = state && state.logged_in && !state.expired;
      button(weixin, 'weixin-login-poll').disabled = !(state && state.login_pending);
      button(weixin, 'weixin-sync').disabled = !loggedIn;
      button(weixin, 'weixin-bind').disabled = !loggedIn || !candidate.value;
      button(weixin, 'weixin-test-notify').disabled = !loggedIn || !state.user_id;
      button(weixin, 'weixin-disconnect').disabled = !(state && (state.logged_in || state.login_pending));
    };

    function renderQr(value) {
      const payload = typeof value === 'string' ? value : '';
      role(weixin, 'qr-area').hidden = !payload;
      if (payload === qrPayload) return;
      qrPayload = payload;
      const container = role(weixin, 'qr');
      container.replaceChildren();
      if (!payload) return;
      try {
        const qr = window.qrcode(0, 'M');
        qr.addData(payload, 'Byte');
        qr.make();
        container.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 16, scalable: true });
        const svg = container.querySelector('svg');
        svg.setAttribute('role', 'img');
        svg.setAttribute('aria-label', '微信登录二维码');
        svg.style.cssText = 'display:block;width:100%;height:auto;max-width:280px';
      } catch (_) {
        container.textContent = '无法生成登录二维码，请刷新页面后重新获取。';
        qrPayload = '';
      }
    }

    function renderAutoReceive(data) {
      const target = role(weixin, 'auto-receive');
      if (!target) return;
      if (!data || typeof data !== 'object' || Array.isArray(data)) {
        target.textContent = '';
        return;
      }
      if (data.enabled !== true) {
        target.textContent = '自动接收已关闭。';
        return;
      }
      const labels = {
        idle: '等待网站访问触发',
        disabled: '等待网站访问触发',
        queued: '已安排接收',
        running: '正在接收',
        bound: '已完成接收',
        wait: '暂无新消息',
        busy: '接收器正忙，请稍后刷新状态',
        select: '请同步并绑定微信接收人',
        error: '接收失败，请检查微信连接状态，必要时重新登录或手动同步',
        dispatch_failed: '站点自身请求失败，请检查站点 URL、证书和防火墙'
      };
      const status = typeof data.status === 'string' && Object.prototype.hasOwnProperty.call(labels, data.status) ? data.status : 'idle';
      target.textContent = '自动接收：' + labels[status]
        + (['bound', 'wait', 'busy', 'select', 'error'].includes(status) ? ' · 最近接收：' + date(data.last_run) : '') + '。';
    }

    function render(data) {
      state = data || {};
      const statusLabels = { wait: '等待扫码', scaned: '已扫码，请在手机确认', scanned: '已扫码，请在手机确认', need_verifycode: '请输入手机微信显示的验证码', expired: '二维码已过期，请重新获取' };
      role(weixin, 'state').textContent = state.expired ? '微信登录已失效，请重新扫码登录。'
        : state.logged_in ? '微信已登录' + (state.account_id ? '，账号：' + state.account_id : '') + '。'
          : state.login_pending ? (statusLabels[state.login_status] || '等待扫码或确认登录') + '。' : '微信尚未登录。';
      role(weixin, 'recipient').textContent = state.user_id ? '已绑定接收人：' + state.user_id : '尚未绑定微信接收人。';
      role(weixin, 'context').textContent = state.logged_in && !state.expired
        ? state.has_context ? '接收会话已就绪。' : '请先给微信机器人发送一条消息，再点击“同步微信接收人”。'
        : '';
      renderAutoReceive(state.auto_receive);
      role(weixin, 'verify').hidden = !state.need_verifycode;
      lastResult(weixin, state.last_result);
      if (!state.need_verifycode) verifyCode.value = '';
      renderQr(state.qrcode_url);
      const previous = candidate.value;
      candidate.replaceChildren(new Option('请选择接收人', ''));
      const candidates = Array.isArray(state.candidates) ? state.candidates.slice(0, 20) : [];
      candidates.forEach((item) => {
        if (item && typeof item.user_id === 'string' && item.user_id) candidate.add(new Option(item.user_id, item.user_id));
      });
      if (Array.from(candidate.options).some((item) => item.value === previous)) candidate.value = previous;
      role(weixin, 'candidates').hidden = candidate.options.length < 2;
    }

    async function refresh() {
      const result = await request(weixin, 'weixin-status');
      render(result.data);
    }

    candidate.addEventListener('change', () => {
      if (weixin.dataset.busy !== '1') weixin.updateButtons();
    });

    weixin.addEventListener('click', (event) => {
      const clicked = event.target.closest('button[data-action]');
      if (!clicked || !weixin.contains(clicked)) return;
      const action = clicked.dataset.action;
      perform(weixin, async () => {
        const values = {};
        if (action === 'weixin-login-poll' && state && state.need_verifycode) {
          const code = verifyCode.value.trim();
          if (!/^[0-9]{1,12}$/.test(code)) throw new Error('请输入手机微信显示的数字验证码。');
          values.verify_code = code;
        }
        if (action === 'weixin-bind') {
          if (!candidate.value) throw new Error('请先选择刚刚发送消息的接收人。');
          values.user_id = candidate.value;
        }
        if (action === 'weixin-status') {
          await refresh();
          message(weixin, '连接状态已更新。');
          return;
        }
        const result = await request(weixin, action, values);
        if (action === 'weixin-disconnect' || (result.data && result.data.status === 'confirmed')) {
          ['enable_comment_by_weixin', 'enable_link_submit_by_weixin', 'weixin_moments_enabled'].forEach((name) => {
            const off = document.querySelector('[name="' + name + '"][value="0"]');
            if (off) off.checked = true;
          });
        }
        await refresh();
        message(weixin, result.message || '操作完成，请核对连接状态。');
      });
    });

    perform(weixin, async () => { await refresh(); message(weixin, ''); });
  }
})();
