/*
 * Full text on hover, for every board page. An element with data-tooltip shows that text. Any other text that
 * is cut off (ellipsis, line clamp) shows its whole text, so long titles on cards and the chat's "To" picker are
 * readable without opening anything. Elements with a native title keep the browser's own tooltip.
 */
(() => {
    const tip = document.createElement('div');
    tip.className = 'sb-tip';
    tip.setAttribute('role', 'tooltip');
    tip.hidden = true;
    let timer = null, owner = null;

    const cut = (el) => el.scrollWidth > el.clientWidth + 1 || el.scrollHeight > el.clientHeight + 1;
    const clean = (s) => String(s || '').replace(/\s+/g, ' ').trim();

    // The nearest element (a few levels up) that has a tooltip, or whose text is cut off.
    function source(start) {
        if (!start?.closest || start.closest('textarea, input, select, #chatLog, .rv-steps, .md')) return null;
        // An explicit tooltip wins over the cut-off text inside it (the picker's label is only part of it).
        const tagged = start.closest('[data-tooltip]');
        if (tagged && tagged.dataset.tooltip) return { el: tagged, text: tagged.dataset.tooltip };
        for (let el = start, i = 0; el && el !== document.body && i < 5; el = el.parentElement, i++) {
            if (el.hasAttribute('title')) return null;
            const text = clean(el.textContent);
            if (text && text.length < 800 && el.clientWidth && cut(el)) return { el, text };
        }
        return null;
    }

    function place(x, y) {
        const pad = 12;
        const r = tip.getBoundingClientRect();
        let left = Math.min(x + pad, window.innerWidth - r.width - 8);
        let top = y + 18;
        if (top + r.height > window.innerHeight - 8) top = y - r.height - 10;
        tip.style.left = `${Math.max(8, left)}px`;
        tip.style.top = `${Math.max(8, top)}px`;
    }

    function hide() {
        clearTimeout(timer);
        owner = null;
        tip.hidden = true;
    }

    document.addEventListener('mouseover', (e) => {
        const s = source(e.target);
        if (!s) { if (owner && !owner.contains(e.target)) hide(); return; }
        if (s.el === owner) return;
        hide();
        owner = s.el;
        const { clientX: x, clientY: y } = e;
        timer = setTimeout(() => {
            if (owner !== s.el) return;
            tip.textContent = s.text;
            tip.hidden = false;
            place(x, y);
        }, 300);
    });
    document.addEventListener('mousemove', (e) => { if (!tip.hidden) place(e.clientX, e.clientY); }, { passive: true });
    document.addEventListener('mouseout', (e) => { if (owner && !owner.contains(e.relatedTarget)) hide(); });
    ['mousedown', 'scroll', 'keydown'].forEach((t) => document.addEventListener(t, hide, { capture: true, passive: true }));
    window.addEventListener('blur', hide);

    if (document.body) document.body.append(tip);
    else document.addEventListener('DOMContentLoaded', () => document.body.append(tip));
})();
