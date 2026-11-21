const sectionLinks = Array.from(document.querySelectorAll('.section-nav a'));
const sections = sectionLinks.map((link) => document.getElementById(link.hash.slice(1)));

let scrollFrame = 0;
function updateSection() {
    scrollFrame = 0;
    const threshold = window.innerHeight * 0.32;
    const current = sections.findLastIndex((section) => section && section.getBoundingClientRect().top <= threshold);
    const atBottom = window.scrollY > 0 && window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 2;

    sectionLinks.forEach((link, index) => {
        if (index === (atBottom ? sections.length - 1 : Math.max(0, current))) {
            link.setAttribute('aria-current', 'location');
        } else {
            link.removeAttribute('aria-current');
        }
    });
}

window.addEventListener('scroll', () => {
    if (!scrollFrame) scrollFrame = requestAnimationFrame(updateSection);
}, { passive: true });
window.addEventListener('resize', updateSection);
updateSection();

const menuToggle = document.querySelector('.profile-menu-toggle');
if (menuToggle) {
    menuToggle.hidden = false;
    menuToggle.addEventListener('click', () => {
        const expanded = menuToggle.getAttribute('aria-expanded') === 'true';
        menuToggle.setAttribute('aria-expanded', String(!expanded));
    });

    sectionLinks.forEach((link) => link.addEventListener('click', () => {
        if (menuToggle.getClientRects().length === 0) return;

        const section = document.getElementById(link.hash.slice(1));
        section?.setAttribute('tabindex', '-1');
        section?.focus({ preventScroll: true });
        menuToggle.setAttribute('aria-expanded', 'false');
    }));
}

const hero = document.querySelector('.has-carousel');
const controls = hero?.querySelector('.carousel-controls');
if (hero && controls) {
    const slides = Array.from(hero.querySelectorAll('.hero-image'));
    const counter = controls.querySelector('[data-slide-count]');
    const playback = controls.querySelector('[data-slide-play]');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let current = 0;
    let timer;

    function selectSlide(position) {
        slides[current].hidden = true;
        current = (position + slides.length) % slides.length;
        slides[current].hidden = false;
        counter.textContent = `${current + 1} / ${slides.length}`;
    }

    function togglePlayback(playing) {
        clearInterval(timer);
        timer = playing ? setInterval(() => selectSlide(current + 1), 6000) : undefined;
        playback.textContent = playing ? 'Pause' : 'Play';
        counter.setAttribute('aria-live', playing ? 'off' : 'polite');
    }

    controls.querySelector('[data-slide-previous]').addEventListener('click', () => {
        togglePlayback(false);
        selectSlide(current - 1);
    });
    controls.querySelector('[data-slide-next]').addEventListener('click', () => {
        togglePlayback(false);
        selectSlide(current + 1);
    });
    playback.addEventListener('click', () => togglePlayback(!timer));
    reducedMotion.addEventListener('change', () => {
        if (reducedMotion.matches) togglePlayback(false);
    });
    controls.hidden = false;
    togglePlayback(!reducedMotion.matches);
}
