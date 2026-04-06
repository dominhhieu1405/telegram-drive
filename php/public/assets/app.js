const path = location.pathname;
const sections = {
  upload: document.getElementById('upload-view'),
  admin: document.getElementById('admin-view'),
  docs: document.getElementById('docs-view'),
  about: document.getElementById('about-view'),
  preview: document.getElementById('preview-view')
};

function show(name) {
  Object.values(sections).forEach((el) => el.classList.add('hidden'));
  sections[name].classList.remove('hidden');
}

if (path === '/admin') show('admin');
else if (path === '/docs') show('docs');
else if (path === '/about') show('about');
else if (path.startsWith('/file/')) show('preview');
else show('upload');

const uploadBtn = document.getElementById('upload-btn');
const fileInput = document.getElementById('file-input');
const uploadResult = document.getElementById('upload-result');

if (uploadBtn) {
  uploadBtn.onclick = async () => {
    const files = [...fileInput.files || []];
    uploadResult.innerHTML = '';
    for (const file of files) {
      const fd = new FormData();
      fd.append('file', file);
      const r = await fetch('/api/upload', { method: 'POST', body: fd });
      const j = await r.json();
      const line = document.createElement('div');
      if (j.success) {
        line.innerHTML = `<strong>${file.name}</strong>: <a href="${j.url}?a=view">${j.url}</a>`;
      } else {
        line.textContent = `${file.name}: ${j.error}`;
      }
      uploadResult.appendChild(line);
    }
  };
}

let adminToken = localStorage.getItem('auth_token') || '';

async function authFetch(url, options = {}) {
  const headers = { ...(options.headers || {}) };
  if (adminToken) headers.Authorization = adminToken;
  return fetch(url, { ...options, headers });
}

const loginBtn = document.getElementById('login-btn');
if (loginBtn) {
  loginBtn.onclick = async () => {
    const username = document.getElementById('admin-user').value;
    const password = document.getElementById('admin-pass').value;
    const r = await fetch('/api/auth', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, password })
    });
    const j = await r.json();
    if (j.success) {
      adminToken = j.token;
      localStorage.setItem('auth_token', adminToken);
      loadStats();
      loadKeys();
      alert('Logged in');
    } else {
      alert(j.error || 'Login failed');
    }
  };
}

async function loadStats() {
  const r = await authFetch('/api/stats');
  const j = await r.json();
  document.getElementById('stats-box').textContent = JSON.stringify(j.items || [], null, 2);
}

async function loadKeys() {
  const list = document.getElementById('keys-list');
  const r = await authFetch('/api/api-keys');
  const j = await r.json();
  list.innerHTML = '';
  (j.keys || []).forEach((k) => {
    const li = document.createElement('li');
    li.textContent = `${k.label}: ${k.key}`;
    list.appendChild(li);
  });
}

const newKeyBtn = document.getElementById('new-key-btn');
if (newKeyBtn) {
  newKeyBtn.onclick = async () => {
    const label = document.getElementById('new-key-label').value || 'Untitled Key';
    await authFetch('/api/api-keys', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ label })
    });
    loadKeys();
  };
}

if (path.startsWith('/file/')) {
  const encoded = path.split('/').pop();
  fetch(`/file/${encoded}?info=true`).then((r) => r.json()).then((j) => {
    if (!j.success) return;
    document.getElementById('preview-name').textContent = j.originalName;
    const media = document.getElementById('preview-media');
    if (String(j.fileType).startsWith('video/')) {
      media.innerHTML = `<video controls src="/file/${encoded}" style="max-width:100%"></video>`;
    } else {
      media.innerHTML = `<img src="/file/${encoded}" style="max-width:100%" alt="preview"/>`;
    }
    const direct = document.getElementById('preview-direct');
    direct.href = `/file/${encoded}`;
    direct.textContent = `/file/${encoded}`;
  });
}

if (path === '/admin' && adminToken) {
  loadStats();
  loadKeys();
}
