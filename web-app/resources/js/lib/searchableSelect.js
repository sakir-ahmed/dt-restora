import { t } from '@/lib/i18n';

/**
 * Makes every native dropdown (<select>) with more than a few options searchable, on every page, so a long list
 * (ingredients, suppliers, units, customers) is not a scroll hunt. The select stays the real field: choosing an
 * option sets its value and fires input and change, so v-model and forms work exactly as before.
 *
 * Opt out with data-native-select on a select. Multi-select lists and short lists keep the browser's own menu.
 */
const MIN_OPTIONS = 6;
const POPOVER_CLASS = 'fixed z-[200] flex max-h-72 flex-col overflow-hidden rounded-md border border-border bg-surface shadow-lg';

let active = null;

function isEligible(element) {
    return element instanceof HTMLSelectElement
        && ! element.multiple
        && element.size <= 1
        && ! element.disabled
        && ! element.hasAttribute('data-native-select')
        && element.options.length >= MIN_OPTIONS;
}

/** The select's options and group headings, in order. */
function readEntries(select) {
    const entries = [];

    for (const child of select.children) {
        if (child instanceof HTMLOptGroupElement) {
            entries.push({ group: true, label: child.label });

            for (const option of child.children) {
                entries.push({ option, label: option.textContent.trim(), disabled: option.disabled || child.disabled });
            }
        } else if (child instanceof HTMLOptionElement) {
            entries.push({ option: child, label: child.textContent.trim(), disabled: child.disabled });
        }
    }

    return entries;
}

function close(restoreFocus = true) {
    if (! active) {
        return;
    }

    const { select, root } = active;

    root.remove();
    active = null;

    if (restoreFocus) {
        select.focus();
    }
}

function choose(option) {
    const { select } = active;

    select.value = option.value;
    select.dispatchEvent(new Event('input', { bubbles: true }));
    select.dispatchEvent(new Event('change', { bubbles: true }));
    close();
}

function place(select, root) {
    const rect = select.getBoundingClientRect();
    const below = window.innerHeight - rect.bottom;
    const flip = below < 240 && rect.top > below;

    root.style.left = `${Math.max(4, Math.min(rect.left, window.innerWidth - Math.max(rect.width, 220) - 4))}px`;
    root.style.minWidth = `${Math.max(rect.width, 220)}px`;
    root.style.maxHeight = `${Math.min(288, Math.max(160, (flip ? rect.top : below) - 12))}px`;

    if (flip) {
        root.style.bottom = `${window.innerHeight - rect.top + 4}px`;
        root.style.top = 'auto';
    } else {
        root.style.top = `${rect.bottom + 4}px`;
        root.style.bottom = 'auto';
    }
}

