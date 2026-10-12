// Progressive enhancement: the site works without this file.
document.documentElement.classList.add('js');

(function () {
  // Project filters
  const buttons = document.querySelectorAll('.filters button');
  buttons.forEach((btn) => {
    btn.addEventListener('click', () => {
      const kind = btn.dataset.filter;
      buttons.forEach((b) => b.classList.toggle('on', b === btn));
      document.querySelectorAll('#projects .card').forEach((card) => {
        card.hidden = kind !== 'all' && card.dataset.kind !== kind;
      });
    });
  });

  // Pointer-driven light sheen and slight tilt on glass cards
  document.querySelectorAll('.card, .row').forEach((el) => {
    el.addEventListener('pointermove', (ev) => {
      const r = el.getBoundingClientRect();
      const x = ev.clientX - r.left;
      const y = ev.clientY - r.top;
      el.style.setProperty('--mx', x + 'px');
      el.style.setProperty('--my', y + 'px');
      if (el.classList.contains('card')) {
        const rx = ((y / r.height) - 0.5) * -6;
        const ry = ((x / r.width) - 0.5) * 6;
        el.style.transform = `perspective(800px) rotateX(${rx}deg) rotateY(${ry}deg)`;
      }
    });
    el.addEventListener('pointerleave', () => { el.style.transform = ''; });
  });

  // Scroll reveal with GSAP (loaded with defer; skip if it failed to load)
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const start = () => {
    if (!window.gsap || reduce) {
      document.querySelectorAll('.reveal').forEach((el) => (el.style.opacity = 1));
      return;
    }
    gsap.utils.toArray('.reveal').forEach((el) => {
      gsap.to(el, {
        opacity: 1, y: 0, duration: .8, ease: 'power3.out',
        scrollTrigger: undefined,
        delay: 0.05,
      });
    });
    gsap.from('.hero h1', { y: 30, opacity: 0, duration: 1, ease: 'power4.out' });
  };
  window.addEventListener('load', start);
})();
