/** 后台认证表单只提交用户输入；会话、密码策略和权限由服务器决定，不在浏览器保存任何凭据。 */
MpayForm.ready(function () {
    'use strict';
    const form = document.querySelector('[data-admin-form]');
    const feedback = document.getElementById('admin-feedback');
    const logout = document.getElementById('admin-logout');
    const mode = form?.dataset.adminForm;
    let busy = false;
    const captchaImage = document.getElementById('admin-captcha-image');
    const captchaRefresh = document.getElementById('admin-captcha-refresh');
    const captchaPlaceholder = document.getElementById('admin-captcha-placeholder');
    const submitButton = form?.querySelector('[type="submit"]');
    let captchaBusy = false, captchaReady = mode !== 'login', captchaUrl = null;
    let captchaController = null, captchaSequence = 0, captchaError = '';
    let waitUntil = 0, captchaWaitUntil = 0, countdown = null;

    function updateButtons() {
        const seconds = Math.max(0, Math.ceil((waitUntil - Date.now()) / 1000));
        if (submitButton) {
            submitButton.disabled = busy || seconds > 0 || !captchaReady;
            if (mode === 'login') submitButton.textContent = seconds ? `${seconds} 秒后重试` : '登录';
        }
        if (captchaRefresh) {
            const imageSeconds = Math.max(0, Math.ceil((captchaWaitUntil - Date.now()) / 1000));
            captchaRefresh.disabled = busy || captchaBusy || imageSeconds > 0;
            if (!captchaBusy && !captchaReady) captchaPlaceholder.textContent = imageSeconds ? `${imageSeconds} 秒后刷新` : '点击重试';
        }
        if (seconds === 0 && Date.now() >= captchaWaitUntil && countdown) { clearInterval(countdown); countdown = null; }
    }
    function waiting(seconds, imageOnly = false) {
        const until = Date.now() + seconds * 1000;
        if (imageOnly) captchaWaitUntil = Math.max(captchaWaitUntil, until);
        else waitUntil = Math.max(waitUntil, until);
        if (!countdown) countdown = setInterval(updateButtons, 250);
        updateButtons();
    }
    /** 图片通过 fetch 读取，才能正确显示 429 等待时间；不读取或保存验证码答案。 */
    async function refreshCaptcha() {
        if (mode !== 'login' || captchaBusy || Date.now() < captchaWaitUntil) return;
        const sequence = ++captchaSequence;
        if (captchaError && feedback?.textContent === captchaError) message('');
        captchaError = '';
        captchaBusy = true; captchaReady = false; form.elements.captcha.value = '';
        captchaImage.hidden = true; captchaPlaceholder.hidden = false; captchaPlaceholder.textContent = '加载中…'; updateButtons();
        const controller = new AbortController();
        captchaController = controller;
        const timeout = setTimeout(() => controller.abort(), 15000);
        try {
            const response = await fetch('/adminapi/captcha', {credentials:'same-origin', cache:'no-store', headers:{Accept:'image/png'}, signal:controller.signal});
            if (sequence !== captchaSequence) return;
            if (!response.ok || !response.headers.get('Content-Type')?.startsWith('image/png')) {
                const seconds = Number(response.headers.get('Retry-After'));
                if (Number.isFinite(seconds) && seconds > 0) waiting(Math.min(seconds, 3600), true);
                const body = await response.json().catch(() => null);
                throw new Error(body?.message || '验证码加载失败，请点击重试');
            }
            const blob = await response.blob();
            if (sequence !== captchaSequence) return;
            const next = URL.createObjectURL(blob);
            if (captchaUrl) URL.revokeObjectURL(captchaUrl);
            captchaUrl = next; captchaImage.src = next;
            await captchaImage.decode();
            if (sequence !== captchaSequence) return;
            captchaImage.hidden = false; captchaPlaceholder.hidden = true; captchaReady = true;
        } catch (error) {
            if (sequence !== captchaSequence) return;
            captchaError = error.name === 'AbortError' ? '验证码加载超时，请点击重试' : error.message;
            message(captchaError);
        } finally {
            clearTimeout(timeout);
            if (sequence === captchaSequence) { captchaController = null; captchaBusy = false; updateButtons(); }
        }
    }

    function message(text) {
        if (feedback) { feedback.textContent = text; feedback.dataset.tone = text.includes('正在') ? 'loading' : 'error'; }
    }
    /** 表单与退出共享提交锁；只抑制当前页面并行点击，不以此替代服务器事务或重放保护。 */
    function submitting(value) {
        busy = value;
        form?.setAttribute('aria-busy', String(value));
        form?.querySelectorAll('button').forEach(button => { button.disabled = value; });
        if (logout) logout.disabled = value;
        updateButtons();
    }
    // 登录本身的 401 表示密码不正确，应留在表单；已登录页面的 401 才跳到固定登录入口。
    window.addEventListener('mpay:session-expired', () => {
        if (mode !== 'login') location.replace('/admin/login');
    });

    form?.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy || Date.now() < waitUntil || !captchaReady || !MpayForm.valid(form)) return;
        const values = new FormData(form);
        const input = mode === 'login'
            ? {username: values.get('username'), password: values.get('password'), captcha: values.get('captcha')}
            : {current_password: values.get('current_password'), new_password: values.get('new_password'),
                password_confirmation: values.get('password_confirmation')};
        if (mode === 'password' && input.new_password !== input.password_confirmation) {
            message('两次输入的新密码必须一致');
            return;
        }
        submitting(true); message('正在提交…');
        try {
            await MpayHttp.request(mode === 'login' ? '/adminapi/login' : '/adminapi/password', {method: 'POST', data: input});
            // 改密后必须重新认证；响应丢失时也不自动重试原密码操作。
            form.reset();
            location.replace(mode === 'login' ? '/admin/dashboard' : '/admin/login');
        } catch (error) {
            const fields = error.data?.errors;
            const detail = fields ? Object.values(fields).flat().filter(value => typeof value === 'string').join('；') : '';
            message(detail || error.message || '请求未完成，请稍后重试');
            if (error.retryAfter > 0) waiting(error.retryAfter);
            if (mode === 'login') {
                form.elements.password.value = '';
                await refreshCaptcha();
            }
        } finally { submitting(false); }
    });

    logout?.addEventListener('click', async () => {
        if (busy) return;
        submitting(true);
        try {
            const exit=()=>MpayHttp.request('/adminapi/logout', {method: 'POST', data: {}});
            if(window.MpayAdmin){
                if(!await MpayAdmin.confirm({title:'退出所有登录',content:'确定退出所有后台登录？',description:'当前及其他浏览器的登录会话都将退出。',confirmText:'退出登录',onConfirm:exit}))return;
            }else await exit();
            location.replace('/admin/login');
        } catch (error) {
            // 不把未知网络结果当作退出成功；用户可以直接重新打开登录入口确认状态。
            message(error.message || '退出状态未确认，请重新打开登录页面');
        } finally { submitting(false); }
    });
    document.getElementById('account-logout')?.addEventListener('click', () => logout?.click());
    captchaRefresh?.addEventListener('click', refreshCaptcha);
    if (mode === 'login') refreshCaptcha();
    window.addEventListener('pagehide', () => {
        clearInterval(countdown); countdown = null; captchaSequence++;
        captchaController?.abort(); captchaController = null; captchaBusy = false;
        if (captchaUrl) { URL.revokeObjectURL(captchaUrl); captchaUrl = null; }
        if (mode === 'login') captchaReady = false;
    });
    // 浏览器后退缓存恢复页面时，旧图片可能已经消费/过期，计时器也必须恢复。
    window.addEventListener('pageshow', event => { if (event.persisted) { waiting(0); refreshCaptcha(); } });
});
