/**
 * The public website's only script (resources/views/site). Every page is complete without it; this adds the theme
 * switch, the small-screen menu, closing the apps menu, a gentle reveal of sections further down the page, and the
 * blog article's table of contents following the reader.
 */
const root = document.documentElement;

// Light or dark, remembered under the same key as the app (resources/js/Core/composables/useTheme).
document.querySelectorAll<HTMLButtonElement>('[data-theme-toggle]').forEach((button) => {
    button.setAttribute('aria-pressed', String(root.dataset.theme === 'dark'));
    button.addEventListener('click', () => {
        const dark = root.dataset.theme !== 'dark';

        root.dataset.theme = dark ? 'dark' : 'light';
        button.setAttribute('aria-pressed', String(dark));

        try {
            localStorage.setItem('oyo:theme', dark ? 'dark' : 'light');
        } catch {
            // Private windows may refuse storage; the choice then lasts for this page only.
        }
    });
});

// The small-screen menu.
const menuButton = document.querySelector<HTMLButtonElement>('[data-menu-toggle]');
const menu = document.getElementById('site-menu');

function setMenu(open: boolean): void {
    if (!menuButton || !menu) {
        return;
    }

    menu.hidden = !open;
    menuButton.setAttribute('aria-expanded', String(open));
    menuButton.setAttribute('aria-label', (open ? menuButton.dataset.labelClose : menuButton.dataset.labelOpen) ?? '');
}

menuButton?.addEventListener('click', () => setMenu(Boolean(menu?.hidden)));

// Menus close on Escape and on a click elsewhere.
const dropdowns = Array.from(document.querySelectorAll<HTMLDetailsElement>('details[data-dropdown]'));

document.addEventListener('click', (event) => {
    dropdowns.forEach((dropdown) => {
        if (dropdown.open && !dropdown.contains(event.target as Node)) {
            dropdown.open = false;
        }
    });
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') {
        return;
    }

    dropdowns.forEach((dropdown) => {
        if (dropdown.open) {
            dropdown.open = false;
            dropdown.querySelector('summary')?.focus();
        }
    });

    if (menu && !menu.hidden) {
        setMenu(false);
        menuButton?.focus();
    }
});

// The start card shows the workspace address as the company name is typed, the way the sign-up form suggests it
// (resources/js/Core/Components/Central/signup.ts: suggestAddress).
const company = document.querySelector<HTMLInputElement>('[data-start-company]');
const address = document.querySelector<HTMLElement>('[data-start-address]');

company?.addEventListener('input', () => {
    if (!address) {
        return;
    }

    const suggested = company.value
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+/, '')
        .slice(0, 63)
        .replace(/-+$/, '');

    address.textContent = suggested || (address.dataset.placeholder ?? '');
});

const motion = 'IntersectionObserver' in window && !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// The home page: the hero fades back as it leaves, and the product tilts up into place as it arrives.
const hero = document.querySelector<HTMLElement>('[data-hero]');
const show = document.querySelector<HTMLElement>('[data-show]');
const stage = show?.querySelector<HTMLElement>('[data-stage]');

if (motion && hero && show && stage) {
    const clamp = (value: number): number => Math.min(1, Math.max(0, value));
    let frame = 0;

    const update = (): void => {
        frame = 0;
        const height = window.innerHeight;

        hero.style.setProperty('--hero', clamp(window.scrollY / (hero.offsetHeight * 0.85)).toFixed(3));
        show.style.setProperty('--rise', clamp((height - stage.getBoundingClientRect().top) / (height * 0.75)).toFixed(3));
    };

    window.addEventListener('scroll', () => {
        frame ||= requestAnimationFrame(update);
    }, { passive: true });
    window.addEventListener('resize', update);
    update();
}

// The hero title's last words take turns. With reduced motion they stay as written; a hidden tab skips its turns.
const rotator = document.querySelector<HTMLElement>('[data-rotate]');

if (rotator && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    let phrases: string[] = [];

    try {
        const parsed: unknown = JSON.parse(rotator.dataset.rotate ?? '[]');
        phrases = Array.isArray(parsed) ? parsed.filter((phrase): phrase is string => typeof phrase === 'string' && phrase !== '') : [];
    } catch {
        phrases = [];
    }

    if (phrases.length > 1) {
        let index = Math.max(0, phrases.indexOf(rotator.textContent?.trim() ?? ''));

        window.setInterval(() => {
            if (document.hidden) return;

            rotator.classList.add('is-leaving');
            window.setTimeout(() => {
                index = (index + 1) % phrases.length;
                rotator.textContent = phrases[index];
                rotator.classList.remove('is-leaving');
                rotator.classList.add('is-entering');
                // Reading the layout commits the start position, so removing the class transitions into place. Done
                // synchronously: a frame callback can stall (a throttled tab) and leave the words invisible.
                void rotator.offsetWidth;
                rotator.classList.remove('is-entering');
            }, 350);
        }, 2800);
    }
}

// Sections below the first screen rise into place as they arrive. Anything already in view is left alone, so
// nothing on the first screen ever flashes; with reduced motion nothing moves at all.
const reveal = Array.from(document.querySelectorAll<HTMLElement>('[data-reveal]'));

if ('IntersectionObserver' in window && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.remove('reveal-pending');
                    observer.unobserve(entry.target);
                }
            });
        },
        { rootMargin: '0px 0px -8% 0px' },
    );

    reveal.forEach((element) => {
        if (element.getBoundingClientRect().top > window.innerHeight) {
            element.classList.add('reveal-pending');
            observer.observe(element);
        }
    });
}

// An article's table of contents marks the section being read: the last heading that has passed the top of the window.
const toc = document.querySelector<HTMLElement>('[data-toc]');

if (toc) {
    const links = Array.from(toc.querySelectorAll<HTMLAnchorElement>('a[href^="#"]'));
    const headings = links
        .map((link) => document.getElementById(decodeURIComponent(link.hash.slice(1))))
        .filter((heading): heading is HTMLElement => heading !== null);
    let frame = 0;

    const mark = (): void => {
        frame = 0;
        let current = -1;

        headings.forEach((heading, index) => {
            if (heading.getBoundingClientRect().top < 140) {
                current = index;
            }
        });

        links.forEach((link, index) => {
            if (index === current) {
                link.setAttribute('aria-current', 'true');
            } else {
                link.removeAttribute('aria-current');
            }
        });
    };

    window.addEventListener('scroll', () => {
        frame ||= requestAnimationFrame(mark);
    }, { passive: true });
    mark();
}
