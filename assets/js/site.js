/* FB Barber : interactions, prise de rendez-vous et galerie.
   Le site reste lisible sans GSAP : les effets de scroll s'ajoutent
   par-dessus et les états de départ restent toujours visibles. */
(() => {
  'use strict';

  /* =====================================================================
     CONFIGURATION DU SALON
     Tout ce qui change d'un salon à l'autre se règle ici : coordonnées,
     horaires, prestations et envoi des demandes de rendez-vous.
     ===================================================================== */
  const SALON = {
    name: 'FB Barber',

    // Coordonnées affichées sur le site. Laissées vides, elles s'affichent « À renseigner ».
    address: '', // ex. '12 rue Exemple, 00000 Ville'
    phone: '', // ex. '06 12 34 56 78'
    email: '', // ex. 'contact@exemple.fr'

    // Envoi des demandes de rendez-vous, par ordre de priorité :
    // 1. bookingEndpoint : adresse d'un service de formulaire qui accepte du JSON
    //    (Formspree, Getform, script maison…). La demande part sans quitter le site.
    // 2. whatsapp : numéro au format international, chiffres seulement (ex. '33612345678').
    //    WhatsApp s'ouvre avec le message déjà rédigé, le client n'a plus qu'à l'envoyer.
    // 3. email (ci-dessus) : la messagerie du client s'ouvre avec la demande rédigée.
    // Si rien n'est renseigné, le module fonctionne en mode démonstration (aucun envoi).
    bookingEndpoint: '',
    whatsapp: '',

    // Horaires D'EXEMPLE, à remplacer. 0 = dimanche, 1 = lundi … 6 = samedi.
    // Chaque jour liste ses plages d'ouverture ; une liste vide signifie fermé.
    hours: {
      0: [],
      1: [],
      2: [['09:00', '12:00'], ['14:00', '19:00']],
      3: [['09:00', '12:00'], ['14:00', '19:00']],
      4: [['09:00', '12:00'], ['14:00', '19:00']],
      5: [['09:00', '12:00'], ['14:00', '19:00']],
      6: [['09:00', '18:00']],
    },
    closedDates: [], // fermetures exceptionnelles, ex. ['2026-12-25', '2027-01-01']
    slotStep: 15, // écart entre deux créneaux proposés, en minutes
    leadTime: 60, // délai minimum avant un rendez-vous, en minutes
    daysAhead: 28, // nombre de jours proposés à la réservation

    // Prestations : la réservation et les cartes de l'accueil lisent cette liste.
    // L'identifiant (id) correspond à l'attribut data-service des cartes.
    services: [
      { id: 'coupe-homme', name: 'Coupe homme', duration: 45, price: 25 },
      { id: 'barbe', name: 'Barbe', duration: 30, price: 15 },
      { id: 'rasage', name: 'Rasage traditionnel', duration: 40, price: 25 },
      { id: 'coupe-enfant', name: 'Coupe enfant', duration: 30, price: 18 },
      { id: 'rituel', name: 'Rituel complet', duration: 90, price: 55 },
    ],
  };

  /* ---------- Outils ---------- */

  const root = document.documentElement;
  const $ = (sel, ctx = document) => ctx.querySelector(sel);
  const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));
  const clamp = (v, min, max) => Math.min(max, Math.max(min, v));
  const pad = (n) => String(n).padStart(2, '0');

  const reduceMQ = window.matchMedia('(prefers-reduced-motion: reduce)');
  const fineMQ = window.matchMedia('(hover: hover) and (pointer: fine)');
  const hasGSAP = typeof window.gsap !== 'undefined' && typeof window.ScrollTrigger !== 'undefined';
  const motion = hasGSAP && !reduceMQ.matches;
  if (hasGSAP) {
    gsap.registerPlugin(ScrollTrigger);
    gsap.config({ nullTargetWarn: false });
  }

  const DAY_SHORT = ['dim', 'lun', 'mar', 'mer', 'jeu', 'ven', 'sam'];
  const DAY_LONG = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
  const fmtLong = new Intl.DateTimeFormat('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' });
  const fmtShort = new Intl.DateTimeFormat('fr-FR', { weekday: 'short', day: 'numeric', month: 'short' });
  const fmtMonthShort = new Intl.DateTimeFormat('fr-FR', { month: 'short' });
  const fmtMonthYear = new Intl.DateTimeFormat('fr-FR', { month: 'long', year: 'numeric' });

  const toMin = (hhmm) => {
    const [h, m] = hhmm.split(':').map(Number);
    return h * 60 + m;
  };
  const toHHMM = (min) => `${pad(Math.floor(min / 60))}:${pad(min % 60)}`;
  const isoDay = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const parseIso = (iso) => {
    const [y, m, d] = iso.split('-').map(Number);
    return new Date(y, m - 1, d);
  };
  const cap = (s) => s.charAt(0).toUpperCase() + s.slice(1);
  const plural = (n, one, many) => `${n} ${n > 1 ? many : one}`;
  const fmtDuration = (min) => {
    const h = Math.floor(min / 60);
    const m = min % 60;
    if (!h) return `${m} min`;
    return m ? `${h} h ${pad(m)}` : `${h} h`;
  };
  const fmtPrice = (n) => `${n} €`;
  const fmtHour = (hhmm) => {
    const [h, m] = hhmm.split(':');
    return `${Number(h)}h${m === '00' ? '' : m}`;
  };
  const rangesFor = (date) => (SALON.closedDates.includes(isoDay(date)) ? [] : SALON.hours[date.getDay()] || []);

  /* ---------- Horaires et statut d'ouverture ---------- */

  function hoursSummary() {
    const order = [1, 2, 3, 4, 5, 6, 0];
    const groups = [];
    order.forEach((day) => {
      const ranges = SALON.hours[day] || [];
      const key = JSON.stringify(ranges);
      const last = groups[groups.length - 1];
      if (last && last.key === key) last.days.push(day);
      else groups.push({ key, ranges, days: [day] });
    });
    return groups.map(({ ranges, days }) => ({
      label: days.length > 1 ? `${DAY_SHORT[days[0]]} – ${DAY_SHORT[days[days.length - 1]]}` : DAY_SHORT[days[0]],
      value: ranges.length ? ranges.map(([a, b]) => `${fmtHour(a)}–${fmtHour(b)}`).join(', ') : 'Fermé',
    }));
  }

  function openStatus(now = new Date()) {
    const mins = now.getHours() * 60 + now.getMinutes();
    const current = rangesFor(now).find(([a, b]) => mins >= toMin(a) && mins < toMin(b));
    if (current) return { open: true, text: `Ouvert maintenant, jusqu’à ${fmtHour(current[1])}` };
    for (let i = 0; i < 8; i++) {
      const day = new Date(now.getFullYear(), now.getMonth(), now.getDate() + i);
      const next = rangesFor(day).find(([a]) => i > 0 || toMin(a) > mins);
      if (next) {
        const when = i === 0 ? 'aujourd’hui' : i === 1 ? 'demain' : DAY_LONG[day.getDay()];
        return { open: false, text: `Fermé, ouvre ${when} à ${fmtHour(next[0])}` };
      }
    }
    return { open: false, text: 'Fermé' };
  }

  /* Remplit les coordonnées, les horaires et les prix depuis la configuration */
  function initSalonInfo() {
    $$('[data-salon]').forEach((el) => {
      const value = SALON[el.dataset.salon];
      if (value) el.textContent = value;
    });

    $$('[data-hours]').forEach((list) => {
      list.textContent = '';
      hoursSummary().forEach(({ label, value }) => {
        const li = document.createElement('li');
        const day = document.createElement('span');
        day.className = 'mono';
        day.textContent = label;
        const time = document.createElement('span');
        time.textContent = value;
        li.append(day, time);
        list.appendChild(li);
      });
    });

    const status = openStatus();
    $$('[data-open-status]').forEach((el) => {
      el.classList.toggle('is-open', status.open);
      const text = $('span', el);
      if (text) text.textContent = status.text;
    });
    $$('[data-open-text]').forEach((el) => {
      el.textContent = status.text;
    });

    $$('[data-service]').forEach((el) => {
      const service = SALON.services.find((s) => s.id === el.dataset.service);
      if (!service) return;
      const price = $('.price', el);
      if (price) price.textContent = fmtPrice(service.price);
      const duration = $('.card__meta .mono', el);
      if (duration) duration.textContent = fmtDuration(service.duration);
    });
  }

  /* ---------- Texte ---------- */

  /* Découpe un titre en lettres (une par span, masquées aux lecteurs d'écran) */
  function splitChars(el) {
    const text = el.textContent.trim();
    el.setAttribute('aria-label', text);
    el.textContent = '';
    return [...text].map((ch, i) => {
      const s = document.createElement('span');
      s.className = 'ch';
      s.setAttribute('aria-hidden', 'true');
      s.style.setProperty('--i', i);
      s.textContent = ch === ' ' ? ' ' : ch;
      el.appendChild(s);
      return s;
    });
  }

  /* Prépare le défilement de texte au survol (deux copies de chaque lettre) */
  function buildRolls() {
    $$('[data-roll]').forEach((el) => {
      const text = el.textContent.trim();
      if (!text || el.querySelector('.roll')) return;
      el.setAttribute('aria-label', text);
      const wrap = document.createElement('span');
      wrap.className = 'roll';
      wrap.setAttribute('aria-hidden', 'true');
      [...text].forEach((ch, i) => {
        const c = document.createElement('span');
        c.className = 'c';
        c.style.setProperty('--i', i);
        const a = document.createElement('span');
        a.textContent = ch === ' ' ? ' ' : ch;
        const b = document.createElement('span');
        b.textContent = a.textContent;
        c.append(a, b);
        wrap.appendChild(c);
      });
      el.textContent = '';
      el.appendChild(wrap);
    });
  }

  /* ---------- Bandeau, en-tête, menu ---------- */

  function initNewsBar() {
    const dateEl = $('[data-date]');
    if (dateEl) {
      dateEl.textContent = new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' })
        .format(new Date())
        .replace('.', '');
    }
    const msgs = $$('.newsbar__msg');
    if (msgs.length < 2 || reduceMQ.matches) return;
    let i = 0;
    setInterval(() => {
      msgs[i].classList.remove('is-on');
      i = (i + 1) % msgs.length;
      msgs[i].classList.add('is-on');
    }, 3800);
  }

  const menu = $('#menu');
  const menuToggle = $('.menu-toggle');
  const menuOpen = () => Boolean(menu && menu.classList.contains('is-open'));

  /* Fond au scroll, masquage quand on descend, barre de progression */
  function initHeader() {
    const header = $('.site-header');
    const bar = $('.progress');
    let lastY = window.scrollY;
    let queued = false;

    const update = () => {
      queued = false;
      const y = window.scrollY;
      const max = root.scrollHeight - window.innerHeight;
      if (bar) bar.style.transform = `scaleX(${max > 0 ? clamp(y / max, 0, 1) : 0})`;
      if (!header) return;
      header.classList.toggle('is-scrolled', y > 40);
      const delta = y - lastY;
      if (menuOpen() || y < 320) header.classList.remove('is-hidden');
      else if (delta > 4) header.classList.add('is-hidden');
      else if (delta < -4) header.classList.remove('is-hidden');
      if (Math.abs(delta) > 4) lastY = y;
    };
    const request = () => {
      if (queued) return;
      queued = true;
      requestAnimationFrame(update);
    };
    window.addEventListener('scroll', request, { passive: true });
    window.addEventListener('resize', request);
    update();
  }

  function setMenu(open) {
    if (!menu || !menuToggle) return;
    if (open) {
      const r = menuToggle.getBoundingClientRect();
      menu.style.setProperty('--x', `${r.left + r.width / 2}px`);
      menu.style.setProperty('--y', `${r.top + r.height / 2}px`);
    }
    menu.classList.toggle('is-open', open);
    menu.setAttribute('aria-hidden', open ? 'false' : 'true');
    menuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    root.classList.toggle('menu-open', open);
  }

  function initMenu() {
    if (!menu || !menuToggle) return;
    menuToggle.addEventListener('click', () => setMenu(!menuOpen()));
    menu.addEventListener('click', (e) => {
      if (e.target.closest('a')) setMenu(false);
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && menuOpen()) {
        setMenu(false);
        menuToggle.focus();
      }
    });
  }

  /* ---------- Bandes de savoir-faire : défilement accéléré par le scroll ---------- */

  function initMarquees() {
    $$('.strip__row').forEach((row) => {
      const track = $('.strip__track', row);
      if (!track) return;
      // Copie pour une boucle sans couture (la copie est masquée aux lecteurs d'écran)
      [...track.children].forEach((node) => {
        const copy = node.cloneNode(true);
        copy.setAttribute('aria-hidden', 'true');
        track.appendChild(copy);
      });
      if (!motion) return;

      const dir = Number(row.dataset.marquee) < 0 ? -1 : 1;
      const speed = Number(row.dataset.speed) || 0.8;
      let half = track.scrollWidth / 2;
      let x = 0;
      let boost = 0;
      let lastY = window.scrollY;
      window.addEventListener('resize', () => {
        half = track.scrollWidth / 2;
      });

      gsap.ticker.add((time, delta) => {
        const k = clamp(delta / 16.67, 0.5, 3);
        const y = window.scrollY;
        const v = clamp((y - lastY) * 1.2, -40, 40);
        lastY = y;
        boost += (Math.abs(v) - boost) * 0.1;
        x += dir * (speed + boost * 0.25) * k;
        if (half > 0) {
          if (x <= -half) x += half;
          if (x >= 0) x -= half;
        }
        track.style.transform = `translate3d(${x.toFixed(2)}px, 0, 0)`;
      });
    });
  }

  /* ---------- Canvas : constellation, braises et fumée de marbre ---------- */

  function starfield(canvas, reduce) {
    const ctx = canvas.getContext('2d');
    const host = canvas.parentElement;
    const narrow = window.innerWidth < 700;
    const STARS = narrow ? 44 : 84;
    const EMBERS = narrow ? 14 : 30;
    const DPR = Math.min(window.devicePixelRatio || 1, 1.5);
    const LINK = 150;
    const rnd = (a, b) => a + Math.random() * (b - a);
    const ptr = { x: -9999, y: -9999 };
    let w = 0;
    let h = 0;
    let stars = [];
    let embers = [];
    let raf = 0;
    let running = false;

    const ember = (fresh) => ({
      x: rnd(0, w),
      y: fresh ? rnd(0, h) : h + rnd(0, 30),
      vx: rnd(-0.12, 0.12),
      vy: rnd(-0.9, -0.3),
      r: rnd(0.6, 1.6),
      a: rnd(0.35, 0.9),
    });

    const draw = () => {
      ctx.clearRect(0, 0, w, h);
      if (!reduce) {
        for (const p of stars) {
          p.x += p.vx;
          p.y += p.vy;
          if (p.x < -10) p.x = w + 10;
          if (p.x > w + 10) p.x = -10;
          if (p.y < -10) p.y = h + 10;
          if (p.y > h + 10) p.y = -10;
          const dx = ptr.x - p.x;
          const dy = ptr.y - p.y;
          if (Math.hypot(dx, dy) < 180) {
            p.x += dx * 0.004;
            p.y += dy * 0.004;
          }
        }
      }

      ctx.lineWidth = 0.6;
      for (let i = 0; i < stars.length; i++) {
        const a = stars[i];
        for (let j = i + 1; j < stars.length; j++) {
          const b = stars[j];
          const dx = a.x - b.x;
          const dy = a.y - b.y;
          const d2 = dx * dx + dy * dy;
          if (d2 < LINK * LINK) {
            ctx.strokeStyle = `rgba(206, 206, 206, ${((1 - Math.sqrt(d2) / LINK) * 0.26).toFixed(3)})`;
            ctx.beginPath();
            ctx.moveTo(a.x, a.y);
            ctx.lineTo(b.x, b.y);
            ctx.stroke();
          }
        }
      }

      ctx.fillStyle = '#ffffff';
      ctx.globalAlpha = 0.75;
      for (const p of stars) {
        ctx.beginPath();
        ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
        ctx.fill();
      }

      ctx.fillStyle = '#cc6437';
      for (const s of embers) {
        if (!reduce) {
          s.x += s.vx;
          s.y += s.vy;
          s.a -= 0.0009;
          if (s.y < -10 || s.a <= 0) Object.assign(s, ember(false));
        }
        ctx.globalAlpha = clamp(s.a, 0, 1);
        ctx.beginPath();
        ctx.arc(s.x, s.y, s.r, 0, Math.PI * 2);
        ctx.fill();
      }
      ctx.globalAlpha = 1;
    };

    const resize = () => {
      const rect = canvas.getBoundingClientRect();
      w = rect.width;
      h = rect.height;
      canvas.width = Math.round(w * DPR);
      canvas.height = Math.round(h * DPR);
      ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
      stars = Array.from({ length: STARS }, () => ({
        x: rnd(0, w), y: rnd(0, h), vx: rnd(-0.14, 0.14), vy: rnd(-0.14, 0.14), r: rnd(0.4, 1.2),
      }));
      embers = Array.from({ length: EMBERS }, () => ember(true));
      if (reduce) draw();
    };

    const frame = () => {
      draw();
      if (running) raf = requestAnimationFrame(frame);
    };
    const start = () => {
      if (reduce || running) return;
      running = true;
      raf = requestAnimationFrame(frame);
    };
    const stop = () => {
      running = false;
      cancelAnimationFrame(raf);
    };

    host.addEventListener('pointermove', (e) => {
      const r = canvas.getBoundingClientRect();
      ptr.x = e.clientX - r.left;
      ptr.y = e.clientY - r.top;
    });
    host.addEventListener('pointerleave', () => {
      ptr.x = -9999;
      ptr.y = -9999;
    });
    window.addEventListener('resize', resize);
    new IntersectionObserver((entries) => {
      entries.forEach((en) => (en.isIntersecting ? start() : stop()));
    }).observe(canvas);
    resize();
  }

  function marble(canvas, reduce) {
    const ctx = canvas.getContext('2d');
    const CELL = 10; // 1 pixel calculé = 10 px à l'écran : l'agrandissement adoucit l'image
    let w = 0;
    let h = 0;
    let img = null;
    let t = Math.random() * 6;
    let raf = 0;
    let running = false;

    const paint = () => {
      const d = img.data;
      for (let y = 0; y < h; y++) {
        const ny = (y / h) * 2 - 1;
        for (let x = 0; x < w; x++) {
          const nx = (x / w) * 2 - 1;
          const a = Math.sin(nx * 2.1 + t + Math.sin(ny * 2.6 - t * 0.7) * 1.6);
          const b = Math.sin(ny * 2.4 - t * 0.8 + Math.cos(nx * 1.7 + t * 0.5) * 1.4);
          const c = Math.sin((nx + ny) * 1.9 + t * 0.45);
          const e = Math.sin(Math.hypot(nx * 1.3, ny * 1.9) * 3.4 - t * 1.05);
          const v = (a + b + c * 0.7 + e * 0.9) / 3.3;
          const g = 12 + Math.pow(v * 0.5 + 0.5, 2.2) * 54;
          const i = (y * w + x) * 4;
          d[i] = g;
          d[i + 1] = g;
          d[i + 2] = g;
          d[i + 3] = 255;
        }
      }
      ctx.putImageData(img, 0, 0);
    };

    const frame = () => {
      t += 0.006;
      paint();
      if (running) raf = requestAnimationFrame(frame);
    };
    const start = () => {
      if (reduce || running) return;
      running = true;
      raf = requestAnimationFrame(frame);
    };
    const stop = () => {
      running = false;
      cancelAnimationFrame(raf);
    };
    const resize = () => {
      const r = canvas.getBoundingClientRect();
      w = Math.max(24, Math.round(r.width / CELL));
      h = Math.max(16, Math.round(r.height / CELL));
      canvas.width = w;
      canvas.height = h;
      img = ctx.createImageData(w, h);
      if (reduce) paint();
    };
    window.addEventListener('resize', resize);
    new IntersectionObserver((entries) => {
      entries.forEach((en) => (en.isIntersecting ? start() : stop()));
    }).observe(canvas);
    resize();
  }

  function initCanvases() {
    const reduce = reduceMQ.matches;
    $$('[data-stars]').forEach((c) => starfield(c, reduce));
    $$('[data-marble]').forEach((c) => marble(c, reduce));
  }

  /* ---------- Survol : curseur, aimant, cartes, lettres du pied de page ---------- */

  function initCursor() {
    if (!fineMQ.matches || reduceMQ.matches) return;
    const c = $('.cursor');
    if (!c) return;
    const label = $('.cursor__label', c);
    const mouse = { x: -200, y: -200 };
    const pos = { x: -200, y: -200 };
    root.classList.add('has-cursor');

    window.addEventListener('pointermove', (e) => {
      mouse.x = e.clientX;
      mouse.y = e.clientY;
    }, { passive: true });

    const loop = () => {
      pos.x += (mouse.x - pos.x) * 0.2;
      pos.y += (mouse.y - pos.y) * 0.2;
      c.style.transform = `translate3d(${pos.x.toFixed(1)}px, ${pos.y.toFixed(1)}px, 0)`;
      requestAnimationFrame(loop);
    };
    requestAnimationFrame(loop);

    document.addEventListener('pointerover', (e) => {
      const hit = e.target.closest ? e.target.closest('a, button, [data-cursor]') : null;
      const text = hit ? hit.dataset.cursor : '';
      c.classList.toggle('is-link', Boolean(hit) && !text);
      c.classList.toggle('is-label', Boolean(text));
      if (label) label.textContent = text || '';
    });
    root.addEventListener('mouseleave', () => c.classList.add('is-away'));
    root.addEventListener('mouseenter', () => c.classList.remove('is-away'));
  }

  function initMagnetic() {
    if (!motion || !fineMQ.matches) return;
    $$('[data-magnetic]').forEach((el) => {
      const qx = gsap.quickTo(el, 'x', { duration: 0.6, ease: 'power3.out' });
      const qy = gsap.quickTo(el, 'y', { duration: 0.6, ease: 'power3.out' });
      el.addEventListener('pointermove', (e) => {
        const r = el.getBoundingClientRect();
        const cx = r.left + r.width / 2 - gsap.getProperty(el, 'x');
        const cy = r.top + r.height / 2 - gsap.getProperty(el, 'y');
        qx((e.clientX - cx) * 0.28);
        qy((e.clientY - cy) * 0.36);
      });
      el.addEventListener('pointerleave', () => {
        qx(0);
        qy(0);
      });
    });
  }

  function initCards() {
    if (!motion || !fineMQ.matches) return;
    $$('.card').forEach((card) => {
      gsap.set(card, { transformPerspective: 900, transformOrigin: '50% 50%' });
      const rx = gsap.quickTo(card, 'rotationX', { duration: 0.8, ease: 'power3.out' });
      const ry = gsap.quickTo(card, 'rotationY', { duration: 0.8, ease: 'power3.out' });
      card.addEventListener('pointermove', (e) => {
        const r = card.getBoundingClientRect();
        const px = (e.clientX - r.left) / r.width;
        const py = (e.clientY - r.top) / r.height;
        card.style.setProperty('--mx', `${(px * 100).toFixed(1)}%`);
        card.style.setProperty('--my', `${(py * 100).toFixed(1)}%`);
        rx((0.5 - py) * 8);
        ry((px - 0.5) * 8);
      });
      card.addEventListener('pointerleave', () => {
        rx(0);
        ry(0);
      });
    });
  }

  function initDodge() {
    if (!motion || !fineMQ.matches) return;
    const mark = $('.footer__mark');
    if (!mark) return;
    const letters = $$('span:not(.sp)', mark);
    mark.addEventListener('pointermove', (e) => {
      letters.forEach((l) => {
        const r = l.getBoundingClientRect();
        const dx = r.left + r.width / 2 - e.clientX;
        const dy = r.top + r.height / 2 - e.clientY;
        const d = Math.hypot(dx, dy) || 1;
        const f = Math.max(0, 1 - d / 240);
        gsap.to(l, {
          x: (dx / d) * f * 70,
          y: (dy / d) * f * 36,
          rotation: (dx / d) * f * 10,
          duration: 0.6,
          ease: 'power3.out',
          overwrite: 'auto',
        });
      });
    });
    mark.addEventListener('pointerleave', () => {
      gsap.to(letters, {
        x: 0, y: 0, rotation: 0, duration: 1.1, ease: 'elastic.out(1, 0.5)', overwrite: 'auto',
      });
    });
  }

  /* ---------- Scroll ---------- */

  /* Intro du hero : le monogramme se dessine, les lettres se lèvent. Rien n'est masqué. */
  function heroIntro(chars) {
    const tl = gsap.timeline({ defaults: { ease: 'expo.out' } });
    tl.from($$('.hero__mark .d'), { strokeDashoffset: 1, duration: 1.8, ease: 'power2.inOut', stagger: 0.16 }, 0)
      .from($$('.hero__mark .core'), { scale: 0, duration: 0.9, ease: 'back.out(3)', transformOrigin: '50% 50%' }, 1.3)
      .from(chars, { yPercent: 24, rotation: 6, transformOrigin: '0% 100%', duration: 1.3, stagger: 0.05 }, 0.2)
      .from($$('.hero__label'), { y: 14, duration: 1.2, stagger: 0.12 }, 0.6)
      .from($$('.hero__center > .pill, .hero__lede, .hero__ctas > *'), { y: 22, duration: 1.2, stagger: 0.1 }, 0.8)
      .from($$('.hero__scroll'), { y: 10, duration: 1.2 }, 1.1);
  }

  /* Hero au scroll : les lettres s'écartent, le monogramme tourne, le fond glisse */
  function heroScroll(chars) {
    const hero = $('.hero');
    if (!hero) return;
    const tl = gsap.timeline({
      scrollTrigger: { trigger: hero, start: 'top top', end: 'bottom top', scrub: 0.6 },
    });
    if (chars.length) {
      tl.to(chars, { x: (i, _el, all) => (i - (all.length - 1) / 2) * 44, ease: 'none' }, 0);
    }
    tl.to($$('.hero__center'), { yPercent: -14, ease: 'none' }, 0)
      .to($$('.hero__mark'), { rotation: 140, transformOrigin: '50% 50%', ease: 'none' }, 0)
      .to($$('.hero__stars, .hero__marble'), { yPercent: 18, ease: 'none' }, 0)
      .to($$('.hero__photo'), { yPercent: 10, scale: 1.08, ease: 'none' }, 0)
      .to($$('.hero__label'), { y: -60, ease: 'none' }, 0)
      .to($$('.hero__scroll'), { opacity: 0, ease: 'none' }, 0);
  }

  /* Révélations liées au scroll : elles déplacent, sans jamais cacher le contenu */
  function revealOnScroll() {
    $$('.display').forEach((heading) => {
      const lines = $$('.line', heading);
      gsap.fromTo(lines.length ? lines : heading, { y: 60, rotation: 2 }, {
        y: 0,
        rotation: 0,
        ease: 'none',
        scrollTrigger: { trigger: heading, start: 'top 94%', end: 'top 58%', scrub: 0.8 },
      });
    });

    $$('[data-reveal]').forEach((el) => {
      gsap.fromTo(el, { y: 40 }, {
        y: 0,
        ease: 'none',
        scrollTrigger: { trigger: el, start: 'top 98%', end: 'top 72%', scrub: 0.6 },
      });
    });

    $$('.bento .card').forEach((card, i) => {
      gsap.fromTo(card, { y: 40 + (i % 2) * 24 }, {
        y: 0,
        ease: 'none',
        scrollTrigger: { trigger: card, start: 'top 98%', end: 'top 66%', scrub: 0.8 },
      });
    });
  }

  /* Photos : parallaxe dans le cadre et montée décalée */
  function shotsMotion() {
    $$('.shot__frame').forEach((frame) => {
      const img = $('img', frame);
      if (!img) return;
      gsap.fromTo(img, { yPercent: -5 }, {
        yPercent: 5,
        ease: 'none',
        scrollTrigger: { trigger: frame, start: 'top bottom', end: 'bottom top', scrub: true },
      });
    });
    $$('.gallery__grid .shot, .teaser__row .shot').forEach((shot, i) => {
      gsap.fromTo(shot, { y: 40 + (i % 3) * 22 }, {
        y: 0,
        ease: 'none',
        scrollTrigger: { trigger: shot, start: 'top 98%', end: 'top 60%', scrub: 0.8 },
      });
    });
  }

  /* Atelier : chaque mot s'allume pendant le scroll (0,45 au repos, lisible) */
  function wordsScrub() {
    const el = $('[data-words]');
    if (!el) return;
    const words = el.textContent.trim().split(/\s+/);
    el.textContent = '';
    const spans = words.map((w, i) => {
      const s = document.createElement('span');
      s.className = 'w';
      s.textContent = w;
      el.appendChild(s);
      if (i < words.length - 1) el.appendChild(document.createTextNode(' '));
      return s;
    });
    const tl = gsap.timeline({
      scrollTrigger: { trigger: el, start: 'top 85%', end: 'bottom 55%', scrub: 0.6 },
    });
    spans.forEach((s, i) => {
      tl.fromTo(s, { opacity: 0.45 }, { opacity: 1, ease: 'none', duration: 1 }, i);
    });
  }

  /* Parcours : horizontal et épinglé sur ordinateur, vertical sur téléphone */
  function processSection() {
    const pin = $('.process__pin');
    const track = $('.process__track');
    const counter = $('[data-count]');
    if (!pin || !track) return;

    gsap.matchMedia().add('(min-width: 900px)', () => {
      const dist = () => Math.max(0, track.scrollWidth - window.innerWidth);
      const tween = gsap.to(track, {
        x: () => -dist(),
        ease: 'none',
        scrollTrigger: {
          trigger: pin,
          pin: true,
          start: 'top top',
          end: () => `+=${dist()}`,
          scrub: 0.6,
          invalidateOnRefresh: true,
          onUpdate: (self) => {
            if (counter) counter.textContent = pad(Math.min(4, Math.floor(self.progress * 4) + 1));
          },
        },
      });

      $$('.step', track).forEach((step) => {
        const num = $('.step__num', step);
        gsap.fromTo(step, { scale: 0.94 }, {
          scale: 1,
          ease: 'none',
          scrollTrigger: { trigger: step, containerAnimation: tween, start: 'left 92%', end: 'left 40%', scrub: true },
        });
        if (num) {
          gsap.fromTo(num, { x: 90 }, {
            x: -60,
            ease: 'none',
            scrollTrigger: { trigger: step, containerAnimation: tween, start: 'left right', end: 'right left', scrub: true },
          });
        }
      });

      return () => {
        gsap.set(track, { clearProps: 'transform' });
      };
    });
  }

  /* ---------- Dialogues : ouverture et fermeture animées ---------- */

  function makeDialog(dlg) {
    let lastFocus = null;
    const open = () => {
      if (dlg.open) return;
      lastFocus = document.activeElement;
      dlg.showModal();
      root.classList.add('dialog-open');
      requestAnimationFrame(() => requestAnimationFrame(() => dlg.classList.add('is-visible')));
    };
    const close = () => {
      if (!dlg.open || dlg.dataset.closing) return;
      dlg.dataset.closing = '1';
      dlg.classList.remove('is-visible');
      const done = () => {
        delete dlg.dataset.closing;
        dlg.close();
        root.classList.remove('dialog-open');
        if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus({ preventScroll: true });
      };
      if (reduceMQ.matches) done();
      else setTimeout(done, 620);
    };
    // Échap : on remplace la fermeture immédiate par la fermeture animée
    dlg.addEventListener('cancel', (e) => {
      e.preventDefault();
      close();
    });
    // Clic sur le fond assombri
    dlg.addEventListener('click', (e) => {
      if (e.target === dlg) close();
    });
    return { open, close };
  }

  /* ---------- Prise de rendez-vous ---------- */

  function initBooking() {
    if (!$('[data-booking]')) return;
    const services = SALON.services;
    const state = { selected: new Set(), date: null, time: null, step: 1 };

    const dlg = document.createElement('dialog');
    dlg.className = 'booking';
    dlg.setAttribute('aria-labelledby', 'booking-title');
    dlg.innerHTML = `
      <div class="booking__panel">
        <header class="booking__head">
          <div class="booking__top">
            <div>
              <p class="mono booking__eyebrow">${SALON.name} · Prise de rendez-vous</p>
              <h2 class="booking__title" id="booking-title">Réserver</h2>
            </div>
            <button class="pill" type="button" data-close>Fermer</button>
          </div>
          <ol class="booking__steps" aria-label="Étapes">
            <li data-for="1"><span>01</span><span>Prestations</span></li>
            <li data-for="2"><span>02</span><span>Créneau</span></li>
            <li data-for="3"><span>03</span><span>Coordonnées</span></li>
            <li data-for="4"><span>04</span><span>Envoi</span></li>
          </ol>
        </header>

        <div class="booking__body">
          <section class="bstep" data-step="1" aria-labelledby="bk-t1">
            <h3 class="bstep__title" id="bk-t1" tabindex="-1">Vos prestations</h3>
            <p class="bstep__hint">Choisissez une ou plusieurs prestations : la durée et le prix s’additionnent.</p>
            <div class="svc-list" data-svc-list></div>
          </section>

          <section class="bstep" data-step="2" aria-labelledby="bk-t2" hidden>
            <h3 class="bstep__title" id="bk-t2" tabindex="-1">Date et heure</h3>
            <div class="days-head">
              <p class="mono" data-month></p>
              <div class="days-nav">
                <button class="pill pill--icon" type="button" data-days="-1" aria-label="Jours précédents">←</button>
                <button class="pill pill--icon" type="button" data-days="1" aria-label="Jours suivants">→</button>
              </div>
            </div>
            <div class="days" data-days-list role="group" aria-label="Choisir un jour"></div>
            <div class="slots" data-slots></div>
          </section>

          <section class="bstep" data-step="3" aria-labelledby="bk-t3" hidden>
            <h3 class="bstep__title" id="bk-t3" tabindex="-1">Vos coordonnées</h3>
            <p class="bstep__hint">Le salon s’en sert uniquement pour confirmer votre rendez-vous.</p>
            <form class="bk-form" data-form novalidate>
              <div class="field">
                <div class="field__box">
                  <input id="bk-name" name="name" type="text" autocomplete="name" placeholder=" " required aria-describedby="bk-name-err">
                  <label for="bk-name">Prénom et nom</label>
                  <span class="field__line" aria-hidden="true"></span>
                </div>
                <p class="field__error" id="bk-name-err"></p>
              </div>
              <div class="field">
                <div class="field__box">
                  <input id="bk-phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder=" " required aria-describedby="bk-phone-err">
                  <label for="bk-phone">Téléphone</label>
                  <span class="field__line" aria-hidden="true"></span>
                </div>
                <p class="field__error" id="bk-phone-err"></p>
              </div>
              <div class="field">
                <div class="field__box">
                  <input id="bk-email" name="email" type="email" autocomplete="email" placeholder=" " aria-describedby="bk-email-err">
                  <label for="bk-email">E-mail (facultatif)</label>
                  <span class="field__line" aria-hidden="true"></span>
                </div>
                <p class="field__error" id="bk-email-err"></p>
              </div>
              <div class="field">
                <div class="field__box">
                  <textarea id="bk-note" name="note" rows="3" maxlength="300" placeholder=" "></textarea>
                  <label for="bk-note">Précision (facultatif)</label>
                  <span class="field__line" aria-hidden="true"></span>
                </div>
                <p class="field__error"></p>
              </div>
              <button type="submit" hidden>Vérifier</button>
            </form>
          </section>

          <section class="bstep" data-step="4" aria-labelledby="bk-t4" hidden>
            <h3 class="bstep__title" id="bk-t4" tabindex="-1">Vérifiez et envoyez</h3>
            <dl class="recap" data-recap></dl>
            <p class="notice" data-notice></p>
            <div class="send" data-send></div>
            <p class="bk-error" data-send-error role="alert"></p>
          </section>

          <section class="bstep" data-step="5" aria-labelledby="bk-t5" hidden>
            <div class="done">
              <svg viewBox="0 0 48 48" aria-hidden="true" focusable="false">
                <path class="d" pathLength="1" d="M24 2L46 24L24 46L2 24Z"/>
                <path class="d" pathLength="1" d="M15 24.5L21.5 31L33.5 18"/>
              </svg>
              <h3 class="done__title" id="bk-t5" tabindex="-1" data-done-title></h3>
              <p class="done__text" data-done-text></p>
              <div class="done__actions">
                <button class="pill" type="button" data-close>Fermer</button>
                <button class="pill" type="button" data-restart>Nouvelle demande</button>
              </div>
            </div>
          </section>
        </div>

        <footer class="booking__foot" data-foot>
          <p class="booking__summary" data-summary aria-live="polite"></p>
          <div class="booking__actions">
            <button class="pill" type="button" data-prev>Retour</button>
            <button class="pill" type="button" data-next>Continuer</button>
          </div>
        </footer>
      </div>`;
    ($('.page') || document.body).appendChild(dlg);

    const dialog = makeDialog(dlg);
    const body = $('.booking__body', dlg);
    const prevBtn = $('[data-prev]', dlg);
    const nextBtn = $('[data-next]', dlg);
    const foot = $('[data-foot]', dlg);
    const summary = $('[data-summary]', dlg);
    const daysBox = $('[data-days-list]', dlg);
    const slotsBox = $('[data-slots]', dlg);
    const form = $('[data-form]', dlg);
    const sendError = $('[data-send-error]', dlg);
    const fields = {
      name: $('#bk-name', dlg),
      phone: $('#bk-phone', dlg),
      email: $('#bk-email', dlg),
      note: $('#bk-note', dlg),
    };

    const chosen = () => services.filter((s) => state.selected.has(s.id));
    const totalDuration = () => chosen().reduce((sum, s) => sum + s.duration, 0);
    const totalPrice = () => chosen().reduce((sum, s) => sum + s.price, 0);
    const value = (key) => fields[key].value.trim();
    const whenText = () => `${fmtLong.format(parseIso(state.date))} à ${toHHMM(state.time)}`;
    const channel = () => {
      if (SALON.bookingEndpoint) return 'endpoint';
      if (SALON.whatsapp) return 'whatsapp';
      if (SALON.email) return 'email';
      return 'demo';
    };

    /* Créneaux d'une journée : tous les départs possibles qui tiennent dans une plage d'ouverture.
       Le site ne connaît pas l'agenda du salon : c'est une demande, confirmée ensuite par le salon. */
    function slotsFor(date, duration) {
      const out = [];
      if (!duration) return out;
      const now = new Date();
      const earliest = isoDay(date) === isoDay(now) ? now.getHours() * 60 + now.getMinutes() + SALON.leadTime : -1;
      rangesFor(date).forEach(([a, b]) => {
        for (let t = toMin(a); t + duration <= toMin(b); t += SALON.slotStep) {
          if (t >= earliest) out.push(t);
        }
      });
      return out;
    }

    // Étape 1 : liste des prestations
    const svcList = $('[data-svc-list]', dlg);
    services.forEach((s) => {
      const label = document.createElement('label');
      label.className = 'svc';
      label.innerHTML = '<input type="checkbox"><span class="svc__mark" aria-hidden="true"></span><span class="svc__info"><span class="svc__name"></span><span class="svc__meta mono"></span></span><span class="svc__price"></span>';
      const input = $('input', label);
      input.id = `svc-${s.id}`;
      input.value = s.id;
      $('.svc__name', label).textContent = s.name;
      $('.svc__meta', label).textContent = fmtDuration(s.duration);
      $('.svc__price', label).textContent = fmtPrice(s.price);
      input.addEventListener('change', () => setService(s.id, input.checked));
      svcList.appendChild(label);
    });

    function setService(id, on) {
      const input = $(`#svc-${id}`, dlg);
      if (!input) return;
      input.checked = on;
      input.closest('.svc').classList.toggle('is-checked', on);
      if (on) state.selected.add(id);
      else state.selected.delete(id);
      // Un créneau choisi peut ne plus tenir si la durée change
      if (state.date && state.time !== null && !slotsFor(parseIso(state.date), totalDuration()).includes(state.time)) {
        state.time = null;
      }
      updateUI();
    }

    // Étape 2 : bande des jours puis créneaux
    function buildDays() {
      daysBox.textContent = '';
      const duration = totalDuration();
      const today = new Date();
      let first = null;
      let last = null;
      let firstAvailable = null;
      for (let i = 0; i < SALON.daysAhead; i++) {
        const d = new Date(today.getFullYear(), today.getMonth(), today.getDate() + i);
        const iso = isoDay(d);
        const count = slotsFor(d, duration).length;
        if (!first) first = d;
        last = d;
        if (count && !firstAvailable) firstAvailable = iso;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'day';
        btn.dataset.date = iso;
        btn.disabled = !count;
        btn.innerHTML = '<span class="mono"></span><b></b><span class="mono"></span>';
        btn.children[0].textContent = DAY_SHORT[d.getDay()];
        btn.children[1].textContent = String(d.getDate());
        btn.children[2].textContent = fmtMonthShort.format(d).replace('.', '');
        btn.setAttribute('aria-label', `${cap(fmtLong.format(d))}${count ? '' : ', aucun créneau'}`);
        btn.addEventListener('click', () => selectDate(iso, true));
        daysBox.appendChild(btn);
      }
      const a = fmtMonthYear.format(first);
      const b = fmtMonthYear.format(last);
      $('[data-month]', dlg).textContent = a === b ? a : `${a.replace(/\s\d{4}$/, '')} – ${b}`;

      if (!state.date || !slotsFor(parseIso(state.date), duration).length) {
        state.date = firstAvailable;
        state.time = null;
      }
      selectDate(state.date, false);
      const current = state.date && $(`.day[data-date="${state.date}"]`, daysBox);
      if (current) daysBox.scrollLeft = Math.max(0, current.offsetLeft - daysBox.offsetLeft - 8);
    }

    function selectDate(iso, animate) {
      state.date = iso;
      if (iso && state.time !== null && !slotsFor(parseIso(iso), totalDuration()).includes(state.time)) state.time = null;
      $$('.day', daysBox).forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.date === iso)));
      renderSlots(animate);
      updateUI();
    }

    function renderSlots(animate) {
      slotsBox.textContent = '';
      if (!state.date) {
        const p = document.createElement('p');
        p.className = 'slots__empty';
        p.textContent = `Aucun créneau libre sur les ${SALON.daysAhead} prochains jours pour cette durée.${SALON.phone ? ` Appelez le salon au ${SALON.phone}.` : ''}`;
        slotsBox.appendChild(p);
        return;
      }
      const list = slotsFor(parseIso(state.date), totalDuration());
      const title = document.createElement('p');
      title.className = 'slots__date';
      title.textContent = cap(fmtLong.format(parseIso(state.date)));
      slotsBox.appendChild(title);

      const groups = [
        ['Matin', (t) => t < 720],
        ['Après-midi', (t) => t >= 720 && t < 1020],
        ['Soir', (t) => t >= 1020],
      ];
      let k = 0;
      groups.forEach(([name, test]) => {
        const times = list.filter(test);
        if (!times.length) return;
        const group = document.createElement('div');
        group.className = 'slots__group';
        const label = document.createElement('p');
        label.className = 'mono slots__label';
        label.textContent = `${name} · ${plural(times.length, 'créneau', 'créneaux')}`;
        const grid = document.createElement('div');
        grid.className = 'slots__grid';
        grid.setAttribute('role', 'group');
        grid.setAttribute('aria-label', name);
        times.forEach((t) => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'slot';
          btn.textContent = toHHMM(t);
          btn.style.setProperty('--k', k++);
          btn.setAttribute('aria-pressed', String(state.time === t));
          btn.addEventListener('click', () => {
            state.time = t;
            $$('.slot', slotsBox).forEach((x) => x.setAttribute('aria-pressed', String(x === btn)));
            updateUI();
          });
          grid.appendChild(btn);
        });
        group.append(label, grid);
        slotsBox.appendChild(group);
      });

      if (animate && !reduceMQ.matches) {
        slotsBox.classList.remove('is-popping');
        void slotsBox.offsetWidth;
        slotsBox.classList.add('is-popping');
      }
    }

    $$('[data-days]', dlg).forEach((btn) => {
      btn.addEventListener('click', () => {
        daysBox.scrollBy({ left: Number(btn.dataset.days) * daysBox.clientWidth * 0.8, behavior: reduceMQ.matches ? 'auto' : 'smooth' });
      });
    });

    // Étape 3 : vérification des coordonnées
    function checkField(key, show) {
      const v = value(key);
      let msg = '';
      if (key === 'name' && v.length < 2) msg = 'Indiquez votre prénom et votre nom.';
      if (key === 'phone') {
        const digits = v.replace(/[\s.\-()]/g, '');
        const ok = /^(?:(?:\+|00)33|0)[1-9]\d{8}$/.test(digits) || /^\+\d{8,15}$/.test(digits);
        if (!ok) msg = 'Indiquez un numéro valide, par exemple 06 12 34 56 78.';
      }
      if (key === 'email' && v && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) msg = 'Vérifiez l’adresse, par exemple nom@exemple.fr.';
      if (show) {
        const wrap = fields[key].closest('.field');
        wrap.classList.toggle('is-invalid', Boolean(msg));
        fields[key].setAttribute('aria-invalid', String(Boolean(msg)));
        $('.field__error', wrap).textContent = msg;
      }
      return !msg;
    }
    const formValid = (show) => ['name', 'phone', 'email'].map((k) => checkField(k, show)).every(Boolean);

    ['name', 'phone', 'email'].forEach((key) => {
      fields[key].addEventListener('input', () => {
        if (fields[key].closest('.field').classList.contains('is-invalid')) checkField(key, true);
      });
      fields[key].addEventListener('blur', () => {
        if (value(key)) checkField(key, true);
      });
    });
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      next();
    });

    // Étape 4 : récapitulatif et envoi
    function message() {
      const lines = [
        `Bonjour ${SALON.name}, je souhaite prendre rendez-vous.`,
        `Prestations : ${chosen().map((s) => s.name).join(' + ')} (${fmtDuration(totalDuration())}, ${totalPrice()} €)`,
        `Date : ${whenText()}`,
        `Nom : ${value('name')}`,
        `Téléphone : ${value('phone')}`,
      ];
      if (value('email')) lines.push(`E-mail : ${value('email')}`);
      if (value('note')) lines.push(`Précision : ${value('note')}`);
      return lines.join('\n');
    }

    const payload = () => ({
      salon: SALON.name,
      prestations: chosen().map((s) => s.name).join(' + '),
      duree_minutes: totalDuration(),
      prix_total: totalPrice(),
      date: state.date,
      heure: toHHMM(state.time),
      nom: value('name'),
      telephone: value('phone'),
      email: value('email'),
      precision: value('note'),
      message: message(),
    });

    function renderRecap() {
      const recap = $('[data-recap]', dlg);
      recap.textContent = '';
      const rows = [
        ['Prestations', `${chosen().map((s) => s.name).join(' + ')} · ${fmtDuration(totalDuration())} · ${fmtPrice(totalPrice())}`, 1],
        ['Date', cap(whenText()), 2],
        ['Nom', value('name'), 3],
        ['Téléphone', value('phone'), 3],
      ];
      if (value('email')) rows.push(['E-mail', value('email'), 3]);
      if (value('note')) rows.push(['Précision', value('note'), 3]);
      rows.forEach(([label, text, step]) => {
        const row = document.createElement('div');
        row.className = 'recap__row';
        const dt = document.createElement('dt');
        dt.className = 'mono';
        dt.textContent = label;
        const dd = document.createElement('dd');
        const span = document.createElement('span');
        span.textContent = text;
        const edit = document.createElement('button');
        edit.type = 'button';
        edit.className = 'recap__edit';
        edit.textContent = 'Modifier';
        edit.setAttribute('aria-label', `Modifier : ${label}`);
        edit.addEventListener('click', () => show(step));
        dd.append(span, edit);
        row.append(dt, dd);
        recap.appendChild(row);
      });

      const ch = channel();
      const call = SALON.phone ? ` Pour toute question, appelez le salon au ${SALON.phone}.` : '';
      const notices = {
        endpoint: 'Votre demande part directement au salon, qui vous confirme le rendez-vous par téléphone ou SMS.',
        whatsapp: 'WhatsApp s’ouvre avec votre demande déjà rédigée : il reste à appuyer sur Envoyer. Le salon vous confirme ensuite le rendez-vous.',
        email: 'Votre messagerie s’ouvre avec la demande déjà rédigée : il reste à l’envoyer. Le salon vous confirme ensuite le rendez-vous.',
        demo: 'Mode démonstration : l’envoi des demandes n’est pas encore configuré, rien ne sera transmis au salon.',
      };
      const notice = $('[data-notice]', dlg);
      notice.textContent = notices[ch] + call;
      notice.classList.toggle('is-demo', ch === 'demo');

      const box = $('[data-send]', dlg);
      box.textContent = '';
      sendError.textContent = '';
      let action;
      if (ch === 'whatsapp' || ch === 'email') {
        action = document.createElement('a');
        if (ch === 'whatsapp') {
          action.href = `https://wa.me/${SALON.whatsapp.replace(/\D/g, '')}?text=${encodeURIComponent(message())}`;
          action.target = '_blank';
          action.rel = 'noopener';
        } else {
          action.href = `mailto:${SALON.email}?subject=${encodeURIComponent('Demande de rendez-vous')}&body=${encodeURIComponent(message())}`;
        }
        action.addEventListener('click', () => setTimeout(() => finish(ch), 200));
      } else {
        action = document.createElement('button');
        action.type = 'button';
        action.addEventListener('click', () => send(action));
      }
      action.className = 'pill pill--xl';
      action.textContent = { endpoint: 'Envoyer la demande', whatsapp: 'Envoyer sur WhatsApp', email: 'Envoyer par e-mail', demo: 'Envoyer la demande' }[ch];
      box.appendChild(action);
    }

    async function send(btn) {
      if (channel() === 'demo') {
        finish('demo');
        return;
      }
      btn.disabled = true;
      btn.textContent = 'Envoi en cours…';
      sendError.textContent = '';
      try {
        const res = await fetch(SALON.bookingEndpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify(payload()),
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        finish('endpoint');
      } catch (err) {
        btn.disabled = false;
        btn.textContent = 'Réessayer l’envoi';
        sendError.textContent = `L’envoi a échoué. Vérifiez votre connexion et réessayez${SALON.phone ? `, ou appelez le salon au ${SALON.phone}` : ''}.`;
      }
    }

    function finish(ch) {
      const when = whenText();
      const texts = {
        endpoint: ['Demande envoyée', `Le salon a reçu votre demande pour le ${when}. Il vous confirme le rendez-vous par téléphone ou SMS.`],
        whatsapp: ['Message prêt', `Votre demande pour le ${when} est rédigée dans WhatsApp. Pensez à appuyer sur Envoyer.`],
        email: ['Message prêt', `Votre demande pour le ${when} est rédigée dans votre messagerie. Pensez à l’envoyer.`],
        demo: ['Demande prête', `Mode démonstration : la demande pour le ${when} n’a pas été envoyée. Une fois l’envoi configuré, elle partira directement au salon.`],
      };
      $('[data-done-title]', dlg).textContent = texts[ch][0];
      $('[data-done-text]', dlg).textContent = texts[ch][1];
      show(5);
    }

    // Navigation entre les étapes
    function enter(section, back) {
      if (reduceMQ.matches) return;
      [...section.children].forEach((child, i) => child.style.setProperty('--k', i));
      section.classList.remove('is-entering', 'from-back');
      void section.offsetWidth;
      section.classList.add('is-entering');
      if (back) section.classList.add('from-back');
    }

    function show(step) {
      const back = step < state.step;
      state.step = step;
      $$('.bstep', dlg).forEach((section) => {
        const on = Number(section.dataset.step) === step;
        section.hidden = !on;
        if (on) enter(section, back);
      });
      if (step === 2) buildDays();
      if (step === 4) renderRecap();
      updateUI();
      body.scrollTop = 0;
      const title = $(`[data-step="${step}"] [tabindex="-1"]`, dlg);
      if (title && dlg.open) title.focus({ preventScroll: true });
    }

    function next() {
      if (state.step === 1 && !state.selected.size) return;
      if (state.step === 2 && (!state.date || state.time === null)) return;
      if (state.step === 3 && !formValid(true)) {
        const invalid = $('.field.is-invalid input', dlg);
        if (invalid) invalid.focus();
        return;
      }
      show(state.step + 1);
    }

    function updateUI() {
      const step = state.step;
      $$('.booking__steps li', dlg).forEach((li) => {
        const n = Number(li.dataset.for);
        li.classList.toggle('is-active', n === Math.min(step, 4) && step < 5);
        li.classList.toggle('is-done', n < step);
        if (n === step) li.setAttribute('aria-current', 'step');
        else li.removeAttribute('aria-current');
      });
      foot.hidden = step >= 5;
      prevBtn.hidden = step === 1;
      nextBtn.hidden = step >= 4;
      nextBtn.textContent = step === 3 ? 'Vérifier' : 'Continuer';
      nextBtn.disabled = (step === 1 && !state.selected.size) || (step === 2 && (!state.date || state.time === null));

      const list = chosen();
      let text = 'Aucune prestation choisie';
      if (list.length) {
        const what = list.length > 1 ? plural(list.length, 'prestation', 'prestations') : list[0].name;
        text = `${what} · ${fmtDuration(totalDuration())} · ${fmtPrice(totalPrice())}`;
      }
      if (step >= 2 && state.date && state.time !== null) {
        text += ` · ${fmtShort.format(parseIso(state.date))} à ${toHHMM(state.time)}`;
      }
      summary.textContent = text;
    }

    function restart() {
      services.forEach((s) => setService(s.id, false));
      state.date = null;
      state.time = null;
      form.reset();
      $$('.field', dlg).forEach((f) => {
        f.classList.remove('is-invalid');
        $('.field__error', f).textContent = '';
      });
      show(1);
    }

    prevBtn.addEventListener('click', () => show(state.step - 1));
    nextBtn.addEventListener('click', next);
    $$('[data-close]', dlg).forEach((b) => b.addEventListener('click', dialog.close));
    $('[data-restart]', dlg).addEventListener('click', restart);

    function openBooking(serviceId) {
      if (state.step === 5) restart();
      const known = services.some((s) => s.id === serviceId);
      if (known) setService(serviceId, true);
      dialog.open();
      show(known ? 2 : state.step);
    }

    // Tous les boutons « Réserver » ouvrent le panneau ; une carte présélectionne sa prestation
    document.addEventListener('click', (e) => {
      const trigger = e.target.closest('[data-booking]');
      if (!trigger || dlg.contains(trigger)) return;
      e.preventDefault();
      openBooking(trigger.dataset.service);
    });

    // Lien direct vers la réservation : …/#rdv (pratique pour une bio Instagram)
    if (window.location.hash === '#rdv') openBooking();

    show(1);
  }

  /* ---------- Visionneuse de la galerie ---------- */

  function initLightbox() {
    const shots = $$('[data-shot]');
    if (!shots.length) return;
    const items = shots.map((a) => ({
      src: a.getAttribute('href'),
      alt: $('img', a) ? $('img', a).alt : '',
      title: $('.shot__title', a) ? $('.shot__title', a).textContent : '',
      text: $('.shot__text', a) ? $('.shot__text', a).textContent : '',
    }));

    const dlg = document.createElement('dialog');
    dlg.className = 'lightbox';
    dlg.setAttribute('aria-label', 'Photo agrandie');
    dlg.innerHTML = `
      <div class="lightbox__inner">
        <div class="lightbox__top">
          <p class="mono lightbox__count" data-count-lb></p>
          <button class="pill" type="button" data-lb-close>Fermer</button>
        </div>
        <div class="lightbox__stage" data-stage><img class="lightbox__img" alt=""></div>
        <div class="lightbox__foot">
          <div class="lightbox__cap">
            <p class="lightbox__title" data-lb-title></p>
            <p class="lightbox__text" data-lb-text></p>
          </div>
          <div class="lightbox__nav">
            <button class="pill" type="button" data-go="-1">← Précédente</button>
            <button class="pill" type="button" data-go="1">Suivante →</button>
          </div>
        </div>
      </div>`;
    ($('.page') || document.body).appendChild(dlg);

    const dialog = makeDialog(dlg);
    const img = $('.lightbox__img', dlg);
    const stage = $('[data-stage]', dlg);
    let index = 0;

    function render(dir) {
      const item = items[index];
      img.src = item.src;
      img.alt = item.alt;
      $('[data-count-lb]', dlg).textContent = `${pad(index + 1)} / ${pad(items.length)}`;
      $('[data-lb-title]', dlg).textContent = item.title;
      $('[data-lb-text]', dlg).textContent = item.text;
      [1, -1].forEach((d) => {
        const pre = new Image();
        pre.src = items[(index + d + items.length) % items.length].src;
      });
      if (motion) {
        gsap.fromTo(img,
          { x: dir ? dir * 80 : 0, opacity: 0, scale: dir ? 0.97 : 0.9 },
          { x: 0, opacity: 1, scale: 1, duration: dir ? 0.7 : 0.9, ease: 'expo.out', overwrite: true });
      }
    }

    const go = (d) => {
      index = (index + d + items.length) % items.length;
      render(d);
    };

    shots.forEach((a, i) => {
      a.addEventListener('click', (e) => {
        e.preventDefault();
        index = i;
        dialog.open();
        render(0);
      });
    });
    $$('[data-go]', dlg).forEach((b) => b.addEventListener('click', () => go(Number(b.dataset.go))));
    $('[data-lb-close]', dlg).addEventListener('click', dialog.close);
    dlg.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowRight') go(1);
      if (e.key === 'ArrowLeft') go(-1);
    });

    // Balayage au doigt
    let startX = null;
    stage.addEventListener('pointerdown', (e) => {
      startX = e.clientX;
    });
    stage.addEventListener('pointerup', (e) => {
      if (startX === null) return;
      const dx = e.clientX - startX;
      startX = null;
      if (Math.abs(dx) > 50) go(dx < 0 ? 1 : -1);
    });
    // Clic à côté de la photo : fermeture
    stage.addEventListener('click', (e) => {
      if (e.target === stage) dialog.close();
    });
  }

  /* ---------- Démarrage ---------- */

  function boot() {
    initSalonInfo();
    buildRolls();

    const heroTitle = $('.hero__title');
    const heroChars = heroTitle && motion ? splitChars(heroTitle) : [];

    initNewsBar();
    initHeader();
    initMenu();
    initMarquees();
    initCanvases();
    initCursor();
    initMagnetic();
    initCards();
    initDodge();
    initBooking();
    initLightbox();

    if (motion) {
      heroIntro(heroChars);
      heroScroll(heroChars);
      revealOnScroll();
      shotsMotion();
      wordsScrub();
      processSection();
    }

    const year = $('[data-year]');
    if (year) year.textContent = String(new Date().getFullYear());

    if (hasGSAP) {
      window.addEventListener('load', () => ScrollTrigger.refresh());
      if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(() => ScrollTrigger.refresh());
      }
    }
  }

  boot();
})();
