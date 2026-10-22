/** Public reference only: no credentials, payment requests or application state. */
(function () {
    'use strict';
    const origin = location.origin;
    const content = document.querySelector('.api-content');
    document.getElementById('api-gateway').textContent = origin + '/';
    // Change the example gateway only; merchant callback domains stay examples.
    const walker = document.createTreeWalker(content, NodeFilter.SHOW_TEXT);
    while (walker.nextNode()) {
        walker.currentNode.nodeValue = walker.currentNode.nodeValue.replaceAll('https://pay.example.com', origin);
    }
    layui.use(['layer'], function () {
        document.querySelectorAll('[data-copy]').forEach(button => {
            button.addEventListener('click', async function () {
                const copied = await MpayClipboard.writeText(document.getElementById(button.dataset.copy).textContent);
                layui.layer.msg(copied ? '已复制' : '复制失败，请选中文本手动复制', {time:1800});
            });
        });
    });
    const outline = document.querySelector('.api-outline');
    const mobile = matchMedia('(max-width: 900px)');
    function resizeOutline() { outline.open = !mobile.matches; }
    resizeOutline();
    mobile.addEventListener('change', resizeOutline);
    const links = Array.from(outline.querySelectorAll('a'));
    function mark(id) {
        links.forEach(link => {
            if (decodeURIComponent(link.hash.slice(1)) === id) link.setAttribute('aria-current', 'location');
            else link.removeAttribute('aria-current');
        });
    }
    links.forEach(link => link.addEventListener('click', function () {
        if (mobile.matches) outline.open = false;
        mark(decodeURIComponent(link.hash.slice(1)));
    }));
    const headings = Array.from(content.querySelectorAll('h2'));
    let scheduled = false;
    function updateLocation() {
        const current = headings.filter(heading => heading.getBoundingClientRect().top <= 160).at(-1);
        mark((current || headings[0]).id);
        scheduled = false;
    }
    addEventListener('scroll', function () {
        if (!scheduled) { scheduled = true; requestAnimationFrame(updateLocation); }
    }, {passive:true});
    updateLocation();
})();
