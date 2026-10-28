/*
 * Copyright (c) 2026 erik <erik@erik.xyz> https://erik.xyz
 * open-admin static site interactions
 */
(() => {
  const isZh = document.documentElement.lang.toLowerCase().startsWith('zh');
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const menuButton = document.querySelector('.menu-toggle');
  const nav = document.getElementById('primary-nav');
  const backToTop = document.getElementById('back-to-top');
  const hero = document.querySelector('.hero');

  const setMenu = (open) => {
    if (!menuButton || !nav) return;
    menuButton.setAttribute('aria-expanded', String(open));
    menuButton.querySelector('.sr-only').textContent = open
      ? (isZh ? '关闭导航' : 'Close navigation')
      : (isZh ? '打开导航' : 'Open navigation');
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
        petImage.alt = isZh ? `小安${name}形态` : `Xiao An, ${name} expression`;
        petImage.setAttribute('aria-label', isZh
          ? `放大查看：${petImage.alt}`
          : `Zoom image: ${petImage.alt}`);

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
      image.setAttribute('aria-label', isZh
        ? `放大查看：${image.alt || '图片'}`
        : `Zoom image: ${image.alt || 'image'}`);
      if (!image.title) image.title = isZh ? '点击放大' : 'Click to zoom';
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
        button.textContent = button.dataset.copiedLabel || (isZh ? '已复制' : 'Copied');
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
        button.textContent = button.dataset.copiedLabel || (isZh ? '已复制' : 'Copied');
      }
      window.setTimeout(() => {
        button.textContent = button.dataset.copyLabel || (isZh ? '复制' : 'Copy');
      }, 1600);
    });
  });

  const searchDialog = document.getElementById('site-search');
  if (searchDialog && typeof searchDialog.showModal === 'function') {
    const searchInput = document.getElementById('search-input');
    const searchResults = document.getElementById('search-results');
    const searchStatus = document.getElementById('search-status');
    const searchOpenButtons = document.querySelectorAll('[data-search-open]');
    const searchClose = searchDialog.querySelector('.search-close');
    const labels = {
      hint: searchDialog.dataset.hintLabel || (isZh ? '输入关键词开始搜索' : 'Type to search'),
      empty: searchDialog.dataset.emptyLabel || (isZh ? '没有找到匹配内容' : 'No matches found'),
      results: searchDialog.dataset.resultsLabel || (isZh ? '找到 {n} 个结果' : '{n} results')
    };
    let searchIndex = [];
    let searchLastFocus = null;
    let searchNavigating = false;

    const normalize = (value) => value.replace(/\s+/g, ' ').trim().toLocaleLowerCase();

    const buildSearchIndex = () => {
      const entries = [];
      const seen = new Set();

      document.querySelectorAll('main section[id]').forEach((section) => {
        const sectionTitle = (section.querySelector('h2, h1')?.innerText || section.id).trim();
        const push = (title, text, context = '', kind = 'section') => {
          const cleanText = normalize(text);
          const key = `${title}|${context}|${section.id}`;
          if (!title || !cleanText || seen.has(key)) return;
          seen.add(key);
          entries.push({ title, text: cleanText, anchor: `#${section.id}`, context, kind });
        };

        push(sectionTitle, section.innerText);

        section.querySelectorAll('article, .timeline li, .install-steps li, .support-card, .pet-profile, .check-list li').forEach((item) => {
          const title = (item.querySelector('h3, figcaption')?.textContent || '').trim();
          if (!title) return;
          push(title, item.innerText, sectionTitle, 'item');
        });
      });

      return entries;
    };

    const makeSnippet = (text, tokens) => {
      const at = text.indexOf(tokens[0]);
      const start = Math.max(0, at - 28);
      const end = Math.min(text.length, (at < 0 ? 0 : at) + 96);
      return `${start > 0 ? '… ' : ''}${text.slice(start, end).trim()}${end < text.length ? ' …' : ''}`;
    };

    const renderSearch = () => {
      const query = normalize(searchInput.value);
      searchResults.replaceChildren();

      if (!query) {
        searchStatus.textContent = labels.hint;
        return;
      }

      if (!searchIndex.length) searchIndex = buildSearchIndex();
      const tokens = query.split(' ').filter(Boolean);
      const matches = searchIndex
        .map((item) => {
          const title = normalize(item.title);
          const context = normalize(item.context);
          const haystack = `${title} ${context} ${item.text}`;
          if (!tokens.every((token) => haystack.includes(token))) return null;
          let score = item.kind === 'section' ? -3 : 0;
          tokens.forEach((token) => {
            if (title.includes(token)) score += 20;
            else if (context.includes(token)) score += 6;
            else score += 1;
          });
          return { item, score };
        })
        .filter(Boolean)
        .sort((a, b) => b.score - a.score)
        .slice(0, 8);

      if (!matches.length) {
        searchStatus.textContent = labels.empty;
        return;
      }

      searchStatus.textContent = labels.results.replace('{n}', String(matches.length));

      matches.forEach(({ item }) => {
        const row = document.createElement('li');
        row.className = 'search-result';
        const link = document.createElement('a');
        link.href = item.anchor;

        if (item.context) {
          const context = document.createElement('span');
          context.className = 'search-result-context';
          context.textContent = item.context;
          link.appendChild(context);
        }

        const title = document.createElement('span');
        title.className = 'search-result-title';
        title.textContent = item.title;
        link.appendChild(title);

        const snippet = document.createElement('span');
        snippet.className = 'search-result-snippet';
        snippet.textContent = makeSnippet(item.text, tokens);
        link.appendChild(snippet);

        link.addEventListener('click', () => {
          searchNavigating = true;
          searchDialog.close();
        });

        row.appendChild(link);
        searchResults.appendChild(row);
      });
    };

    const openSearch = () => {
      const active = document.activeElement;
      searchLastFocus = active && active !== document.body ? active : searchOpenButtons[0];
      searchInput.value = '';
      searchResults.replaceChildren();
      searchStatus.textContent = labels.hint;
      searchNavigating = false;
      document.body.classList.add('search-open');
      searchDialog.showModal();
      searchInput.focus();
    };

    searchOpenButtons.forEach((button) => {
      button.addEventListener('click', openSearch);
    });

    searchClose.addEventListener('click', () => searchDialog.close());
    searchInput.addEventListener('input', renderSearch);
    searchDialog.addEventListener('click', (event) => {
      if (event.target === searchDialog) searchDialog.close();
    });
    searchDialog.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        searchDialog.close();
      }
    });
    searchDialog.addEventListener('close', () => {
      document.body.classList.remove('search-open');
      if (!searchNavigating && searchLastFocus) searchLastFocus.focus();
    });

    document.addEventListener('keydown', (event) => {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        if (searchDialog.open) searchInput.focus();
        else openSearch();
      }
    });
  }

})();
