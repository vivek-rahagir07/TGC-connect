/**
 * TGC Connect - Pure Vanilla JS Client Library & Animation Engine
 */

// 1. Web Audio API Sound Engine (zero external audio files, pure browser synthesis)
let audioCtx = null;
let soundEnabled = true;

function getAudioContext() {
  if (!audioCtx) {
    const AudioContext = window.AudioContext || window.webkitAudioContext;
    if (AudioContext) {
      audioCtx = new AudioContext();
    }
  }
  if (audioCtx && audioCtx.state === 'suspended') {
    audioCtx.resume();
  }
  return audioCtx;
}

function playSound(type = 'click') {
  if (!soundEnabled) return;
  try {
    const ctx = getAudioContext();
    if (!ctx) return;

    const now = ctx.currentTime;
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.connect(gain);
    gain.connect(ctx.destination);

    if (type === 'click') {
      osc.type = 'sine';
      osc.frequency.setValueAtTime(600, now);
      osc.frequency.exponentialRampToValueAtTime(300, now + 0.04);
      gain.gain.setValueAtTime(0.08, now);
      gain.gain.exponentialRampToValueAtTime(0.001, now + 0.04);
      osc.start(now);
      osc.stop(now + 0.04);
    } else if (type === 'success') {
      // Pleasant dual tone chime
      osc.type = 'triangle';
      osc.frequency.setValueAtTime(523.25, now); // C5
      osc.frequency.setValueAtTime(659.25, now + 0.1); // E5
      osc.frequency.setValueAtTime(783.99, now + 0.2); // G5
      gain.gain.setValueAtTime(0.12, now);
      gain.gain.linearRampToValueAtTime(0.15, now + 0.2);
      gain.gain.exponentialRampToValueAtTime(0.001, now + 0.5);
      osc.start(now);
      osc.stop(now + 0.5);
    } else if (type === 'error') {
      osc.type = 'sawtooth';
      osc.frequency.setValueAtTime(220, now);
      osc.frequency.linearRampToValueAtTime(140, now + 0.2);
      gain.gain.setValueAtTime(0.15, now);
      gain.gain.exponentialRampToValueAtTime(0.001, now + 0.25);
      osc.start(now);
      osc.stop(now + 0.25);
    } else if (type === 'shutter') {
      // Camera shutter snap
      osc.type = 'noise';
      osc.frequency.setValueAtTime(800, now);
      osc.frequency.exponentialRampToValueAtTime(120, now + 0.08);
      gain.gain.setValueAtTime(0.18, now);
      gain.gain.exponentialRampToValueAtTime(0.001, now + 0.08);
      osc.start(now);
      osc.stop(now + 0.08);
    } else if (type === 'beep') {
      osc.type = 'sine';
      osc.frequency.setValueAtTime(880, now);
      gain.gain.setValueAtTime(0.1, now);
      gain.gain.exponentialRampToValueAtTime(0.001, now + 0.1);
      osc.start(now);
      osc.stop(now + 0.1);
    }
  } catch (e) {
    // Audio not allowed yet before user interaction
  }
}

// 2. Celebratory Canvas Confetti Burst (Lightweight, pure canvas)
function fireConfetti(durationMs = 2500) {
  let canvas = document.getElementById('confettiCanvas');
  if (!canvas) {
    canvas = document.createElement('canvas');
    canvas.id = 'confettiCanvas';
    document.body.appendChild(canvas);
  }

  canvas.width = window.innerWidth;
  canvas.height = window.innerHeight;
  const ctx = canvas.getContext('2d');

  const particles = [];
  const colors = ['#4f46e5', '#06b6d4', '#10b981', '#f59e0b', '#ec4899', '#8b5cf6', '#3b82f6'];

  for (let i = 0; i < 90; i++) {
    particles.push({
      x: canvas.width / 2,
      y: canvas.height / 2 + 100,
      vx: (Math.random() - 0.5) * 16,
      vy: (Math.random() - 0.8) * 18,
      size: Math.random() * 8 + 4,
      color: colors[Math.floor(Math.random() * colors.length)],
      rotation: Math.random() * 360,
      rotSpeed: (Math.random() - 0.5) * 10,
      gravity: 0.35,
      opacity: 1
    });
  }

  const startTime = Date.now();

  function animate() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    const elapsed = Date.now() - startTime;

    let alive = false;
    for (let p of particles) {
      p.x += p.vx;
      p.y += p.vy;
      p.vy += p.gravity;
      p.rotation += p.rotSpeed;
      p.opacity = Math.max(0, 1 - (elapsed / durationMs));

      if (p.opacity > 0 && p.y < canvas.height + 50) {
        alive = true;
        ctx.save();
        ctx.globalAlpha = p.opacity;
        ctx.translate(p.x, p.y);
        ctx.rotate((p.rotation * Math.PI) / 180);
        ctx.fillStyle = p.color;
        ctx.fillRect(-p.size / 2, -p.size / 2, p.size, p.size * 1.4);
        ctx.restore();
      }
    }

    if (alive && elapsed < durationMs) {
      requestAnimationFrame(animate);
    } else {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
    }
  }

  requestAnimationFrame(animate);
}

