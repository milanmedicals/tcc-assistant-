/*! TCC Shop Assistant: dependency-free widget. All server text is inserted with textContent, never innerHTML. */
(function () {
	'use strict';
	var D = window.tccaData;
	if (!D || !D.rest) { return; }
	var doc = document;
	var KEY = 'tccaChat1';
	var MAX_HIST = 40;

	/* ---------- tiny helpers ---------- */
	function el(tag, cls, text) {
		var n = doc.createElement(tag);
		if (cls) { n.className = cls; }
		if (text != null && text !== '') { n.textContent = text; }
		return n;
	}
	function attr(n, o) { for (var k in o) { if (o[k] != null) { n.setAttribute(k, o[k]); } } return n; }
	var ICON = {
		chat: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/></svg>',
		x: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>',
		send: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>',
		trash: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2m-9 0l1 14h8l1-14"/></svg>',
		check: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5 9-10"/></svg>'
	};
	function icon(name) { var s = el('span', 'tcca-ico'); s.innerHTML = ICON[name]; return s; } // static strings only.

	/* ---------- state ---------- */
	var st = { session: '', msgs: [], busy: false, open: false, lastPage: '', ctx: { pid: 0, pids: [], vtoken: '', dtoken: '' } };
	var lastOrder = null; // memory only: a guest's verified order + email, never stored
	try {
		var saved = JSON.parse(sessionStorage.getItem(KEY) || 'null');
		if (saved && saved.session) { st.session = saved.session; st.msgs = saved.msgs || []; st.ctx = saved.ctx || st.ctx; st.lastPage = saved.lastPage || ''; }
	} catch (e) { /* storage blocked: chat still works */ }
	if (!st.session) { st.session = (Math.random().toString(36).slice(2) + Date.now().toString(36)).slice(0, 24); }
	function persist() {
		try { sessionStorage.setItem(KEY, JSON.stringify({ session: st.session, msgs: st.msgs.slice(-MAX_HIST), ctx: st.ctx, lastPage: st.lastPage })); } catch (e) { /* ignore */ }
	}
	/* Sales measurement: a random id in a first-party cookie, set only once the visitor uses the chat (and only if the shop switched it on). */
	function setSid() {
		if (!D.attr) { return; }
		try { doc.cookie = 'tcca_sid=' + st.session + '; Max-Age=604800; Path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : ''); } catch (e) { /* cookies blocked */ }
	}
	function ping(type, pid, qty) {
		try { fetch(D.rest + 'track', { method: 'POST', keepalive: true, credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ type: type, pid: pid || 0, qty: qty || 1, session: st.session }) }).catch(function () { /* best effort */ }); } catch (e) { /* ignore */ }
	}
	/* Context sent with every message: what was shown, plus the page the visitor is on (the server re-checks it). */
	function ctxOut() {
		var c = {}; for (var k in st.ctx) { c[k] = st.ctx[k]; }
		if (D.page && D.page.type) { c.page = { type: D.page.type, id: D.page.id || 0 }; }
		return c;
	}
	function remember(role, text, r) {
		var m = { role: role, text: String(text || '').slice(0, 600) };
		if (r) { m.r = r; }
		st.msgs.push(m);
		// Keep rich answers only for the most recent ones so storage stays small.
		var n = 0;
		for (var i = st.msgs.length - 1; i >= 0; i--) { if (st.msgs[i].r && ++n > 10) { delete st.msgs[i].r; } }
		persist();
	}
	/* What may be kept in the browser: orders and the cart become "show again" buttons (privacy and freshness). */
	function slim(r) {
		var o = { reply: r.reply, ai: r.ai, fb: false, actions: r.actions || [], chips: r.chips || [], cards: [] };
		(r.cards || []).forEach(function (c) {
			if (c.type === 'order') { o.cards.push(D.loggedIn ? { type: 'ref', label: 'Order #' + c.number + ' details', message: 'Track order #' + c.number } : { type: 'note', text: 'Order details are hidden after you close the chat, for your privacy. Ask again to see them.' }); }
			else if (c.type === 'cart') { o.cards.push({ type: 'ref', label: 'Your cart', message: 'Show my cart' }); }
			else if (c.type === 'account') { o.cards.push({ type: 'ref', label: 'Your account details', message: 'Find my orders' }); }
			else if (c.type === 'customer') { o.cards.push({ type: 'ref', label: 'Customer ' + (c.email || ''), message: 'find ' + (c.email || '') }); }
			else { o.cards.push(c); }
		});
		return o;
	}

	/* ---------- shell ---------- */
	var root = el('div', 'tcca');
	var launcher = attr(el('button', 'tcca-launch'), { type: 'button', 'aria-label': 'Open ' + D.name, 'aria-expanded': 'false' });
	launcher.appendChild(icon('chat'));
	var teaser = el('div', 'tcca-teaser'); teaser.hidden = true;

	var panel = attr(el('section', 'tcca-panel'), { role: 'dialog', 'aria-label': D.name, tabindex: '-1' });
	panel.hidden = true;
	var head = el('header', 'tcca-head');
	var av = el('span', 'tcca-av');
	if (D.mark) { var im = attr(el('img'), { src: D.mark, alt: '', width: 22, height: 22 }); av.appendChild(im); } else { av.appendChild(icon('chat')); }
	var ht = el('div', 'tcca-head__t');
	ht.appendChild(el('strong', '', D.name));
	ht.appendChild(el('small', '', 'Not a pharmacist. For medical advice, we connect you with one.'));
	var clr = attr(el('button', 'tcca-iconbtn'), { type: 'button', 'aria-label': 'Clear conversation' }); clr.appendChild(icon('trash'));
	var cls = attr(el('button', 'tcca-iconbtn'), { type: 'button', 'aria-label': 'Close chat' }); cls.appendChild(icon('x'));
	head.appendChild(av); head.appendChild(ht); head.appendChild(clr); head.appendChild(cls);

	var log = attr(el('div', 'tcca-log'), { role: 'log', 'aria-live': 'polite', 'aria-relevant': 'additions' });
	var foot = el('form', 'tcca-foot');
	var ta = attr(el('textarea', 'tcca-in'), { rows: 1, maxlength: 500, placeholder: 'Ask me anything…', 'aria-label': 'Your message' });
	var sendBtn = attr(el('button', 'tcca-send'), { type: 'submit', 'aria-label': 'Send' }); sendBtn.appendChild(icon('send'));
	foot.appendChild(ta); foot.appendChild(sendBtn);
	var note = el('p', 'tcca-note', D.privacy || '');
	panel.appendChild(head); panel.appendChild(log); panel.appendChild(foot); if (D.privacy) { panel.appendChild(note); }
	root.appendChild(teaser); root.appendChild(panel); root.appendChild(launcher);
	/* Everything lives in a Shadow DOM: the theme's CSS cannot reach in, and ours cannot leak out.
	   display:contents means the host itself takes no space in the page, so nothing can ever appear in the footer. */
	var host = doc.createElement('div'); host.id = 'tcca-host';
	var sh = host.attachShadow({ mode: 'open' });
	var gs = doc.createElement('style'); gs.textContent = '#tcca-host{all:initial;display:contents}';
	/* Replace the theme's own chat button while this assistant is running (the server already does this on first paint;
	   this repeats it for cached pages). The "tcca-failed" class gives the theme's button back if we cannot start. */
	if (D.replace) {
		doc.body.classList.add('tcca-active');
		if (!doc.getElementById('tcca-swap')) {
			gs.textContent += D.replace.split(',').map(function (x) { return x.trim() ? 'body.tcca-active:not(.tcca-failed) ' + x.trim() + '{display:none!important}' : ''; }).join('');
		}
	}
	doc.head.appendChild(gs);
	host.style.visibility = 'hidden';
	sh.appendChild(root); doc.body.appendChild(host);
	function styled() { try { return getComputedStyle(launcher).position === 'fixed'; } catch (e) { return false; } }
	/* Hide / restore the theme's button through the style API as well: it still works on sites whose security policy blocks inline <style>. */
	function swapTheme(on) {
		if (!D.replace) { return; }
		try {
			doc.querySelectorAll(D.replace).forEach(function (n) { if (on) { n.style.setProperty('display', 'none', 'important'); } else { n.style.removeProperty('display'); } });
		} catch (e) { /* invalid selector from a filter: ignore */ }
	}
	function mountOk() { host.style.visibility = 'visible'; window.tccaReady = true; doc.body.classList.remove('tcca-failed'); swapTheme(true); }
	function giveUp(why) {
		if (window.tccaReady) { return; }
		try { host.parentNode && host.parentNode.removeChild(host); } catch (e) { /* ignore */ }
		doc.body.classList.add('tcca-failed'); // the theme's own chat button comes back
		swapTheme(false);
		if (window.console && console.warn) { console.warn('TCC Assistant could not start (' + why + '). The theme chat button is shown instead.'); }
	}
	function useLink() {
		if (!D.css) { giveUp('styles not applied'); return; }
		var lk = doc.createElement('link'); lk.rel = 'stylesheet'; lk.href = D.css; sh.insertBefore(lk, sh.firstChild);
		lk.addEventListener('load', function () { if (styled()) { mountOk(); } else { giveUp('styles not applied'); } });
		lk.addEventListener('error', function () { giveUp('stylesheet failed to load'); });
		setTimeout(function () { if (!window.tccaReady) { giveUp('stylesheet timed out'); } }, 5000);
	}
	if (D.cssText) {
		var sty = doc.createElement('style'); sty.textContent = D.cssText; sh.insertBefore(sty, sh.firstChild);
		if (styled()) { mountOk(); } else { sh.removeChild(sty); useLink(); } // inline styles blocked? try the file
	} else { useLink(); }
	/* Mirror the theme's layout hints (mobile bottom bar, product page) onto the widget. */
	function syncBody() { ['has-bnav', 'mm-is-product'].forEach(function (c) { root.classList.toggle(c, doc.body.classList.contains(c)); }); }
	syncBody(); try { new MutationObserver(syncBody).observe(doc.body, { attributes: true, attributeFilter: ['class'] }); } catch (e) { /* older browsers */ }
	/* Phones: follow the visible area so the on-screen keyboard never covers the message box. */
	var vv = window.visualViewport;
	function fit() { if (vv) { root.style.setProperty('--vvh', Math.round(vv.height) + 'px'); root.style.setProperty('--vvt', Math.round(vv.offsetTop) + 'px'); } }
	if (vv) { vv.addEventListener('resize', fit); vv.addEventListener('scroll', fit); fit(); }
	var small = window.matchMedia ? window.matchMedia('(max-width: 640px), (max-height: 520px)') : { matches: false };

	/* ---------- text formatting (safe: nodes only) ---------- */
	function inline(parent, line) {
		var parts = line.split(/(\*\*[^*]+\*\*)/);
		parts.forEach(function (p) {
			if (/^\*\*[^*]+\*\*$/.test(p)) { parent.appendChild(el('strong', '', p.slice(2, -2))); }
			else if (p) { parent.appendChild(doc.createTextNode(p)); }
		});
	}
	function formatText(text) {
		var wrap = el('div', 'tcca-txt'), ul = null;
		String(text).split(/\n+/).forEach(function (line) {
			var m = line.match(/^\s*[-•*]\s+(.*)$/);
			if (m) { if (!ul) { ul = el('ul'); wrap.appendChild(ul); } var li = el('li'); inline(li, m[1]); ul.appendChild(li); }
			else if (line.trim()) { ul = null; var p = el('p'); inline(p, line); wrap.appendChild(p); }
		});
		return wrap;
	}

	/* ---------- network ---------- */
	function api(route, body) {
		function go(useNonce) {
			var h = { 'Content-Type': 'application/json' };
			if (useNonce && D.nonce) { h['X-WP-Nonce'] = D.nonce; }
			return fetch(D.rest + route, { method: 'POST', credentials: 'same-origin', headers: h, body: JSON.stringify(body) }).then(function (r) {
				// A stale login nonce: retry as a guest rather than failing the chat.
				if (r.status === 403 && useNonce && D.nonce) { D.nonce = ''; return go(false); }
				return r.json().then(function (j) { return { status: r.status, data: j }; });
			});
		}
		return go(true);
	}

	/* ---------- message rendering ---------- */
	var lastBot = null;
	function addRow(role) {
		var row = el('div', 'tcca-row tcca-row--' + role);
		log.appendChild(row);
		return row;
	}
	function userMsg(text) {
		var row = addRow('user');
		row.appendChild(el('div', 'tcca-bub', text));
		log.scrollTop = log.scrollHeight;
	}
	function typing() {
		var row = addRow('bot'); row.classList.add('tcca-typing');
		var b = el('div', 'tcca-bub'); b.setAttribute('aria-label', 'Assistant is typing');
		b.appendChild(el('i')); b.appendChild(el('i')); b.appendChild(el('i'));
		row.appendChild(b); log.scrollTop = log.scrollHeight;
		return row;
	}
	function btnLink(a) {
		var n = attr(el('a', 'tcca-btn', a.label), { href: a.url });
		if (/^https?:/i.test(a.url) && a.url.indexOf(location.origin) !== 0) { n.target = '_blank'; n.rel = 'noopener noreferrer'; }
		return n;
	}
	function productCard(c) {
		var card = el('div', 'tcca-card tcca-prod');
		if (c.img) { var i = attr(el('img'), { src: c.img, alt: '', loading: 'lazy', width: 64, height: 64 }); card.appendChild(i); }
		var b = el('div', 'tcca-prod__b');
		var name = attr(el('a', 'tcca-prod__n', c.name), { href: c.url });
		b.appendChild(name);
		if (c.meta) { b.appendChild(el('div', 'tcca-muted', c.meta)); }
		if (c.why) { b.appendChild(el('div', 'tcca-why', c.why)); }
		var pr = el('div', 'tcca-prod__p');
		if (c.price) { pr.appendChild(el('strong', '', (c.from ? c.from + ' ' : '') + c.price)); }
		if (c.mrp) { pr.appendChild(el('s', 'tcca-muted', c.mrp)); }
		if (c.off) { pr.appendChild(el('span', 'tcca-tag tcca-tag--ok', c.off + '% off')); }
		b.appendChild(pr);
		var tags = el('div', 'tcca-tags');
		tags.appendChild(el('span', 'tcca-tag ' + (c.stock ? 'tcca-tag--ok' : 'tcca-tag--bad'), c.stock ? 'In stock' : 'Out of stock'));
		if (c.rx) { tags.appendChild(el('span', 'tcca-tag tcca-tag--rx', 'Rx')); }
		b.appendChild(tags);
		var acts = el('div', 'tcca-acts');
		if (c.canAdd) {
			var add = attr(el('button', 'tcca-btn tcca-btn--pri', 'Add to cart'), { type: 'button' });
			add.addEventListener('click', function () { addToCart(c, add, false); });
			var buy = attr(el('button', 'tcca-btn', 'Buy now'), { type: 'button' });
			buy.addEventListener('click', function () { addToCart(c, buy, true); });
			acts.appendChild(add); acts.appendChild(buy);
		}
		b.appendChild(acts);
		var more = el('div', 'tcca-acts tcca-acts--sm');
		var about = attr(el('button', 'tcca-link', 'About'), { type: 'button' });
		about.addEventListener('click', function () { send('Tell me about ' + c.name); });
		var sim = attr(el('button', 'tcca-link', 'Similar'), { type: 'button' });
		sim.addEventListener('click', function () { send('Similar to ' + c.name); });
		var view = attr(el('a', 'tcca-link', 'View page'), { href: c.url });
		more.appendChild(about); more.appendChild(sim);
		if (!c.stock) { var nt = attr(el('button', 'tcca-link', 'Notify me'), { type: 'button' }); nt.addEventListener('click', function () { send('Notify me when ' + c.name + ' is back in stock'); }); more.appendChild(nt); }
		more.appendChild(view);
		b.appendChild(more);
		card.appendChild(b);
		return card;
	}
	function plain(h) { var t = doc.createElement('textarea'); t.innerHTML = String(h || ''); return t.value.replace(/<[^>]*>/g, ''); }
	/* Cart changes go through the theme's own nonce-protected cart AJAX, so the header count stays in sync. */
	function cartPost(params) {
		if (window.MM && typeof MM.post === 'function') {
			return MM.post('mm_cart', params).then(function (r) {
				if (r && r.success) { try { MM.applyState(r.data); } catch (e) { /* header refreshes on next load */ } return { ok: true, data: r.data }; }
				return { ok: false, msg: r && r.data && r.data.message ? plain(r.data.message) : '' };
			}, function () { return { ok: false, msg: '' }; });
		}
		return Promise.resolve({ ok: false, nomm: true });
	}
	function showCart(lead) {
		var t = typing();
		return api('cart', { lead: lead || '' }).then(function (r) { t.remove(); if (r.data && r.data.reply != null) { renderBot(r.data); } }).catch(function () { t.remove(); });
	}
	function addToCart(c, btn, thenCheckout) {
		var label = btn.textContent;
		btn.disabled = true; btn.textContent = 'Adding…';
		cartPost({ op: 'add', product_id: c.id, qty: 1 }).then(function (r) {
			btn.disabled = false;
			if (r.nomm) { location.href = (D.cartUrl || '/') + (D.cartUrl && D.cartUrl.indexOf('?') < 0 ? '?' : '&') + 'add-to-cart=' + c.id; return; }
			if (!r.ok) { btn.textContent = r.msg || 'Could not add'; setTimeout(function () { btn.textContent = label; }, 2500); return; }
			btn.textContent = label;
			ping('add', c.id, 1);
			if (thenCheckout && D.checkoutUrl) { ping('checkout'); location.href = D.checkoutUrl; return; }
			showCart('added');
		});
	}
	function cartCard(c) {
		var card = el('div', 'tcca-card tcca-cart');
		card.appendChild(el('strong', '', 'Your cart (' + c.count + (c.count === 1 ? ' item)' : ' items)')));
		var ul = el('ul', 'tcca-lines');
		var canEdit = !!(window.MM && typeof MM.post === 'function');
		c.lines.forEach(function (l) {
			var li = el('li');
			li.appendChild(el('span', 'tcca-lines__n', l.name));
			var qty = el('span', 'tcca-qty');
			if (canEdit) {
				var minus = attr(el('button', '', '−'), { type: 'button', 'aria-label': 'Decrease quantity of ' + l.name });
				var plus = attr(el('button', '', '+'), { type: 'button', 'aria-label': 'Increase quantity of ' + l.name });
				var num = el('b', '', String(l.qty));
				function set(q) { card.classList.add('is-busy'); cartPost({ op: q < 1 ? 'remove' : 'set', product_id: l.id, variation_id: l.vid, bulk_qty: l.bulk, qty: q }).then(function () { card.classList.remove('is-busy'); refreshCart(card); }); }
				minus.addEventListener('click', function () { set(l.qty - 1); });
				plus.addEventListener('click', function () { set(l.qty + 1); });
				qty.appendChild(minus); qty.appendChild(num); qty.appendChild(plus);
			} else { qty.appendChild(el('b', '', '× ' + l.qty)); }
			li.appendChild(qty);
			li.appendChild(el('span', 'tcca-lines__t', l.total));
			ul.appendChild(li);
		});
		card.appendChild(ul);
		var tot = el('div', 'tcca-cart__tot'); tot.appendChild(el('span', '', 'Total')); tot.appendChild(el('strong', '', c.total)); card.appendChild(tot);
		card.appendChild(el('p', 'tcca-muted', 'Shipping is calculated at checkout.'));
		var acts = el('div', 'tcca-acts');
		acts.appendChild(btnLink({ label: 'Checkout', url: c.checkout })); acts.lastChild.classList.add('tcca-btn--pri');
		acts.lastChild.addEventListener('click', function () { ping('checkout'); });
		acts.appendChild(btnLink({ label: 'View cart', url: c.cartUrl }));
		card.appendChild(acts);
		card.appendChild(emailBar('cart'));
		return card;
	}
	/* Replace a cart card with a fresh one after a quantity change. */
	function refreshCart(card) {
		api('cart', {}).then(function (r) {
			var cc = r.data && r.data.cards && r.data.cards[0];
			if (cc && cc.type === 'cart') { card.replaceWith(cartCard(cc)); }
			else { card.replaceWith(el('div', 'tcca-bub', (r.data && r.data.reply) || 'Your cart is empty.')); }
		});
	}
	function verifyForm(prefill) {
		var f = el('form', 'tcca-card tcca-form');
		f.appendChild(field('Email, club number or order number', 'identifier', 'text', 'you@example.com  or  1657 1945', prefill));
		var hp = attr(el('input', 'tcca-hp'), { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true' }); f.appendChild(hp);
		f.appendChild(el('p', 'tcca-muted', 'We email a 6-digit code to the address on file. Nothing about the account is shown until you enter it.'));
		var go = attr(el('button', 'tcca-btn tcca-btn--pri', 'Send me a code'), { type: 'submit' }); f.appendChild(go);
		f.addEventListener('submit', function (e) {
			e.preventDefault(); var v = f.elements.identifier.value.trim(); if (!v) { return; }
			go.disabled = true; var t = typing();
			api('verify/start', { identifier: v, website: f.elements.website.value, session: st.session }).then(function (r) { t.remove(); f.remove(); renderBot(r.data); }).catch(function () { t.remove(); go.disabled = false; });
		});
		return f;
	}
	function codeForm(ticket) {
		var f = el('form', 'tcca-card tcca-form');
		var w = el('label', 'tcca-f'); w.appendChild(el('span', '', '6-digit code'));
		var i = attr(el('input', 'tcca-code'), { type: 'text', name: 'code', inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: 6, pattern: '[0-9]{6}', placeholder: '••••••', 'aria-label': '6-digit code' });
		w.appendChild(i); f.appendChild(w);
		var go = attr(el('button', 'tcca-btn tcca-btn--pri', 'Verify'), { type: 'submit' }); f.appendChild(go);
		f.addEventListener('submit', function (e) {
			e.preventDefault(); var v = i.value.replace(/\D/g, ''); if (v.length !== 6) { i.focus(); return; }
			go.disabled = true; var t = typing();
			api('verify/check', { ticket: ticket, code: v, session: st.session }).then(function (r) {
				t.remove(); f.remove(); var d = r.data || {};
				if (d.vtoken) { st.ctx.vtoken = d.vtoken; persist(); }
				renderBot(d);
			}).catch(function () { t.remove(); go.disabled = false; });
		});
		setTimeout(function () { i.focus(); }, 60);
		return f;
	}
	function accountCard(c) {
		var card = el('div', 'tcca-card tcca-acct');
		card.appendChild(el('strong', '', c.name ? c.name + '\'s account' : 'Your account'));
		card.appendChild(el('p', 'tcca-muted', c.member ? 'Club member no. ' + c.member.no + (c.member.since ? ' · since ' + c.member.since : '') : 'No club membership found for this email.'));
		var ul = el('ul', 'tcca-orders');
		(c.orders || []).forEach(function (o) {
			var li = el('li'); var l = el('span', 'tcca-orders__n'); l.appendChild(el('b', '', 'Order #' + o.number)); l.appendChild(el('small', 'tcca-muted', [o.date, o.total].filter(Boolean).join(' · '))); li.appendChild(l);
			li.appendChild(el('span', 'tcca-tag tcca-tag--' + (o.tone === 'ok' ? 'ok' : o.tone === 'bad' ? 'bad' : o.tone === 'warn' ? 'warn' : 'prog'), o.status));
			var b = attr(el('button', 'tcca-link', 'Track'), { type: 'button' }); b.addEventListener('click', function () { send('Track order #' + o.number); }); li.appendChild(b);
			ul.appendChild(li);
		});
		card.appendChild(ul);
		if (c.via === 'code') { card.appendChild(el('p', 'tcca-muted', 'Verified for this chat only.')); }
		return card;
	}
	function customerCard(c) {
		var card = el('div', 'tcca-card tcca-acct tcca-staff');
		card.appendChild(el('span', 'tcca-art__k', 'Staff only'));
		card.appendChild(el('strong', '', c.name || c.email || 'Customer'));
		var meta = [c.email, c.phone].filter(Boolean).join(' · '); if (meta) { card.appendChild(el('p', 'tcca-muted', meta)); }
		card.appendChild(el('p', 'tcca-muted', (c.member ? 'Club no. ' + c.member.no + (c.member.since ? ' · since ' + c.member.since : '') : 'No club membership') + ' · Spend ' + c.spend));
		var ul = el('ul', 'tcca-orders');
		(c.orders || []).forEach(function (o) {
			var li = el('li'); var l = el('span', 'tcca-orders__n'); l.appendChild(el('b', '', 'Order #' + o.number)); l.appendChild(el('small', 'tcca-muted', [o.date, o.total, o.tracking].filter(Boolean).join(' · '))); li.appendChild(l);
			li.appendChild(el('span', 'tcca-tag tcca-tag--' + (o.tone === 'ok' ? 'ok' : o.tone === 'bad' ? 'bad' : o.tone === 'warn' ? 'warn' : 'prog'), o.status));
			if (o.edit) { li.appendChild(attr(el('a', 'tcca-link', 'Open'), { href: o.edit })); }
			ul.appendChild(li);
		});
		card.appendChild(ul);
		if (c.edit) { var a = el('div', 'tcca-acts'); a.appendChild(btnLink({ label: 'Open customer in admin', url: c.edit })); card.appendChild(a); }
		return card;
	}
	function noteCard(c) { return el('div', 'tcca-card tcca-muted', c.text); }
	function refCard(c) {
		var d = el('div', 'tcca-card tcca-ref'); d.appendChild(el('span', '', c.label));
		var b = attr(el('button', 'tcca-btn', 'Show again'), { type: 'button' }); b.addEventListener('click', function () { send(c.message); });
		d.appendChild(b); return d;
	}
	function compareCard(c) {
		var card = el('div', 'tcca-card tcca-cmp'), sc = el('div', 'tcca-cmp__s'), tb = el('table');
		var head = el('tr'); head.appendChild(el('th'));
		c.cols.forEach(function (p) { var th = el('th'); th.appendChild(attr(el('a', '', p.name), { href: p.url })); head.appendChild(th); });
		tb.appendChild(head);
		c.rows.forEach(function (r) { var tr = el('tr'); tr.appendChild(el('th', '', r.label)); r.vals.forEach(function (v, i) { tr.appendChild(el('td', r.best === i ? 'is-best' : '', v)); }); tb.appendChild(tr); });
		var ar = el('tr'); ar.appendChild(el('th'));
		c.cols.forEach(function (p) { var td = el('td'); if (p.canAdd) { var b = attr(el('button', 'tcca-btn tcca-btn--pri', 'Add'), { type: 'button' }); b.addEventListener('click', function () { addToCart(p, b, false); }); td.appendChild(b); } ar.appendChild(td); });
		tb.appendChild(ar); tb.style.minWidth = (84 + 104 * c.cols.length) + 'px'; sc.appendChild(tb); card.appendChild(sc); return card;
	}
	function couponCard(c) {
		var card = el('div', 'tcca-card tcca-coupon');
		card.appendChild(el('span', 'tcca-art__k', 'Your one-time code'));
		card.appendChild(el('div', 'tcca-coupon__code', c.code));
		card.appendChild(el('p', 'tcca-muted', (c.terms || []).join(' · ')));
		var acts = el('div', 'tcca-acts'), msg = el('p', 'tcca-muted'); msg.setAttribute('role', 'status');
		var cp = attr(el('button', 'tcca-btn', 'Copy'), { type: 'button' });
		cp.addEventListener('click', function () { if (navigator.clipboard) { navigator.clipboard.writeText(c.code).then(function () { cp.textContent = 'Copied'; setTimeout(function () { cp.textContent = 'Copy'; }, 1800); }); } });
		acts.appendChild(cp);
		if (window.MM && typeof MM.post === 'function') {
			var ap = attr(el('button', 'tcca-btn tcca-btn--pri', 'Apply to my cart'), { type: 'button' });
			ap.addEventListener('click', function () { ap.disabled = true; cartPost({ op: 'coupon', code: c.code }).then(function (r) { ap.disabled = false; if (r.ok) { msg.textContent = 'Applied. Your cart total is updated.'; showCart(''); } else { msg.textContent = r.msg || 'Add something to your cart first, then apply the code at checkout.'; } }); });
			acts.appendChild(ap);
		}
		card.appendChild(acts); card.appendChild(msg);
		if (c.note) { card.appendChild(el('p', 'tcca-muted', c.note)); }
		return card;
	}
	function couponForm() {
		var f = el('form', 'tcca-card tcca-form');
		f.appendChild(field('Your email (the code is sent here)', 'email', 'email', 'you@example.com'));
		var hp = attr(el('input', 'tcca-hp'), { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true' }); f.appendChild(hp);
		var go = attr(el('button', 'tcca-btn tcca-btn--pri', 'Get my code'), { type: 'submit' }); f.appendChild(go);
		f.addEventListener('submit', function (e) {
			e.preventDefault(); go.disabled = true; var t = typing();
			api('coupon', { email: f.elements.email.value.trim(), website: f.elements.website.value, session: st.session, dtoken: st.ctx.dtoken || '' }).then(function (r) { t.remove(); f.remove(); renderBot(r.data); }).catch(function () { t.remove(); go.disabled = false; });
		});
		return f;
	}
	/* "Email me this": signed-in visitors get it at their account email; guests type an address. */
	function emailBar(kind, extra) {
		var wrap = el('div', 'tcca-mail'), btn = attr(el('button', 'tcca-link', 'Email me this'), { type: 'button' }), msg = el('span', 'tcca-muted'); msg.setAttribute('role', 'status');
		wrap.appendChild(btn); wrap.appendChild(msg);
		function post(email) {
			msg.textContent = 'Sending…';
			var body = { kind: kind, email: email || '', website: '', session: st.session };
			for (var k in (extra || {})) { body[k] = extra[k]; }
			api('email', body).then(function (r) { msg.textContent = (r.data && r.data.message) || 'Sent.'; }).catch(function () { msg.textContent = 'Could not send. Please try again.'; });
		}
		btn.addEventListener('click', function () {
			if (D.loggedIn || (extra && extra.email)) { post(extra && extra.email); return; }
			if (wrap.querySelector('form')) { return; }
			var f = el('form', 'tcca-inline'), i = attr(el('input'), { type: 'email', placeholder: 'you@example.com', required: 'required', 'aria-label': 'Your email' }), go = attr(el('button', 'tcca-btn tcca-btn--pri', 'Send'), { type: 'submit' });
			f.appendChild(i); f.appendChild(go);
			f.addEventListener('submit', function (e) { e.preventDefault(); post(i.value.trim()); f.remove(); });
			wrap.insertBefore(f, msg); i.focus();
		});
		return wrap;
	}
	/* Add what the visitor asked for ("add 2 mounjaro"), then show the cart or go to checkout. */
	function autoAdd(a) {
		var t = typing();
		cartPost({ op: 'add', product_id: a.id, qty: a.qty || 1 }).then(function (r) {
			t.remove();
			if (r.nomm) { location.href = (D.cartUrl || '/') + (D.cartUrl && D.cartUrl.indexOf('?') < 0 ? '?' : '&') + 'add-to-cart=' + a.id + '&quantity=' + (a.qty || 1); return; }
			if (!r.ok) { renderBot({ reply: r.msg || 'I could not add that to your cart.' }); return; }
			ping('add', a.id, a.qty || 1);
			if (a.checkout && D.checkoutUrl) { ping('checkout'); location.href = D.checkoutUrl; return; }
			showCart('added');
		});
	}
	function articleCard(c) {
		var a = attr(el('a', 'tcca-card tcca-art'), { href: c.url });
		if (c.kind) { a.appendChild(el('span', 'tcca-art__k', c.kind)); }
		a.appendChild(el('strong', 'tcca-art__t', c.title));
		if (c.excerpt) { a.appendChild(el('span', 'tcca-art__e', c.excerpt)); }
		return a;
	}
	function feedbackRow(question, trace) {
		var row = el('div', 'tcca-fb'); row.appendChild(el('span', '', 'Was this helpful?'));
		function vote(up) {
			api('feedback', { helpful: up, message: question, trace: trace || null, session: st.session }).catch(function () { /* best effort */ });
			row.textContent = ''; row.appendChild(el('span', '', up ? 'Thanks!' : 'Thanks. A pharmacist can help further if you tap the button below.'));
		}
		var y = attr(el('button', 'tcca-link', 'Yes'), { type: 'button' }); y.addEventListener('click', function () { vote(true); });
		var n = attr(el('button', 'tcca-link', 'No'), { type: 'button' }); n.addEventListener('click', function () { vote(false); });
		row.appendChild(y); row.appendChild(n);
		return row;
	}
	function orderCard(c) {
		var card = el('div', 'tcca-card tcca-order');
		var top = el('div', 'tcca-order__top');
		top.appendChild(el('strong', '', 'Order #' + c.number));
		top.appendChild(el('span', 'tcca-tag tcca-tag--' + (c.tone === 'ok' ? 'ok' : c.tone === 'bad' ? 'bad' : c.tone === 'warn' ? 'warn' : 'prog'), c.status));
		card.appendChild(top);
		if (c.heading) { card.appendChild(el('p', 'tcca-order__h', c.heading)); }
		if (c.steps && c.steps.length) {
			var ol = el('ol', 'tcca-steps');
			c.steps.forEach(function (s) { var li = el('li', (s.done ? 'is-done ' : '') + (s.now ? 'is-now' : '')); li.appendChild(el('i')); li.appendChild(el('span', '', s.label)); if (s.now) { li.setAttribute('aria-current', 'step'); } ol.appendChild(li); });
			card.appendChild(ol);
		}
		var meta = el('div', 'tcca-muted');
		var bits = [];
		if (c.placed) { bits.push('Placed ' + c.placed); }
		if (c.total) { bits.push(c.total); }
		if (c.eta) { bits.push('Estimated arrival ' + c.eta); }
		meta.textContent = bits.join(' · ');
		card.appendChild(meta);
		if (c.track) {
			var tr = el('div', 'tcca-track');
			tr.appendChild(el('span', '', (c.track.carrier ? c.track.carrier + ' · ' : '') + c.track.number));
			var cp = attr(el('button', 'tcca-btn', 'Copy'), { type: 'button' });
			cp.addEventListener('click', function () { if (navigator.clipboard) { navigator.clipboard.writeText(c.track.number).then(function () { cp.textContent = 'Copied'; setTimeout(function () { cp.textContent = 'Copy'; }, 1800); }); } });
			tr.appendChild(cp);
			if (c.track.url) { tr.appendChild(btnLink({ label: 'Track parcel', url: c.track.url })); }
			card.appendChild(tr);
		} else if (c.steps && c.steps.length) {
			card.appendChild(el('p', 'tcca-muted', 'A tracking number appears here once the order ships.'));
		}
		if (c.items && c.items.length) { var ul = el('ul', 'tcca-items'); c.items.forEach(function (t) { ul.appendChild(el('li', '', t)); }); card.appendChild(ul); }
		var oa = el('div', 'tcca-acts');
		if (c.url) { oa.appendChild(btnLink({ label: 'Open order page', url: c.url })); }
		if (c.reorder && c.reorder.length && window.MM && typeof MM.post === 'function') {
			var ro = attr(el('button', 'tcca-btn tcca-btn--pri', 'Reorder these items'), { type: 'button' });
			ro.addEventListener('click', function () {
				ro.disabled = true; ro.textContent = 'Adding…';
				c.reorder.reduce(function (p, it) { return p.then(function () { ping('add', it.id, it.qty); return cartPost({ op: 'add', product_id: it.id, variation_id: it.vid, qty: it.qty }); }); }, Promise.resolve()).then(function () { ro.disabled = false; ro.textContent = 'Reorder these items'; showCart('added'); });
			});
			oa.appendChild(ro);
		}
		if (oa.childNodes.length) { card.appendChild(oa); }
		card.appendChild(emailBar('order', c.url ? { order: c.number } : { order: c.number, email: lastOrder && lastOrder.email }));
		return card;
	}
	function field(labelTxt, name, type, ph, val) {
		var w = el('label', 'tcca-f');
		w.appendChild(el('span', '', labelTxt));
		var i = attr(el('input'), { type: type, name: name, placeholder: ph || '', autocomplete: type === 'email' ? 'email' : 'off' });
		if (val) { i.value = val; }
		w.appendChild(i);
		return w;
	}
	function orderForm(prefill) {
		var f = el('form', 'tcca-card tcca-form');
		f.appendChild(field('Order number', 'order', 'text', 'e.g. 48219', prefill));
		f.appendChild(field('Email used at checkout', 'email', 'email', 'you@example.com'));
		var go = attr(el('button', 'tcca-btn tcca-btn--pri', 'Track my order'), { type: 'submit' });
		f.appendChild(go);
		f.addEventListener('submit', function (e) {
			e.preventDefault();
			var o = f.elements.order.value.trim(), m = f.elements.email.value.trim();
			if (!o || !m) { return; }
			go.disabled = true;
			var t = typing();
			lastOrder = { order: o, email: m };
			api('order', { order: o, email: m, session: st.session }).then(function (r) {
				t.remove(); go.disabled = false; f.remove(); renderBot(r.data);
			}).catch(function () { t.remove(); go.disabled = false; renderBot({ reply: 'Sorry, something went wrong. Please try again.' }); });
		});
		return f;
	}
	/* "Email me when it is back" and "please source this": one email, stored until sent. */
	function leadForm(kind, r) {
		var f = el('form', 'tcca-card tcca-form');
		f.appendChild(el('strong', '', kind === 'restock' ? 'Email me when it is back' : 'Ask a pharmacist to find it'));
		if (r.known) { f.appendChild(el('p', 'tcca-muted', 'We will use your account email (' + r.known + ').')); }
		else { f.appendChild(field('Your email', 'email', 'email', 'you@example.com')); }
		if (kind === 'request') { f.appendChild(field('What you need', 'term', 'text', 'Name and strength', r.fterm)); }
		var hp = attr(el('input', 'tcca-hp'), { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true' }); f.appendChild(hp);
		f.appendChild(el('p', 'tcca-muted', kind === 'restock' ? 'One email, when it is back in stock. Nothing else.' : 'Used only to reply about this request.'));
		var go = attr(el('button', 'tcca-btn tcca-btn--pri', kind === 'restock' ? 'Notify me' : 'Send request'), { type: 'submit' }); f.appendChild(go);
		f.addEventListener('submit', function (e) {
			e.preventDefault(); go.disabled = true; var t = typing();
			api('lead', { kind: kind, email: f.elements.email ? f.elements.email.value.trim() : '', pid: r.fpid || 0, term: f.elements.term ? f.elements.term.value.trim() : (r.fterm || ''), website: f.elements.website.value, session: st.session }).then(function (x) {
				t.remove();
				if (x.data && x.status < 300) { f.remove(); renderBot(x.data); } else { go.disabled = false; renderBot({ reply: (x.data && x.data.reply) || 'Sorry, that did not go through. Please try again.' }); }
			}).catch(function () { t.remove(); go.disabled = false; });
		});
		return f;
	}
	function lastUser() { for (var i = st.msgs.length - 1; i >= 0; i--) { if (st.msgs[i].role === 'user') { return st.msgs[i].text; } } return ''; }
	function handoffForm() {
		var f = el('form', 'tcca-card tcca-form');
		f.appendChild(el('strong', '', 'Message a pharmacist'));
		if (!D.loggedIn) { f.appendChild(field('Your name', 'name', 'text', '')); f.appendChild(field('Email or phone', 'contact', 'text', 'How should we reach you?')); }
		var w = el('label', 'tcca-f'); w.appendChild(el('span', '', 'Your message'));
		var t = attr(el('textarea'), { name: 'message', rows: 3, maxlength: 1000 }); t.value = lastUser(); w.appendChild(t); f.appendChild(w);
		var hp = attr(el('input', 'tcca-hp'), { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true' }); f.appendChild(hp);
		var msg = el('p', 'tcca-muted'); msg.setAttribute('role', 'status'); f.appendChild(msg);
		var go = attr(el('button', 'tcca-btn tcca-btn--pri', 'Send to pharmacist'), { type: 'submit' }); f.appendChild(go);
		f.addEventListener('submit', function (e) {
			e.preventDefault(); go.disabled = true; msg.textContent = 'Sending…';
			api('handoff', {
				name: f.elements.name ? f.elements.name.value : '', contact: f.elements.contact ? f.elements.contact.value : '',
				message: f.elements.message.value, website: f.elements.website.value, history: st.msgs.slice(-8).map(function (m) { return { role: m.role, text: m.text }; }), session: st.session
			}).then(function (r) {
				if (r.data && r.data.ok) { f.textContent = ''; f.appendChild(el('p', '', r.data.message)); }
				else { go.disabled = false; msg.textContent = (r.data && r.data.message) || 'Could not send. Please try again.'; }
			}).catch(function () { go.disabled = false; msg.textContent = 'Could not send. Please try again.'; });
		});
		return f;
	}
	function openPharmacist(btn) {
		var sheet = doc.getElementById('mm-help');
		if (window.MM && typeof MM.openHelp === 'function' && sheet) {
			try { MM.openHelp(); } catch (e) { /* fall through */ }
			setTimeout(function () {
				if (sheet.hidden) { showInline(btn); } else { setOpen(false, true); }
			}, 150);
		} else { showInline(btn); }
	}
	function showInline(btn) {
		var host = btn.closest('.tcca-row');
		if (host && !host.querySelector('.tcca-form')) { host.querySelector('.tcca-stack').appendChild(handoffForm()); log.scrollTop = log.scrollHeight; }
	}

	function renderBot(r, restoring) {
		var row = addRow('bot'); lastBot = row;
		var stack = el('div', 'tcca-stack'); row.appendChild(stack);
		if (r.reply) {
			var b = el('div', 'tcca-bub'); b.appendChild(formatText(r.reply));
			if (r.ai) { b.appendChild(el('small', 'tcca-ai', 'AI-generated. Please confirm important details with a pharmacist.')); }
			stack.appendChild(b);
		}
		if (r.fb && !restoring) { stack.appendChild(feedbackRow(lastUser(), r.trace)); }
		var pids = [];
		(r.cards || []).forEach(function (c) {
			try {
				var n = c.type === 'order' ? orderCard(c) : c.type === 'cart' ? cartCard(c) : c.type === 'article' ? articleCard(c) : c.type === 'compare' ? compareCard(c) : c.type === 'coupon' ? couponCard(c) : c.type === 'ref' ? refCard(c) : c.type === 'note' ? noteCard(c) : c.type === 'account' ? accountCard(c) : c.type === 'customer' ? customerCard(c) : productCard(c);
				stack.appendChild(n);
				if (c.type === 'product') { pids.push(c.id); }
			} catch (e) { /* one bad card must not hide the rest of the answer */ }
		});
		if (pids.length) { st.ctx.pid = pids[0]; st.ctx.pids = pids.slice(0, 6); }
		if (r.dtoken && !restoring) { st.ctx.dtoken = r.dtoken; persist(); }
		if (!restoring) {
			if (r.form === 'order') { stack.appendChild(orderForm(r.prefill)); }
			if (r.form === 'coupon') { stack.appendChild(couponForm()); }
			if (r.form === 'restock' || r.form === 'request') { stack.appendChild(leadForm(r.form, r)); }
			if (r.form === 'verify') { stack.appendChild(verifyForm(r.prefill)); }
			if (r.form === 'code' && r.ticket) { stack.appendChild(codeForm(r.ticket)); }
			if (r.form === 'handoff' && !(r.actions || []).some(function (a) { return a.type === 'pharmacist'; })) { stack.appendChild(handoffForm()); }
		}
		if (r.actions && r.actions.length) {
			var ac = el('div', 'tcca-acts');
			r.actions.forEach(function (a) {
				if (a.type === 'pharmacist') { var p = attr(el('button', 'tcca-btn tcca-btn--pri', a.label), { type: 'button' }); p.addEventListener('click', function () { openPharmacist(p); }); ac.appendChild(p); }
				else if (a.type === 'link' && a.url) { ac.appendChild(btnLink(a)); }
			});
			stack.appendChild(ac);
		}
		if (r.chips && r.chips.length && !restoring) { stack.appendChild(chips(r.chips)); }
		if (!restoring) {
			if (r.reply || (r.cards && r.cards.length)) { remember('assistant', r.reply || '', slim(r)); }
			// Start of the new answer in view, so long cards are read from the top.
			log.scrollTop = Math.max(0, row.offsetTop - 8);
			if (r.autoadd) { autoAdd(r.autoadd); }
		}
	}
	function chips(list) {
		var c = el('div', 'tcca-chips');
		list.forEach(function (x) {
			var b = attr(el('button', 'tcca-chip', x[0]), { type: 'button' });
			b.addEventListener('click', function () { send(x[1], x[2]); });
			c.appendChild(b);
		});
		return c;
	}

	/* ---------- conversation ---------- */
	function setBusy(v) { st.busy = v; sendBtn.disabled = v; }
	function send(text, alias) {
		text = String(text || '').trim();
		if (!text || st.busy) { return; }
		Array.prototype.forEach.call(log.querySelectorAll('.tcca-chips'), function (n) { n.remove(); });
		userMsg(text); remember('user', text);
		setSid();
		setBusy(true);
		var t = typing();
		api('chat', { message: text, history: st.msgs.slice(-7, -1).map(function (m) { return { role: m.role, text: m.text }; }), session: st.session, ctx: ctxOut(), alias_of: alias || '' }).then(function (r) {
			t.remove(); setBusy(false);
			if (r.data && r.data.reply != null) { renderBot(r.data); }
			else { renderBot({ reply: 'Sorry, I could not answer that. Please try again.' }); }
		}).catch(function () {
			t.remove(); setBusy(false);
			renderBot({ reply: 'I could not connect just now. Please check your connection and try again.' });
		});
	}
	function welcome() {
		var p = D.page;
		if (p && p.hello) { st.lastPage = p.type + ':' + (p.id || 0); }
		renderBot({ reply: p && p.hello ? p.hello : D.welcome, chips: p && p.chips && p.chips.length ? p.chips : D.chips });
		st.msgs.pop(); persist(); // The welcome line is not part of the history sent to the server.
	}
	/* Moved to a different page mid-conversation: offer help that fits the new page, once. */
	function pageNudge() {
		var p = D.page; if (!p || !p.hello) { return; }
		var k = p.type + ':' + (p.id || 0); if (st.lastPage === k) { return; }
		st.lastPage = k; renderBot({ reply: p.hello, chips: p.chips });
		st.msgs.pop(); persist();
	}
	function restore() {
		if (!st.msgs.length) { welcome(); return; }
		st.msgs.forEach(function (m) {
			if (m.role === 'user') { userMsg(m.text); }
			else if (m.r) { renderBot(m.r, true); }
			else { var row = addRow('bot'); var s2 = el('div', 'tcca-stack'); var b2 = el('div', 'tcca-bub'); b2.appendChild(formatText(m.text)); s2.appendChild(b2); row.appendChild(s2); }
		});
		log.scrollTop = log.scrollHeight;
		pageNudge();
	}
	var started = false;
	function setOpen(v, silent) {
		st.open = v; panel.hidden = !v; root.classList.toggle('is-open', v);
		if (v && !st.told && window.tcci && window.tcci.cta) { st.told = true; window.tcci.cta('chat'); } /* TCC Insights counts chat opens */
		launcher.setAttribute('aria-expanded', v ? 'true' : 'false');
		if (small.matches) { doc.documentElement.style.overflow = v ? 'hidden' : ''; }
		teaser.hidden = true;
		if (v && !started) { started = true; restore(); }
		if (v) { setTimeout(function () { ta.focus(); }, 30); try { sessionStorage.setItem('tccaTeaser', '1'); } catch (e) { /* ignore */ } }
		else if (!silent) { launcher.focus(); }
	}
	launcher.addEventListener('click', function () { setOpen(!st.open); });
	cls.addEventListener('click', function () { setOpen(false); });
	clr.addEventListener('click', function () { st.msgs = []; st.lastPage = ''; st.ctx = { pid: 0, pids: [], vtoken: '', dtoken: '' }; persist(); log.textContent = ''; welcome(); });
	foot.addEventListener('submit', function (e) { e.preventDefault(); var v = ta.value; ta.value = ''; grow(); send(v); });
	ta.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); foot.requestSubmit ? foot.requestSubmit() : foot.dispatchEvent(new Event('submit', { cancelable: true })); } });
	function grow() { ta.style.height = 'auto'; var h = Math.min(ta.scrollHeight, 110); ta.style.height = h + 'px'; ta.style.overflowY = ta.scrollHeight > 110 ? 'auto' : 'hidden'; }
	ta.addEventListener('input', grow);
	doc.addEventListener('keydown', function (e) { if (e.key === 'Escape' && st.open) { setOpen(false); } });
	// Other buttons can open the assistant: <a data-tcca-open>.
	doc.addEventListener('click', function (e) { var t = e.target.closest && e.target.closest('[data-tcca-open]'); if (t && window.tccaReady) { e.preventDefault(); setOpen(true); } });

	/* ---------- one-time teaser ---------- */
	if (D.teaser) {
		var seen = false; try { seen = sessionStorage.getItem('tccaTeaser') === '1'; } catch (e) { /* ignore */ }
		if (!seen) {
			setTimeout(function () {
				if (st.open) { return; }
				teaser.textContent = '';
				var t = attr(el('button', 'tcca-teaser__t', (D.page && D.page.teaser) || 'Need help finding a medicine or tracking an order?'), { type: 'button' });
				t.addEventListener('click', function () { setOpen(true); });
				var x = attr(el('button', 'tcca-teaser__x'), { type: 'button', 'aria-label': 'Dismiss' }); x.appendChild(icon('x'));
				x.addEventListener('click', function () { teaser.hidden = true; try { sessionStorage.setItem('tccaTeaser', '1'); } catch (e) { /* ignore */ } });
				teaser.appendChild(t); teaser.appendChild(x); teaser.hidden = false;
				setTimeout(function () { teaser.hidden = true; }, 14000);
			}, 8000);
		}
	}
}());
