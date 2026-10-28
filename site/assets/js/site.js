/*
 * Copyright (c) 2026 erik <erik@erik.xyz> https://erik.xyz
 * open-admin static site interactions
 */
(() => {
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const menuButton = document.querySelector('.menu-toggle');
  const nav = document.getElementById('primary-nav');
  const backToTop = document.getElementById('back-to-top');
  const hero = document.querySelector('.hero');

  const setMenu = (open) => {
    if (!menuButton || !nav) return;
    menuButton.setAttribute('aria-expanded', String(open));
    menuButton.querySelector('.sr-only').textContent = open ? '关闭导航' : '打开导航';
    nav.classList.toggle('is-open', open);
    document.body.classList.toggle('nav-open', open);
  };

  if (menuButton && nav) {
    menuButton.addEventListener('click', () => {
      setMenu(menuButton.getAttribute('aria-expanded') !== 'true');
    });

    nav.addEventListener('click', (event) => {
      if (event.target.closest('a')) setMenu(false);
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && menuButton.getAttribute('aria-expanded') === 'true') {
        setMenu(false);
        menuButton.focus();
      }
    });

    window.addEventListener('resize', () => {
      if (window.innerWidth > 920) setMenu(false);
    });
  }

  const revealItems = document.querySelectorAll('[data-reveal]');
  if ('IntersectionObserver' in window && !reduceMotion.matches) {
    const revealObserver = new IntersectionObserver((entries, observer) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('is-visible');
        observer.unobserve(entry.target);
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.12 });

    revealItems.forEach((item) => revealObserver.observe(item));
  } else {
    revealItems.forEach((item) => item.classList.add('is-visible'));
  }

  if (backToTop && hero) {
    if ('IntersectionObserver' in window) {
      const heroObserver = new IntersectionObserver(([entry]) => {
        const pastHero = !entry.isIntersecting && entry.boundingClientRect.top < 0;
        backToTop.classList.toggle('is-visible', pastHero);
      }, { threshold: 0 });
      heroObserver.observe(hero);
    } else {
      backToTop.classList.add('is-visible');
    }

    backToTop.addEventListener('click', () => {
      window.scrollTo({ top: 0, behavior: reduceMotion.matches ? 'auto' : 'smooth' });
    });
  }

  const petStage = document.querySelector('.pet-stage');
  const petImage = document.getElementById('pet-stage-image');
  const petName = document.getElementById('pet-stage-name');
  const expressionButtons = document.querySelectorAll('.expression');

  if (petStage && petImage && petName) {
    expressionButtons.forEach((button) => {
      button.addEventListener('click', () => {
        if (button.classList.contains('is-active')) return;

        expressionButtons.forEach((item) => {
          const active = item === button;
          item.classList.toggle('is-active', active);
          item.setAttribute('aria-pressed', String(active));
        });

        const slug = button.dataset.pet;
        const name = button.dataset.name;
        const nextSource = `./assets/img/pet-expressions/${slug}.svg`;

        petStage.classList.add('is-switching');
        petImage.alt = `小安${name}形态`;
        petImage.setAttribute('aria-label', `放大查看：小安${name}形态`);

        const finish = () => {
          petImage.removeEventListener('load', finish);
          petStage.classList.remove('is-switching');
        };

        petImage.addEventListener('load', finish, { once: true });
        petImage.src = nextSource;
        petName.textContent = name;

        if (petImage.complete) finish();
        window.setTimeout(finish, 500);
      });
    });
  }


  const lightbox = document.getElementById('image-lightbox');
  if (lightbox && typeof lightbox.showModal === 'function') {
    const lightboxImage = document.getElementById('lightbox-image');
    const lightboxCaption = document.getElementById('lightbox-caption');
    const lightboxClose = lightbox.querySelector('.lightbox-close');
    let lastFocused = null;

    const closeLightbox = () => {
      if (lightbox.open) lightbox.close();
    };

    const openLightbox = (trigger) => {
      lastFocused = trigger;
      lightboxImage.src = trigger.currentSrc || trigger.src;
      lightboxImage.alt = trigger.alt || '';
      lightboxCaption.textContent = trigger.alt || '';
      document.body.classList.add('lightbox-open');
      lightbox.showModal();
      lightboxClose.focus();
    };

    document.querySelectorAll('img[data-lightbox]').forEach((image) => {
      image.tabIndex = 0;
      image.setAttribute('role', 'button');
      image.setAttribute('aria-haspopup', 'dialog');
      image.setAttribute('aria-label', `放大查看：${image.alt || '图片'}`);
      if (!image.title) image.title = '点击放大';
      image.addEventListener('click', () => openLightbox(image));
      image.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        openLightbox(image);
      });
    });

    lightboxClose.addEventListener('click', closeLightbox);
    lightbox.addEventListener('click', (event) => {
      if (event.target === lightbox || event.target.classList.contains('lightbox-frame')) closeLightbox();
    });
    lightbox.addEventListener('close', () => {
      document.body.classList.remove('lightbox-open');
      if (lastFocused) lastFocused.focus();
    });
  }

  document.querySelectorAll('.copy-button').forEach((button) => {
    button.addEventListener('click', async () => {
      const target = document.getElementById(button.dataset.copy);
      if (!target) return;
      const text = target.innerText;
      try {
        await navigator.clipboard.writeText(text);
        button.textContent = '已复制';
      } catch {
        const area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        document.execCommand('copy');
        area.remove();
        button.textContent = '已复制';
      }
      window.setTimeout(() => { button.textContent = '复制'; }, 1600);
    });
  });
})();