// 3. Animated Number Counter (0 -> Target)
function animateValue(element, start, end, duration = 800) {
  if (!element) return;
  const startTimestamp = performance.now();
  const step = (now) => {
    const progress = Math.min((now - startTimestamp) / duration, 1);
    // Ease out quad
    const easeProgress = 1 - (1 - progress) * (1 - progress);
    const current = Math.floor(easeProgress * (end - start) + start);
    element.textContent = current.toLocaleString();
    if (progress < 1) {
      requestAnimationFrame(step);
    } else {
      element.textContent = end.toLocaleString();
    }
  };
  requestAnimationFrame(step);
}

// 4. Device Fingerprint (Anti-Proxy Helper)
function getDeviceFingerprint() {
  const nav = window.navigator;
  const screen = window.screen;
  const raw = [
    nav.userAgent,
    nav.language,
    screen.width + 'x' + screen.height,
    screen.colorDepth,
    new Date().getTimezoneOffset()
  ].join('|');

  // Simple string hash
  let hash = 0;
  for (let i = 0; i < raw.length; i++) {
    hash = ((hash << 5) - hash) + raw.charCodeAt(i);
    hash |= 0;
  }
  return 'fp-' + Math.abs(hash).toString(16);
}

// 5. Toast Notifications
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
  if (type === 'success') {
    icon = '✅';
    playSound('success');
  } else if (type === 'error') {
    icon = '❌';
    playSound('error');
  } else if (type === 'warning') {
    icon = '⚠️';
    playSound('beep');
  } else {
    playSound('click');
  }

  toast.innerHTML = `<span>${icon}</span> <span>${message}</span>`;
  container.appendChild(toast);

  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transform = 'translateX(100%)';
    toast.style.transition = 'all 0.3s ease';
    setTimeout(() => toast.remove(), 300);
  }, 4200);
}

// 6. Generic API Client
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

// 7. Modal Control with Light-Dismiss
function openModal(id) {
  const m = document.getElementById(id);
  if (m) {
    m.classList.add('active');
    playSound('click');
  }
}

function closeModal(id) {
  const m = document.getElementById(id);
  if (m) {
    m.classList.remove('active');
  }
}

// Global light dismiss & ESC key handler for modals
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay.active').forEach(m => m.classList.remove('active'));
  }
});

document.addEventListener('click', (e) => {
  if (e.target.classList && e.target.classList.contains('modal-overlay')) {
    e.target.classList.remove('active');
  }
});

// 8. Workplace Digital Clock
function initDigitalClock(elementId) {
  const el = document.getElementById(elementId);
  if (!el) return;

  function tick() {
    const now = new Date();
    const timeStr = now.toLocaleTimeString([], { hour12: false });
    const dateStr = now.toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' });
    el.innerHTML = `<span style="font-weight: 800; letter-spacing: 0.5px;">${timeStr}</span> &bull; <span style="opacity: 0.85;">${dateStr}</span>`;
  }
  tick();
  setInterval(tick, 1000);
}

// 9. Auth State Navigation Bar Sync
async function handleLogout() {
  playSound('click');
  await apiRequest('auth.php?action=logout', 'POST');
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
          <a href="admin.html" class="nav-link"><i data-lucide="layout-dashboard" style="width: 18px;"></i> Admin Console</a>
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
            <div style="font-size: 0.85rem; font-weight: 800;">${u.name}</div>
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
        <a href="index.html" class="nav-link">Home</a>
        <a href="login.html" class="nav-link">Sign In</a>
        <a href="register.html" class="nav-link">Join Team</a>
      `;
    }
    if (navUserArea) {
      navUserArea.innerHTML = `
        <a href="login.html" class="btn btn-secondary btn-sm">Sign In</a>
        <a href="register.html" class="btn btn-primary btn-sm">Onboard Employee</a>
      `;
    }
  }

  if (window.lucide) {
    lucide.createIcons();
  }
}

// 10. Webcam Live Streaming & Snapshot Capture
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
    await video.play();
    return true;
  } catch (err) {
    console.warn('Webcam live stream note:', err);
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
  playSound('shutter');
  return canvas.toDataURL('image/jpeg', 0.88);
}
