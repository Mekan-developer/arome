/* ============================================================
   AROME · движок урока
   Страница урока задаёт STEPS и RESET, всё остальное здесь.
   ============================================================ */
   "use strict";

   const $  = (s, r = document) => r.querySelector(s);
   const $$ = (s, r = document) => [...r.querySelectorAll(s)];
   const el = n => $('[data-el="' + n + '"]');
   
   const S = { step: 0, paused: false, run: 0, audio: null, steps: [], reset: null, done: false, fast: false, muted: false };
   
   /* ---------- ожидание с учётом паузы ---------- */
   function wait(ms) {
     if (S.fast) return Promise.resolve();          // перемотка — без задержек
     const my = S.run;
     return new Promise(res => {
       let left = ms, last = performance.now();
       (function tick(now) {
         if (S.run !== my) return;
         const d = now - last; last = now;
         if (!S.paused) left -= d;
         if (left <= 0) res(); else requestAnimationFrame(tick);
       })(last);
     });
   }
   
   /* ---------- курсор ---------- */
   function cursorEl() { return $('#cursor'); }
   
   async function moveTo(node, ms = 780) {
     if (!node) return;
   
     // если поле уехало за край прокручиваемой области — подматываем к нему
     const box = node.closest('.m-body, .p-body');
     if (box && box.scrollHeight > box.clientHeight + 2) {
       const nb = node.getBoundingClientRect(), bb = box.getBoundingClientRect();
       if (nb.top < bb.top + 12 || nb.bottom > bb.bottom - 12) {
         box.scrollTo({ top: box.scrollTop + (nb.top - bb.top) - box.clientHeight / 2 + node.offsetHeight / 2, behavior: S.fast ? 'auto' : 'smooth' });
         await wait(480);
       }
     }
   
     const sc = $('#scaler'), cur = cursorEl();
     const a = sc.getBoundingClientRect(), b = node.getBoundingClientRect();
     const k = a.width ? sc.offsetWidth / a.width : 1;
     const x = (b.left - a.left) * k + node.offsetWidth / 2;
     const y = (b.top - a.top) * k + node.offsetHeight / 2;
     cur.classList.add('on');
     cur.style.transition = S.fast ? 'none' : `transform ${ms}ms cubic-bezier(.35,.1,.25,1)`;
     cur.style.transform = `translate(${x}px, ${y}px)`;
     await wait(ms + 60);
   }
   
   async function tap() {
     if (S.fast) return;
     const r = $('#ring');
     r.classList.remove('tap'); void r.offsetWidth; r.classList.add('tap');
     await wait(330);
   }
   
   async function click(node, hold = 420) {
     await moveTo(node);
     if (node) node.classList.add('lit');
     await tap();
     await wait(hold);
     if (node) node.classList.remove('lit');
   }
   
   /* ---------- печать в поле ---------- */
   async function typeIn(node, text, perChar = 52) {
     if (!node) return;
     node.classList.remove('ph');
     if (S.fast) { node.textContent = text; return; }
     node.classList.add('lit');
     node.textContent = '';
     const car = document.createElement('span');
     car.className = 'caret';
     node.appendChild(car);
     for (const ch of text) {
       car.insertAdjacentText('beforebegin', ch);
       await wait(perChar);
     }
     await wait(300);
     car.remove();
     node.classList.remove('lit');
   }
   
   /* ---------- выпадающий список ---------- */
   async function pickFrom(selName, value) {
     const box = el(selName);
     if (!box) return;
   
     if (S.fast) {
       const o = $$('.sel-list div', box).find(d => d.textContent.trim() === value);
       if (o) { $$('.sel-list div', box).forEach(d => d.classList.remove('pick')); o.classList.add('pick'); }
       $('.sel-face', box).textContent = value;
       return;
     }
   
     await moveTo(box, 520);
     box.classList.add('open');
     await tap();
     await wait(650);
     const opt = $$('.sel-list div', box).find(d => d.textContent.trim() === value);
     if (opt) {
       $$('.sel-list div', box).forEach(d => d.classList.remove('pick'));
       opt.classList.add('pick');
       await moveTo(opt, 420);
       await tap();
       $('.sel-face', box).textContent = value;
     }
     await wait(350);
     box.classList.remove('open');
     await wait(280);
   }
   
   /* ---------- модалки ---------- */
   function openModal(id) {
     const m = $('#' + id);
     if (m) m.classList.add('on');
     const bg = $('#mbg');
     if (bg) bg.classList.add('on');
   }
   function closeModals() {
     $$('.modal, .drawer').forEach(m => m.classList.remove('on'));
     $$('.m-body').forEach(b => b.scrollTop = 0);
     const bg = $('#mbg');
     if (bg) bg.classList.remove('on');
   }
   
   /* ---------- субтитры ---------- */
   function say(text) {
     const p = $('#subs');
     if (!p) return;
     p.classList.remove('on');
     setTimeout(() => { p.textContent = text; p.classList.add('on'); }, 160);
   }
   
   /* ---------- звук ---------- */
   const TRACKS = new Set();          // все созданные дорожки — чтобы ни одна не осталась играть
   
   function killTrack(a) {
     TRACKS.delete(a);
     try {
       a.pause();
       a.currentTime = 0;
       a.removeAttribute('src');
       a.load();                      // обрывает загрузку и отложенный play()
     } catch (e) {}
   }
   
   function stopAudio() {
     S.audio = null;
     TRACKS.forEach(killTrack);
     TRACKS.clear();
   }
   
   function playAudio(src) {
     stopAudio();
     if (!src || S.fast) return null;
   
     const a = new Audio(src);
     a.muted = S.muted;
     TRACKS.add(a);
     S.audio = a;
   
     const p = a.play();
     if (p && p.then) {
       // если шаг сменился, пока play() ещё думал — глушим сразу после старта
       p.then(() => { if (S.audio !== a) killTrack(a); }).catch(() => {});
     }
     return a;
   }
   
   /* ждём конца дорожки; вернёт false, если файла нет или он не читается */
   function audioDone(a) {
     return new Promise(res => {
       let settled = false;
       const ok  = () => { if (!settled) { settled = true; res(true); } };
       const bad = () => { if (!settled) { settled = true; res(false); } };
       a.addEventListener('ended', ok);
       a.addEventListener('error', bad);
       setTimeout(() => { if (!settled && a.readyState === 0) bad(); }, 2200);
     });
   }
   
   /* ---------- проигрывание ---------- */
   async function playStep() {
     const run = ++S.run;
     const st = S.steps[S.step];
     if (!st) return;
   
     say(st.text);
     const no = $('#stepNo');
     if (no) no.textContent = String(S.step + 1).padStart(2, '0') + ' / ' + String(S.steps.length).padStart(2, '0');
   
     const a = playAudio(st.audio);
   
     // полоса: по дорожке, если она есть, иначе по таймеру шага
     let spent = 0, last = performance.now();
     (function bar(now) {
       if (S.run !== run) return;
       const d = now - last; last = now;
       if (!S.paused) spent += d;
       const track = S.audio;
       const pct = (track && isFinite(track.duration) && track.duration > 0)
         ? track.currentTime / track.duration * 100
         : spent / st.dur * 100;
       const el = $('#bar');
       if (el) el.style.width = Math.min(100, pct) + '%';
       requestAnimationFrame(bar);
     })(last);
   
     // если сцена споткнулась — шаг всё равно должен доиграть и пойти дальше
     const anim = (st.act ? st.act() : Promise.resolve())
       .catch(err => console.error('Шаг ' + (S.step + 1) + ':', err));
   
     const voice = a
       ? audioDone(a).then(okay => okay ? wait(600) : wait(st.dur))
       : wait(st.dur);
   
     await Promise.all([anim, voice]);
     if (S.run !== run) return;
   
     if (S.step < S.steps.length - 1) { S.step++; playStep(); }
     else finish();
   }
   
   function finish() {
     S.run++; stopAudio(); S.done = true;
     $('#cPlay').textContent = 'Смотреть снова';
     say('Инструкция закончена. Можно посмотреть заново или вернуться к списку.');
   }
   
   /* перемотка: прокручиваем шаги в ускоренном режиме, чтобы сцена
      пришла в нужное состояние без ожидания анимаций */
   async function goto(i) {
     S.run++; stopAudio(); S.paused = false; S.done = false;
     $('#cPlay').textContent = 'Пауза';
     const my = S.run;
   
     S.fast = true;
     S.reset();
     for (let k = 0; k < i; k++) {
       const st = S.steps[k];
       if (st.act) await st.act();
       if (S.run !== my) { S.fast = false; return; }   // нажали ещё раз — эту сборку бросаем
     }
     S.fast = false;
     if (S.run !== my) return;
   
     S.step = i;
     stopAudio();                                       // страховка перед новой дорожкой
     playStep();
   }
   
   /* ---------- масштаб ---------- */
   function fit() {
     const st = $('#stage'), sc = $('#scaler');
     const k = Math.min((st.clientWidth - 40) / 1180, (st.clientHeight - 40) / 640, 1);
     sc.style.transform = 'scale(' + k + ')';
   }
   
   /* ---------- звук вкл/выкл ---------- */
   function setMute(on) {
     S.muted = on;
     if (S.audio) S.audio.muted = on;
     const b = $('#cMute');
     if (b) {
       b.classList.toggle('off', on);
       b.setAttribute('aria-label', on ? 'Включить звук' : 'Выключить звук');
       b.setAttribute('aria-pressed', String(on));
     }
     try { localStorage.setItem('arome-muted', on ? '1' : '0'); } catch (e) {}
   }
   
   /* ---------- запуск ---------- */
   function startLesson(cfg) {
     S.steps = cfg.steps;
     S.reset = cfg.reset;
   
     $('#cPlay').onclick = () => {
       if (S.done) { goto(0); return; }
       S.paused = !S.paused;
       $('#cPlay').textContent = S.paused ? 'Продолжить' : 'Пауза';
       if (S.audio) S.paused ? S.audio.pause() : S.audio.play().catch(() => {});
     };
     $('#cNext').onclick  = () => goto(Math.min(S.steps.length - 1, S.step + 1));
     $('#cPrev').onclick  = () => goto(Math.max(0, S.step - 1));
     $('#cAgain').onclick = () => goto(0);
   
     const mute = $('#cMute');
     if (mute) mute.onclick = () => setMute(!S.muted);
     let saved = '0';
     try { saved = localStorage.getItem('arome-muted') || '0'; } catch (e) {}
     setMute(saved === '1');
   
     document.addEventListener('keydown', e => {
       if (e.code === 'Space') { e.preventDefault(); $('#cPlay').click(); }
       if (e.code === 'ArrowRight') $('#cNext').click();
       if (e.code === 'ArrowLeft')  $('#cPrev').click();
       if (e.code === 'KeyM')       setMute(!S.muted);
     });
   
     window.addEventListener('resize', fit);
   
     $('#go').onclick = () => {
       $('#intro').classList.add('gone');
       setTimeout(() => $('#intro').style.display = 'none', 600);
       fit(); goto(0);
     };
   
     fit();
     try { S.reset(); } catch (e) { console.error('RESET:', e); }
   }