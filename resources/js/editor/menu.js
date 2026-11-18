/**
 * The "⋯" menus in the action bar (`[data-tpl-menu]`).
 *
 * Every listener is delegated from the widget's root, never bound to the menu's own
 * markup: a host may replace what sits inside the root without telling us, and a menu
 * redrawn that way still works. The only document listener (a click outside) is dropped
 * once the root itself leaves the DOM.
 */

/** Where focus goes on a key inside an open menu of `count` items; null for any other key. */
export function stepIndex(index, count, key) {
    switch (key) {
        case 'ArrowDown': return (index + 1) % count;
        case 'ArrowUp': return index <= 0 ? count - 1 : index - 1;
        case 'Home': return 0;
        case 'End': return count - 1;
        default: return null;
    }
}

const itemsOf = (menu) => [...menu.querySelectorAll('[role="menuitem"]')]
    .filter((item) => !item.hidden && item.style.display !== 'none' && !item.disabled);

const toggleOf = (menu) => menu.querySelector('[data-tpl-menu-toggle]');
const listOf = (menu) => menu.querySelector('[role="menu"]');
const isOpen = (menu) => toggleOf(menu)?.getAttribute('aria-expanded') === 'true';

function open(menu, focusLast = false) {
    toggleOf(menu)?.setAttribute('aria-expanded', 'true');
    listOf(menu).hidden = false;

    const items = itemsOf(menu);

    items[focusLast ? items.length - 1 : 0]?.focus();
}

function close(menu, restoreFocus) {
    if (!isOpen(menu)) {
        return;
    }

    toggleOf(menu)?.setAttribute('aria-expanded', 'false');
    listOf(menu).hidden = true;

    if (restoreFocus) {
        toggleOf(menu)?.focus();
    }
}

export function wireMenus(root) {
    if (root.dataset.tplMenus === '1') {
        return;
    }

    root.dataset.tplMenus = '1';

    const controller = new AbortController();
    const { signal } = controller;
    const openMenus = () => [...root.querySelectorAll('[data-tpl-menu]')].filter(isOpen);

    root.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-tpl-menu-toggle]');
        const menu = event.target.closest('[data-tpl-menu]');

        if (toggle && menu) {
            isOpen(menu) ? close(menu, false) : open(menu);

            return;
        }

        // The item's own handler (preview, activate…) already ran on the way up.
        if (menu && event.target.closest('[role="menuitem"]')) {
            close(menu, true);
        }
    }, { signal });

    root.addEventListener('keydown', (event) => {
        const menu = event.target.closest('[data-tpl-menu]');

        if (!menu) {
            return;
        }

        if (event.target.closest('[data-tpl-menu-toggle]') && !isOpen(menu)) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                open(menu, event.key === 'ArrowUp');
            }

            return;
        }

        if (!isOpen(menu)) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            close(menu, true);

            return;
        }

        if (event.key === 'Tab') {
            close(menu, false);

            return;
        }

        const items = itemsOf(menu);
        const next = stepIndex(items.indexOf(event.target.closest('[role="menuitem"]')), items.length, event.key);

        if (next !== null && items.length > 0) {
            event.preventDefault();
            items[next].focus();
        }
    }, { signal });

    document.addEventListener('click', (event) => {
        openMenus().filter((menu) => !menu.contains(event.target)).forEach((menu) => close(menu, false));
    }, { signal });

    const teardown = new MutationObserver(() => {
        if (!root.isConnected) {
            teardown.disconnect();
            controller.abort();
            delete root.dataset.tplMenus;
        }
    });

    teardown.observe(document.body, { childList: true, subtree: true });
}