function open(select) {
    close(false);

    const entries = readEntries(select);
    const root = document.createElement('div');
    const searchBox = document.createElement('div');
    const input = document.createElement('input');
    const list = document.createElement('ul');

    root.className = POPOVER_CLASS;
    root.setAttribute('role', 'listbox');
    root.dataset.searchableSelect = '';
    searchBox.className = 'shrink-0 border-b border-border p-1.5';
    input.type = 'text';
    input.placeholder = t('common.search');
    input.setAttribute('aria-label', t('common.search'));
    input.className = 'h-7 w-full rounded border border-border bg-background px-2 text-[13px] text-foreground outline-none placeholder:text-muted/60 focus:border-accent';
    list.className = 'thin-scrollbar min-h-0 flex-1 overflow-y-auto p-1';
    searchBox.append(input);
    root.append(searchBox, list);
    document.body.append(root);

    active = { select, root, input, rows: [], highlighted: 0 };

    const render = () => {
        const needle = input.value.trim().toLowerCase();
        const visible = [];
        let pendingGroup = null;

        for (const entry of entries) {
            if (entry.group) {
                pendingGroup = entry;

                continue;
            }

            if (needle && ! entry.label.toLowerCase().includes(needle)) {
                continue;
            }

            if (pendingGroup) {
                visible.push(pendingGroup);
                pendingGroup = null;
            }

            visible.push(entry);
        }

        list.replaceChildren();
        active.rows = visible.filter((entry) => ! entry.group && ! entry.disabled);

        if (visible.length === 0) {
            const empty = document.createElement('li');

            empty.className = 'px-2 py-1.5 text-[13px] text-muted';
            empty.textContent = t('common.no_results');
            list.append(empty);

            return;
        }

        for (const entry of visible) {
            const item = document.createElement('li');

            if (entry.group) {
                item.className = 'px-2 pb-0.5 pt-1.5 text-[11px] font-semibold uppercase tracking-wide text-muted';
                item.textContent = entry.label;
                list.append(item);

                continue;
            }

            const selected = entry.option.value === select.value && ! entry.disabled;

            item.setAttribute('role', 'option');
            item.setAttribute('aria-selected', String(selected));
            item.textContent = entry.label || '---';
            item.className = entry.disabled
                ? 'rounded px-2 py-1.5 text-[13px] text-muted/60'
                : `cursor-pointer rounded px-2 py-1.5 text-[13px] text-foreground hover:bg-background ${selected ? 'font-semibold' : ''}`;

            if (! entry.disabled) {
                entry.element = item;
                item.addEventListener('mousedown', (event) => event.preventDefault());
                item.addEventListener('click', () => choose(entry.option));
                item.addEventListener('mousemove', () => highlight(active.rows.indexOf(entry), false));
            }

            list.append(item);
        }

        const current = active.rows.findIndex((entry) => entry.option.value === select.value);

        highlight(needle ? 0 : Math.max(0, current));
    };

    const highlight = (index, scroll = true) => {
        if (! active) {
            return;
        }

        active.highlighted = Math.max(0, Math.min(index, active.rows.length - 1));

        active.rows.forEach((entry, position) => {
            entry.element?.classList.toggle('bg-accent/15', position === active.highlighted);

            if (scroll && position === active.highlighted) {
                entry.element?.scrollIntoView({ block: 'nearest' });
            }
        });
    };

    input.addEventListener('input', render);
    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            highlight(active.highlighted + 1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            highlight(active.highlighted - 1);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            event.stopPropagation();

            if (active.rows[active.highlighted]) {
                choose(active.rows[active.highlighted].option);
            }
        } else if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            close();
        } else if (event.key === 'Tab') {
            close(false);
        }
    });

    place(select, root);
    render();
    input.focus();
}

function onMouseDown(event) {
    if (event.button !== 0) {
        return;
    }

    const target = event.target instanceof Element ? event.target : null;

    if (active && target && active.root.contains(target)) {
        return;
    }

    const select = target?.closest('select');

    if (select && isEligible(select)) {
        // The browser's own menu would open over this one; the select keeps focus and the value.
        event.preventDefault();
        select.focus();

        if (active?.select === select) {
            close();
        } else {
            open(select);
        }

        return;
    }

    close(false);
}

function onKeyDown(event) {
    if (active) {
        return;
    }

    const select = event.target instanceof HTMLSelectElement ? event.target : null;

    if (! select || ! isEligible(select)) {
        return;
    }

    if (event.key === ' ' || event.key === 'F4' || (event.altKey && (event.key === 'ArrowDown' || event.key === 'ArrowUp'))) {
        event.preventDefault();
        open(select);
    }
}

/** Installs the behaviour once for the whole app (browser only). */
export function installSearchableSelects() {
    if (typeof document === 'undefined' || document.documentElement.dataset.searchableSelects) {
        return;
    }

    document.documentElement.dataset.searchableSelects = '1';
    document.addEventListener('mousedown', onMouseDown, true);
    document.addEventListener('keydown', onKeyDown, true);
    window.addEventListener('resize', () => close(false));
    window.addEventListener('scroll', (event) => {
        if (active && ! active.root.contains(event.target)) {
            close(false);
        }
    }, true);
}
