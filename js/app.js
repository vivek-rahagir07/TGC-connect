/**
 * TGC Connect - Pure Vanilla JS Client Library
 */

function showToast(message, type = 'info') {
  let container = document.getElementById('toast-container');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toast-container';
    document.body.appendChild(container);
  }

  const toast = document.createElement('div');
  toast.className = `toast ${type}`;

  let icon = 'ℹ️';
  if (type === 'success') icon = '✅';
  if (type === 'error') icon = '❌';
  if (type === 'warning') icon = '⚠️';

  toast.innerHTML = `<span>${icon}</span> <span>${message}</span>`;
  container.appendChild(toast);

  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transform = 'translateX(100%)';
    toast.style.transition = 'all 0.3s ease';
    setTimeout(() => toast.remove(), 300);
  }, 4000);
}

async function apiRequest(endpoint, method = 'GET', data = null) {
  const options = {
    method: method,
    headers: {
      'Accept': 'application/json',
    },
  };

  if (data) {
    options.headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(data);
  }

  try {
    const res = await fetch(`api/${endpoint}`, options);
    const json = await res.json();
    return { ok: res.ok, status: res.status, data: json };
  } catch (err) {
    console.error('API Error:', err);
    return { ok: false, status: 500, data: { message: 'Failed to connect to backend server.' } };
  }
}

function openModal(id) {
  const m = document.getElementById(id);
  if (m) m.classList.add('active');
}

function closeModal(id) {
  const m = document.getElementById(id);
  if (m) m.classList.remove('active');
}

function initDigitalClock(elementId) {
  const el = document.getElementById(elementId);
  if (!el) return;

  function tick() {
    const now = new Date();
    el.innerHTML = `<span style="font-weight: 700;">${now.toLocaleTimeString()}</span> &bull; <span style="opacity: 0.85;">${now.toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' })}</span>`;
  }
  tick();
  setInterval(tick, 1000);
}

async function handleLogout() {
  const res = await apiRequest('auth.php?action=logout', 'POST');
  window.location.href = 'login.html';
}

async function checkAuthNav() {
  const res = await apiRequest('auth.php?action=me');
  const navUserArea = document.getElementById('navUserArea');
  const navMenu = document.getElementById('navMenu');

  if (res.ok && res.data.authenticated) {
    const u = res.data.user;
    if (navMenu) {
      if (u.role === 'admin') {
        navMenu.innerHTML = `
          <a href="admin.html" class="nav-link"><i data-lucide="layout-dashboard" style="width: 18px;"></i> Admin Dashboard</a>
          <a href="qr_scanner.html" class="nav-link"><i data-lucide="qr-code" style="width: 18px;"></i> QR Punch</a>
        `;
      } else {
        navMenu.innerHTML = `
          <a href="portal.html" class="nav-link"><i data-lucide="user" style="width: 18px;"></i> Employee Portal</a>
          <a href="qr_scanner.html" class="nav-link"><i data-lucide="qr-code" style="width: 18px;"></i> QR Punch</a>
        `;
      }
    }

    if (navUserArea) {
      navUserArea.innerHTML = `
        <div class="user-profile-badge">
          <div class="avatar-sm">
            ${u.photo_url ? `<img src="${u.photo_url}" style="width: 100%; height: 100%; object-fit: cover;">` : u.name.charAt(0)}
          </div>
          <div style="line-height: 1.2;">
            <div style="font-size: 0.85rem; font-weight: 700;">${u.name}</div>
            <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: capitalize;">${u.role}</div>
          </div>
        </div>
        <button onclick="handleLogout()" class="btn btn-secondary btn-sm" title="Logout">
          <i data-lucide="log-out" style="width: 16px;"></i>
        </button>
      `;
    }
  } else {
    if (navMenu) {
      navMenu.innerHTML = `
        <a href="login.html" class="nav-link">Login</a>
        <a href="register.html" class="nav-link">Employee Onboarding</a>
      `;
    }
    if (navUserArea) {
      navUserArea.innerHTML = `
        <a href="login.html" class="btn btn-secondary btn-sm">Login</a>
        <a href="register.html" class="btn btn-primary btn-sm">Join Team</a>
      `;
    }
  }

  if (window.lucide) {
    lucide.createIcons();
  }
}

// Camera Live Streaming & Snapshot for Onboarding
let mediaStream = null;

async function initWebcam(videoElementId) {
  const video = document.getElementById(videoElementId);
  if (!video) return false;

  try {
    mediaStream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
      audio: false
    });
    video.srcObject = mediaStream;
    video.play();
    return true;
  } catch (err) {
    console.error('Webcam Error:', err);
    showToast('Unable to access device camera. Please check permissions.', 'error');
    return false;
  }
}

function stopWebcam() {
  if (mediaStream) {
    mediaStream.getTracks().forEach(track => track.stop());
    mediaStream = null;
  }
}

function takeSnapshot(videoElementId, canvasElementId) {
  const video = document.getElementById(videoElementId);
  const canvas = document.getElementById(canvasElementId);
  if (!video || !canvas) return null;

  canvas.width = video.videoWidth || 640;
  canvas.height = video.videoHeight || 480;
  const ctx = canvas.getContext('2d');
  ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
  return canvas.toDataURL('image/jpeg', 0.85);
}
