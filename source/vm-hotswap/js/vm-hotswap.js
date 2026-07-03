/* vm-hotswap web UI. Talks to scripts/api.php via fetch. Vanilla JS to
   avoid dragging in a bundler for a plugin — matches Unraid convention. */

(function () {
  'use strict';

  const API = '/plugins/vm-hotswap/scripts/api.php';

  function fmtBytes(n) {
    if (!n) return '—';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
    return n.toFixed(n >= 100 ? 0 : 1) + ' ' + units[i];
  }

  async function api(action, params, method = 'GET') {
    const url = new URL(API, location.origin);
    url.searchParams.set('action', action);
    let body;
    if (method === 'GET' && params) {
      for (const [k, v] of Object.entries(params)) url.searchParams.set(k, v);
    } else if (params) {
      body = new URLSearchParams(params);
    }
    const res = await fetch(url.toString(), { method, body });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.json();
  }

  async function renderVMs() {
    const container = document.getElementById('vms');
    const loading = document.getElementById('vms-loading');
    container.innerHTML = '';
    loading.style.display = '';

    let domains, images;
    try {
      const [d, i] = await Promise.all([
        api('domains'),
        api('images'),
      ]);
      if (!d.ok) throw new Error(d.error);
      domains = d.domains;
      images = i.ok ? i.images : [];
    } catch (e) {
      loading.textContent = 'Error: ' + e.message;
      return;
    }
    loading.style.display = 'none';

    if (domains.length === 0) {
      container.textContent = 'No VMs found. (Is libvirt running?)';
      return;
    }

    const tpl = document.getElementById('tpl-vm');
    for (const dom of domains) {
      const node = tpl.content.cloneNode(true);
      const wrap = node.querySelector('.vm');
      wrap.dataset.name = dom.name;
      wrap.querySelector('.vm-name').textContent = dom.name;
      const stateEl = wrap.querySelector('.vm-state');
      stateEl.textContent = dom.state;
      stateEl.classList.add('state-' + dom.state.replace(/\s+/g, '-'));

      const src = wrap.querySelector('.new-source');
      for (const img of images) {
        const opt = document.createElement('option');
        opt.value = img.path;
        opt.textContent = `${img.name} (${fmtBytes(img.size)})`;
        src.appendChild(opt);
      }
      wrap.querySelector('.attach').addEventListener('click', () => onAttach(wrap));
      container.appendChild(node);
      loadDisks(wrap, dom.name);
    }
  }

  async function loadDisks(wrap, domain) {
    const tbody = wrap.querySelector('.disks-body');
    tbody.innerHTML = '<tr><td colspan="4">Loading…</td></tr>';
    let disks;
    try {
      const r = await api('disks', { domain });
      if (!r.ok) throw new Error(r.error);
      disks = r.disks;
    } catch (e) {
      tbody.innerHTML = `<tr><td colspan="4">Error: ${e.message}</td></tr>`;
      return;
    }
    tbody.innerHTML = '';
    if (disks.length === 0) {
      tbody.innerHTML = '<tr><td colspan="4"><em>No disks attached.</em></td></tr>';
      return;
    }
    for (const d of disks) {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${d.target}</td>
        <td>${d.device}</td>
        <td class="src">${d.source}</td>
        <td class="actions"></td>
      `;
      const actions = tr.querySelector('.actions');
      const detach = document.createElement('button');
      detach.textContent = 'Detach';
      detach.addEventListener('click', () => onDetach(wrap, domain, d.target));
      actions.appendChild(detach);
      const swap = document.createElement('button');
      swap.textContent = 'Cold swap…';
      swap.addEventListener('click', () => onColdSwap(wrap, domain, d.target));
      actions.appendChild(swap);
      tbody.appendChild(tr);
    }
  }

  async function onAttach(wrap) {
    const msg = wrap.querySelector('.attach-row .msg');
    const domain = wrap.dataset.name;
    const source = wrap.querySelector('.new-source').value;
    const target = wrap.querySelector('.new-target').value.trim();
    if (!source || !target) { msg.textContent = 'Pick a source and enter a target.'; return; }
    msg.textContent = 'Attaching…';
    try {
      const r = await api('attach', { domain, source, target }, 'POST');
      msg.textContent = r.ok ? '✓ ' + r.message : '✗ ' + r.message;
      if (r.ok) loadDisks(wrap, domain);
    } catch (e) { msg.textContent = 'Error: ' + e.message; }
  }

  async function onDetach(wrap, domain, target) {
    if (!confirm(`Detach ${target} from ${domain}? (This does NOT delete the image file.)`)) return;
    try {
      const r = await api('detach', { domain, target }, 'POST');
      if (!r.ok) { alert(r.message); return; }
      loadDisks(wrap, domain);
    } catch (e) { alert(e.message); }
  }

  async function onColdSwap(wrap, domain, target) {
    const options = Array.from(wrap.querySelectorAll('.new-source option'))
      .map(o => `${o.textContent}\n  → ${o.value}`).join('\n');
    const pick = prompt(`Enter FULL PATH of the new source image for ${target}.\n\nAvailable images:\n${options}`);
    if (!pick) return;
    try {
      const r = await api('swap_cold', { domain, target, new_source: pick.trim() }, 'POST');
      if (!r.ok) { alert(r.message); return; }
      loadDisks(wrap, domain);
    } catch (e) { alert(e.message); }
  }

  async function saveCfg() {
    const msg = document.getElementById('save-cfg-msg');
    const path  = document.getElementById('cfg-images').value.trim();
    const backup = document.getElementById('cfg-backup').checked ? '1' : '0';
    try {
      const r = await api('save_settings', { IMAGES_PATH: path, BACKUP_XML: backup }, 'POST');
      msg.textContent = r.ok ? '✓ saved' : '✗ ' + (r.error || 'failed');
      if (r.ok) setTimeout(renderVMs, 200);
    } catch (e) { msg.textContent = 'Error: ' + e.message; }
  }

  document.getElementById('save-cfg').addEventListener('click', saveCfg);
  renderVMs();
})();
