/** 后台导航使用 element，手机抽屉使用 layer；仅持久化桌面收起偏好。 */
layui.use(['element', 'layer'], function () {
    'use strict';
    const body = document.body;
    const sidebar = document.getElementById('admin-sidebar');
    const toggle = document.getElementById('admin-menu-toggle');
    if (!sidebar || !toggle) return;
    const main = document.querySelector('main.mpay-shell');
    const header = document.querySelector('.admin-header');
    const mobile = window.matchMedia('(max-width:960px)');
    const preference = 'mpay.admin.sidebar.collapsed';
    let collapsed = false,
        drawer = null,
        tip = null,
        restoreFocus = true;
    try {
        collapsed = localStorage.getItem(preference) === '1';
    } catch (_) {
        /* 浏览器禁用存储时，导航仍正常使用。 */
    }

    main.id = 'admin-main';
    main.tabIndex = -1;
    const currentPage = document.getElementById('admin-current-page');
    currentPage.textContent = main.querySelector('h1')?.textContent || '管理后台';
    // 通知与回调排查为业务页面的下级入口，侧栏高亮和面包屑保留用户所处上下文。
    const parent = {
        '/admin/notifications': ['/admin/orders', '交易订单'],
        '/admin/ingress': ['/admin/maintenance', '运行维护'],
    }[location.pathname];
    if (parent) {
        const link = document.createElement('a');
        link.href = parent[0];
        link.textContent = parent[1];
        const separator = document.createElement('span');
        separator.textContent = '/';
        separator.setAttribute('aria-hidden', 'true');
        currentPage.before(link, separator);
    }
    const username = document.getElementById('admin-username').textContent.trim();
    document.getElementById('admin-avatar').textContent =
        Array.from(username)[0]?.toLocaleUpperCase() || 'A';
    document
        .getElementById('admin-account-menu')
        .setAttribute('aria-label', '账户菜单 · ' + username);
    document.querySelectorAll('.admin-nav a').forEach((link) => {
        if (new URL(link.href).pathname === (parent?.[0] || location.pathname)) {
            link.closest('.layui-nav-item').classList.add('layui-this');
            link.setAttribute('aria-current', parent ? 'location' : 'page');
        }
    });
    layui.element.render('nav', 'admin-navigation');

    function hideTip() {
        if (tip !== null) layui.layer.close(tip);
        tip = null;
    }
    function sync() {
        hideTip();
        // layer 还原隐藏节点时会留下 display:none；导航显隐继续交给响应式主题。
        sidebar.style.removeProperty('display');
        body.classList.toggle('admin-sidebar-collapsed', collapsed);
        const opened = drawer !== null;
        const expanded = mobile.matches ? opened : !collapsed;
        const label = expanded ? '收起导航' : '展开导航';
        toggle.setAttribute('aria-expanded', String(expanded));
        toggle.setAttribute('aria-label', label);
        toggle.title = label;
        toggle.firstElementChild.className =
            'layui-icon layui-icon-' + (expanded ? 'shrink-right' : 'spread-left');
        sidebar.inert = mobile.matches && !opened;
        main.inert = header.inert = mobile.matches && opened;
    }
    function closeMobile(focus = true) {
        restoreFocus = focus;
        if (drawer !== null) layui.layer.close(drawer);
    }
    function openMobile() {
        if (drawer !== null) return;
        restoreFocus = true;
        // 直接复用导航节点；遮罩、定位、动画及关闭后还原由 layer 管理，不复制导航与遮罩。
        drawer = layui.layer.open({
            type: 1,
            title: false,
            content: layui.$(sidebar),
            area: [Math.min(252, innerWidth - 48) + 'px', '100%'],
            offset: 'l',
            anim: 'slideRight',
            isOutAnim: true,
            shade: 0.3,
            shadeClose: true,
            scrollbar: false,
            resize: false,
            move: false,
            skin: 'mpay-nav-layer',
            success(layer, index) {
                drawer = index;
                sync();
                layer.attr({ role: 'dialog', 'aria-modal': 'true', 'aria-label': '管理导航' });
                layer.find('.layui-layer-close').attr('aria-label', '关闭导航');
                sidebar.querySelector('[aria-current]')?.focus();
            },
            end() {
                drawer = null;
                sync();
                if (restoreFocus) toggle.focus();
            },
        });
    }
    toggle.addEventListener('click', () => {
        if (mobile.matches) {
            if (drawer === null) openMobile();
            else closeMobile();
        } else {
            collapsed = !collapsed;
            try {
                localStorage.setItem(preference, collapsed ? '1' : '0');
            } catch (_) {
                /* 偏好持久化失败不阻塞导航。 */
            }
            sync();
            // Layui 按 resize 重算表格宽度，避免正文扩展后固定列仍停留在旧位置。
            window.dispatchEvent(new Event('resize'));
        }
    });
    mobile.addEventListener('change', () => {
        const focusInSidebar = sidebar.contains(document.activeElement);
        closeMobile(focusInSidebar);
        sync();
    });
    document.addEventListener('keydown', (event) => {
        if (!mobile.matches || drawer === null || event.defaultPrevented) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            closeMobile();
        }
        // 手机抽屉打开时，键盘不会穿到遮罩后的页面或顶栏。
        if (event.key === 'Tab') {
            const items = Array.from(
                sidebar.closest('.layui-layer').querySelectorAll('a,button'),
            ).filter((item) => item.getClientRects().length);
            const target =
                event.shiftKey && document.activeElement === items[0]
                    ? items.at(-1)
                    : !event.shiftKey && document.activeElement === items.at(-1)
                      ? items[0]
                      : null;
            if (target) {
                event.preventDefault();
                target.focus();
            }
        }
    });
    sync();

    sidebar.querySelectorAll('.admin-nav a').forEach((link) => {
        const show = () => {
            if (mobile.matches || !collapsed) return;
            hideTip();
            tip = layui.layer.tips(link.getAttribute('aria-label'), link, {
                tips: [2, '#253e37'],
                time: 0,
                anim: -1,
                isOutAnim: false,
            });
        };
        link.addEventListener('mouseenter', show);
        link.addEventListener('focus', show);
        link.addEventListener('mouseleave', hideTip);
        link.addEventListener('blur', hideTip);
        link.addEventListener('click', () => {
            hideTip();
            if (mobile.matches) closeMobile(false);
        });
    });
    sidebar.querySelector('.admin-nav').addEventListener('scroll', hideTip, { passive: true });
    window.addEventListener('resize', hideTip);
});
